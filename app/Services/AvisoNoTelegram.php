<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posta uma mensagem no grupo de alertas do Telegram (#219).
 *
 * O token e o chat vêm do `.env` do servidor (`TELEGRAM_BOT_TOKEN`,
 * `TELEGRAM_CHAT_ID_ALERTAS`), e não do chaveiro do Mac que o
 * `deploy/publicar-changelog.sh` usa: quem posta aqui é o servidor, e o
 * servidor não tem chaveiro.
 *
 * NUNCA lança. O aviso é o último passo de algo que já aconteceu — a tarefa
 * já foi aberta —, e um Telegram fora do ar ou um `.env` sem a chave não
 * pode derrubar o recebimento dos erros, que é o que importa. Sem
 * configuração, registra no log e devolve `false`.
 */
class AvisoNoTelegram
{
    /** O Telegram recusa mensagem acima disto — ver `deploy/publicar-changelog.sh`. */
    public const LIMITE = 4096;

    public function configurado(): bool
    {
        return filled(config('services.telegram.bot_token')) && filled(config('services.telegram.chat_id_alertas'));
    }

    /** @param  string  $html  HTML do Telegram (`<b>`, `<i>`, `<code>`, `<a>`), já escapado */
    public function enviar(string $html): bool
    {
        if (! $this->configurado()) {
            Log::warning('Aviso do Telegram não enviado: falta TELEGRAM_BOT_TOKEN ou TELEGRAM_CHAT_ID_ALERTAS no .env.', [
                'mensagem' => mb_substr(strip_tags($html), 0, 300),
            ]);

            return false;
        }

        // Cortar HTML no meio de uma tag faria o Telegram recusar a mensagem
        // inteira; quem monta o texto já resume cada parte, e isto é só a rede
        // de segurança — por isso corta o TEXTO, sem as tags.
        if (mb_strlen($html) > self::LIMITE) {
            $html = htmlspecialchars(mb_substr(strip_tags($html), 0, self::LIMITE - 10), ENT_NOQUOTES).' […]';
        }

        try {
            $resposta = Http::timeout(10)->asForm()->post(
                'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage',
                [
                    'chat_id' => config('services.telegram.chat_id_alertas'),
                    'text' => $html,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => 'true',
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Aviso do Telegram falhou: '.$e->getMessage());

            return false;
        }

        // O Telegram responde 200 com `"ok": false` em alguns erros: confere o
        // corpo, como o script do changelog faz.
        if (! $resposta->successful() || $resposta->json('ok') !== true) {
            Log::warning('Aviso do Telegram recusado.', [
                'status' => $resposta->status(),
                'descricao' => $resposta->json('description'),
            ]);

            return false;
        }

        return true;
    }
}
