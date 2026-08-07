<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use App\Models\SistemaCliente;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Todos os clientes de todos os sistemas, numa lista só.
 *
 * A coluna que importa é a última: a quem este cliente corresponde na matriz.
 * É ela que responde a pergunta que motivou a integração inteira — "o cliente
 * ativo lá dentro está sendo cobrado aqui?".
 */
class IntegracaoClienteController extends Controller
{
    public function index(Request $request): View
    {
        $sistemas = Sistema::where('categoria', 'saas')->orderBy('nome')->get();

        $consulta = SistemaCliente::query()
            ->with(['sistema', 'cliente', 'sistemaRevenda'])
            ->when($request->sistema, fn ($q, $id) => $q->where('sistema_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->vinculo === 'sem', fn ($q) => $q->whereNull('cliente_id'))
            ->when($request->vinculo === 'com', fn ($q) => $q->whereNotNull('cliente_id'))
            ->when($request->busca, function ($q, $busca) {
                $digitos = preg_replace('/\D+/', '', $busca);

                return $q->where(function ($sub) use ($busca, $digitos) {
                    $sub->where('nome', 'like', "%{$busca}%")
                        ->orWhere('razao_social', 'like', "%{$busca}%");

                    if ($digitos !== '') {
                        $sub->orWhere('cpf_cnpj', 'like', "%{$digitos}%");
                    }
                });
            });

        // Os que sumiram na origem ficam fora por padrão, mas continuam
        // alcançáveis: o retrato guarda o histórico, e escondê-lo para sempre
        // seria o mesmo que ter apagado.
        if ($request->ausentes !== 'sim') {
            $consulta->presentes();
        }

        $clientes = $consulta->orderBy('nome')->paginate(50)->withQueryString();

        return view('integracao.clientes', [
            'sistemas' => $sistemas,
            'clientes' => $clientes,
            'filtros' => $request->only(['sistema', 'status', 'vinculo', 'busca', 'ausentes']),
            'semVinculo' => SistemaCliente::presentes()->semVinculo()->count(),
            'atualizadoEm' => $sistemas->max('sincronizado_em'),
        ]);
    }
}
