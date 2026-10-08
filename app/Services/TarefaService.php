<?php

namespace App\Services;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * O que acontece com a tarefa DEPOIS de o envio ser validado: nascer, ganhar
 * dono, avisar quem recebe.
 *
 * Saiu do `TarefaController` em 28/09/2026 porque a criação ganhou uma segunda
 * porta — o servidor MCP (`App\Mcp`), por onde o agente abre tarefa em nome de
 * quem o comanda. As duas portas validam do seu jeito (formulário de um lado,
 * argumentos de ferramenta do outro), mas o que vem depois precisa ser um só:
 * os padrões, a régua de quem não triaga, a trava de reenvio, o checklist, a
 * mãe e o aviso. Copiado na ferramenta, uma tarefa aberta pelo agente nasceria
 * diferente da aberta na tela, e a diferença só apareceria no dia em que
 * alguém consertasse um lado só.
 *
 * O `FluxoTarefaService` continua respondendo por onde a tarefa ANDA; aqui é
 * só como ela NASCE e como troca de mãos.
 */
class TarefaService
{
    /**
     * O formato do relato do Bug, para as duas portas validarem igual. O que é
     * OBRIGATÓRIO não está aqui: ver `comORelatoDoBug`.
     *
     * "O que esperava" e "o que aconteceu" saíram no segundo ajuste da #204:
     * repetiam o resumo, que no Bug passou a se chamar "O que aconteceu".
     */
    public const REGRAS_DO_RELATO = [
        'defeito_quem' => 'nullable|string|max:255',
        'defeito_quando' => 'nullable|date',
    ];

    public function __construct(private readonly FluxoTarefaService $fluxo) {}

    /**
     * Cria a tarefa — ou devolve null quando o mesmo envio acabou de ser gravado.
     *
     * @param  array<string, mixed>  $dados  os campos já validados: `titulo`,
     *                                       `resumo`, `tipo`, `sistema_id`,
     *                                       `responsavel_id`, `prioridade`,
     *                                       `prazo`, `status`
     * @param  list<string|null>  $itens  o checklist, em texto; brancos são ignorados
     * @param  int|null  $paiId  a tarefa de que esta é subtarefa, se houver
     */
    public function criar(array $dados, User $autor, array $itens = [], ?int $paiId = null): ?Tarefa
    {
        // O tipo é escolha de quem abre, e não tem mais padrão (#204, pedido do
        // dono do produto em 01/10/2026): com "Desenvolvimento" vindo marcado,
        // bug era aberto como desenvolvimento por omissão e sumia da contagem
        // de bugs por sistema. A recusa mora AQUI para a tela e o
        // `criar_tarefa` dizerem a mesma frase.
        if (blank($dados['tipo'] ?? null)) {
            throw ValidationException::withMessages(['tipo' => self::recusaSemTipo()]);
        }

        $dados['prioridade'] ??= 'media';
        $dados['criado_por_id'] = $autor->id;

        $dados = $this->comORelatoDoBug($dados);

        $dados = $this->semTriagemDeQuemNaoTriaga($dados, $autor);

        // A mãe é conferida no servidor, e não no campo que a sugeriu: um
        // nível só, e mãe encerrada não recebe. Se ela não serve, a tarefa
        // nasce SOLTA em vez de não nascer — o texto já foi digitado, e
        // recusar o envio inteiro por causa do vínculo jogaria fora o trabalho
        // para consertar a parte menor dele.
        $pai = $paiId ? Tarefa::find($paiId) : null;

        if ($pai && ! $pai->podeReceberSubtarefa()) {
            $pai = null;
        }

        // Mesma rede do comentário (AC-137): aqui o clique duplo custa mais
        // caro, porque a segunda tarefa não é uma linha repetida na conversa —
        // é um card a mais no quadro, que alguém vai ter de cancelar na mão.
        // O checklist e o aviso ficam DENTRO da trava: a que impede o segundo
        // card impede a lista dobrada e o segundo sino tocando pelo mesmo fato.
        if ($this->reenvioDaMesmaTarefa($dados)) {
            return null;
        }

        $tarefa = Tarefa::create($dados);

        // `forceFill` porque `tarefa_pai_id` fica fora do `fillable`: um
        // formulário de cadastro não deveria conseguir pendurar uma tarefa em
        // outra de passagem.
        if ($pai) {
            $tarefa->forceFill(['tarefa_pai_id' => $pai->id])->save();
        }

        // Em branco não vira item: o campo de novo item do formulário viaja
        // como o último `itens[]` mesmo sem texto, e quando não há nada ali
        // não há nada a gravar.
        foreach ($itens as $texto) {
            if (trim((string) $texto) !== '') {
                $tarefa->itens()->create(['texto' => trim((string) $texto)]);
            }
        }

        $this->avisarNascimento($tarefa, $autor);

        return $tarefa;
    }

