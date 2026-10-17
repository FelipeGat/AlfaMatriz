<?php

namespace Tests\Feature\Secretario;

use App\Services\Secretario\Email\TransporteImap;

/**
 * Um servidor IMAP em memória, só o bastante para o ClienteImap. Guarda cada
 * comando recebido — é assim que o teste prova que o Matriz só lê.
 */
class ImapFalso implements TransporteImap
{
    public array $comandos = [];
    public bool $cair = false;
    private array $saida = [];

    /** @param array<string, array{uidvalidity: int, mensagens: array<int, array{cabecalho: string, texto: string, data: string}>}> $pastas */
    public function __construct(public array $pastas, public string $senha = 'segredo') {}

    public function abrir(string $host, int $porta, string $seguranca, int $timeout): void
    {
        if ($this->cair) {
            throw new \RuntimeException('conexao');
        }
        $this->saida = ['* OK IMAP falso pronto'];
    }

    public function ligarTls(): void {}

    public function escrever(string $dados): void
    {
        [$tag, $cmd] = explode(' ', rtrim($dados, "\r\n"), 2);
        $this->comandos[] = preg_replace('/^LOGIN .*/', 'LOGIN ***', $cmd);
        $ok = fn () => $this->saida[] = "{$tag} OK feito";
        if (str_starts_with($cmd, 'LOGIN')) {
            str_contains($cmd, '"'.$this->senha.'"') ? $ok() : $this->saida[] = "{$tag} NO [AUTHENTICATIONFAILED] recusado";
        } elseif ($cmd === 'LIST "" "*"') {
            foreach (array_keys($this->pastas) as $p) {
                $this->saida[] = '* LIST (\\HasNoChildren) "/" "'.$p.'"';
            }
            $ok();
        } elseif (preg_match('/^EXAMINE "(.+)"$/', $cmd, $m)) {
            $p = $this->pastas[$m[1]] ?? null;
            if (! $p) {
                $this->saida[] = "{$tag} NO pasta";

                return;
            }
            $this->saida[] = '* '.count($p['mensagens']).' EXISTS';
            $this->saida[] = '* OK [UIDVALIDITY '.$p['uidvalidity'].'] ok';
            $this->saida[] = "{$tag} OK [READ-ONLY] EXAMINE";
            $this->atual = $m[1];
        } elseif (preg_match('/^UID SEARCH (SINCE .+|UID (\d+):\*)$/', $cmd, $m)) {
            $uids = array_keys($this->pastas[$this->atual]['mensagens']);
            if (isset($m[2])) {
                $uids = array_filter($uids, fn ($u) => $u >= (int) $m[2]) ?: [max(array_keys($this->pastas[$this->atual]['mensagens']) ?: [0])];
            }
            $this->saida[] = '* SEARCH '.implode(' ', $uids);
            $ok();
        } elseif (preg_match('/^UID FETCH ([\d,]+) /', $cmd, $m)) {
            foreach (explode(',', $m[1]) as $i => $uid) {
                $msg = $this->pastas[$this->atual]['mensagens'][(int) $uid] ?? null;
                if (! $msg) {
                    continue;
                }
                $this->saida[] = '* '.($i + 1).' FETCH (UID '.$uid.' INTERNALDATE "'.$msg['data'].'" BODY[HEADER.FIELDS (FROM)] {'.strlen($msg['cabecalho']).'}';
                $this->saida[] = ['literal' => $msg['cabecalho']];
                $this->saida[] = ' BODY[TEXT]<0> {'.strlen($msg['texto']).'}';
                $this->saida[] = ['literal' => $msg['texto']];
                $this->saida[] = ')';
            }
            $ok();
        } elseif ($cmd === 'LOGOUT' || $cmd === 'STARTTLS') {
            $ok();
        } else {
            $this->saida[] = "{$tag} BAD comando que o Matriz nunca deveria mandar";
        }
    }

    private string $atual = 'INBOX';

    public function lerLinha(): ?string
    {
        $l = array_shift($this->saida);

        return is_array($l) ? null : $l;
    }

    public function lerBytes(int $n): string
    {
        $l = array_shift($this->saida);

        return is_array($l) ? $l['literal'] : '';
    }

    public function fechar(): void {}

    public static function mensagem(string $de, string $assunto, string $texto, string $messageId, ?string $inReplyTo = null, string $data = '17-Oct-2026 09:12:00 -0300'): array
    {
        return ['cabecalho' => "From: {$de}\r\nSubject: {$assunto}\r\nMessage-ID: {$messageId}\r\n".($inReplyTo ? "In-Reply-To: {$inReplyTo}\r\n" : '')."Content-Type: text/plain; charset=utf-8\r\n\r\n",
            'texto' => $texto, 'data' => $data];
    }
}
