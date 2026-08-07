<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaRevenda;
use App\Services\Integracao\CorteService;
use App\Services\Integracao\ErroIntegracao;
use App\Services\Integracao\VinculadorService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * A conferência do cadastro que já existe dentro de um sistema.
 *
 * É a tela que torna o corte seguro: enquanto sobrar um registro sem par na
 * matriz, a virada fica travada. Cada pendência aparece pelo MOTIVO, porque
 * cada motivo pede uma ação diferente — e uma lista única de "não vinculados"
 * obrigaria a pessoa a descobrir isso registro a registro.
 */
class ConferenciaController extends Controller
{
    public function __construct(
        private readonly VinculadorService $vinculador,
        private readonly CorteService $corte,
    ) {}

    public function index(Request $request): View
    {
        $sistemas = Sistema::where('categoria', 'saas')->orderBy('nome')->get();
        $sistema = $sistemas->firstWhere('id', (int) $request->sistema) ?? $sistemas->first();

        $baldes = [
            VinculadorService::SEM_PAR => collect(),
            VinculadorService::VARIOS_CANDIDATOS => collect(),
            VinculadorService::SEM_DOCUMENTO => collect(),
        ];

        if ($sistema) {
            $pendentes = SistemaCliente::doSistema($sistema)->presentes()->semVinculo()->orderBy('nome')->get();

            foreach ($pendentes as $registro) {
                $motivo = $this->vinculador->motivoDeNaoVincular($registro);

                if ($motivo !== null) {
                    $baldes[$motivo]->push([
                        'registro' => $registro,
                        'candidatos' => $motivo === VinculadorService::VARIOS_CANDIDATOS
                            ? $this->vinculador->candidatosParaCliente($registro)
                            : collect(),
                    ]);
                }
            }
        }

        return view('integracao.conferencia', [
            'sistemas' => $sistemas,
            'sistema' => $sistema,
            'baldes' => $baldes,
            'revendasPendentes' => $sistema
                ? SistemaRevenda::doSistema($sistema)->presentes()->whereNull('revenda_id')->orderBy('nome')->get()
                : collect(),
            'total' => $sistema ? $this->corte->totalDePendencias($sistema) : 0,
            'motivoDoCorte' => $sistema ? $this->corte->motivoParaNaoAplicar($sistema) : 'sem_importacao',
            'estagio' => $sistema ? $this->corte->estagio($sistema) : 'nao_ligado',
        ]);
    }

    public function vincularCliente(Request $request, SistemaCliente $sistemaCliente): RedirectResponse
    {
        $dados = $request->validate(['cliente_id' => 'required|exists:clientes,id']);

        $this->vinculador->vincularClienteManualmente(
            $sistemaCliente,
            Cliente::findOrFail($dados['cliente_id'])
        );

        return back()->with('status', "{$sistemaCliente->nome} ficou ligado ao cliente da matriz.");
    }

    public function vincularRevenda(Request $request, SistemaRevenda $sistemaRevenda): RedirectResponse
    {
        $dados = $request->validate(['revenda_id' => 'required|exists:revendas,id']);

        $this->vinculador->vincularRevendaManualmente(
            $sistemaRevenda,
            Revenda::findOrFail($dados['revenda_id'])
        );

        return back()->with('status', "{$sistemaRevenda->nome} ficou ligada à revenda da matriz.");
    }

    /**
     * Aplica o corte.
     *
     * A recusa é do serviço, não da tela: esconder o botão impede o clique
     * distraído, mas não impede um pedido montado à mão — e este é o passo
     * praticamente irreversível da integração inteira.
     */
    public function aplicarCorte(Sistema $sistema): RedirectResponse
    {
        try {
            $this->corte->aplicar($sistema);
        } catch (ErroIntegracao $erro) {
            return back()->with('erro', $erro->getMessage());
        }

        return back()->with(
            'status',
            "A partir de agora a matriz é dona do cadastro do {$sistema->nome}."
        );
    }
}
