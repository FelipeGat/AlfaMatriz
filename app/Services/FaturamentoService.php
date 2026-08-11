<?php

namespace App\Services;

use App\Models\ClienteModulo;
use App\Models\Cobranca;
use App\Models\FaturamentoSnapshot;
use App\Models\Revenda;
use App\Models\Sistema;
use Carbon\Carbon;

class FaturamentoService
{
    /**
     * Gera o faturamento mensal: para cada revenda, soma o custo de licenciamento
     * de todos os sistemas que ela usa (baseado na contagem de clientes ativos
     * por sistema e no tier de atacado aplicável) e consolida em UMA única
     * cobrança por revenda/competência — não uma por cliente.
     *
     * Idempotente: se já existe snapshot/cobrança para a competência, pula.
     * Escopo: só clientes vinculados a uma revenda. Clientes diretos continuam
     * sendo cobrados manualmente pela tela de Receitas.
     */
    public function gerarParaCompetencia(string $competencia): array
    {
        $mes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();
        $vencimento = $mes->copy()->endOfMonth()->addDays(5);

        $resultado = [];

        $revendas = Revenda::where('ativo', true)->get();

        foreach ($revendas as $revenda) {
            $breakdown = [];

            $sistemas = Sistema::where('ativo', true)
                ->whereHas('clientes', fn ($q) => $q->where('revenda_id', $revenda->id)->where('clientes.ativo', true)->where('cliente_sistema.ativo', true))
                ->get();

            foreach ($sistemas as $sistema) {
                $jaExiste = FaturamentoSnapshot::where('competencia', $competencia)
                    ->where('sistema_id', $sistema->id)
                    ->where('revenda_id', $revenda->id)
                    ->exists();

                if ($jaExiste) {
                    $breakdown[] = ['sistema' => $sistema->nome, 'status' => 'ja_gerado'];

                    continue;
                }

                $clientesAtivos = $sistema->clientes()
                    ->where('revenda_id', $revenda->id)
                    ->where('clientes.ativo', true)
                    ->where('cliente_sistema.ativo', true)
                    ->get(['clientes.id', 'clientes.nome']);

                $qtd = $clientesAtivos->count();

                $tier = $sistema->tierParaVolume($qtd, $revenda->id);

                if (! $tier) {
                    $breakdown[] = [
                        'sistema' => $sistema->nome, 'status' => 'sem_tier_compativel',
                        'clientes_ativos' => $qtd,
                    ];

                    continue;
                }

                $licenciamento = $tier->calcularMensalidade($qtd);

                // Módulos são cobrados à parte da licença. O valor é o que a
                // Alfa registra ao ativar o módulo — só `super_admin` tem essa
                // tela no AlfaControl, então é preço de atacado (Alfa→revenda)
                // e entra na fatura. Se um dia a revenda passar a registrar o
                // preço de varejo dela, este é o ponto a rever.
                $modulos = $this->modulosDaCompetencia($sistema, $clientesAtivos->pluck('id'), $competencia);
                $valor = $licenciamento + $modulos['total'];

                $snapshot = FaturamentoSnapshot::create([
                    'competencia' => $competencia,
                    'sistema_id' => $sistema->id,
                    'revenda_id' => $revenda->id,
                    'clientes_ativos' => $qtd,
                    'valor_unitario' => $qtd > 0 ? round($licenciamento / $qtd, 2) : $licenciamento,
                    'valor_licenciamento' => $licenciamento,
                    'valor_modulos' => $modulos['total'],
                    'detalhe_modulos' => $modulos['detalhe'] ?: null,
                    'total' => $valor,
                ]);

                $breakdown[] = [
                    'status' => 'gerado',
                    'sistema' => $sistema->nome,
                    'tier' => $tier->nome,
                    'clientes_ativos' => $qtd,
                    'clientes' => $clientesAtivos->pluck('nome')->all(),
                    'valor' => $valor,
                    'valor_licenciamento' => $licenciamento,
                    'valor_modulos' => $modulos['total'],
                    'modulos' => $modulos['detalhe'],
                    'snapshot_id' => $snapshot->id,
                ];
            }

            $totalRevenda = collect($breakdown)->where('status', 'gerado')->sum('valor');

            if ($totalRevenda <= 0) {
                $resultado[$revenda->nome] = $breakdown;

                continue;
            }

            $cobrancaExistente = Cobranca::where('revenda_id', $revenda->id)
                ->where('competencia', $competencia)
                ->where('tipo', 'locacao_sistema')
                ->first();

            if ($cobrancaExistente) {
                $resultado[$revenda->nome] = array_merge($breakdown, [['status' => 'cobranca_ja_existe', 'cobranca_id' => $cobrancaExistente->id]]);

                continue;
            }

            $cobranca = Cobranca::create([
                'revenda_id' => $revenda->id,
                'descricao' => "Licenciamento de sistemas Alfa — competência {$mes->translatedFormat('m/Y')}",
                'valor' => $totalRevenda,
                'data_vencimento' => $vencimento->toDateString(),
                'status' => 'pendente',
                'tipo' => 'locacao_sistema',
                'competencia' => $competencia,
                'detalhamento' => collect($breakdown)->where('status', 'gerado')->values()->all(),
            ]);

            FaturamentoSnapshot::where('competencia', $competencia)
                ->where('revenda_id', $revenda->id)
                ->whereIn('id', collect($breakdown)->where('status', 'gerado')->pluck('snapshot_id'))
                ->update(['cobranca_id' => $cobranca->id]);

            $resultado[$revenda->nome] = ['cobranca_id' => $cobranca->id, 'total' => $totalRevenda, 'detalhamento' => $breakdown];
        }

        return $resultado;
    }

    /**
     * Os módulos que esses clientes tinham vigentes na competência.
     *
     * "Vigente" é ter começado até o fim do mês e não ter terminado antes do
     * começo dele — um módulo contratado no dia 20 entra na competência, e um
     * encerrado no mês anterior não.
     *
     * Só `status = ativo`: contratação suspensa ou encerrada não é receita
     * corrente. Valor nulo soma zero — o campo é opcional no AlfaControl, e
     * módulo ativado sem preço é lacuna de cadastro, não erro de cálculo.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $clienteIds
     * @return array{total: float, detalhe: array<int, array<string, mixed>>}
     */
    private function modulosDaCompetencia(Sistema $sistema, $clienteIds, string $competencia): array
    {
        if ($clienteIds->isEmpty()) {
            return ['total' => 0.0, 'detalhe' => []];
        }

        $inicioDoMes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();

        $contratacoes = ClienteModulo::query()
            ->with('modulo')
            ->whereIn('cliente_id', $clienteIds)
            ->where('status', 'ativo')
            ->whereHas('modulo', fn ($q) => $q->where('sistema_id', $sistema->id))
            ->get()
            ->filter(fn (ClienteModulo $c) => $c->vigenteEm($inicioDoMes));

        $detalhe = $contratacoes
            ->groupBy(fn (ClienteModulo $c) => $c->modulo->codigo)
            ->map(fn ($doModulo, $codigo) => [
                'codigo' => $codigo,
                'nome' => $doModulo->first()->modulo->nome,
                'clientes' => $doModulo->count(),
                'valor' => (float) $doModulo->sum('valor_mensal'),
            ])
            ->sortBy('codigo')
            ->values()
            ->all();

        return [
            'total' => (float) $contratacoes->sum('valor_mensal'),
            'detalhe' => $detalhe,
        ];
    }
}
