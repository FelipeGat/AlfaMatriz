<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use App\Services\ProvisionadorRevendaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RevendaController extends Controller
{
    public function index(Request $request)
    {
        $aba = $request->query('aba', 'revendas');

        if ($aba === 'clientes') {
            return $this->indexClientes($request);
        }

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

        // Quem provisiona revenda pela Matriz, seja qual for o produto. Na
        // Fase 1 do AlfaControl só o AlfaGym declara essa capacidade.
        $sistemaProvisionador = Sistema::comCapacidade('provisiona_revenda')->first();

        $linhas = $revendas->map(fn (Revenda $revenda) => $this->linha($revenda, $baseTotal, $sistemaProvisionador));

        $linhas = (match ($ordem) {
            'nome' => $linhas->sortBy('revenda.nome'),
            'clientes' => $linhas->sortByDesc('clientes'),
            default => $linhas->sortByDesc('mrr'),
        })->values();

        // Totais, KPIs e escala da barra saem da lista INTEIRA, antes do corte
        // de página: a barra de base compara revenda com a maior do recorte, e
        // se o divisor fosse o maior da página a mesma revenda mudaria de
        // tamanho conforme a página em que aparecesse.
        $totais = [
            'clientes' => (int) $linhas->sum('clientes'),
            'mrr' => (float) $linhas->sum('mrr'),
            'sistemas' => $linhas->flatMap(fn ($l) => $l['sistemas'])->unique()->count(),
        ];
        $kpis = $this->kpis($revendas, $linhas, $baseTotal, $mrrTotal, $cadastradas);
        $maiorBase = max((int) $linhas->max('clientes'), 1);

        return view('revendas.index', [
            'aba' => 'revendas',
            'clientesCadastrados' => $this->clientesCadastrados(),
            'linhas' => $this->paginarColecao($linhas),
            'filtros' => ['q' => $busca, 'status' => $status, 'ordem' => $ordem],
            'cadastradas' => $cadastradas,
            'maiorBase' => $maiorBase,
            'totais' => $totais,
            'kpis' => $kpis,
            'sistemaProvisionador' => $sistemaProvisionador,
        ]);
    }

    /**
     * A aba "Clientes" da tela de Revendas: reusa a listagem de clientes da
     * tela de Clientes, mas dentro do contexto de revendas (admin vê todas,
     * usuário de revenda vê só a própria).
     *
     * @return View
     */
    public function indexClientes(Request $request)
    {
        $dados = app(ClienteController::class)->dadosDaLista($request);
        $dados['aba'] = 'clientes';

        $cadastradas = Revenda::when(auth()->user()->temEscopoDeRevenda(), fn ($q) => $q->where('id', auth()->user()->revenda_id))->count();

        return view('revendas.index', [
            'aba' => 'clientes',
            // O modal de cadastro vive nesta tela agora: precisa da lista de
            // revendas ATIVAS (não a do filtro) e dos sistemas.
            'revendasParaCadastro' => $dados['revendasParaCadastro'],
            'clientesCadastrados' => $dados['clientes']->total(),
            'linhas' => collect(),
            'filtros' => ['q' => '', 'status' => '', 'ordem' => 'mrr', 'aba' => 'clientes'],
            'cadastradas' => $cadastradas,
            // A tabela de revendas não é renderizada nesta aba, mas a view é a
            // MESMA: a régua da barra segue o resto dos placeholders abaixo,
            // para nenhum ajuste futuro no template esbarrar em variável que
            // só existe na outra aba.
            'maiorBase' => 1,
            'totais' => ['clientes' => 0, 'mrr' => 0.0, 'sistemas' => 0],
            'kpis' => [
                'ativas' => ['valor' => 0, 'nota' => ''],
                'clientes' => ['valor' => 0, 'nota' => ''],
                'mrr' => ['valor' => 0.0, 'nota' => ''],
                'ticket' => ['valor' => 0.0, 'nota' => ''],
            ],
            'sistemaProvisionador' => null,
            'clientesView' => $dados,
        ]);
    }

    /**
     * Quantos clientes o usuário enxerga — o número que a aba mostra ao lado do
     * rótulo. É o sinal de que ali tem conteúdo, não decoração.
     */
    private function clientesCadastrados(): int
    {
        return Cliente::when(auth()->user()->temEscopoDeRevenda(),
            fn ($q) => $q->where('revenda_id', auth()->user()->revenda_id))->count();
    }

    /**
     * Uma linha da tabela: tudo que a tela precisa saber sobre a revenda,
     * calculado uma vez só.
     *
     * @return array<string, mixed>
     */
    private function linha(Revenda $revenda, int $baseTotal, ?Sistema $sistemaProvisionador = null): array
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
            'provisionada' => $sistemaProvisionador !== null && $revenda->idExternoNoSistema($sistemaProvisionador) !== null,
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

        $sistema = Sistema::comCapacidade('provisiona_revenda')->first();

        if (! $sistema) {
            return back()->with('erro', 'Nenhum sistema da matriz provisiona revenda.');
        }

        $data = $request->validate([
            'nome_admin' => 'required|string|max:100',
            'email_admin' => 'required|email|max:255',
            'senha_admin' => 'required|string|min:8',
        ]);

        try {
            // Provisionar no gym e criar o acesso local andam juntos: um acesso
            // na Matriz apontando para revenda que o gym recusou seria um
            // usuário que entra e não consegue cadastrar nada.
            DB::transaction(function () use ($sistema, $revenda, $data) {
                (new ProvisionadorRevendaService($sistema))->provisionar($revenda, [
                    'nome' => $data['nome_admin'],
                    'email' => $data['email_admin'],
                    'senha' => $data['senha_admin'],
                ]);

                $this->criarAcessoDaRevenda($revenda, $data);
            });
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('status', "Revenda {$revenda->nome} provisionada no AlfaGym e com acesso ao painel.");
    }

    /**
     * O acesso da revenda ao painel da Matriz, com o mesmo e-mail e senha do
     * administrador que acabou de ser criado no AlfaGym — uma credencial só
     * para os dois painéis enquanto ambos operam.
     *
     * Reaproveita o usuário quando o e-mail já existe (revenda reprovisionada,
     * ou acesso criado antes pelo comando de migração): a senha de quem já
     * entra não é redefinida por um provisionamento.
     *
     * @param  array{nome_admin: string, email_admin: string, senha_admin: string}  $data
     */
    private function criarAcessoDaRevenda(Revenda $revenda, array $data): void
    {
        $existente = User::where('email', $data['email_admin'])->first();

        if ($existente) {
            $existente->update(['revenda_id' => $revenda->id]);
            $usuario = $existente;
        } else {
            $usuario = User::create([
                'name' => $data['nome_admin'],
                'email' => $data['email_admin'],
                'password' => $data['senha_admin'],
                'revenda_id' => $revenda->id,
                'ativo' => true,
                // ÚNICA conta que nasce sem a troca obrigatória, e o motivo é
                // o parágrafo acima: esta senha é a MESMA do AlfaGym, de
                // propósito. Forçar a troca aqui separaria as duas em silêncio
                // — a pessoa passaria a ter uma senha em cada painel sem nunca
                // ter pedido isso, e descobriria no dia em que o outro
                // recusasse a que ela acabou de escolher.
                'primeiro_acesso' => false,
            ]);
        }

        $perfil = Perfil::where('slug', 'revenda')->first();

        if ($perfil) {
            $usuario->perfis()->syncWithoutDetaching([$perfil->id]);
        }
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
            // Desde quando a revenda existe, que não é o dia em que a linha
            // nasceu aqui. É o que permite a curva de crescimento dizer a
            // verdade sobre a base importada.
            'data_cadastro' => 'nullable|date',
        ]);
    }
}
