<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use App\Models\SistemaCliente;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * O contrato de cada cliente ao lado do uso real dentro do sistema.
 *
 * O dinheiro é TODO do AlfaMatriz: o valor que o cliente paga vive em
 * `clientes.valor_mensal`, e o preço que a revenda paga vive nos tiers de
 * atacado. Do sistema vem só o USO — quantas unidades estão ativas.
 *
 * Pedir dinheiro a cinco sistemas diferentes seria manter cinco verdades sobre
 * a mesma coisa; na primeira vez que divergissem, ninguém saberia qual
 * acreditar. Por isso esta tela cruza duas fontes, e só uma delas é externa.
 */
class IntegracaoContratoController extends Controller
{
    public function index(Request $request): View
    {
        $sistemas = Sistema::where('categoria', 'saas')->orderBy('nome')->get();
        $linhas = $this->linhas($request->sistema, $request->revenda);

        return view('integracao.contratos', [
            'sistemas' => $sistemas,
            'porRevenda' => $this->agrupar($linhas),
            'totais' => [
                'contratado' => (float) $linhas->sum('valor_mensal'),
                'unidades' => (int) $linhas->sum('unidades'),
                'clientes' => $linhas->count(),
                'sem_contrato' => $linhas->where('sem_contrato', true)->count(),
                'sem_vinculo' => $linhas->where('sem_vinculo', true)->count(),
            ],
            'filtros' => $request->only(['sistema', 'revenda']),
            'atualizadoEm' => $sistemas->max('sincronizado_em'),
        ]);
    }

    public function exportar(Request $request): StreamedResponse
    {
        $linhas = $this->linhas($request->sistema, $request->revenda);

        return response()->streamDownload(function () use ($linhas) {
            $saida = fopen('php://output', 'w');

            // Marca de codificação e ponto e vírgula: é o que o Excel em
            // português abre sem estragar acento e sem jogar tudo numa coluna.
            fwrite($saida, "\xEF\xBB\xBF");

            fputcsv($saida, [
                'Revenda', 'Sistema', 'Cliente no sistema', 'Cliente na matriz', 'Documento',
                'Tipo de contrato', 'Valor mensal contratado', 'Unidades ativas no sistema', 'Situação no sistema',
            ], ';');

            foreach ($linhas as $linha) {
                fputcsv($saida, [
                    $linha['revenda'],
                    $linha['sistema'],
                    $linha['nome_no_sistema'],
                    $linha['nome_na_matriz'] ?? 'sem vínculo',
                    $linha['documento'],
                    $linha['tipo_contrato'] ?? '',
                    $linha['valor_mensal'] !== null ? number_format($linha['valor_mensal'], 2, ',', '') : '',
                    $linha['unidades'],
                    $linha['status'],
                ], ';');
            }

            fclose($saida);
        }, 'contratos-e-uso-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Uma linha por cliente ativo dentro de um sistema.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function linhas(mixed $sistemaId, mixed $revendaId): Collection
    {
        return SistemaCliente::query()
            ->presentes()
            ->ativos()
            ->when($sistemaId, fn ($q, $id) => $q->where('sistema_id', $id))
            ->with(['sistema', 'cliente.revenda'])
            ->get()
            ->when($revendaId, fn (Collection $itens) => $itens->filter(
                fn (SistemaCliente $registro) => (string) $registro->cliente?->revenda_id === (string) $revendaId
            ))
            ->map(function (SistemaCliente $registro) {
                $cliente = $registro->cliente;
                $valor = $cliente?->valor_mensal !== null ? (float) $cliente->valor_mensal : null;

                return [
                    'registro' => $registro,
                    'sistema' => $registro->sistema?->nome,
                    'sistema_modelo' => $registro->sistema,
                    'revenda' => $cliente?->revenda?->nome ?? ($cliente ? 'venda direta' : 'sem vínculo na matriz'),
                    'nome_no_sistema' => $registro->nome,
                    'nome_na_matriz' => $cliente?->nome,
                    'cliente' => $cliente,
                    'documento' => $registro->cpf_cnpj,
                    'tipo_contrato' => $cliente?->tipo_cliente,
                    'valor_mensal' => $valor,
                    'unidades' => (int) $registro->unidades_ativas,
                    'status' => $registro->status,
                    // Ativo lá dentro e sem valor contratado aqui: está sendo
                    // usado e não está sendo cobrado de ninguém.
                    'sem_contrato' => $cliente !== null && ($valor === null || $valor <= 0),
                    'sem_vinculo' => $cliente === null,
                ];
            })
            ->sortBy([
                fn ($a, $b) => strcmp((string) $a['revenda'], (string) $b['revenda']),
                fn ($a, $b) => strcmp((string) $a['nome_no_sistema'], (string) $b['nome_no_sistema']),
            ])
            ->values();
    }

    /**
     * Agrupa por revenda, e dentro dela por sistema.
     *
     * O total de cada bloco é a soma das linhas logo abaixo dele — um total
     * que não confere com o que está à vista destrói a confiança na tela
     * inteira, mesmo quando o número certo é o de cima.
     */
    private function agrupar(Collection $linhas): Collection
    {
        return $linhas
            ->groupBy('revenda')
            ->map(fn (Collection $daRevenda) => [
                'contratado' => (float) $daRevenda->sum('valor_mensal'),
                'unidades' => (int) $daRevenda->sum('unidades'),
                'clientes' => $daRevenda->count(),
                'sem_contrato' => $daRevenda->where('sem_contrato', true)->count(),
                'porSistema' => $daRevenda->groupBy('sistema'),
            ]);
    }
}
