<?php

namespace App\Mcp\Tools;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ListarTarefas extends Ferramenta
{
    protected string $name = 'listar_tarefas';

    protected string $title = 'Listar tarefas';

    protected string $description = 'Lista tarefas do quadro, uma por linha, com código, etapa, prioridade, responsável, sistema, prazo e marcas (bloqueada, retorno, pergunta) e, em Em staging e Em produção, o veredito desta etapa (aprovado ou reprovado e por quem, ou "sem veredito"). Por padrão só as em aberto, mais recentes primeiro.';

    protected array $permissao = ['tarefas', 'ler'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'situacao' => $schema->string()
                ->enum(array_merge(['abertas', 'todas', 'arquivadas', 'para_arquivar'], array_keys(Tarefa::STATUS)))
                ->description('"abertas" (padrão) é o quadro: nem encerradas nem arquivadas; "todas" inclui encerradas e arquivadas; "arquivadas" é só o arquivo; "para_arquivar" são as paradas há muito tempo (sugestão para a triagem); ou a chave de uma etapa (fora do arquivo).'),
            'responsavel' => $schema->string()
                ->description('Nome de uma pessoa, "eu", ou "ninguém" para as que estão sem responsável.'),
            'texto' => $schema->string()
                ->description('Trecho do título ou do resumo.'),
            'limite' => $schema->integer()->min(1)->max(100)
                ->description('Quantas no máximo (padrão 30).'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'situacao' => 'nullable|in:'.implode(',', array_merge(['abertas', 'todas', 'arquivadas', 'para_arquivar'], array_keys(Tarefa::STATUS))),
            'responsavel' => 'nullable|string|max:255',
            'texto' => 'nullable|string|max:255',
            'limite' => 'nullable|integer|min:1|max:100',
        ]);

        $consulta = Tarefa::query()->with(['responsavel', 'sistema', 'perguntaPara']);

        $situacao = $dados['situacao'] ?? 'abertas';

        match ($situacao) {
            'abertas' => $consulta->whereNotIn('status', Tarefa::STATUS_TERMINAIS)->foraDoArquivo(),
            'todas' => null,
            'arquivadas' => $consulta->arquivadas(),
            'para_arquivar' => $consulta->candidatasAoArquivo(),
            default => $consulta->where('status', $situacao)->foraDoArquivo(),
        };

        if (filled($dados['responsavel'] ?? null)) {
            if (in_array(mb_strtolower(trim($dados['responsavel'])), ['ninguém', 'ninguem', 'sem', 'sem responsável'], true)) {
                $consulta->whereNull('responsavel_id');
            } else {
                $pessoa = $this->pessoa($dados['responsavel'], $usuario);

                if (is_string($pessoa)) {
                    return Response::error($pessoa);
                }

                $consulta->where('responsavel_id', $pessoa->id);
            }
        }

        if (filled($dados['texto'] ?? null)) {
            $texto = trim($dados['texto']);

            $consulta->where(fn ($sub) => $sub
                ->where('titulo', 'like', '%'.$texto.'%')
                ->orWhere('resumo', 'like', '%'.$texto.'%'));
        }

        // Corta pelas mais recentes no banco e ordena por etapa em memória: a
        // ordem das etapas é a do mapa `STATUS`, que o SQL não conhece, e o
        // corte precisa vir antes para o limite não descartar uma coluna
        // inteira só porque ela vem depois no mapa.
        $tarefas = $consulta
            ->latest('updated_at')
            ->limit($dados['limite'] ?? 30)
            ->get()
            ->sortBy(fn (Tarefa $tarefa) => array_search($tarefa->status, array_keys(Tarefa::STATUS), true))
            ->values();

        if ($tarefas->isEmpty()) {
            return Response::text('Nenhuma tarefa encontrada com esse recorte.');
        }

        // O veredito (T-629) só existe em Em staging e Em produção: carrega
        // eventos e relatórios só dessas, de uma vez, e `vereditoDaEtapa`
        // responde em memória — sem duas consultas por linha.
        $tarefas->filter(fn (Tarefa $tarefa) => in_array($tarefa->status, Tarefa::PORTOES_DE_VEREDITO, true))
            ->load(['eventos', 'relatoriosTeste.autor', 'relatoriosTeste.evento']);

        return Response::text(
            $tarefas->count().' tarefa(s):'."\n"
            .$tarefas->map(fn (Tarefa $tarefa) => '- '.$this->linhaDaTarefa($tarefa)
                .$this->marcaDoVeredito($tarefa)
                .($tarefa->tarefa_pai_id ? ' — subtarefa de #'.$tarefa->tarefa_pai_id : ''))->implode("\n")
        );
    }

    /**
     * O selo do card na linha: "[Staging aprovado por Fulano em 19/11]", ou
     * "[sem veredito]" no portão ainda sem carimbo — a falta também é
     * informação, e é a que mais engana quem não a vê. Fica só aqui, e não em
     * `linhaDaTarefa`, porque aquela linha volta depois de mover e editar,
     * quando o carimbo já não é o assunto.
     */
    private function marcaDoVeredito(Tarefa $tarefa): string
    {
        if (! $tarefa->passaPelosPortoes() || ! in_array($tarefa->status, Tarefa::PORTOES_DE_VEREDITO, true)) {
            return '';
        }

        $veredito = $tarefa->vereditoDaEtapa();

        return $veredito
            ? ' ['.$veredito->rotulo().' por '.($veredito->autor?->name ?? '?').' em '.$veredito->created_at->format('d/m').']'
            : ' [sem veredito]';
    }
}
