<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\ProvisionadorAlfaGymService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class RevendaController extends Controller
{
    public function index(Request $request)
    {
        $busca = trim((string) $request->query('q', ''));
        $status = $request->query('status', '');
        $ordem = $request->query('ordem', 'mrr');

        $cadastradas = Revenda::when(auth()->user()->temEscopoDeRevenda(), fn ($q) => $q->where('id', auth()->user()->revenda_id))->count();

        $revendas = Revenda::query()
            ->when(auth()->user()->temEscopoDeRevenda(), fn ($q) => $q->where('id', auth()->user()->revenda_id))
            ->when($busca !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('nome', 'like', "%{$busca}%")
                ->orWhere('cnpj', 'like', "%{$busca}%")))
            ->when($status === 'ativo', fn ($q) => $q->where('ativo', true))
            ->when($status === 'inativo', fn ($q) => $q->where('ativo', false))
            ->orderBy('nome')
            ->get();

        $baseTotal = max(Cliente::where('ativo', true)->count(), 1);
        $mrrTotal = $this->mrrDaCompetencia(now()->format('Y-m'));

        $sistemaAlfaGym = Sistema::query()->where('slug', 'alfagym')->first();

        $linhas = $revendas->map(fn (Revenda $revenda) => $this->linha($revenda, $baseTotal, $sistemaAlfaGym));

        $linhas = (match ($ordem) {
            'nome' => $linhas->sortBy('revenda.nome'),
            'clientes' => $linhas->sortByDesc('clientes'),
            default => $linhas->sortByDesc('mrr'),
        })->values();

        return view('revendas.index', [
            'linhas' => $linhas,
            'filtros' => ['q' => $busca, 'status' => $status, 'ordem' => $ordem],
            'cadastradas' => $cadastradas,
            'totais' => [
                'clientes' => (int) $linhas->sum('clientes'),
                'mrr' => (float) $linhas->sum('mrr'),
                'sistemas' => $linhas->flatMap(fn ($l) => $l['sistemas'])->unique()->count(),
            ],
            'kpis' => $this->kpis($revendas, $linhas, $baseTotal, $mrrTotal, $cadastradas),
            'sistemaAlfaGym' => $sistemaAlfaGym,
        ]);
    }

    /**
     * Uma linha da tabela: tudo que a tela precisa saber sobre a revenda,
     * calculado uma vez só.
     *
     * @return array<string, mixed>
     */
    private function linha(Revenda $revenda, int $baseTotal, ?Sistema $sistemaAlfaGym = null): array
    {
        $clientesAtivos = Cliente::where('revenda_id', $revenda->id)->where('ativo', true);

        $sistemas = Cliente::where('clientes.revenda_id', $revenda->id)
            ->where('clientes.ativo', true)
            ->join('cliente_sistema', 'cliente_sistema.cliente_id', '=', 'clientes.id')
            ->where('cliente_sistema.ativo', true)
            ->join('sistemas', 'sistemas.id', '=', 'cliente_sistema.sistema_id')
            ->distinct()
            ->pluck('sistemas.nome');

        $mrr = $this->mrrDaRevenda($revenda->id, now()->format('Y-m'));
        $anterior = $this->mrrDaRevenda($revenda->id, now()->subMonth()->format('Y-m'));

        // A pendência que interessa aqui é a que trava o dinheiro: cobrança
        // dessa revenda vencida e ainda não paga.
        $emAtraso = Cobranca::where('revenda_id', $revenda->id)
            ->where('status', 'pendente')
            ->whereDate('data_vencimento', '<', now()->startOfDay())
            ->count();

        $clientes = $clientesAtivos->count();

        return [
            'revenda' => $revenda,
            'clientes' => $clientes,
            'share' => $clientes / $baseTotal,
            'mrr' => $mrr,
            'delta' => $this->variacao($mrr, $anterior),
            'sistemas' => $sistemas->all(),
            'emAtraso' => $emAtraso,
            'provisionada' => $sistemaAlfaGym !== null && $revenda->idExternoNoSistema($sistemaAlfaGym) !== null,
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $linhas */
    private function kpis(Collection $revendas, Collection $linhas, int $baseTotal, float $mrrTotal, int $cadastradas): array
    {
        $ativas = $revendas->where('ativo', true)->count();
        $clientesViaRevenda = (int) $linhas->sum('clientes');
        $mrrRevenda = (float) $linhas->sum('mrr');

        return [
            'ativas' => ['valor' => $ativas, 'nota' => 'de '.$cadastradas.' cadastradas'],
            'clientes' => [
                'valor' => $clientesViaRevenda,
                'nota' => round(($clientesViaRevenda / $baseTotal) * 100).'% da base',
            ],
            'mrr' => [
                'valor' => $mrrRevenda,
                'nota' => $mrrTotal > 0
                    ? round(($mrrRevenda / $mrrTotal) * 100).'% do MRR total'
                    : 'sem MRR na competência',
            ],
            'ticket' => [
                'valor' => $ativas > 0 ? $mrrRevenda / $ativas : 0.0,
                'nota' => 'por revenda ativa',
            ],
        ];
    }

    private function mrrDaRevenda(int $revendaId, string $competencia): float
    {
        return (float) Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])
            ->where('competencia', $competencia)
            ->where('status', '!=', 'cancelado')
            ->where('revenda_id', $revendaId)
            ->sum('valor');
    }

    private function mrrDaCompetencia(string $competencia): float
    {
        return (float) Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])
            ->where('competencia', $competencia)
            ->where('status', '!=', 'cancelado')
            ->sum('valor');
    }

    private function variacao(float $atual, float $anterior): ?string
    {
        if ($anterior <= 0) {
            return null;
        }

        $percentual = (($atual - $anterior) / $anterior) * 100;

        return ($percentual >= 0 ? '+' : '−').number_format(abs($percentual), 1, ',', '.').'%';
    }

    public function create()
    {
        abort_if(auth()->user()->temEscopoDeRevenda(), 403, 'Sua revenda é provisionada pela matriz.');

        return view('revendas.create');
    }

    public function store(Request $request)
    {
        abort_if(auth()->user()->temEscopoDeRevenda(), 403, 'Sua revenda é provisionada pela matriz.');

        $data = $this->validated($request);
        $data['ativo'] = $request->boolean('ativo');

        Revenda::create($data);

        return redirect()->route('revendas.index')->with('status', 'Revenda cadastrada com sucesso.');
    }

    public function edit(Revenda $revenda)
    {
        $this->autorizarAcesso($revenda);

        return view('revendas.edit', compact('revenda'));
    }

    public function update(Request $request, Revenda $revenda)
    {
        $this->autorizarAcesso($revenda);

        $data = $this->validated($request, $revenda->id);
        $data['ativo'] = $request->boolean('ativo');

        $revenda->update($data);

        return redirect()->route('revendas.index')->with('status', 'Revenda atualizada com sucesso.');
    }

    public function destroy(Revenda $revenda)
    {
        $this->autorizarAcesso($revenda);

        $revenda->delete();

        return redirect()->route('revendas.index')->with('status', 'Revenda removida.');
    }

    /**
     * Provisiona a revenda no AlfaGym: cria lá a revenda + usuário ADMIN_REVENDA
     * e ancora a revenda local no sistema, para o sincronizador reconhecê-la.
     */
    public function provisionar(Request $request, Revenda $revenda)
    {
        $this->autorizarAcesso($revenda);

        $sistema = Sistema::query()->where('slug', 'alfagym')->first();

        if (! $sistema) {
            return back()->with('erro', 'Sistema AlfaGym não está cadastrado na matriz.');
        }

        $data = $request->validate([
            'nome_admin' => 'required|string|max:100',
            'email_admin' => 'required|email|max:255',
            'senha_admin' => 'required|string|min:8',
        ]);

        try {
            (new ProvisionadorAlfaGymService($sistema))->provisionar($revenda, [
                'nome' => $data['nome_admin'],
                'email' => $data['email_admin'],
                'senha' => $data['senha_admin'],
            ]);
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('status', "Revenda {$revenda->nome} provisionada no AlfaGym.");
    }

    /**
     * Usuário de revenda só mexe na própria revenda — e também não cadastra
     * outra revenda (quem tem escopo usa o portfólio que a matriz provisionou).
     */
    private function autorizarAcesso(Revenda $revenda): void
    {
        $user = auth()->user();

        if ($user->temEscopoDeRevenda() && $revenda->id !== $user->revenda_id) {
            abort(403, 'Você só pode acessar a própria revenda.');
        }
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'nome' => 'required|string|max:255',
            'cnpj' => 'nullable|string|max:18|unique:revendas,cnpj,'.($ignoreId ?? 'NULL').',id',
            'contato_nome' => 'nullable|string|max:255',
            'contato_email' => 'nullable|email|max:255',
            'contato_telefone' => 'nullable|string|max:30',
        ]);
    }
}
