<?php

namespace App\Services\Secretario\Email;

use RuntimeException;

/**
 * IMAP SÓ DE LEITURA (docs/specs/secretario-emails-gestor, research D1).
 *
 * Os únicos comandos que existem aqui são LOGIN, STARTTLS, LIST, EXAMINE,
 * UID SEARCH, UID FETCH com BODY.PEEK e LOGOUT. EXAMINE abre a pasta em modo
 * leitura (RFC 3501 §6.3.2) e BODY.PEEK não liga a marca \Seen (§6.4.5): ler
 * pelo Matriz nunca marca a mensagem como lida — o defeito do Gestor
 * (ImapService.php:583) não tem como acontecer, porque não há comando que
 * escreva. Não há SELECT, STORE, COPY, MOVE, EXPUNGE, APPEND nem envio.
 */
class ClienteImap
{
    private int $tag = 0;

    public function __construct(private TransporteImap $transporte, private int $timeout = 30) {}

    /** Erros de conexão e login voltam com um código curto — nunca com senha nem resposta do servidor. */
    public function conectar(string $host, int $porta, string $seguranca, string $usuario, string $senha): void
    {
        if (! in_array($seguranca, ['ssl', 'tls'], true)) {
            throw new RuntimeException('sem_cifra');
        }
        if (preg_match('/[\r\n\x00]/', $usuario.$senha)) {
            throw new RuntimeException('credencial_invalida');
        }
        $this->transporte->abrir($host, $porta, $seguranca, $this->timeout);
        $saudacao = $this->transporte->lerLinha();
        if ($saudacao === null || ! str_starts_with($saudacao, '* OK')) {
            throw new RuntimeException('saudacao');
        }
        if ($seguranca === 'tls') {
            [$ok] = $this->comando('STARTTLS');
            if (! $ok) {
                throw new RuntimeException('tls');
            }
            $this->transporte->ligarTls();
        }
        [$ok] = $this->comando('LOGIN '.$this->texto($usuario).' '.$this->texto($senha));
        if (! $ok) {
            throw new RuntimeException('login');
        }
    }

    /** @return list<string> */
    public function pastas(): array
    {
        [, $respostas] = $this->comando('LIST "" "*"');
        $pastas = [];
        foreach ($respostas as $r) {
            if (preg_match('/^\* LIST \([^)]*\) (?:"[^"]*"|NIL) (.+)$/i', $r['texto'], $m)) {
                $nome = $r['literais'][0] ?? trim($m[1]);
                $pastas[] = $this->semAspas($nome);
            }
        }

        return $pastas;
    }

