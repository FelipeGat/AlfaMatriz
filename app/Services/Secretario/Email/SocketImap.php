<?php

namespace App\Services\Secretario\Email;

use RuntimeException;

/**
 * Socket TLS de verdade. O certificado do servidor é VERIFICADO (padrão do
 * PHP): senha de caixa não trafega para quem não prova ser o servidor.
 */
class SocketImap implements TransporteImap
{
    /** @var resource|null */
    private $socket = null;

    /** `false` só no teste local com servidor de certificado autoassinado. */
    public function __construct(private bool $verificarCertificado = true) {}

    public function abrir(string $host, int $porta, string $seguranca, int $timeout): void
    {
        $esquema = $seguranca === 'ssl' ? 'ssl' : 'tcp';
        $contexto = stream_context_create(['ssl' => ['verify_peer' => $this->verificarCertificado, 'verify_peer_name' => $this->verificarCertificado,
            'allow_self_signed' => ! $this->verificarCertificado, 'SNI_enabled' => true, 'peer_name' => $host]]);
        // Só endereço público, e a conexão vai ao IP já conferido (sem uma
        // segunda resolução que o DNS poderia trocar). O teste local, com
        // servidor na rede interna, é o modo sem verificação.
        $destino = $host;
        if ($this->verificarCertificado) {
            $publicos = array_values(array_filter(@gethostbynamel($host) ?: [],
                fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false));
            if (! $publicos) {
                throw new RuntimeException('conexao');
            }
            $destino = $publicos[0];
        }
        $socket = @stream_socket_client("{$esquema}://{$destino}:{$porta}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $contexto);
        if (! $socket) {
            throw new RuntimeException('conexao');
        }
        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;
    }

    public function ligarTls(): void
    {
        if (! @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('tls');
        }
    }

    public function escrever(string $dados): void
    {
        if (@fwrite($this->socket, $dados) === false) {
            throw new RuntimeException('escrita');
        }
    }

    public function lerLinha(): ?string
    {
        $linha = @fgets($this->socket, 65536);
        if ($linha === false) {
            if (stream_get_meta_data($this->socket)['timed_out'] ?? false) {
                throw new RuntimeException('tempo');
            }

            return null;
        }

        return rtrim($linha, "\r\n");
    }

    public function lerBytes(int $n): string
    {
        $dados = '';
        while (strlen($dados) < $n) {
            $parte = @fread($this->socket, min(65536, $n - strlen($dados)));
            if ($parte === false || $parte === '') {
                throw new RuntimeException('leitura');
            }
            $dados .= $parte;
        }

        return $dados;
    }

    public function fechar(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