    /**
     * Grava a edição da tarefa — e devolve a etapa para onde ela foi junto com
     * o responsável, se foi.
     *
     * Saiu do `TarefaController::update` em 01/10/2026, quando o servidor MCP
     * ganhou a porta de editar: até ali o agente criava a tarefa e não tinha
     * como corrigir título, prazo ou responsável.
     *
     * @param  array<string, mixed>  $dados  só os campos que mudam, já validados
     */
    public function atualizar(Tarefa $tarefa, array $dados, User $autor): ?string
    {
        // Envio sem o campo mantém o tipo que a tarefa já tem: `null` aqui
        // apagaria a coluna, porque o padrão do modelo só vale na criação.
        $dados['tipo'] ??= $tarefa->tipo;
        $dados['prioridade'] ??= $tarefa->prioridade;

        $dados = $this->comORelatoDoBug($dados, $tarefa);

        // Na edição, o que a triagem decidiu fica como está: quem não triaga
        // salvar a tarefa não pode zerar a prioridade nem soltar o responsável
        // de passagem.
        $dados = $this->semTriagemDeQuemNaoTriaga($dados, $autor, $tarefa);

        $responsavelAntes = $tarefa->responsavel_id;

        $tarefa->update($dados);

        $etapaNova = $this->seguirOResponsavel($tarefa);

        // Ganhar a tarefa pela edição é o mesmo fato que ganhá-la na criação, e
        // o aviso é o mesmo. Depois de `seguirOResponsavel`, para a meta dizer
        // a etapa em que o card de fato ficou — direcionar da fila já o leva ao
        // Backlog no mesmo gesto.
        if ($tarefa->responsavel_id !== null && $tarefa->responsavel_id !== $responsavelAntes) {
            $this->avisarDirecionamento($tarefa, $autor);
        }

        return $etapaNova;
    }

    /**
     * Direcionar move a tarefa; tirar o dono a devolve para a fila.
     *
     * Na criação, escolher responsável já fazia a tarefa nascer no Backlog
     * (`Tarefa::booted`) — mas na edição o mesmo gesto a deixava em Aberta, e
     * quem direcionava tinha de arrastar o card em seguida. Era o mesmo fato
     * com dois comportamentos, e dois passos para uma intenção só.
     *
     * O movimento passa pelo motor do fluxo, e não por um `update` direto, para
     * o cronômetro da etapa continuar honesto: um card que troca de coluna sem
     * evento seria tempo de Aberta contado como tempo de Backlog.
     *
     * Só vale entre Aberta e Backlog. Trocar o responsável de uma tarefa que já
     * está em andamento é trocar quem faz, não recomeçar o fluxo dela.
     */
    private function seguirOResponsavel(Tarefa $tarefa): ?string
    {
        $destino = match (true) {
            $tarefa->status === 'aberta' && $tarefa->responsavel_id !== null => 'backlog',
            $tarefa->status === 'backlog' && $tarefa->responsavel_id === null => 'aberta',
            default => null,
        };

        if ($destino) {
            $this->fluxo->mover($tarefa, $destino);
        }

        return $destino;
    }

    /**
     * Exclui a tarefa — o único gesto do quadro sem desfazer.
     *
     * Só quem faz triagem, e nunca a mãe com filha aberta: excluí-la soltaria
     * oito bugs sem mãe de uma vez. A recusa volta como `RuntimeException` com
     * a frase que a tela mostra.
     */
    public function excluir(Tarefa $tarefa, User $autor): void
    {
        if (! $autor->podeTriarTarefas()) {
            throw new \RuntimeException('Só quem faz triagem exclui tarefa. Para encerrar sem apagar, cancele.');
        }

        if ($impedimento = $tarefa->motivoParaNaoEncerrar()) {
            throw new \RuntimeException($impedimento);
        }

        // O aviso sai ANTES do forceDelete e SEM `tarefa_id`: a coluna apaga em
        // cascata junto com a tarefa, e um aviso preso a ela morreria no mesmo
        // instante em que nasce — logo o aviso do único gesto sem desfazer.
        // Criador e responsável são quem sente a falta do card.
        foreach (collect([$tarefa->criado_por_id, $tarefa->responsavel_id])->filter()->unique() as $destinatarioId) {
            Notificacao::avisar((int) $destinatarioId, $autor->id, [
                'tipo' => 'exclusao',
                'nivel' => 'atencao',
                'icone' => 'trash',
                'titulo' => '«'.$tarefa->titulo.'» foi excluída do quadro',
                'meta' => 'Por '.$autor->name.' · sem desfazer',
                'rota' => route('tarefas.index'),
            ]);
        }

        // `forceDelete` porque excluir aqui QUER dizer sumir: a tarefa usa
        // SoftDeletes, e um `delete()` deixaria a linha no banco sem aparecer
        // em lugar nenhum — nem no quadro, nem no histórico, nem para quem
        // fosse auditar. Excluir pela metade é o pior dos dois mundos.
        $tarefa->forceDelete();
    }

