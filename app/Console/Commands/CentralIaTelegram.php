<?php

namespace App\Console\Commands;

use App\Services\CentralIa\TelegramApi;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Console\Command;
use App\Services\CentralIa\TelegramConsumerLock;
use Illuminate\Support\Facades\DB;

/**
 * O processo do bot da Central de IA: lê o Telegram e leva as entregas.
 *
 * Roda num processo PRÓPRIO, e não no scheduler nem no `queue:work` do
 * sistema: no ambiente local o banco é cópia da produção, e ligar o
 * agendador geral dispararia as integrações de verdade.
 *
 * Long polling, e não webhook: não exige porta aberta para a internet. O
 * Telegram aceita UM leitor por bot — dois leitores se derrubam com 409 —,
 * por isso a trava e o recuo diante do conflito.
 */
class CentralIaTelegram extends Command
{
    protected $signature = 'central-ia:telegram {--uma-vez : Um ciclo só, sem esperar por novidade}';

    protected $description = 'Ouve o bot da Central de IA e entrega ao Telegram as mensagens da conversa';

    private bool $parar = false;

    public function handle(TelegramApi $api, TelegramCanal $canal, TelegramConsumerLock $lock): int
    {
        if (! $api->configurado()) {
            $this->error('CENTRAL_IA_TELEGRAM_TOKEN não configurado.');

            return self::FAILURE;
        }

        try {
            if (! $lock->acquire()) {
                $this->warn('Outro consumidor da Central está ativo. Nada foi lido.');
                return self::FAILURE;
            }

            $api->protegerConsumidor(fn () => $lock->assertOwned());

            // A exclusividade começa antes de qualquer chamada ao Telegram.
            $webhook = $api->chamar('getWebhookInfo');
            if (! $webhook['ok'] || filled($webhook['resultado']['url'] ?? null)) {
                $this->error('O bot tem webhook configurado (ou não respondeu). Nada foi alterado.');
                return self::FAILURE;
            }

            $this->trap([SIGTERM, SIGINT], fn () => $this->parar = true);
            $this->info('Ouvindo @'.config('central_ia.telegram.bot').'.');
            do {
                $lock->assertOwned();
                $pausa = $this->ciclo($api, $canal, $lock);
                if ($this->option('uma-vez')) {
                    return $pausa === null ? self::SUCCESS : self::FAILURE;
                }
                if ($pausa) {
                    sleep($pausa);
                }
            } while (! $this->parar);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Consumidor encerrado com segurança ('.class_basename($e).').');
            return self::FAILURE;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                $this->warn('Conexão da trava encerrada ('.class_basename($e).').');
            }
        }
    }

    /**
     * @return int|null segundos de pausa antes do próximo ciclo; null = segue direto
     */
    private function ciclo(TelegramApi $api, TelegramCanal $canal, TelegramConsumerLock $lock): ?int
    {
        try {
            app(\App\Services\CentralIa\ProgressoService::class)->publicar();
            $lock->assertOwned();
            $canal->entregarPendentes();
            $canal->sinalizarAtividade();

            $proximo = (int) DB::table('central_ia_telegram_cursor')->value('proximo_update_id');
            $leitura = $api->chamar('getUpdates', [
                'offset' => $proximo,
                'timeout' => $this->option('uma-vez') ? 0 : min(3, config('central_ia.telegram.espera_segundos')),
                'allowed_updates' => ['message'],
            ], config('central_ia.telegram.espera_segundos') + 10);

            if (! $leitura['ok']) {
                if ($leitura['erro'] === 'conflito') {
                    $this->warn('Outro consumidor está lendo este bot (409). Desligue-o; tentando de novo em 30 s.');

                    return 30;
                }

                return $leitura['esperar'] ?? 5;
            }

            $updates = collect($leitura['resultado'])->filter(fn ($u) => is_int($u['update_id'] ?? null))->sortBy('update_id');

            foreach ($updates as $update) {
                $lock->assertOwned();
                $canal->receber($update);
                $lock->assertOwned();
                // O cursor anda update a update: uma queda no meio relê só o
                // que faltou, e o único do update_id segura a repetição.
                $this->salvarCursor($update['update_id'] + 1);
            }

            $lock->assertOwned();
            $canal->entregarPendentes();

            return null;
        } catch (\Throwable $e) {
            // Só a classe: a mensagem de um erro HTTP pode trazer a URL com o token.
            $this->warn('Falha no ciclo do bot ('.class_basename($e).'). O cursor foi preservado.');

            return 5;
        }
    }

    private function salvarCursor(int $proximo): void
    {
        $id = DB::table('central_ia_telegram_cursor')->value('id');

        $id
            ? DB::table('central_ia_telegram_cursor')->where('id', $id)->update(['proximo_update_id' => $proximo, 'updated_at' => now()])
            : DB::table('central_ia_telegram_cursor')->insert(['proximo_update_id' => $proximo, 'created_at' => now(), 'updated_at' => now()]);
    }
}
