<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Permissao;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * A tela do rastro.
 *
 * Só lê. Não há `store`, `update` nem `destroy` aqui, e é de propósito: o
 * modelo recusa alteração, e um endpoint de escrita seria a única porta capaz
 * de fazer a auditoria mentir.
 */
class AuditoriaController extends Controller
{
    public function __construct()
    {
        // Auditoria é gestão da matriz. A revenda tem o `revenda_id` limitando
        // o que ela enxerga em toda tela — e aqui não haveria como aplicá-lo: a
        // linha guarda o alvo como tipo + id, sem a revenda dele, e descobrir a
        // qual carteira cada registro pertence exigiria consultar a tabela de
        // origem, que pode não existir mais. Deixar entrar com um filtro que
        // não filtra seria pior que a porta fechada.
        $this->bloquearVisaoDaMatriz();
    }

    public function index(Request $request): View
    {
        $filtros = [
            'busca' => trim((string) $request->query('busca', '')),
            'usuario' => (string) $request->query('usuario', ''),
            'recurso' => (string) $request->query('recurso', ''),
            'acao' => (string) $request->query('acao', ''),
            'de' => (string) $request->query('de', ''),
            'ate' => (string) $request->query('ate', ''),
        ];

        $registros = Auditoria::with('usuario')
            ->when($filtros['busca'] !== '', function ($query) use ($filtros) {
                $termo = '%'.$filtros['busca'].'%';

                // Busca no NOME DO ATOR e na descrição do alvo — os dois campos
                // que guardam texto do momento do fato. Procurar pelo nome atual
                // da conta (juntando `users`) devolveria menos: quem foi
                // renomeado ou excluído sumiria da própria busca.
                $query->where(fn ($q) => $q
                    ->where('usuario_nome', 'like', $termo)
                    ->orWhere('descricao', 'like', $termo));
            })
            ->when($filtros['usuario'] !== '', fn ($query) => $query->where('user_id', $filtros['usuario']))
            ->when($filtros['recurso'] !== '', fn ($query) => $query->where('recurso', $filtros['recurso']))
            ->when($filtros['acao'] !== '', fn ($query) => $query->where('acao', $filtros['acao']))
            ->when($filtros['de'] !== '', fn ($query) => $query->whereDate('created_at', '>=', $filtros['de']))
            ->when($filtros['ate'] !== '', fn ($query) => $query->whereDate('created_at', '<=', $filtros['ate']))
            // Desempate por `id`, e não só pela data: a geração do faturamento
            // grava dezenas de linhas no MESMO segundo, e sem o segundo critério
            // a ordem delas muda a cada carregamento — a mesma linha aparecendo
            // em duas páginas e outra em nenhuma.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return view('auditoria.index', [
            'registros' => $registros,
            'filtros' => $filtros,
            'kpis' => $this->kpis(),
            'recursos' => $this->recursos(),
            'acoes' => $this->acoes(),
            'usuarios' => $this->autoresRegistrados(),
        ]);
    }

    /**
     * Os números falam da BASE, e não do recorte — mesmo contrato do resto do
     * painel. Aqui isso importa mais que nas outras telas: quem filtra por um
     * usuário quer continuar vendo que houve 40 recusas de senha na semana,
     * justamente para desconfiar do recorte que acabou de escolher.
     *
     * @return array<string, int>
     */
    private function kpis(): array
    {
        return [
            'hoje' => Auditoria::whereDate('created_at', today())->count(),
            'entradas' => Auditoria::where('acao', 'entrou')->whereDate('created_at', today())->count(),
            'recusas' => Auditoria::where('acao', 'recusado')
                ->where('created_at', '>=', now()->subDays(7))->count(),
            'exclusoes' => Auditoria::where('acao', 'excluiu')
                ->where('created_at', '>=', now()->subDays(30))->count(),
        ];
    }

    /**
     * Os recursos oferecidos no filtro, com o rótulo humano que a grade de
     * permissões já usa.
     *
     * Sai da tabela `permissoes` porque é ela quem define o vocabulário — uma
     * lista escrita aqui à mão passaria a divergir no dia em que um recurso
     * novo nascesse, e o filtro esconderia linhas que existem.
     *
     * @return array<string, string>
     */
    private function recursos(): array
    {
        $recursos = Permissao::orderBy('descricao')->pluck('descricao', 'recurso')->all();

        // `acesso` não é recurso de permissão e por isso não vem da tabela; ele
        // encabeça a lista porque é o recorte mais procurado desta tela.
        return ['acesso' => 'Acesso ao painel'] + $recursos;
    }

    /**
     * As ações oferecidas no filtro — só as que EXISTEM no banco.
     *
     * Uma lista fixa ofereceria "Provisionou" a quem nunca provisionou nada, e
     * filtrar por ela devolveria a tela vazia, que se lê como defeito. O custo
     * é um `distinct` numa coluna indexada de lado nenhum, e ele é aceitável
     * porque o número de ações distintas é de uma dúzia, não do tamanho da
     * tabela.
     *
     * @return array<string, string>
     */
    private function acoes(): array
    {
        return Auditoria::query()
            ->distinct()
            ->orderBy('acao')
            ->pluck('acao')
            ->mapWithKeys(fn (string $acao) => [$acao => Auditoria::rotuloDe($acao)])
            ->all();
    }

    /**
     * Quem aparece no rastro — e não toda conta do painel.
     *
     * `withTrashed` porque a conta excluída continua sendo autora do que fez, e
     * é a que mais interessa procurar. Sem ela, o filtro perderia exatamente o
     * nome que motivou a visita à tela.
     *
     * @return Collection<int, User>
     */
    private function autoresRegistrados()
    {
        $ids = Auditoria::whereNotNull('user_id')->distinct()->pluck('user_id');

        return User::withTrashed()->whereIn('id', $ids)->orderBy('name')->get();
    }
}
