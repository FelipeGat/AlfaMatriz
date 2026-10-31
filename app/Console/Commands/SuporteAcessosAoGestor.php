<?php

namespace App\Console\Commands;

use App\Services\Suporte\AcessoInterno;
use Illuminate\Console\Command;

/**
 * A lista de quem é da casa vai ao Gestor de 15 em 15 minutos (acesso-interno.md, FR-A11).
 * A tela Suporte › Pedro já manda ao salvar; isto cobre a falha de rede e a primeira
 * carga depois da publicação, sem ninguém precisar salvar a lista de novo.
 */
class SuporteAcessosAoGestor extends Command
{
    protected $signature = 'suporte:acessos-ao-gestor';

    protected $description = 'Manda ao Gestor as chaves dos números da casa, que o Pedro atende a qualquer hora';

    public function handle(AcessoInterno $acessos): int
    {
        if ($acessos->mandarAoGestor() === false) {
            $this->warn('O Gestor não recebeu a lista da casa; nova tentativa na próxima rodada.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
