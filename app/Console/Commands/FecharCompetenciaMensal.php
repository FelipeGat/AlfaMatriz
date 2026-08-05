<?php

namespace App\Console\Commands;

use App\Services\DespesaFixaService;
use App\Services\FaturamentoService;
use Illuminate\Console\Command;

class FecharCompetenciaMensal extends Command
{
    /**
     * Roda todo último dia do mês: gera o faturamento consolidado das
     * revendas (1 cobrança por revenda com base nos clientes ativos) e
     * as parcelas do mês das despesas fixas do centro de custo Alfa.
     */
    protected $signature = 'app:fechar-competencia-mensal {competencia? : AAAA-MM, padrão é o mês atual}';

    protected $description = 'Gera o faturamento das revendas e as despesas fixas do mês (fechamento mensal)';

    public function handle(FaturamentoService $faturamentoService, DespesaFixaService $despesaFixaService): int
    {
        $competencia = $this->argument('competencia') ?? now()->format('Y-m');

        $this->info("Fechando competência {$competencia}...");

        $this->line('');
        $this->line('== Faturamento das revendas ==');
        $faturamento = $faturamentoService->gerarParaCompetencia($competencia);
        foreach ($faturamento as $revenda => $resultado) {
            if (isset($resultado['cobranca_id'])) {
                $this->line("  {$revenda}: cobrança #{$resultado['cobranca_id']} — R$ ".number_format($resultado['total'], 2, ',', '.'));
            } else {
                $this->line("  {$revenda}: nada a gerar (sem clientes ativos elegíveis ou já gerado)");
            }
        }

        $this->line('');
        $this->line('== Despesas fixas ==');
        $despesas = $despesaFixaService->gerarParaCompetencia($competencia);
        $gerados = collect($despesas)->where('status', 'gerado');
        $existentes = collect($despesas)->where('status', 'ja_gerado')->count();
        foreach ($gerados as $item) {
            $this->line("  {$item['descricao']}: R$ ".number_format($item['valor'], 2, ',', '.'));
        }
        $this->line("  ({$existentes} já existiam para esta competência)");

        $this->line('');
        $this->info('Fechamento concluído.');

        return self::SUCCESS;
    }
}
