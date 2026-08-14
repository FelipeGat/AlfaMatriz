<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * O endereço de um sistema integrado carrega uma chave de integração a cada
 * chamada. Sem esta regra, quem edita o catálogo aponta `base_url` para
 * qualquer host — inclusive um da própria rede — e o painel entrega a chave
 * para lá.
 *
 * A checagem é só SINTÁTICA: resolver o host (DNS) antes de comparar foi
 * cogitado e ficou de fora — a resolução muda entre a validação e o uso, e
 * prometer uma garantia que não se sustenta é pior que a checagem honesta que
 * este arquivo faz.
 */
class EnderecoPublico implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $endereco = parse_url($value);
        $esquema = $endereco['scheme'] ?? null;
        $host = $endereco['host'] ?? null;

        if ($esquema !== 'https') {
            $fail('O endereço precisa ser HTTPS.');

            return;
        }

        if ($host === null || $this->ehHostInterno($host)) {
            $fail('O endereço não pode apontar para a própria máquina ou para a rede interna.');
        }
    }

    /**
     * As seis faixas do AC-270: `localhost`, `127.x`, `10.x`, `172.16–31.x`,
     * `192.168.x` e `169.254.x` (link-local, onde vive o metadado de nuvem).
     */
    private function ehHostInterno(string $host): bool
    {
        $host = strtolower($host);

        if ($host === 'localhost') {
            return true;
        }

        if (! preg_match('/^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}$/', $host, $octetos)) {
            return false;
        }

        $primeiro = (int) $octetos[1];
        $segundo = (int) $octetos[2];

        return $primeiro === 127
            || $primeiro === 10
            || ($primeiro === 172 && $segundo >= 16 && $segundo <= 31)
            || ($primeiro === 192 && $segundo === 168)
            || ($primeiro === 169 && $segundo === 254);
    }
}