    /**
     * A frase de quem tenta abrir tarefa sem dizer o tipo — com os tipos, para
     * quem lê (pessoa ou agente) não precisar ir procurar.
     */
    public static function recusaSemTipo(): string
    {
        $tipos = array_values(Tarefa::TIPOS);
        $ultimo = array_pop($tipos);

        return 'Escolha o tipo da tarefa: '.implode(', ', $tipos).' ou '.$ultimo.'.';
    }

    /**
     * O relato do bug: exigido para o Bug, descartado dos outros tipos.
     *
     * "Quem" e "quando" são o mínimo (tarefa #204): as #185 e #191 chegaram só
     * com o sintoma, e bug que afeta uma pessoa só não se investiga sem saber
     * QUAL pessoa e EM QUE minuto procurar no log. O que aconteceu vai no
     * resumo, que no Bug muda de nome na tela para dizer isso.
     *
     * A exigência mora aqui, e não na validação de cada porta, porque a tela e
     * o `criar_tarefa`/`editar_tarefa` do MCP precisam recusar com a MESMA
     * frase. Vale também na edição: trocar o tipo para Bug sem o relato seria
     * a porta dos fundos da exigência. Na edição parcial (MCP) o que não veio
     * é o que já está gravado.
     *
     * Nos outros tipos os campos saem do envio em vez de serem gravados: o
     * formulário os carrega escondidos, e quem escolheu Bug, preencheu e
     * voltou para Desenvolvimento não pediu para gravar um relato — e na
     * edição, trocar de tipo não apaga o relato que já existia.
     *
     * O `quando` vira data aqui pela trava de reenvio: ela compara o envio com
     * a linha gravada, e o "2026-09-30T14:20" do campo não casa com o
     * "2026-09-30 14:20:00" da coluna — o clique duplo criaria dois bugs.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function comORelatoDoBug(array $dados, ?Tarefa $tarefa = null): array
    {
        $campos = array_keys(self::REGRAS_DO_RELATO);

        if ($dados['tipo'] !== 'bug') {
            return array_diff_key($dados, array_flip($campos));
        }

        foreach ($campos as $campo) {
            if (array_key_exists($campo, $dados)) {
                $dados[$campo] = filled($dados[$campo]) ? trim((string) $dados[$campo]) : null;
            }
        }

        $valor = fn (string $campo) => array_key_exists($campo, $dados) ? $dados[$campo] : $tarefa?->{$campo};

        $faltas = array_filter([
            'defeito_quem' => blank($valor('defeito_quem'))
                ? 'Bug precisa dizer quem foi afetado: o cliente, o aluno ou a academia.'
                : null,
            'defeito_quando' => blank($valor('defeito_quando'))
                ? 'Bug precisa dizer quando aconteceu: o dia e a hora.'
                : null,
        ]);

        if ($faltas !== []) {
            throw ValidationException::withMessages($faltas);
        }

        if (filled($dados['defeito_quando'] ?? null)) {
            $dados['defeito_quando'] = Carbon::parse($dados['defeito_quando'])->startOfMinute();
        }

        return $dados;
    }

    /**
     * O que quem NÃO triaga não decide — na criação e na edição.
     *
     * Na criação (`$tarefa` null), a tarefa nasce sem dono e esperando triagem:
     * "Média" por omissão seria uma classificação que ninguém fez, e é o
     * motivo de "A definir" existir (AC-194). Na edição, o que a triagem
     * decidiu fica como está.
     *
     * O prazo é a exceção: o RESPONSÁVEL combina a própria data de entrega,
     * então o dele passa. Só cai o de quem não é nenhum dos dois — e na
     * criação nunca é ele, porque a tarefa nasce sem dono. Mesma régua da
     * Agenda (`prazoPodeSerDefinidoPor`).
     *
     * A coluna declarada também cai: Backlog é "priorizado e com dono", e quem
     * não triaga não pode dar nenhum dos dois. Deixar passar criaria no
     * Backlog um card sem responsável, que é a contradição que a coluna Aberta
     * existe para não ter.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function semTriagemDeQuemNaoTriaga(array $dados, User $usuario, ?Tarefa $tarefa = null): array
    {
        if ($usuario->podeTriarTarefas()) {
            return $dados;
        }

        $dados['prioridade'] = $tarefa?->prioridade ?? 'nao_definida';
        $dados['responsavel_id'] = $tarefa?->responsavel_id;

        if (! ($tarefa?->prazoPodeSerDefinidoPor($usuario) ?? false)) {
            $dados['prazo'] = $tarefa?->prazo;
        }

        unset($dados['status']);

        return $dados;
    }

    /**
     * Publica um comentário comum e avisa quem a tarefa envolve (#312).
     *
     * Envolve = o responsável, quem foi apontado para validar a passagem atual
     * e quem abriu — menos quem comentou (`avisar` já cala o autor, e com isso
     * o agente que comenta em nome de alguém não avisa a própria pessoa). O som
     * é só para os dois de quem o trabalho DEPENDE: quem abriu acompanha a
     * conversa pelo sino, mas não é interrompido por ela.
     *
     * Mora aqui, e não num evento do modelo, de propósito: pergunta e resposta
     * já têm aviso próprio (avisar de novo seria eco), e o arquivamento e o
     * vigia de logs escrevem comentário de registro, que não é conversa. Quem
     * publica conversa — a rota, o salvar com comentário e o MCP — passa por
     * aqui. A trava de reenvio fica em quem chama, como já estava.
     */
    public function comentar(Tarefa $tarefa, string $corpo, User $autor): TarefaComentario
    {
        $comentario = $tarefa->comentarios()->create([
            'autor_id' => $autor->id,
            'corpo' => $corpo,
        ]);

        $validadorId = $tarefa->apontadoDestaPassagem()?->id;

        $envolvidos = collect([$tarefa->responsavel_id, $validadorId, $tarefa->criado_por_id])
            ->filter()
            ->unique();

        foreach ($envolvidos as $destinatarioId) {
            Notificacao::avisar((int) $destinatarioId, $autor->id, [
                'tipo' => 'comentario',
                'nivel' => 'marca',
                'sonora' => in_array((int) $destinatarioId, [(int) $tarefa->responsavel_id, (int) $validadorId], true),
                'icone' => 'chat',
                'titulo' => $autor->name.' comentou em «'.$tarefa->titulo.'»',
                'meta' => Str::limit(preg_replace('/\s+/', ' ', $corpo), 90),
                'rota' => route('tarefas.index'),
                'tarefa_id' => $tarefa->id,
            ]);
        }

        return $comentario;
    }

