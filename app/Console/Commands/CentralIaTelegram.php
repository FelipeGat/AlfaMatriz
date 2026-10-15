<?php

namespace App\Console\Commands;

use App\Models\CentralIaTelegramBot;
use App\Services\CentralIa\TelegramApi;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Console\Command;
use App\Services\CentralIa\TelegramConsumerLock;

/**
 * O processo dos bots da Central de IA: lê o Telegram e leva as entregas.
 *
 * Roda num processo PRÓPRIO, e não no scheduler nem no `queue:work` do
 * sistema: no ambiente local o banco é cópia da produção, e ligar o
 * agendador geral dispararia as integrações de verdade.
 *
 * Cada pessoa tem o PRÓPRIO bot (T-374), cadastrado nos Ajustes da Central;
 * este processo ouve todos os que foram cadastrados NESTE servidor (uma cópia
 * do banco em outro lugar não os lê). Sem bot nenhum, ele fica de pé e espera.
 *
 * Long polling, e não webhook: não exige porta aberta para a internet. O
 * Telegram aceita UM leitor por bot — dois leitores se derrubam com 409 —,
 * por isso a trava (um processo por banco) e, por bot, o recuo diante do
 * conflito: o bot de uma pessoa lido em outro lugar não para os dos outros.
 */
class CentralIaTelegram extends Command
{
    protected $signature = 'central-ia:telegram {--uma-vez : Um ciclo só, sem esperar por novidade}';

    protected $description = 'Ouve os bots da Central de IA e entrega ao Telegram as mensagens da conversa';

    private bool $parar = false;

    /** @var array<int, int> bot => instante (unix) até quando ele fica de lado. */
    private array $deLado = [];

    /** @var array<int, true> bots cujo webhook já foi conferido neste processo. */
    private array $conferidos = [];

    /** @var array<string, int> falhas seguidas de cada update ("bot:update"), no processo. */
    private array $falhasPorUpdate = [];

