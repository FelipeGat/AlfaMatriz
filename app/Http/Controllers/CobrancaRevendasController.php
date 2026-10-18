<?php

namespace App\Http\Controllers;

use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\MercadoPago\BoletoDaRevenda;
use App\Services\MercadoPago\ErroDoMercadoPago;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Financeiro › Cobrança de revendas (docs/specs/cobranca-revendas-boleto): o
 * cadastro que o boleto precisa, o preço por cliente, quem é faturável, a
 * prévia e as cobranças com o boleto. Só com `cobranca_revendas` (FR-001).
 */
class CobrancaRevendasController extends Controller
{
    public const NOME_DO_PRECO = 'Por cliente';

    public function __construct(private BoletoDaRevenda $boletos) {}

    public function index(Request $request): View
    {
        $this->bloquearVisaoDaMatriz();
        $revendas = Revenda::where('ativo', true)->orderByDesc('cobranca_por_cliente')->orderBy('nome')->get();
        $revenda = $revendas->firstWhere('id', (int) $request->query('revenda')) ?? $revendas->first();
        abort_unless($revenda, 404, 'Nenhuma revenda ativa.');

        $produtos = Sistema::produtos()->where('ativo', true)
            ->whereHas('clientes', fn ($q) => $q->where('revenda_id', $revenda->id))->orderBy('nome')->get()
            ->map(function (Sistema $s) use ($revenda) {
                $clientes = $s->clientes()->where('revenda_id', $revenda->id)->where('clientes.ativo', true)->where('cliente_sistema.ativo', true)
                    ->orderBy('clientes.nome')->get(['clientes.id', 'clientes.nome']);
                $preco = $this->precoPorCliente($s, $revenda);
                $faturaveis = $clientes->filter(fn ($c) => (bool) $c->pivot->faturavel)->count();

                return ['sistema' => $s, 'clientes' => $clientes, 'preco' => $preco?->valor_excedente_unidade, 'faturaveis' => $faturaveis,
                    'subtotal' => $preco ? $faturaveis * (float) $preco->valor_excedente_unidade : 0.0];
            });

        $cobrancas = Cobranca::where('revenda_id', $revenda->id)->where('tipo', 'locacao_sistema')->latest('competencia')->latest('id')->limit(12)->get();

        return view('cobranca-revendas.index', [
            'revendas' => $revendas, 'revenda' => $revenda, 'produtos' => $produtos, 'total' => $produtos->sum('subtotal'),
            'cobrancas' => $cobrancas, 'impedimentos' => $cobrancas->mapWithKeys(fn ($c) => [$c->id => $c->mp_payment_id ? null : $this->boletos->impedimento($c)]),
            'contas' => ContaFinanceira::orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    public function salvarRevenda(Request $request, Revenda $revenda): RedirectResponse
    {
        $this->bloquearVisaoDaMatriz();
        $d = $request->validate([
            'cobranca_por_cliente' => ['boolean'],
            'dia_vencimento' => ['nullable', 'integer', 'between:1,28'],
            'cnpj' => ['nullable', 'string', 'max:20'],
            'email_financeiro' => ['nullable', 'email', 'max:190'],
            'cep' => ['nullable', 'string', 'max:9'],
            'logradouro' => ['nullable', 'string', 'max:190'],
            'numero' => ['nullable', 'string', 'max:20'],
            'bairro' => ['nullable', 'string', 'max:120'],
            'cidade' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'size:2'],
            'conta_financeira_id' => ['nullable', 'integer', 'exists:contas_financeiras,id'],
        ]);
        $d['cobranca_por_cliente'] = $request->boolean('cobranca_por_cliente');
        $d['uf'] = isset($d['uf']) ? mb_strtoupper($d['uf']) : null;
        $revenda->update($d);

        return back()->with('status', 'Dados de cobrança da '.$revenda->nome.' salvos.');
    }

    /** O preço por cliente da revenda: uma faixa "por unidade" só dela, por produto. */
    public function salvarPrecos(Request $request, Revenda $revenda): RedirectResponse
    {
        $this->bloquearVisaoDaMatriz();
        $precos = $request->validate(['precos' => ['required', 'array'], 'precos.*' => ['nullable', 'numeric', 'min:0', 'max:99999']])['precos'];
        DB::transaction(function () use ($precos, $revenda) {
            foreach ($precos as $sistemaId => $valor) {
                $sistema = Sistema::produtos()->find((int) $sistemaId);
                if (! $sistema || $valor === null || $valor === '') {
                    continue;
                }
                PrecoAtacado::updateOrCreate(['sistema_id' => $sistema->id, 'revenda_id' => $revenda->id, 'nome' => self::NOME_DO_PRECO], [
                    'preco_base' => 0, 'unidades_inclusas' => 0, 'valor_excedente_unidade' => round((float) $valor, 2), 'limite_unidades' => null,
                    'ordem' => 0, 'vigencia_inicio' => now()->startOfMonth()->toDateString(), 'vigencia_fim' => null,
                ]);
            }
        });

        return back()->with('status', 'Preços por cliente salvos.');
    }

    public function faturavel(Request $request, Revenda $revenda): RedirectResponse
    {
        $this->bloquearVisaoDaMatriz();
        $d = $request->validate(['cliente_id' => ['required', 'integer'], 'sistema_id' => ['required', 'integer'], 'faturavel' => ['required', 'boolean']]);
        $cliente = $revenda->clientes()->findOrFail($d['cliente_id']);
        abort_unless($cliente->sistemas()->whereKey($d['sistema_id'])->exists(), 404);
        $cliente->sistemas()->updateExistingPivot($d['sistema_id'], ['faturavel' => $request->boolean('faturavel')]);

        return back()->with('status', $cliente->nome.($request->boolean('faturavel') ? ' entra' : ' sai').' da cobrança.');
    }

    public function emitir(Cobranca $cobranca): RedirectResponse
    {
        $this->bloquearVisaoDaMatriz();
        try {
            $c = $this->boletos->emitir($cobranca);
        } catch (ErroDoMercadoPago $e) {
            $cobranca->forceFill(['mp_erro' => mb_substr($e->getMessage(), 0, 255)])->save();

            return back()->with('erro', $e->getMessage());
        }

        return back()->with('status', 'Boleto emitido: vence '.$c->data_vencimento->format('d/m/Y').'.');
    }

    public function conferir(Cobranca $cobranca): RedirectResponse
    {
        $this->bloquearVisaoDaMatriz();
        abort_unless(filled($cobranca->mp_payment_id), 404);
        try {
            $status = $this->boletos->conferir($cobranca);
        } catch (ErroDoMercadoPago $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('status', $status === 'approved' ? 'Pago: baixa feita no Caixa.' : 'Mercado Pago: '.$status.'.');
    }

    private function precoPorCliente(Sistema $s, Revenda $revenda): ?PrecoAtacado
    {
        return $s->tiersVigentes($revenda->id)->first(fn ($t) => $t->revenda_id === $revenda->id);
    }
}
