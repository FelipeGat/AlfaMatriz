<?php

namespace App\Console\Commands;

use App\Jobs\DeliverTelegramMessage;
use App\Models\MessageDelivery;
use App\Services\TelegramClient;
use App\Services\TelegramIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramPoll extends Command
{
    protected $signature = 'central:telegram-poll {--once : Processa um ciclo para diagnóstico}';

    protected $description = 'Recebe mensagens privadas sem webhook; não modifica configurações do bot.';

    public function handle(TelegramClient $client, TelegramIngestor $ingestor): int
    {
        if (! config('central.telegram_enabled') || ! config('central.telegram_token')) {
            $this->error('Telegram não configurado.');

            return self::FAILURE;
        }
        $webhook = $client->call('getWebhookInfo');
        if (! $webhook['ok'] || ! empty($webhook['result']['url'])) {
            $this->error('Não foi possível confirmar bot sem webhook. Nenhuma configuração alterada.');

            return self::FAILURE;
        }
        do {
            $lock = Cache::lock('central:telegram-poll', 60);
            if (! $lock->get()) {
                $this->error('Outro polling está ativo.');

                return self::FAILURE;
            }
            $failed = false;
            try {
                MessageDelivery::where('status', 'sending')->where('updated_at', '<', now()->subMinute())->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted']);
                foreach (MessageDelivery::where('status', 'pending')->limit(100)->get() as $delivery) {
                    DeliverTelegramMessage::dispatch($delivery->id)->onQueue('telegram');
                }
                $offset = (int) (DB::table('central_states')->where('key', 'telegram_offset')->value('value') ?? 0);
                $result = $client->call('getUpdates', ['offset' => $offset, 'timeout' => $this->option('once') ? 0 : 10, 'allowed_updates' => ['message']], 20);
                if (! $result['ok']) {
                    $failed = true;
                    $this->warn('Telegram indisponível; aguardando nova tentativa.');
                } else {
                    $updates = $result['result'];
                    usort($updates, fn ($a, $b) => ($a['update_id'] ?? 0) <=> ($b['update_id'] ?? 0));
                    foreach ($updates as $update) {
                        if (! isset($update['update_id']) || ! is_int($update['update_id'])) {
                            continue;
                        }
                        $ingestor->ingest($update);
                        $offset = max($offset, $update['update_id'] + 1);
                        DB::table('central_states')->updateOrInsert(['key' => 'telegram_offset'], ['value' => (string) $offset]);
                    }
                }
            } catch (\Throwable) {
                $failed = true;
                $this->warn('Falha local no ciclo; cursor preservado para recuperação.');
            } finally {
                $lock->release();
            }
            if ($this->option('once')) {
                return $failed ? self::FAILURE : self::SUCCESS;
            }
            usleep($failed ? 5000000 : 250000);
        } while (true);
    }
}
