<?php

namespace App\Mcp\Tools;

use App\Models\Compromisso;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaItem;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * O que toda ferramenta do servidor MCP tem em comum: agir em nome de alguém,
 * pedir licença antes, e falar a língua do quadro.
 *
 * O servidor roda em nome de UMA pessoa (ver `AlfaMatrizServer::boot`), e cada
 * ferramenta espelha a rota da tela que faz a mesma coisa — inclusive a
 * permissão. É `$permissao` que diz qual, com o mesmo par recurso/ação do
 * middleware `permissao:` da rota, para o agente não conseguir por aqui o que
 * a pessoa não conseguiria pela tela.
 *
 * As recusas do domínio chegam como `RuntimeException` com a frase pronta — a
 * mesma que a tela mostra no flash — e viram erro de ferramenta em vez de
 * exceção do servidor: o agente precisa LER o motivo ("Alguém já moveu esta
 * tarefa", "É preciso escrever a pergunta") para agir sobre ele.
 */
abstract class Ferramenta extends Tool
{
    /**
     * O recurso e a ação que a rota equivalente exige.
     *
     * @var array{0: string, 1: string}
     */
    protected array $permissao = ['tarefas', 'ler'];

    public function handle(Request $request): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof User) {
            return Response::error('Nenhum usuário está identificado neste servidor.');
        }

        // Relida a cada chamada, como a tela relê a cada requisição
        // (`PermissoesDaRequisicao`): o processo do stdio vive horas, e uma
        // permissão retirada no meio do caminho não pode seguir valendo até
        // alguém reiniciar o agente.
        $usuario->esquecerPermissoes();

        [$recurso, $acao] = $this->permissao;

        if (! $usuario->canPermissao($recurso, $acao)) {
            return Response::error('Você não tem permissão para executar esta ação ('.$recurso.', '.$acao.').');
        }

        try {
            return $this->executar($request, $usuario);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }
    }

    abstract protected function executar(Request $request, User $usuario): Response;

    /** A tarefa pelo código como o quadro a chama — "#128" ou "128". */
    protected function tarefaPeloCodigo(int|string $codigo): ?Tarefa
    {
        $id = (int) ltrim(trim((string) $codigo), '#');

        return $id > 0 ? Tarefa::find($id) : null;
    }

    /** O item de checklist pelo número que `ver_tarefa` mostra. */
    protected function itemPeloNumero(int|string $numero): ?TarefaItem
    {
        $id = (int) $numero;

        return $id > 0 ? TarefaItem::find($id) : null;
    }

    /** O item como o checklist o escreve — com o número, que é como o agente o aponta depois. */
    protected function linhaDoItem(TarefaItem $item): string
    {
        return ($item->feito ? '- [x] ' : '- [ ] ').'item '.$item->id.': '.$item->texto;
    }

    /** O compromisso pelo número como a agenda o mostra — "#7" ou "7". */
    protected function compromissoPeloNumero(int|string $numero): ?Compromisso
    {
        $id = (int) ltrim(trim((string) $numero), '#');

        return $id > 0 ? Compromisso::with(['participantes', 'criadoPor', 'tarefa'])->find($id) : null;
    }

    /**
     * O compromisso por extenso — o mesmo texto para quem abre, marca e altera,
     * para o agente conferir o que gravou lendo sempre a mesma ficha.
     */
    protected function fichaDoCompromisso(Compromisso $compromisso, User $usuario): string
    {
        $inicio = $compromisso->comecaEm();
        $termino = $compromisso->terminaEm();

        return implode("\n", array_filter([
            'Compromisso #'.$compromisso->id.': '.$compromisso->titulo,
            'Quando: '.$inicio->format('d/m/Y H:i').' até '
                .($compromisso->viraODia() ? $termino->format('d/m/Y H:i').' (vira o dia)' : $termino->format('H:i')),
            'Categoria: '.(Compromisso::CATEGORIAS[$compromisso->categoria]['rotulo'] ?? $compromisso->categoria),
            'Participantes: '.($compromisso->participantes->pluck('name')->implode(', ') ?: 'ninguém'),
            'Marcado por: '.($compromisso->criadoPor?->name ?? '?'),
            $compromisso->tarefa ? 'Tarefa vinculada: '.$compromisso->tarefa->codigo().' · '.$compromisso->tarefa->titulo : null,
            filled($compromisso->descricao) ? 'Pauta: '.$compromisso->descricao : null,
            $compromisso->podeSerEditadoPor($usuario)
                ? 'Você pode alterar ou desmarcar este compromisso.'
                : 'Você não pode alterá-lo: só quem marcou, ou quem faz triagem.',
        ]));
    }

    /**
     * Uma pessoa do time pelo nome ou e-mail — "eu" é quem comanda o agente.
     *
     * Devolve a FRASE em vez da pessoa quando não há como decidir: nome que
     * ninguém tem, ou trecho que bate com duas. Escolher uma delas em silêncio
     * mandaria a tarefa para a pessoa errada, que é pior do que perguntar.
     */
    protected function pessoa(string $texto, User $usuario): User|string
    {
        $texto = trim($texto);

        if (in_array(mb_strtolower($texto), ['eu', 'mim', 'me'], true)) {
            return $usuario;
        }

        $candidatos = User::query()
            ->where('ativo', true)
            ->whereNull('revenda_id')
            ->where(fn ($consulta) => $consulta
                ->where('name', 'like', '%'.$texto.'%')
                ->orWhere('email', $texto))
            ->orderBy('name')
            ->get();

        $exato = $candidatos->first(fn (User $candidato) => mb_strtolower($candidato->name) === mb_strtolower($texto)
            || mb_strtolower($candidato->email) === mb_strtolower($texto));

        if ($exato) {
            return $exato;
        }

        if ($candidatos->count() === 1) {
            return $candidatos->first();
        }

        if ($candidatos->isEmpty()) {
            return 'Não encontrei ninguém chamado "'.$texto.'". Use `referencias` para ver os nomes.';
        }

        return 'Há mais de uma pessoa com "'.$texto.'": '.$candidatos->pluck('name')->implode(', ').'. Diga o nome completo.';
    }

    /** Um sistema pelo nome ou slug, com a mesma régua de `pessoa`. */
    protected function sistema(string $texto): Sistema|string
    {
        $texto = trim($texto);

        $candidatos = Sistema::query()
            ->where('ativo', true)
            ->where(fn ($consulta) => $consulta
                ->where('nome', 'like', '%'.$texto.'%')
                ->orWhere('slug', $texto))
            ->orderBy('nome')
            ->get();

        $exato = $candidatos->first(fn (Sistema $candidato) => mb_strtolower($candidato->nome) === mb_strtolower($texto)
            || $candidato->slug === $texto);

        if ($exato) {
            return $exato;
        }

        if ($candidatos->count() === 1) {
            return $candidatos->first();
        }

        if ($candidatos->isEmpty()) {
            return 'Não encontrei o sistema "'.$texto.'". Use `referencias` para ver os nomes.';
        }

        return 'Há mais de um sistema com "'.$texto.'": '.$candidatos->pluck('nome')->implode(', ').'. Diga o nome completo.';
    }

    /**
     * A linha que resume a tarefa — a mesma em toda ferramenta, para o agente
     * ler o quadro sempre do mesmo jeito.
     */
    protected function linhaDaTarefa(Tarefa $tarefa): string
    {
        $cabeca = implode(' · ', [
            $tarefa->codigo(),
            Tarefa::rotuloDaEtapa($tarefa->status),
            Tarefa::PRIORIDADES[$tarefa->prioridade] ?? $tarefa->prioridade,
        ]);

        $meta = array_filter([
            $tarefa->responsavel ? 'resp. '.$tarefa->responsavel->name : 'sem responsável',
            $tarefa->sistema?->nome,
            $tarefa->prazo ? 'prazo '.$tarefa->prazo->format('d/m/Y') : null,
        ]);

        $marcas = array_filter([
            $tarefa->estaArquivada() ? 'ARQUIVADA: '.$tarefa->rotuloDoArquivamento() : null,
            $tarefa->estaBloqueada() ? 'BLOQUEADA' : null,
            $tarefa->temRetorno() ? 'RETORNO' : null,
            $tarefa->temPergunta() ? 'PERGUNTA para '.($tarefa->perguntaPara?->name ?? '?') : null,
        ]);

        return $cabeca.' · '.$tarefa->titulo.' ('.implode(' · ', $meta).')'
            .($marcas ? ' ['.implode(', ', $marcas).']' : '');
    }
}
