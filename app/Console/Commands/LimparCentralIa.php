<?php

namespace App\Console\Commands;

use App\Services\CentralIa\Limpeza;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Apaga da Central de IA o que passou do prazo (T-378). As regras e os prazos
 * moram em `Limpeza` e em `central_ia.retencao`; aqui só a porta.
 *
 * `--dry-run` conta o que sairia sem apagar nada — é o jeito de ver, antes
 * da primeira volta numa base de verdade, quanto vai embora. A volta de
 * verdade deixa a contagem no log, para se saber depois o que sumiu e quando.
 */
class LimparCentralIa extends Command
{
    protected $signature = 'central-ia:limpar {--dry-run : Mostra o que apagaria, sem apagar}';

    protected $description = 'Apaga anexos, prévias e histórico da Central de IA que passaram do prazo';

    private const ROTULOS = [
        'anexos' => 'Anexos (arquivo e registro)',
        'previas' => 'Prévias (arquivo e registro)',
        'eventos' => 'Eventos',
        'entregas' => 'Entregas ao Telegram',
        'execucoes' => 'Pedidos a agentes',
        'mensagens' => 'Mensagens',
    ];

    public function handle(Limpeza $limpeza): int
    {
        $ensaio = (bool) $this->option('dry-run');
        try {
            $r = $limpeza->executar($ensaio);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($ensaio ? 'Ensaio: nada foi apagado. Sairia:' : 'Apagado:');
        $this->table(['O quê', 'Quantos'], collect(self::ROTULOS)->map(fn ($rotulo, $k) => [$rotulo, $r[$k]])->values()->all());
        if (! $ensaio) {
            Log::info('central-ia:limpar apagou o que passou do prazo', $r + [
                'anexos_dias' => (int) config('central_ia.retencao.anexos_dias'),
                'historico_dias' => (int) config('central_ia.retencao.historico_dias'),
            ]);
        }

        return self::SUCCESS;
    }
}