    /**
     * Avisa quem ganhou a tarefa — direcionada, o responsável; sem dono, quem
     * triaga, para que a fila não fique esperando alguém abrir o quadro.
     */
    public function avisarNascimento(Tarefa $tarefa, User $autor): void
    {
        if ($tarefa->responsavel_id !== null) {
            $this->avisarDirecionamento($tarefa, $autor);

            return;
        }

        foreach (User::idsDeQuemTriaTarefas() as $destinatarioId) {
            Notificacao::avisar($destinatarioId, $autor->id, [
                'tipo' => 'triagem',
                'nivel' => 'marca',
                'icone' => 'clipboard',
                'titulo' => '«'.$tarefa->titulo.'» aguarda triagem',
                'meta' => 'Aberta por '.$autor->name.' · sem responsável',
                'rota' => route('tarefas.index'),
                'tarefa_id' => $tarefa->id,
            ]);
        }
    }

    /**
     * Ganhar a tarefa é o mesmo fato na criação e na edição — e o aviso é o
     * mesmo. A meta diz a etapa em que o card de fato ficou, por isso quem
     * chama avisa DEPOIS de qualquer movimento que o direcionamento provoque.
     */
    public function avisarDirecionamento(Tarefa $tarefa, User $autor): void
    {
        Notificacao::avisar($tarefa->responsavel_id, $autor->id, [
            'tipo' => 'direcionamento',
            'nivel' => 'atencao',
            'icone' => 'user-plus',
            'titulo' => '«'.$tarefa->titulo.'» foi direcionada a você',
            'meta' => 'Em '.Tarefa::rotuloDaEtapa($tarefa->status).' · por '.$autor->name,
            'rota' => route('tarefas.index'),
            'tarefa_id' => $tarefa->id,
        ]);
    }

    /**
     * O mesmo envio, gravado há instantes — o clique duplo e o "voltar" que
     * reenvia o formulário.
     *
     * @param  array<string, mixed>  $dados
     */
    private function reenvioDaMesmaTarefa(array $dados): bool
    {
        return Tarefa::where($dados)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();
    }
}
