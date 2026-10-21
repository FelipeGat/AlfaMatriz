<?php

namespace App\Services\Secretario\Email;

/** A conexão crua com o servidor IMAP — trocável no teste por respostas gravadas. */
interface TransporteImap
{
    /** @param 'ssl'|'tls' $seguranca */
    public function abrir(string $host, int $porta, string $seguranca, int $timeout): void;

    /** STARTTLS já negociado no protocolo: liga a criptografia no socket. */
    public function ligarTls(): void;

    public function escrever(string $dados): void;

    /** Uma linha sem o CRLF; null no fim da conexão. */
    public function lerLinha(): ?string;

    public function lerBytes(int $n): string;

    public function fechar(): void;
}
