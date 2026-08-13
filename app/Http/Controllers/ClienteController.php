<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\GerenciadorLicencaService;
use App\Services\ProvisionadorClienteService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        return view('clientes.index', $this->dadosDaLista($request));
    }

    /**
     * Dados da listagem de clientes — reutilizados pela tela de Clientes e
     * pela aba "Clientes" da tela de Revendas.
     *
     * @return array<string, mixed>
     */
    public function dadosDaLista(Request $request): array
    {
        $busca = trim((string) $request->query('busca', ''));
        $filtroRevenda = $request->query('revenda');

        $temEscopo = auth()->user()->temEscopoDeRevenda();

        $clientes = Cliente::with(['revenda', 'sistemas', 'modulosContratados.modulo'])
            ->when($temEscopo, fn ($q) => $q->where('revenda_id', auth()->user()->revenda_id))
            ->when($busca !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('nome', 'like', "%{$busca}%")
                ->orWhere('razao_social', 'like', "%{$busca}%")
                ->orWhere('nome_fantasia', 'like', "%{$busca}%")
                ->orWhere('cpf_cnpj', 'like', "%{$busca}%")
                ->orWhere('cidade', 'like', "%{$busca}%")))
            ->when(! $temEscopo && $filtroRevenda, fn ($q) => $q->where('revenda_id', $filtroRevenda))
            ->when($request->query('sistema'), fn ($q, $sistema) => $q->whereHas('sistemas',
                fn ($s) => $s->where('sistemas.id', $sistema)->where('cliente_sistema.ativo', true)))
            ->when($request->query('status') === 'ativo', fn ($q) => $q->where('ativo', true))
            ->when($request->query('status') === 'inativo', fn ($q) => $q->where('ativo', false))
            ->orderBy('nome')
            ->paginate(15)
            ->withQueryString();

        // O estado de pagamento é o que decide a cor da linha, e ele não mora
        // no cliente: sai das cobranças pendentes dele.
        $pagamentos = $this->pagamentos($clientes->getCollection());

        return [
            'clientes' => $clientes,
            'pagamentos' => $pagamentos,
            'revendas' => Revenda::when($temEscopo, fn ($q) => $q->where('id', auth()->user()->revenda_id))->orderBy('nome')->get(),
            // O filtro lista TODAS as revendas (inclusive inativas, para achar
            // cliente antigo); o cadastro só pode oferecer as ativas. São
            // perguntas diferentes, então são duas listas.
            'revendasParaCadastro' => Revenda::where('ativo', true)
                ->when($temEscopo, fn ($q) => $q->where('id', auth()->user()->revenda_id))
                ->orderBy('nome')
                ->get(),
            'sistemas' => Sistema::produtos()->where('ativo', true)->orderBy('nome')->get(),
            'filtros' => [
                'busca' => $busca,
                'revenda' => $request->query('revenda', ''),
                'sistema' => $request->query('sistema', ''),
                'status' => $request->query('status', ''),
            ],
            'kpis' => $this->kpis(),
            'totais' => $this->totais($clientes->getCollection(), $pagamentos),
        ];
    }

    /**
     * Estado de cobrança por cliente da página.
     *
     * Uma consulta só para todos os clientes à vista — perguntar cliente a
     * cliente transformaria a lista em dezenas de consultas.
     *
     * @param  Collection<int, Cliente>  $clientes
     * @return array<int, array{estado: string, dias: int}>
     */
    private function pagamentos(Collection $clientes): array
    {
        $ids = $clientes->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $pendentes = Cobranca::whereIn('cliente_id', $ids)
            ->where('status', 'pendente')
            ->get(['cliente_id', 'data_vencimento'])
            ->groupBy('cliente_id');

        $comCobranca = Cobranca::whereIn('cliente_id', $ids)
            ->pluck('cliente_id')
            ->unique();

        $hoje = now()->startOfDay();

        return $clientes->mapWithKeys(function (Cliente $cliente) use ($pendentes, $comCobranca, $hoje) {
            if (! $comCobranca->contains($cliente->id)) {
                return [$cliente->id => ['estado' => 'sem_cobranca', 'dias' => 0]];
            }

            $vencidas = ($pendentes[$cliente->id] ?? collect())
                ->filter(fn ($c) => Carbon::parse($c->data_vencimento)->lt($hoje));

            if ($vencidas->isEmpty()) {
                return [$cliente->id => ['estado' => 'em_dia', 'dias' => 0]];
            }

            // `diffInDays` do Carbon 3 vem com sinal: data no passado devolve
            // negativo. Aqui a pergunta é "há quantos dias venceu", então o
            // que interessa é a distância, não a direção.
            $dias = (int) abs($hoje->diffInDays(Carbon::parse($vencidas->min('data_vencimento'))));

            return [$cliente->id => ['estado' => 'atrasado', 'dias' => $dias]];
        })->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function kpis(): array
    {
        $escopo = auth()->user()->temEscopoDeRevenda()
            ? ['revenda_id' => auth()->user()->revenda_id]
            : [];

        $cadastrados = Cliente::where($escopo)->count();
        $ativos = Cliente::where($escopo)->where('ativo', true)->count();
        $emContrato = Cliente::where($escopo)->where('ativo', true)->where('tipo_cliente', 'CONTRATO')->count();
        $avulsos = Cliente::where($escopo)->where('ativo', true)->where('tipo_cliente', '!=', 'CONTRATO')->count();

        // O ticket é a média de QUEM ESTÁ EM CONTRATO. Somar o valor mensal
        // dos avulsos e dividir pelos em contrato infla o número — o avulso
        // entra no numerador sem entrar no denominador.
        $mensal = (float) Cliente::where($escopo)
            ->where('ativo', true)
            ->where('tipo_cliente', 'CONTRATO')
            ->sum('valor_mensal');

        return [
            'cadastrados' => ['valor' => $cadastrados, 'nota' => $ativos.' ativos · '.($cadastrados - $ativos).' inativos'],
            'contrato' => [
                'valor' => $emContrato,
                'nota' => $ativos > 0 ? round(($emContrato / $ativos) * 100).'% da base' : 'sem base ativa',
            ],
            'avulsos' => ['valor' => $avulsos, 'nota' => 'sem contrato mensal'],
            'ticket' => [
                'valor' => $emContrato > 0 ? $mensal / $emContrato : 0.0,
                'nota' => 'por cliente em contrato',
            ],
        ];
    }

    /**
     * @param  Collection<int, Cliente>  $pagina
     * @param  array<int, array{estado: string, dias: int}>  $pagamentos
     */
    private function totais(Collection $pagina, array $pagamentos): array
    {
        return [
            'contratos' => $pagina->where('tipo_cliente', 'CONTRATO')->count(),
            'avulsos' => $pagina->where('tipo_cliente', '!=', 'CONTRATO')->count(),
            'mensal' => (float) $pagina->sum('valor_mensal'),
            // Somatório das licenças vigentes na página — separado do comercial.
            'licencas' => (float) $pagina->sum(fn (Cliente $c) => $c->sistemas
                ->where('pivot.ativo', true)
                ->sum(fn ($s) => $s->pivot->valorDaLicenca() ?? 0)),
            'atrasados' => collect($pagamentos)->where('estado', 'atrasado')->count(),
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data = $this->prepararDados($request, $data);

        // A revenda do cliente vem do escopo de quem cadastra, nunca do
        // formulário: senão bastaria trocar o campo para pôr um cliente na
        // carteira de outra revenda.
        if (auth()->user()->temEscopoDeRevenda()) {
            $data['revenda_id'] = auth()->user()->revenda_id;
            // Valor e vencimento são o comercial da Alfa, definidos quando a
            // licença é liberada — a revenda não os informa.
            $data['tipo_cliente'] = 'AVULSO';
            $data['valor_mensal'] = null;
            $data['dia_vencimento'] = null;
        }

        $sistemas = $request->input('sistemas', []);

        try {
            // Gravar aqui e criar lá andam juntos: se o outro lado recusa, o
            // cliente não pode sobrar na Matriz existindo só de um lado.
            $cliente = DB::transaction(function () use ($request, $data, $sistemas) {
                $cliente = Cliente::create($data);

                $this->sincronizarSistemas($cliente, $sistemas);
                $this->sincronizarEmails($cliente, $request->input('emails', []));
                $this->sincronizarTelefones($cliente, $request->input('telefones', []));

                $this->provisionarNosSistemas($cliente, $request, $sistemas);

                return $cliente;
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['integracao' => $e->getMessage()])->withInput();
        }

        return redirect()->route('clientes.index')->with('status', 'Cliente cadastrado com sucesso.');
    }

    /**
     * Cria o cliente nos sistemas marcados que a Matriz sabe provisionar.
     *
     * O cliente nasce lá aguardando licença — quem libera é o administrador da
     * Alfa. Sistema marcado que a Matriz não provisiona (o AlfaControl durante
     * a implantação) não dispara chamada nenhuma: o vínculo fica só comercial,
     * e o cadastro de lá continua sendo feito no painel dele.
     *
     * @param  array<int|string>  $sistemas
     */
    private function provisionarNosSistemas(Cliente $cliente, Request $request, array $sistemas): void
    {
        $marcados = array_map('strval', $sistemas);

        $provisionaveis = Sistema::comCapacidade('provisiona_cliente')
            ->whereIn('id', $sistemas)
            ->get()
            ->filter(fn (Sistema $sistema) => in_array((string) $sistema->id, $marcados, true));

        foreach ($provisionaveis as $sistema) {
            (new ProvisionadorClienteService($sistema))
                ->provisionar($cliente->fresh(), $this->validarAdmin($request, $sistema));
        }
    }

    /**
     * Os dados do administrador daquele sistema, quando ele exige um.
     *
     * @return array{nome_admin: string, email_admin: string, senha_admin: string}
     */
    private function validarAdmin(Request $request, Sistema $sistema): array
    {
        if (! $sistema->suporta('exige_admin_no_cliente')) {
            return ['nome_admin' => '', 'email_admin' => '', 'senha_admin' => ''];
        }

        $chave = "admins.{$sistema->id}";

        $dados = $request->validate([
            "{$chave}.nome" => 'required|string|max:100',
            "{$chave}.email" => 'required|email|max:255',
            "{$chave}.senha" => 'required|string|min:8',
        ]);

        $admin = data_get($dados, "admins.{$sistema->id}");

        return [
            'nome_admin' => $admin['nome'],
            'email_admin' => $admin['email'],
            'senha_admin' => $admin['senha'],
        ];
    }

    public function edit(Cliente $cliente)
    {
        $this->autorizarAcesso($cliente);

        $revendas = Revenda::where('ativo', true)
            ->when(auth()->user()->temEscopoDeRevenda(), fn ($q) => $q->where('id', auth()->user()->revenda_id))
            ->orderBy('nome')
            ->get();
        $sistemas = Sistema::produtos()->where('ativo', true)->orderBy('nome')->get();
        $cliente->load('sistemas', 'emails', 'telefones');

        return view('clientes.edit', compact('cliente', 'revendas', 'sistemas'));
    }

    public function update(Request $request, Cliente $cliente)
    {
        $this->autorizarAcesso($cliente);

        $data = $this->validated($request, $cliente->id);
        $data = $this->prepararDados($request, $data);

        // Usuário de revenda nunca muda o dono do cliente: a revenda vem do
        // escopo dele, não do formulário.
        if (auth()->user()->temEscopoDeRevenda()) {
            $data['revenda_id'] = auth()->user()->revenda_id;
        }

        $cliente->update($data);

        $this->sincronizarSistemas($cliente, $request->input('sistemas', []));
        $this->sincronizarEmails($cliente, $request->input('emails', []));
        $this->sincronizarTelefones($cliente, $request->input('telefones', []));

        return redirect()->route('clientes.index')->with('status', 'Cliente atualizado com sucesso.');
    }

    public function destroy(Cliente $cliente)
    {
        $this->autorizarAcesso($cliente);

        $cliente->delete();

        return redirect()->route('clientes.index')->with('status', 'Cliente removido.');
    }

    /**
     * Libera a licença do cliente no sistema (o admin da Matriz atende o
     * pedido da revenda). Só clientes pendentes de licença podem liberar;
     * o cliente permanece vinculado à revenda (nunca vira avulso).
     */
    public function liberarLicenca(Request $request, Cliente $cliente, Sistema $sistema)
    {
        $this->exigirDecisaoDaAlfa();
        $this->autorizarAcesso($cliente);

        $vinculo = $this->exigirLicencaGerenciavel($cliente, $sistema);

        abort_if(($vinculo->pivot->status_saas ?? '') !== 'pendente', 422, 'A licença desse cliente não está pendente.');

        $dados = $this->validarOperacaoLicenca($request);

        try {
            (new GerenciadorLicencaService($sistema))->liberar($cliente, $dados);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['licenca' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Licença liberada com sucesso.');
    }

    /**
     * Renova a licença do cliente no sistema com um novo período mensal/anual.
     * Vale para qualquer cliente com licença ativa (incluindo vencida/bloqueada
     * — quem renova retoma o acesso no novo período).
     */
    public function renovarLicenca(Request $request, Cliente $cliente, Sistema $sistema)
    {
        $this->exigirDecisaoDaAlfa();
        $this->autorizarAcesso($cliente);

        $this->exigirLicencaGerenciavel($cliente, $sistema);
        $dados = $this->validarOperacaoLicenca($request);

        try {
            (new GerenciadorLicencaService($sistema))->renovar($cliente, $dados);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['licenca' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Licença renovada com sucesso.');
    }

    /**
     * Bloqueia o acesso do cliente no sistema.
     */
    public function bloquearLicenca(Cliente $cliente, Sistema $sistema)
    {
        $this->exigirDecisaoDaAlfa();
        $this->autorizarAcesso($cliente);

        $this->exigirLicencaGerenciavel($cliente, $sistema);

        try {
            (new GerenciadorLicencaService($sistema))->bloquear($cliente);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['licenca' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Acesso bloqueado.');
    }

    /**
     * Desbloqueia o acesso do cliente no sistema.
     */
    public function desbloquearLicenca(Cliente $cliente, Sistema $sistema)
    {
        $this->exigirDecisaoDaAlfa();
        $this->autorizarAcesso($cliente);

        $this->exigirLicencaGerenciavel($cliente, $sistema);

        try {
            (new GerenciadorLicencaService($sistema))->desbloquear($cliente);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['licenca' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Acesso desbloqueado.');
    }

    /**
     * Campos comuns de uma operação sobre a licença (tipo, valor, observação).
     *
     * @return array{tipo: string, valor: float|null, obs: string|null}
     */
    private function validarOperacaoLicenca(Request $request): array
    {
        return $request->validate([
            'tipo' => 'required|in:mensal,anual',
            'valor' => 'nullable|numeric|min:0',
            'obs' => 'nullable|string|max:500',
        ]);
    }

    /**
     * O sistema da rota precisa (a) saber gerenciar licença pela Matriz e
     * (b) ter esse cliente. Substitui o `where('slug', 'alfagym')` que estava
     * espalhado pelas quatro ações: a pergunta certa é sobre a capacidade, não
     * sobre qual produto é.
     *
     * Durante a implantação de um sistema novo, `gerencia_licenca` fica
     * desligada — a tela não oferece a ação e um POST direto é recusado aqui.
     */
    private function exigirLicencaGerenciavel(Cliente $cliente, Sistema $sistema): object
    {
        abort_unless(
            $sistema->suporta('gerencia_licenca'),
            422,
            "A licença do {$sistema->nome} não é gerenciada pela Matriz."
        );

        $vinculo = $cliente->sistemas()->where('sistemas.id', $sistema->id)->first();

        abort_if(! $vinculo, 404, "Esse cliente não usa o {$sistema->nome}.");

        return $vinculo;
    }

    /**
     * Usuário de revenda só acessa os próprios clientes (e ainda assim com o
     * revenda_id do registro conferindo com o do usuário, nunca o do formulário).
     */
    private function autorizarAcesso(Cliente $cliente): void
    {
        $user = auth()->user();

        if ($user->temEscopoDeRevenda() && $cliente->revenda_id !== $user->revenda_id) {
            abort(403, 'Você só pode acessar os clientes da sua revenda.');
        }
    }

    /**
     * Licença é decisão da Alfa: a revenda cadastra o cliente e pede, quem
     * libera, renova, suspende e reativa é o administrador da matriz.
     *
     * A tela já esconde as ações de quem tem escopo de revenda — mas esconder
     * botão não é autorização: sem esta recusa, um POST direto passaria.
     */
    private function exigirDecisaoDaAlfa(): void
    {
        abort_if(
            auth()->user()->temEscopoDeRevenda(),
            403,
            'A licença do cliente é liberada pela Alfa. Sua revenda acompanha o pedido pela lista de clientes.'
        );
    }

    /**
     * Não usa sync() puro: desmarcar um sistema deve "cancelar" o vínculo
     * (ativo=false + cancelado_em), não apagar a linha — senão perde o
     * histórico usado pra calcular clientes cancelados/churn na tela de Produtos.
     */
    private function sincronizarSistemas(Cliente $cliente, array $sistemaIdsSelecionados): void
    {
        $jaVinculados = $cliente->sistemas()->pluck('sistemas.id')->all();

        // O retrato de ANTES, para a auditoria: quais sistemas estavam ativos.
        // Precisa ser lido aqui porque as três operações abaixo mexem no PIVÔ
        // `cliente_sistema` — `attach` e `updateExistingPivot` não disparam
        // evento nenhum do Eloquent, nem com `ClienteSistema` sendo um `Pivot`.
        // O trait de auditoria não vê nada disto acontecer.
        $antes = $cliente->sistemas()->wherePivot('ativo', true)->pluck('sistemas.nome')->sort()->values()->all();

        foreach ($sistemaIdsSelecionados as $id) {
            if (in_array($id, $jaVinculados)) {
                $cliente->sistemas()->updateExistingPivot($id, ['ativo' => true, 'cancelado_em' => null]);
            } else {
                $cliente->sistemas()->attach($id, ['ativo' => true, 'ativado_em' => now()->toDateString()]);
            }
        }

        $desmarcados = array_diff($jaVinculados, $sistemaIdsSelecionados);
        foreach ($desmarcados as $id) {
            $cliente->sistemas()->updateExistingPivot($id, ['ativo' => false, 'cancelado_em' => now()->toDateString()]);
        }

        $this->registrarMudancaDeSistemas($cliente, $antes);
    }

    /**
     * O rastro de quais sistemas o cliente usa.
     *
     * Não é detalhe de cadastro: tirar um sistema daqui marca o vínculo como
     * cancelado, e é isso que decide se o cliente entra na fatura daquele
     * produto no mês seguinte. Sem registro, a receita cai e não há como dizer
     * quem decidiu, nem quando.
     *
     * Guarda os NOMES pelo mesmo motivo dos perfis de usuário: a linha tem de
     * continuar legível anos depois, e uma lista de ids exige consultar uma
     * tabela que pode ter mudado.
     *
     * @param  array<int, string>  $antes
     */
    private function registrarMudancaDeSistemas(Cliente $cliente, array $antes): void
    {
        $depois = $cliente->sistemas()->wherePivot('ativo', true)
            ->pluck('sistemas.nome')->sort()->values()->all();

        // Salvar a ficha sem mexer nos sistemas é o caso comum — a mesma tela
        // corrige um telefone. Registrar assim mesmo encheria a auditoria de
        // mudanças que não mudaram nada.
        if ($antes === $depois) {
            return;
        }

        Auditoria::registrar(
            recurso: 'clientes',
            acao: 'alterou',
            alvo: $cliente,
            descricao: $cliente->nome,
            alteracoes: ['sistemas' => [
                'de' => $antes ? implode(', ', $antes) : 'nenhum',
                'para' => $depois ? implode(', ', $depois) : 'nenhum',
            ]],
        );
    }

    /**
     * Re-sincroniza e-mails/telefones do zero a cada save (mesmo padrão destrutivo do Gestor.Alfa —
     * mais simples que diff, e a lista raramente passa de 2-3 itens).
     */
    private function sincronizarEmails(Cliente $cliente, array $emails): void
    {
        // O retrato de ANTES, para a auditoria. Este método regrava a lista do
        // zero a cada salvamento — é o padrão destrutivo explicado acima —, e o
        // trait de auditoria, ligado nos modelos, registraria três exclusões e
        // três criações toda vez que alguém salvasse a ficha para corrigir a
        // cidade. Comparar as duas listas transforma isso em uma linha, e só
        // quando algo realmente mudou.
        $antes = $cliente->emails()->orderBy('email')->pluck('email')->all();

        $cliente->emails()->delete();

        $temPrincipal = false;
        foreach ($emails as $item) {
            if (empty($item['email'] ?? null)) {
                continue;
            }

            $principal = ! $temPrincipal && ! empty($item['principal']);
            $temPrincipal = $temPrincipal || $principal;

            $cliente->emails()->create([
                'email' => $item['email'],
                'principal' => $principal,
                'financeiro' => ! empty($item['financeiro']),
            ]);
        }

        if (! $temPrincipal && $cliente->emails()->exists()) {
            $cliente->emails()->first()->update(['principal' => true]);
        }

        $this->registrarMudancaDeContatos(
            $cliente, 'e-mails', $antes,
            $cliente->emails()->orderBy('email')->pluck('email')->all()
        );
    }

    private function sincronizarTelefones(Cliente $cliente, array $telefones): void
    {
        // Mesma razão do `sincronizarEmails`: a lista é regravada do zero.
        $antes = $cliente->telefones()->orderBy('telefone')->pluck('telefone')->all();

        $cliente->telefones()->delete();

        $temPrincipal = false;
        foreach ($telefones as $item) {
            if (empty($item['telefone'] ?? null)) {
                continue;
            }

            $principal = ! $temPrincipal && ! empty($item['principal']);
            $temPrincipal = $temPrincipal || $principal;

            $cliente->telefones()->create([
                'telefone' => $item['telefone'],
                'principal' => $principal,
            ]);
        }

        if (! $temPrincipal && $cliente->telefones()->exists()) {
            $cliente->telefones()->first()->update(['principal' => true]);
        }

        $this->registrarMudancaDeContatos(
            $cliente, 'telefones', $antes,
            $cliente->telefones()->orderBy('telefone')->pluck('telefone')->all()
        );
    }

    /**
     * O rastro de uma lista de contatos que foi regravada do zero.
     *
     * Uma linha por MUDANÇA, e não por registro tocado. O e-mail e o telefone
     * do cliente são por onde a cobrança chega: trocar um e-mail financeiro
     * desvia o boleto, e essa é exatamente a alteração sobre a qual alguém vai
     * perguntar depois.
     *
     * @param  array<int, string>  $antes
     * @param  array<int, string>  $depois
     */
    private function registrarMudancaDeContatos(Cliente $cliente, string $campo, array $antes, array $depois): void
    {
        if ($antes === $depois) {
            return;
        }

        Auditoria::registrar(
            recurso: 'clientes',
            acao: 'alterou',
            alvo: $cliente,
            descricao: $cliente->nome,
            alteracoes: [$campo => [
                'de' => $antes ? implode(', ', $antes) : 'nenhum',
                'para' => $depois ? implode(', ', $depois) : 'nenhum',
            ]],
        );
    }

    /**
     * Resolve nome/razão social/nome_fantasia (mesma regra do Gestor.Alfa) e zera
     * valor_mensal/dia_vencimento quando o cliente é AVULSO.
     */
    private function prepararDados(Request $request, array $data): array
    {
        $data['ativo'] = $request->boolean('ativo', true);
        $data['nota_fiscal'] = $request->boolean('nota_fiscal');

        if ($data['tipo_pessoa'] === 'PF') {
            $data['nome'] = trim($data['nome'] ?? '');
            $data['razao_social'] = $data['nome'];
            $data['nome_fantasia'] = null;
        } else {
            // `?? null` como a linha do PF logo acima: `nome_fantasia` é
            // opcional na validação, então a chave pode simplesmente não vir.
            // Pelo formulário ela sempre vem — vazia vira null e o `?:` cai no
            // `razao_social` —, mas qualquer envio que não seja o formulário
            // derrubava aqui com 500 em vez de gravar o cliente.
            $data['nome'] = ($data['nome_fantasia'] ?? null) ?: $data['razao_social'];
        }

        if (! empty($data['cpf_cnpj'])) {
            $data['cpf_cnpj'] = preg_replace('/\D/', '', $data['cpf_cnpj']);
        }

        if (($data['tipo_cliente'] ?? 'AVULSO') === 'AVULSO') {
            $data['valor_mensal'] = null;
            $data['dia_vencimento'] = null;
        } elseif (! empty($data['dia_vencimento'])) {
            $data['dia_vencimento'] = min((int) $data['dia_vencimento'], 28);
        }

        return $data;
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'revenda_id' => 'required|exists:revendas,id',
            'tipo_pessoa' => 'required|in:PF,PJ',
            'nome' => 'required_if:tipo_pessoa,PF|nullable|string|max:255',
            'razao_social' => 'required_if:tipo_pessoa,PJ|nullable|string|max:255',
            'nome_fantasia' => 'nullable|string|max:255',
            'cpf_cnpj' => [
                'nullable', 'string', 'max:20',
                Rule::unique('clientes', 'cpf_cnpj')->ignore($ignoreId)->whereNull('deleted_at'),
            ],
            'tipo_cliente' => 'required|in:CONTRATO,AVULSO',
            'valor_mensal' => 'nullable|required_if:tipo_cliente,CONTRATO|numeric|min:0',
            'dia_vencimento' => 'nullable|required_if:tipo_cliente,CONTRATO|integer|between:1,28',
            'forma_pagamento_recebimento' => 'nullable|in:boleto,faturado',
            'data_cadastro' => 'nullable|date',
            'cep' => 'nullable|string|max:10',
            'logradouro' => 'nullable|string|max:255',
            'numero' => 'nullable|string|max:20',
            'bairro' => 'nullable|string|max:100',
            'cidade' => 'nullable|string|max:100',
            'uf' => 'nullable|string|max:2',
            'complemento' => 'nullable|string|max:100',
            'inscricao_estadual' => 'nullable|string|max:30',
            'inscricao_municipal' => 'nullable|string|max:30',
            'observacoes' => 'nullable|string',
            'emails' => 'nullable|array',
            'emails.*.email' => 'nullable|email|max:255',
            'telefones' => 'nullable|array',
            'telefones.*.telefone' => 'nullable|string|max:30',
        ]);
    }
}
