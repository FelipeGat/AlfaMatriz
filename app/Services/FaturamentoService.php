<?php

namespace App\Services;

use App\Models\ClienteModulo;
use App\Models\Cobranca;
use App\Models\FaturamentoSnapshot;
use App\Models\Modulo;
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

            $sistemas = Sistema::produtos()->where('ativo', true)
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
     * O que o fechamento cobraria das revendas nesta competência, sem gravar
     * nada — a mesma conta de `gerarParaCompetencia`: licenciamento pelo tier
     * de cada revenda mais os módulos vigentes no mês.
     *
     * Existe porque o Centro de Controle precisa mostrar receita recorrente
     * ANTES de alguém rodar o fechamento. Até existir, o card zerava todo dia
     * 1º — como se a receita tivesse evaporado na virada do mês.
     *
     * Ignora snapshot já gravado de propósito: a pergunta aqui é "quanto vale
     * o contratado hoje", não "quanto ainda falta gerar". Sistema sem tier
     * compatível fica de fora, pelo mesmo motivo de sempre — cobrar por ele
     * seria inventar um preço que ninguém configurou.
     *
     * @return array{total: float, porRevenda: array<int, float>}
     */
    public function previsaoDaCompetencia(?string $competencia = null): array
    {
        $competencia ??= now()->format('Y-m');

        $porRevenda = [];

        // O catálogo inteiro UMA vez, com o que a conta lê de cada produto: os
        // clientes (para o volume), os tiers (para o preço) e as contratações
        // de módulo (para a segunda parcela da linha).
        //
        // Era um laço aninhado — uma consulta de sistemas por revenda, e três
        // por sistema de cada revenda —, então o custo crescia com revendas ×
        // sistemas. A conta é a mesma; o que mudou é que ela passou a ser feita
        // sobre dado já na memória.
        $sistemas = Sistema::produtos()->where('ativo', true)
            ->with(['clientes', 'precosAtacado', 'modulos.contratacoes'])
            ->get();

        foreach (Revenda::where('ativo', true)->get() as $revenda) {
            $total = 0.0;

            foreach ($sistemas as $sistema) {
                $clientesAtivos = $sistema->clientesComVinculoAtivo()
                    ->where('revenda_id', $revenda->id)
                    ->values();

                // Fazia o papel do `whereHas` que escolhia os sistemas desta
                // revenda: sem cliente dela aqui, não há o que cobrar.
                if ($clientesAtivos->isEmpty()) {
                    continue;
                }

                $tier = $sistema->tierParaVolume($clientesAtivos->count(), $revenda->id);

                if (! $tier) {
                    continue;
                }

                $total += $tier->calcularMensalidade($clientesAtivos->count())
                    + $this->modulosDaCompetencia($sistema, $clientesAtivos->pluck('id'), $competencia)['total'];
            }

            if ($total > 0) {
                $porRevenda[$revenda->id] = $total;
            }
        }

        return [
            'total' => (float) array_sum($porRevenda),
            'porRevenda' => $porRevenda,
        ];
    }

    /**
     * Os módulos que esses clientes tinham vigentes na competência.
     *
     * "Vigente" é ter começado até o fim do mês e não ter terminado antes do
     * começo dele — um módulo contratado no dia 20 entra na competência, e um
     * encerrado no mês anterior não. A regra mora em
     * `ClienteModulo::vigentesNaCompetencia()`, porque a prévia da tela e o MRR
     * de atacado precisam somar exatamente o mesmo que a fatura.
     *
     * Valor nulo soma zero — o campo é opcional no AlfaControl, e módulo
     * ativado sem preço é lacuna de cadastro, não erro de cálculo.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $clienteIds
     * @return array{total: float, detalhe: array<int, array<string, mixed>>}
     */
    private function modulosDaCompetencia(Sistema $sistema, $clienteIds, string $competencia): array
    {
        $contratacoes = $this->contratacoesVigentes($sistema, $clienteIds, $competencia);

        if ($contratacoes->isEmpty()) {
            return ['total' => 0.0, 'detalhe' => []];
        }

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

    /**
     * As contratações vigentes deste sistema, da memória quando o chamador já
     * trouxe os módulos, do banco quando não.
     *
     * A REGRA é uma só, `ClienteModulo::vigenteEm()` — a mesma dos dois
     * caminhos. Duplicar o critério de vigência aqui seria abrir a porta para a
     * prévia e a fatura discordarem sobre o mesmo módulo, que é o erro que
     * `vigentesNaCompetencia()` existe para evitar.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $clienteIds
     * @return \Illuminate\Support\Collection<int, ClienteModulo>
     */
    private function contratacoesVigentes(Sistema $sistema, $clienteIds, string $competencia)
    {
        $clienteIds = collect($clienteIds);

        if ($clienteIds->isEmpty()) {
            return collect();
        }

        if (! $sistema->relationLoaded('modulos') || ! $sistema->modulos->every->relationLoaded('contratacoes')) {
            return ClienteModulo::vigentesNaCompetencia($sistema->id, $clienteIds, $competencia);
        }

        $inicioDoMes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();

        return $sistema->modulos
            // O módulo é pendurado em cada contratação porque o detalhamento o
            // nomeia pelo código: sem isto, cada linha iria buscá-lo sozinha e
            // o laço voltaria pela porta dos fundos.
            ->flatMap(fn (Modulo $modulo) => $modulo->contratacoes
                ->each(fn (ClienteModulo $c) => $c->setRelation('modulo', $modulo)))
            ->filter(fn (ClienteModulo $c) => $c->status === 'ativo')
            ->filter(fn (ClienteModulo $c) => $clienteIds->contains($c->cliente_id))
            ->filter(fn (ClienteModulo $c) => $c->vigenteEm($inicioDoMes))
            ->values();
    }
}
