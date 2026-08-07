<?php

namespace App\Http\Controllers;

use App\Models\Sincronizacao;
use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaLicenca;
use App\Services\Integracao\CorteService;
use App\Services\Integracao\ErroIntegracao;
use App\Services\Integracao\FabricaDeConector;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * O painel de integração: em que pé está cada sistema da casa.
 *
 * Responde três perguntas de relance — está conectado? de quando é o dado que
 * estou vendo? a matriz já manda nele? — porque são as três que decidem se dá
 * para confiar em qualquer outra tela desta seção.
 */
class IntegracaoController extends Controller
{
    public function __construct(private readonly CorteService $corte) {}

    public function index(): View
    {
        // Só os produtos vendidos como serviço: é o filtro que mantém o Gestor
        // fora da integração, e ele mora aqui e no serviço, não na consulta de
        // cada tela.
        $sistemas = Sistema::where('categoria', 'saas')->orderBy('nome')->get();

        $cartoes = $sistemas->map(fn (Sistema $sistema) => $this->retratoDe($sistema));

        return view('integracao.index', [
            'cartoes' => $cartoes,
            'resumo' => [
                'conectados' => $cartoes->where('configurado', true)->count(),
                'total' => $cartoes->count(),
                'clientes' => SistemaCliente::presentes()->count(),
                'vencendo' => SistemaLicenca::vencendoEm((int) config('integracao.dias_para_licenca_vencendo', 30))->count(),
                'pendencias' => $cartoes->sum('pendencias'),
                'fora_do_ar' => $cartoes->where('fora_do_ar', true)->count(),
            ],
        ]);
    }

    /**
     * Bate na porta do sistema e conta o que ouviu.
     *
     * É a primeira coisa a fazer depois de preencher endereço e chave — e a
     * única forma de descobrir que a chave está errada sem esperar a próxima
     * sincronização falhar de madrugada.
     */
    public function testarConexao(Sistema $sistema, FabricaDeConector $fabrica): RedirectResponse
    {
        try {
            $resposta = $fabrica->para($sistema)->ping();
        } catch (ErroIntegracao $erro) {
            return back()->with('erro', "{$sistema->nome}: {$erro->getMessage()}");
        }

        $versao = $resposta['versao'] ?? 'desconhecida';
        $contrato = $resposta['contrato'] ?? 'desconhecido';
        $unidade = $resposta['unidade_cobranca'] ?? 'não declarada';

        return back()->with(
            'status',
            "{$sistema->nome} respondeu: versão {$versao}, contrato {$contrato}, cobrando por {$unidade}."
        );
    }

    /** @return array<string, mixed> */
    private function retratoDe(Sistema $sistema): array
    {
        $ultima = Sincronizacao::where('sistema_id', $sistema->id)
            ->concluidas()
            ->latest('id')
            ->first();

        $motivo = $sistema->motivoIntegracaoIndisponivel();

        return [
            'sistema' => $sistema,
            'configurado' => $motivo === null,
            'motivo' => $motivo,
            'estagio' => $this->corte->estagio($sistema),
            'pendencias' => $sistema->importado_em ? $this->corte->totalDePendencias($sistema) : 0,
            // Fora do ar é diferente de nunca configurado: um pede conserto lá,
            // o outro pede preenchimento aqui.
            'fora_do_ar' => $motivo === null && $sistema->falhas_consecutivas > 0,
            'ultima' => $ultima,
            'clientes' => SistemaCliente::doSistema($sistema)->presentes()->count(),
            'clientes_ativos' => SistemaCliente::doSistema($sistema)->ativos()->count(),
            'unidades' => (int) SistemaCliente::doSistema($sistema)->ativos()->sum('unidades_ativas'),
            'licencas_vencendo' => SistemaLicenca::doSistema($sistema)
                ->vencendoEm((int) config('integracao.dias_para_licenca_vencendo', 30))
                ->count(),
        ];
    }
}