    /** Abre SÓ PARA LEITURA. @return array{uidvalidity: int, existentes: int} */
    public function examinar(string $pasta): array
    {
        [$ok, $respostas] = $this->comando('EXAMINE '.$this->texto($pasta));
        if (! $ok) {
            throw new RuntimeException('pasta');
        }
        $uidvalidity = 0;
        $existentes = 0;
        foreach ($respostas as $r) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $r['texto'], $m)) {
                $uidvalidity = (int) $m[1];
            }
            if (preg_match('/^\* (\d+) EXISTS/i', $r['texto'], $m)) {
                $existentes = (int) $m[1];
            }
        }

        return ['uidvalidity' => $uidvalidity, 'existentes' => $existentes];
    }

    /** @return list<int> UIDs recebidos desde a data (RFC 3501 SEARCH SINCE: só a data). */
    public function desde(\DateTimeInterface $data): array
    {
        return $this->buscar('UID SEARCH SINCE '.$data->format('j-M-Y'));
    }

    /** @return list<int> UIDs depois de $ultimo. */
    public function depoisDe(int $ultimo): array
    {
        // "n:*" devolve a última mensagem mesmo quando n passa do fim: filtra.
        return array_values(array_filter($this->buscar('UID SEARCH UID '.($ultimo + 1).':*'), fn ($u) => $u > $ultimo));
    }

    /**
     * Cabeçalhos e o começo do corpo, SEM marcar como lida (BODY.PEEK).
     *
     * @param  list<int>  $uids
     * @return list<array{uid: int, data_interna: ?string, cabecalho: string, texto: string}>
     */
    public function buscarMensagens(array $uids, int $bytesDoCorpo = 4000): array
    {
        $saida = [];
        foreach (array_chunk($uids, 50) as $lote) {
            $campos = 'BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE MESSAGE-ID IN-REPLY-TO CONTENT-TYPE CONTENT-TRANSFER-ENCODING)]';
            [$ok, $respostas] = $this->comando('UID FETCH '.implode(',', array_map('intval', $lote))." (UID INTERNALDATE {$campos} BODY.PEEK[TEXT]<0.{$bytesDoCorpo}>)");
            if (! $ok) {
                throw new RuntimeException('fetch');
            }
            foreach ($respostas as $r) {
                if (! preg_match('/^\* \d+ FETCH/i', $r['texto']) || ! preg_match('/\bUID (\d+)/i', $r['texto'], $u)) {
                    continue;
                }
                $cabecalho = '';
                $texto = '';
                foreach ($r['partes'] as [$antes, $literal]) {
                    if (stripos($antes, 'HEADER.FIELDS') !== false) {
                        $cabecalho = $literal;
                    } elseif (stripos($antes, 'BODY[TEXT]') !== false) {
                        $texto = $literal;
                    }
                }
                preg_match('/INTERNALDATE "([^"]+)"/i', $r['texto'], $d);
                $saida[] = ['uid' => (int) $u[1], 'data_interna' => $d[1] ?? null, 'cabecalho' => $cabecalho, 'texto' => $texto];
            }
        }

        return $saida;
    }

    public function sair(): void
    {
        try {
            $this->comando('LOGOUT');
        } catch (\Throwable) {
        }
        $this->transporte->fechar();
    }

    /** @return list<int> */
    private function buscar(string $comando): array
    {
        [$ok, $respostas] = $this->comando($comando);
        if (! $ok) {
            throw new RuntimeException('busca');
        }
        $uids = [];
        foreach ($respostas as $r) {
            if (preg_match('/^\* SEARCH ?(.*)$/i', $r['texto'], $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $n) {
                    if (ctype_digit($n)) {
                        $uids[] = (int) $n;
                    }
                }
            }
        }

        return $uids;
    }

    /**
     * Manda um comando e lê até a resposta com a tag. Literais {n} são lidos
     * inteiros e guardados na ordem, com o texto que veio antes de cada um.
     *
     * @return array{0: bool, 1: list<array{texto: string, literais: list<string>, partes: list<array{0: string, 1: string}>}>}
     */
    private function comando(string $comando): array
    {
        $tag = 'S'.str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        $this->transporte->escrever("{$tag} {$comando}\r\n");
        $respostas = [];
        while (true) {
            $linha = $this->transporte->lerLinha();
            if ($linha === null) {
                throw new RuntimeException('conexao_caiu');
            }
            if (str_starts_with($linha, $tag.' ')) {
                return [(bool) preg_match('/^'.$tag.' OK/i', $linha), $respostas];
            }
            // Resposta com literal(is): junta as linhas até acabar.
            $texto = '';
            $literais = [];
            $partes = [];
            while (preg_match('/\{(\d+)\}$/', $linha, $m)) {
                $antes = substr($linha, 0, -strlen($m[0]));
                $literal = $this->transporte->lerBytes((int) $m[1]);
                $texto .= $antes.'{literal}';
                $literais[] = $literal;
                $partes[] = [$antes, $literal];
                $linha = $this->transporte->lerLinha() ?? '';
            }
            $texto .= $linha;
            $respostas[] = ['texto' => $texto, 'literais' => $literais, 'partes' => $partes];
        }
    }

    /** String IMAP entre aspas (RFC 3501 §4.3), com \ e " escapados. */
    private function texto(string $valor): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $valor).'"';
    }

    private function semAspas(string $v): string
    {
        $v = trim($v);

        return str_starts_with($v, '"') && str_ends_with($v, '"') ? stripcslashes(substr($v, 1, -1)) : $v;
    }
}
