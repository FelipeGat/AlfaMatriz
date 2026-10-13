<?php

namespace App\Services\CentralIa;

use Illuminate\Support\Facades\Http;

/**
 * A única porta para a API do bot da Central.
 *
 * O token viaja na URL (`/bot<token>/método`), e a mensagem de exceção do
 * cliente HTTP repete a URL inteira: nada daqui é logado nem repassado, e quem
 * chama recebe só um código.
 *
 * Os códigos separam o que importa para não dobrar mensagem:
 *  - `recusada`: o Telegram respondeu que não — não chegou, pode corrigir e tentar;
 *  - `incerta`: rede caiu ou 5xx — PODE ter chegado, não reenviar sozinho;
 *  - `conflito`: 409, outro consumidor (ou webhook) está lendo o mesmo bot;
 *  - `limite`: 429, não chegou; tentar depois.
 */
class TelegramApi
{
    private ?\Closure $verificarConsumidor = null;

    public function protegerConsumidor(callable $verificar): void
    {
        $this->verificarConsumidor = \Closure::fromCallable($verificar);
    }

    public function verificarConsumidor(): void
    {
        if ($this->verificarConsumidor) {
            ($this->verificarConsumidor)();
        }
    }

    /** Só o consumidor usa esta URL; exceções nunca expõem o token. */
    public function baixar(string $fileId, string $destino): bool
    {
        $info = $this->chamar('getFile', ['file_id' => $fileId]);
        $caminho = $info['resultado']['file_path'] ?? null;
        if (! $info['ok'] || ! is_string($caminho) || ! preg_match('#^[A-Za-z0-9_./-]+$#', $caminho)
            || str_contains($caminho, '..') || ($info['resultado']['file_size'] ?? 0) > AnexosService::MAX_BYTES) {
            return false;
        }
        try {
            $r = Http::connectTimeout(5)->timeout(45)->withOptions(['stream' => true])
                ->get('https://api.telegram.org/file/bot'.config('central_ia.telegram.token').'/'.$caminho);
            if (! $r->successful()) {
                return false;
            }
            $body = $r->toPsrResponse()->getBody();
            $saida = fopen($destino, 'wb');
            $tamanho = 0;
            try {
                while (! $body->eof()) {
                    $parte = $body->read(65536);
                    $tamanho += strlen($parte);
                    if ($tamanho > AnexosService::MAX_BYTES) {
                        return false;
                    }
                    fwrite($saida, $parte);
                }
            } finally {
                fclose($saida);
                $body->close();
            }
            return $tamanho > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function configurado(): bool
    {
        return filled(config('central_ia.telegram.token'));
    }

    /**
     * @return array{ok: bool, resultado?: mixed, erro?: string, esperar?: int}
     */
    /** Manda um arquivo (sendPhoto/sendDocument). Mesmo contrato de `chamar`. */
    public function enviarArquivo(string $metodo, string $campo, string $conteudo, string $nome, array $dados, int $timeout = 60): array
    {
        $this->verificarConsumidor();
        if (! $this->configurado()) return ['ok' => false, 'erro' => 'nao_configurado'];
        try {
            $resposta = Http::connectTimeout(5)->timeout($timeout)->attach($campo, $conteudo, $nome)
                ->post('https://api.telegram.org/bot'.config('central_ia.telegram.token').'/'.$metodo, $dados);
        } catch (\Throwable) {
            return ['ok' => false, 'erro' => 'incerta'];
        }
        $corpo = $resposta->json();

        return $resposta->successful() && ($corpo['ok'] ?? false) === true ? ['ok' => true]
            : ['ok' => false, 'erro' => $resposta->status() === 429 ? 'limite' : ($resposta->clientError() ? 'recusada' : 'incerta')];
    }

    public function chamar(string $metodo, array $dados = [], int $timeout = 15): array
    {
        $this->verificarConsumidor();
        if (! $this->configurado()) {
            return ['ok' => false, 'erro' => 'nao_configurado'];
        }

        try {
            $resposta = Http::connectTimeout(5)->timeout($timeout)
                ->post('https://api.telegram.org/bot'.config('central_ia.telegram.token').'/'.$metodo, $dados);
        } catch (\Throwable $e) {
            // Falha de CONEXÃO (DNS, recusa, tempo esgotado ao conectar) quer
            // dizer que nada saiu: dá para tentar de novo sem duplicar. Em
            // 13/10/2026 a resposta de um assunto ficou "incerta" num soluço de
            // rede e a pessoa teve de perguntar "travou?".
            $msg = preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot[REDACTED]', $e->getMessage());
            $naoSaiu = (bool) preg_match('/cURL error (6|7)\b|Connection timed out after|Could not resolve|Failed to connect|Connection refused/i', $msg);
            \Illuminate\Support\Facades\Log::warning('Telegram: chamada falhou', ['metodo' => $metodo, 'nao_saiu' => $naoSaiu, 'erro' => mb_substr($msg, 0, 300)]);

            return ['ok' => false, 'erro' => $naoSaiu ? 'nao_enviada' : 'incerta'];
        }

        $corpo = $resposta->json();

        if ($resposta->successful() && is_array($corpo) && ($corpo['ok'] ?? false) === true) {
            return ['ok' => true, 'resultado' => $corpo['result'] ?? null];
        }

        return match (true) {
            $resposta->status() === 409 => ['ok' => false, 'erro' => 'conflito'],
            $resposta->status() === 429 => ['ok' => false, 'erro' => 'limite', 'esperar' => (int) ($corpo['parameters']['retry_after'] ?? 5)],
            $resposta->clientError() => ['ok' => false, 'erro' => 'recusada'],
            default => ['ok' => false, 'erro' => 'incerta'],
        };
    }
}