    public function handle(TelegramApi $api, TelegramCanal $canal, TelegramConsumerLock $lock): int
    {
        try {
            if (! $lock->acquire()) {
                $this->warn('Outro consumidor da Central está ativo. Nada foi lido.');
                return self::FAILURE;
            }

            $api->protegerConsumidor(fn () => $lock->assertOwned());

            $this->trap([SIGTERM, SIGINT], fn () => $this->parar = true);
            $this->info('Ouvindo os bots da Central cadastrados neste servidor.');
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
     * Uma volta: entrega o que está pendente e lê cada bot uma vez.
     *
     * @return int|null segundos de pausa antes do próximo ciclo; null = segue direto
     */
    private function ciclo(TelegramApi $api, TelegramCanal $canal, TelegramConsumerLock $lock): ?int
    {
        try {
            app(\App\Services\CentralIa\ProgressoService::class)->publicar();
            $lock->assertOwned();
            $canal->entregarPendentes();
            $canal->sinalizarAtividade();

            $bots = CentralIaTelegramBot::desteServidor()
                ->where('situacao', '!=', CentralIaTelegramBot::TOKEN_INVALIDO)->orderBy('id')->get()
                ->filter(fn ($b) => ($this->deLado[$b->id] ?? 0) <= time());
            // Um bot só: long polling curto, como antes. Vários: cada um é
            // lido sem espera, e a volta toda pausa um pouco quando nada chegou
            // — senão o último da fila esperaria a soma das esperas.
            $espera = $this->option('uma-vez') || $bots->count() !== 1 ? 0 : min(3, config('central_ia.telegram.espera_segundos'));
            $chegou = false;
            $falha = null;

            foreach ($bots as $bot) {
                $lock->assertOwned();
                $r = $this->lerBot($api->doBot($bot), $bot, $canal, $lock, $espera);
                $chegou = $chegou || $r === true;
                if ($r === false) {
                    $falha = 5;
                }
            }

            $lock->assertOwned();
            $canal->entregarPendentes();
            $canal->entregarPrevias();

            if ($this->option('uma-vez')) {
                return $falha;
            }

            return $chegou ? null : ($bots->count() === 1 ? $falha : ($falha ?? 1));
        } catch (\Throwable $e) {
            // Só a classe: a mensagem de um erro HTTP pode trazer a URL com o token.
            $this->warn('Falha no ciclo dos bots ('.class_basename($e).'). Os cursores foram preservados.');

            return 5;
        }
    }

    /**
     * @return bool|null true = chegou update; null = nada chegou; false = falhou
     */
    private function lerBot(TelegramApi $api, CentralIaTelegramBot $bot, TelegramCanal $canal, TelegramConsumerLock $lock, int $espera): ?bool
    {
        if (! isset($this->conferidos[$bot->id])) {
            $webhook = $api->chamar('getWebhookInfo');
            if (! $webhook['ok']) {
                return $this->falhou($bot, $webhook);
            }
            if (filled($webhook['resultado']['url'] ?? null)) {
                // Outro sistema lê este bot por webhook: nada é alterado nele.
                $this->marcar($bot, CentralIaTelegramBot::WEBHOOK);
                $this->deLado[$bot->id] = time() + 300;
                $this->warn("O bot @{$bot->usuario} tem webhook configurado. Ficou de lado.");

                return false;
            }
            $this->conferidos[$bot->id] = true;
        }

        $leitura = $api->chamar('getUpdates', [
            'offset' => $bot->proximo_update_id,
            'timeout' => $espera,
            'allowed_updates' => ['message'],
        ], $espera + 10);

        if (! $leitura['ok']) {
            return $this->falhou($bot, $leitura);
        }
        if ($bot->situacao !== CentralIaTelegramBot::OK) {
            $this->marcar($bot, CentralIaTelegramBot::OK);
        }

        $updates = collect($leitura['resultado'])->filter(fn ($u) => is_int($u['update_id'] ?? null))->sortBy('update_id');

        foreach ($updates as $update) {
            $lock->assertOwned();
            $chave = $bot->id.':'.$update['update_id'];
            try {
                $canal->receber($update, $bot);
                unset($this->falhasPorUpdate[$chave]);
            } catch (\Throwable $e) {
                // Um update que sempre explode travava o bot para sempre:
                // o cursor ficava parado e ele era relido a cada 5 s (com
                // download e aviso repetidos). Na terceira falha, passa.
                $n = $this->falhasPorUpdate[$chave] = ($this->falhasPorUpdate[$chave] ?? 0) + 1;
                // Nas duas primeiras, só ESTE bot para neste ciclo, com o cursor
                // parado. Relançar abortava a volta inteira, e o update ruim de
                // uma pessoa atrasava os bots e as entregas de todas (revisão
                // da T-374).
                if ($n < 3) {
                    $this->warn("Update {$update['update_id']} do bot @{$bot->usuario} falhou (".class_basename($e).'); tento de novo em 5 s.');
                    // Com o bot de outra pessoa recebendo, a volta segue sem
                    // pausa e as três tentativas saíam em segundos — um
                    // tropeço passageiro (banco, rede) descartava a mensagem.
                    $this->deLado[$bot->id] = time() + 5;

                    return false;
                }
                $this->warn("Update {$update['update_id']} do bot @{$bot->usuario} falhou 3 vezes (".class_basename($e).'); seguindo adiante.');
                \App\Services\CentralIa\Eventos::registrar($bot->user_id, 'telegram_update_descartado',
                    "Mensagem do Telegram {$update['update_id']} falhou 3 vezes (".class_basename($e).') e foi deixada para trás; reenvie se precisar.', ['origem' => 'telegram'], 'erro');
            }
            $lock->assertOwned();
            // O cursor anda update a update: uma queda no meio relê só o
            // que faltou, e o único do update_id segura a repetição.
            if (! $this->gravar($bot, ['proximo_update_id' => $update['update_id'] + 1])) {
                return null;
            }
        }

        return $updates->isNotEmpty() ? true : null;
    }

    private function falhou(CentralIaTelegramBot $bot, array $r): bool
    {
        match ($r['erro'] ?? null) {
            'conflito' => [$this->marcar($bot, CentralIaTelegramBot::CONFLITO), $this->deLado[$bot->id] = time() + 30,
                $this->warn("Outro consumidor está lendo o bot @{$bot->usuario} (409). Tentando de novo em 30 s.")],
            // 401/404: token revogado no @BotFather. Volta quando a pessoa
            // cadastrar o token novo pela tela.
            'recusada' => in_array($r['status'] ?? null, [401, 404], true) ? [$this->marcar($bot, CentralIaTelegramBot::TOKEN_INVALIDO),
                $this->warn("O Telegram recusou o token do bot @{$bot->usuario}. Ele fica parado até a pessoa cadastrar de novo.")] : null,
            'limite' => $this->deLado[$bot->id] = time() + (int) ($r['esperar'] ?? 5),
            default => null,
        };

        return false;
    }

    private function marcar(CentralIaTelegramBot $bot, string $situacao): void
    {
        $this->gravar($bot, ['situacao' => $situacao, 'situacao_em' => now()]);
    }

    /**
     * Grava no bot SÓ se ele continua o mesmo que este ciclo leu. A pessoa pode
     * trocar de bot ou renovar o token pela tela no meio do ciclo (o
     * `updateOrCreate` reaproveita a linha): um `save()` do modelo carregado
     * antes punha o cursor do bot antigo na linha do novo, e o Telegram dava
     * por lido o /start dele; ou marcava "token inválido" o token que acabou de
     * ser renovado (revisão da T-374). O `updated_at` é a versão: a tela o
     * muda ao gravar. Mudou: não grava, e o próximo ciclo relê a linha.
     */
    private function gravar(CentralIaTelegramBot $bot, array $campos): bool
    {
        $agora = now();
        $n = CentralIaTelegramBot::whereKey($bot->id)
            ->where('telegram_bot_id', $bot->telegram_bot_id)
            ->where('updated_at', $bot->updated_at)
            ->update($campos + ['updated_at' => $agora]);
        if ($n === 0) {
            return false;
        }
        $bot->forceFill($campos + ['updated_at' => $agora])->syncOriginal();

        return true;
    }
}
