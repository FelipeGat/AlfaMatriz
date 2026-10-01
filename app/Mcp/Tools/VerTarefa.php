<?php

namespace App\Mcp\Tools;

use App\Models\Tarefa;
use App\Models\TarefaAnexo;
use App\Models\TarefaComentario;
use App\Models\TarefaEvento;
use App\Models\TarefaItem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * A tarefa inteira, como o modal a mostra — e mais uma linha que o modal não
 * precisa: para onde ESTA pessoa pode movê-la. O modal desenha o menu com os
 * destinos; o agente não tem menu, então a lista vem escrita, com o motivo
 * quando ela é vazia.
 */
class VerTarefa extends Ferramenta
{
    /** Comentários e eventos mais antigos que isso saem do texto: o agente lê o fim da conversa, não o arquivo. */
    private const ULTIMOS = 12;

    protected string $name = 'ver_tarefa';

    protected string $title = 'Ver tarefa';

    protected string $description = 'Tudo sobre uma tarefa: resumo, detalhes, responsável, marcas (bloqueio, retorno, pergunta), checklist, conversa, histórico de etapas e para onde VOCÊ pode movê-la. Leia antes de mover ou responder.';

    protected array $permissao = ['tarefas', 'ler'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()->description('O código, como "#128" ou 128.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate(['tarefa' => 'required|string|max:20']);

        $tarefa = $this->tarefaPeloCodigo($dados['tarefa']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$dados['tarefa'].'.');
        }

        $tarefa->load([
            'responsavel', 'sistema', 'criadoPor', 'interlocutor', 'perguntaDe', 'perguntaPara',
            'pai', 'subtarefas', 'duplicadaDe', 'duplicadas', 'itens', 'comentarios.autor', 'eventos.autor', 'anexos',
        ]);

        $blocos = [$this->linhaDaTarefa($tarefa)];

        $blocos[] = implode(' · ', array_filter([
            'Tipo: '.(Tarefa::TIPOS[$tarefa->tipo] ?? $tarefa->tipo),
            'Criada por '.($tarefa->criadoPor?->name ?? '?').' em '.$tarefa->created_at->format('d/m/Y'),
            $tarefa->interlocutor ? 'Interlocutor: '.$tarefa->interlocutor->name : null,
            $tarefa->versao_producao ? 'Versão em produção: '.$tarefa->versao_producao : null,
        ]));

        if (filled($tarefa->resumo)) {
            $blocos[] = 'Resumo: '.$tarefa->resumo;
        }

        if (filled($tarefa->detalhes)) {
            $blocos[] = 'Detalhes: '.$tarefa->detalhes;
        }

        // O relato do Defeito (#204) é o caso concreto — quem e em que minuto
        // procurar no log —, e é a primeira coisa de que o agente precisa para
        // investigar. Sem ele, o print nos anexos seria a única pista.
        if ($tarefa->tipo === 'defeito') {
            $blocos[] = "Relato do defeito:\n".implode("\n", [
                '- Quem: '.($tarefa->defeito_quem ?: 'não informado'),
                '- Quando: '.($tarefa->defeito_quando?->format('d/m/Y H:i') ?? 'não informado'),
                '- Esperado: '.($tarefa->defeito_esperado ?: 'não informado'),
                '- Ocorrido: '.($tarefa->defeito_ocorrido ?: 'não informado'),
            ]);
        }

        if ($tarefa->pai) {
            $blocos[] = 'Subtarefa de '.$tarefa->pai->codigo().' · '.$tarefa->pai->titulo;
        }

        // As duas pontas da duplicidade (#205): quem lê a cancelada chega à
        // original, e quem lê a original sabe que o pedido se repetiu.
        if ($tarefa->duplicadaDe) {
            $blocos[] = 'Cancelada como duplicada de '.$tarefa->duplicadaDe->codigo().' · '.$tarefa->duplicadaDe->titulo;
        }

        if ($tarefa->duplicadas->isNotEmpty()) {
            $blocos[] = 'Pedida de novo (canceladas como duplicadas desta): '.$tarefa->duplicadas
                ->map(fn (Tarefa $copia) => $copia->codigo())->implode(', ');
        }

        if ($tarefa->subtarefas->isNotEmpty()) {
            $blocos[] = "Subtarefas:\n".$tarefa->subtarefas
                ->map(fn (Tarefa $sub) => '- '.$sub->codigo().' · '.Tarefa::rotuloDaEtapa($sub->status).' · '.$sub->titulo)
                ->implode("\n");
        }

        $marcas = array_filter([
            $tarefa->estaBloqueada() ? $tarefa->rotuloDoBloqueio().': '.$tarefa->bloqueio_motivo : null,
            $tarefa->temRetorno() ? $tarefa->rotuloDoRetorno().($tarefa->retorno_motivo ? ': '.$tarefa->retorno_motivo : '') : null,
            $tarefa->temPergunta()
                ? 'Pergunta de '.($tarefa->perguntaDe?->name ?? '?').' para '.($tarefa->perguntaPara?->name ?? '?')
                    .' desde '.$tarefa->pergunta_em->format('d/m H:i').' (rodada '.$tarefa->rodadas.')'
                    .($tarefa->esperaRespostaDe($usuario) ? ' — a vez é SUA' : '')
                : null,
        ]);

        if ($marcas !== []) {
            $blocos[] = "Marcas:\n- ".implode("\n- ", $marcas);
        }

        if ($tarefa->itens->isNotEmpty()) {
            $blocos[] = "Checklist:\n".$tarefa->itens
                ->map(fn (TarefaItem $item) => $this->linhaDoItem($item))
                ->implode("\n");
        }

        if ($tarefa->anexos->isNotEmpty()) {
            // Com o número de cada um: é ele que `ver_anexo` recebe. Só o nome
            // dizia ao agente que havia uma prova, sem dar como olhar para ela.
            $blocos[] = "Anexos (abra com ver_anexo):\n".$tarefa->anexos
                ->map(fn (TarefaAnexo $anexo) => '- anexo '.$anexo->id.': '.$anexo->nome_original
                    .' · '.($anexo->eh_imagem ? 'imagem' : 'arquivo').' · '.$anexo->tamanho_formatado)
                ->implode("\n");
        }

        if ($tarefa->comentarios->isNotEmpty()) {
            $omitidos = max(0, $tarefa->comentarios->count() - self::ULTIMOS);

            $blocos[] = 'Conversa'.($omitidos ? ' (últimos '.self::ULTIMOS.' de '.$tarefa->comentarios->count().')' : '').":\n"
                .$tarefa->comentarios->slice(-self::ULTIMOS)
                    ->map(fn (TarefaComentario $c) => '- '.$c->created_at->format('d/m H:i').' '.($c->autor?->name ?? '?')
                        .($c->pergunta ? ' (pergunta)' : '').': '.$c->corpo)
                    ->implode("\n");
        }

        $eventos = $tarefa->eventos->sortBy('entrou_em')->values();

        if ($eventos->isNotEmpty()) {
            $blocos[] = "Histórico de etapas:\n".$eventos->slice(-self::ULTIMOS)
                ->map(fn (TarefaEvento $e) => '- '.$e->entrou_em?->format('d/m H:i').' '
                    .($e->de_status ? Tarefa::rotuloDaEtapa($e->de_status).' → ' : 'nasceu em ')
                    .Tarefa::rotuloDaEtapa($e->para_status)
                    .($e->autor ? ' ('.$e->autor->name.')' : '')
                    .($e->motivo ? ' — '.$e->motivo : ''))
                ->implode("\n");
        }

        $destinos = $tarefa->destinosPara($usuario);

        $blocos[] = $destinos !== []
            ? 'Você pode mover para: '.collect($destinos)->map(fn (string $s) => $s.' ('.Tarefa::rotuloDaEtapa($s).')')->implode(', ')
            : 'Você não pode mover esta tarefa'.(($motivo = $tarefa->motivoParaNaoMover($usuario)) ? ': '.$motivo : '.');

        return Response::text(implode("\n\n", $blocos));
    }
}
