<?php

namespace App\Console\Commands;

use App\Services\DespesaFixaService;
use Illuminate\Console\Command;

/**
 * Lança as despesas fixas do mês no dia 01 (Felipe, 18/10/2026): o fechamento
 * do último dia as lançava já vencidas — Hostinger no dia 05, pró-labore no 15 —
 * e o mês corrente ficava sem contas a pagar. Idempotente: o fechamento, que
 * continua rodando, encontra tudo já gerado.
 */
class GerarDespesasFixas extends Command
{
    protected $signature = 'despesas:gerar-fixas {competencia? : AAAA-MM, padrão o mês corrente}';

    protected $description = 'Lança as despesas fixas da competência (padrão: o mês corrente)';

    public function handle(DespesaFixaService $despesas): int
    {
        $competencia = $this->argument('competencia') ?? now()->format('Y-m');
        $r = collect($despesas->gerarParaCompetencia($competencia));
        foreach ($r->where('status', 'gerado') as $i) {
            $this->line("  {$i['descricao']}: R$ ".number_format($i['valor'], 2, ',', '.'));
        }
        $this->info("{$competencia}: {$r->where('status', 'gerado')->count()} lançada(s), {$r->where('status', 'ja_gerado')->count()} já existiam.");

        return self::SUCCESS;
    }
}
