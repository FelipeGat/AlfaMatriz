<?php

namespace App\Services;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Arquivar e desarquivar tarefa (#208).
 *
 * O arquivo é para o que não vai andar AGORA, mas não deve sumir: a ideia
 * boa para depois, o pedido sem retorno de quem abriu, o defeito que talvez
 * já esteja resolvido. Cancelar é decidir que não se faz; arquivar é "agora
 * não" ou "não sabemos" — e por isso a tarefa volta, e volta para onde
 * estava.
 *
 * A tela e o servidor MCP chamam por aqui, como fazem com a duplicidade:
 * a regra copiada na ferramenta divergiria da tela na primeira vez que
 * alguém a ajustasse de um lado só. Recusa com a frase pronta
 * (`RuntimeException`), que a tela mostra no flash e o MCP devolve ao agente.
 */
class ArquivoDeTarefas
{
    /**
     * Tira a tarefa do quadro, com o motivo, e avisa quem a abriu.
     *
     * Só quem faz triagem (decisão do dono do produto em 02/10/2026): arquivar
     * é decidir o que sai da frente do time, a mesma régua de prioridade e
     * responsável.
     *
     * O comentário nasce ANTES da marca, e não é detalhe: quem reabre a
     * arquivada é o comentário de quem a abriu (`reabrirSeQuemAbriuRespondeu`),
     * e na tarefa que a própria pessoa abriu e arquivou, o aviso escrito
     * depois da marca a desarquivaria no mesmo instante.
     */
    public function arquivar(Tarefa $tarefa, ?string $motivo, ?string $nota, User $autor): Tarefa
    {
        if (! $autor->podeTriarTarefas()) {
            throw new \RuntimeException('Só quem faz triagem arquiva tarefa.');
        }

        if ($tarefa->estaArquivada()) {
            throw new \RuntimeException('A tarefa '.$tarefa->codigo().' já está arquivada.');
        }

        if (in_array($tarefa->status, Tarefa::STATUS_TERMINAIS, true)) {
            throw new \RuntimeException('A tarefa '.$tarefa->codigo().' já está encerrada. Só tarefa em curso é arquivada.');
        }

        if (! array_key_exists((string) $motivo, Tarefa::MOTIVOS_DE_ARQUIVAMENTO)) {
            throw new \RuntimeException('Diga por que está arquivando: '
                .implode(', ', array_map(fn ($chave, $rotulo) => $chave.' ('.$rotulo.')', array_keys(Tarefa::MOTIVOS_DE_ARQUIVAMENTO), Tarefa::MOTIVOS_DE_ARQUIVAMENTO)).'.');
        }

        // A filha em curso ficaria no quadro pendurada numa mãe que ninguém
        // vê — e levá-la junto arquivaria trabalho de outra pessoa sem ela
        // saber. Quem arquiva decide filha por filha.
        $filhasEmCurso = $tarefa->subtarefas()
            ->foraDoArquivo()
            ->whereNotIn('status', Tarefa::STATUS_TERMINAIS)
            ->pluck('id');

        if ($filhasEmCurso->isNotEmpty()) {
            throw new \RuntimeException('A tarefa '.$tarefa->codigo().' tem subtarefas em curso ('
                .$filhasEmCurso->map(fn ($id) => '#'.$id)->implode(', ').'). Arquive ou encerre as subtarefas antes.');
        }

        $nota = trim((string) $nota) ?: null;

        return DB::transaction(function () use ($tarefa, $motivo, $nota, $autor) {
            $tarefa->comentarios()->create([
                'autor_id' => $autor->id,
                'corpo' => $this->avisoDoArquivamento($tarefa, $motivo, $nota, $autor),
            ]);

            // `forceFill` porque as colunas ficam fora do `fillable`, como as
            // do bloqueio: um `update` de formulário não arquiva de passagem.
            $tarefa->forceFill([
                'arquivada_em' => now(),
                'arquivada_por_id' => $autor->id,
                'arquivamento_motivo' => $motivo,
                'arquivamento_nota' => $nota,
            ])->save();

            $this->avisarArquivamento($tarefa, $autor);

            return $tarefa->refresh();
        });
    }

    /**
     * Devolve a tarefa ao quadro, na coluna e com o responsável de antes.
     *
     * O gesto da tela e do MCP: só quem faz triagem, como arquivar. A volta
     * pela resposta de quem abriu não passa por aqui — ver
     * `reabrirSeQuemAbriuRespondeu`.
     */
    public function desarquivar(Tarefa $tarefa, User $autor): Tarefa
    {
        if (! $autor->podeTriarTarefas()) {
            throw new \RuntimeException('Só quem faz triagem desarquiva tarefa.');
        }

        if (! $tarefa->estaArquivada()) {
            throw new \RuntimeException('A tarefa '.$tarefa->codigo().' não está arquivada.');
        }

        return DB::transaction(function () use ($tarefa, $autor) {
            $this->tirarDoArquivo($tarefa);

            // Registro na conversa, onde o arquivamento também ficou: sem ele,
            // quem lê a tarefa depois vê "arquivada" e nada dizendo que voltou.
            $tarefa->comentarios()->create([
                'autor_id' => $autor->id,
                'corpo' => 'Desarquivada. Voltou para '.Tarefa::rotuloDaEtapa($tarefa->status).'.',
            ]);

            $this->avisarVolta($tarefa, $autor->id, 'Desarquivada por '.$autor->name);

            return $tarefa->refresh();
        });
    }

    /**
     * Quem abriu a tarefa respondeu: ela volta para o quadro sozinha.
     *
     * É a promessa do aviso de arquivamento — "responda aqui e ela volta" —,
     * e o que resolve o "não sei se foi resolvido": quem sabe é quem abriu, e
     * a resposta é a notícia. Sem pedir triagem, de propósito: exigir
     * que quem abriu peça a alguém para ler o próprio comentário recriaria a
     * espera que levou ao arquivo.
     *
     * Chamado pelo `created` do comentário (`TarefaComentario::booted`), que é
     * o único ponto por onde passam os cinco caminhos que comentam — tela,
     * pergunta, resposta, salvar e MCP.
     */
    public function reabrirSeQuemAbriuRespondeu(TarefaComentario $comentario): void
    {
        $tarefa = $comentario->tarefa;

        if (! $tarefa?->estaArquivada()
            || $tarefa->criado_por_id === null
            || $comentario->autor_id !== $tarefa->criado_por_id) {
            return;
        }

        $this->tirarDoArquivo($tarefa);

        $this->avisarVolta($tarefa, $comentario->autor_id,
            ($comentario->autor?->name ?? 'Quem abriu').' respondeu: '.Str::limit((string) $comentario->corpo, 80));
    }

    private function tirarDoArquivo(Tarefa $tarefa): void
    {
        $tarefa->forceFill([
            'arquivada_em' => null,
            'arquivada_por_id' => null,
            'arquivamento_motivo' => null,
            'arquivamento_nota' => null,
        ])->save();
    }

    /**
     * O comentário que registra o arquivamento — e o recado para quem abriu.
     *
     * Na conversa, e não só na marca: a marca some quando a tarefa volta, e
     * o histórico de que ela passou meses no arquivo, e por quê, é o que
     * explica o buraco na linha do tempo.
     */
    private function avisoDoArquivamento(Tarefa $tarefa, string $motivo, ?string $nota, User $autor): string
    {
        $linha = 'Arquivada · '.Tarefa::MOTIVOS_DE_ARQUIVAMENTO[$motivo].($nota ? ': '.$nota : '.');

        $quemAbriu = $tarefa->criadoPor;

        if (! $quemAbriu || $quemAbriu->id === $autor->id) {
            return $linha."\nSaiu do quadro, mas guarda o histórico. Um comentário de quem abriu a traz de volta.";
        }

        return $linha."\n".$quemAbriu->name.', se ainda precisar disto, responda aqui que ela volta para o quadro.';
    }

    /**
     * Quem abriu é quem tem o que dizer — e não lê o quadro todo dia. O
     * responsável também recebe: era trabalho dele que saiu da frente.
     */
    private function avisarArquivamento(Tarefa $tarefa, User $autor): void
    {
        $destinatarios = collect([$tarefa->criado_por_id, $tarefa->responsavel_id])->filter()->unique();

        foreach ($destinatarios as $destinatarioId) {
            Notificacao::avisar($destinatarioId, $autor->id, [
                'tipo' => 'arquivamento',
                'nivel' => 'marca',
                'icone' => 'arquivo',
                'titulo' => '«'.$tarefa->titulo.'» foi arquivada',
                'meta' => Str::limit($tarefa->rotuloDoArquivamento().($tarefa->arquivamento_nota ? ': '.$tarefa->arquivamento_nota : ''), 120),
                'rota' => route('tarefas.index'),
                'tarefa_id' => $tarefa->id,
            ]);
        }
    }

    /**
     * A volta é notícia para quem vai pegar a tarefa: o responsável e a
     * triagem — a tarefa reaparece numa coluna, e ninguém está olhando para
     * ela.
     */
    private function avisarVolta(Tarefa $tarefa, ?int $autorId, string $meta): void
    {
        $destinatarios = User::idsDeQuemTriaTarefas()
            ->push($tarefa->responsavel_id)
            ->filter()
            ->unique();

        foreach ($destinatarios as $destinatarioId) {
            Notificacao::avisar($destinatarioId, $autorId, [
                'tipo' => 'desarquivamento',
                'nivel' => 'marca',
                'icone' => 'arquivo',
                'titulo' => '«'.$tarefa->titulo.'» voltou do arquivo',
                'meta' => Str::limit($meta, 120),
                'rota' => route('tarefas.index'),
                'tarefa_id' => $tarefa->id,
            ]);
        }
    }
}
