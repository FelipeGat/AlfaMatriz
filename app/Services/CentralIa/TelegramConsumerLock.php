<?php

namespace App\Services\CentralIa;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A trava pertence à conexão MariaDB, não ao hostname nem a um prazo de cache.
 * Continua válida durante downloads demorados; a queda do processo libera-a.
 * A conexão é exclusiva: uma transação/reconexão da aplicação não troca seu dono.
 */
class TelegramConsumerLock
{
    private ?Connection $connection = null;

    private ?\PDO $pdo = null;

    private string $name = '';

    public function acquire(): bool
    {
        if ($this->connection) {
            throw new RuntimeException('Trava do consumidor já inicializada.');
        }

        $default = DB::connection();
        if ($default->getDriverName() !== 'mysql') {
            throw new RuntimeException('O consumidor exige MariaDB/MySQL para exclusividade por conexão.');
        }

        config(['database.connections.central_ia_telegram_lock' => $default->getConfig()]);
        $this->connection = DB::connection('central_ia_telegram_lock');
        $this->pdo = $this->connection->getPdo();
        $this->name = 'central-ia:telegram:'.substr(hash('sha256', $default->getDatabaseName()), 0, 32);
        // PDO direto evita a reconexão automática do Laravel: perdendo a
        // conexão, este processo para; não passa a trabalhar sem ser dono.
        $query = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute([$this->name]);

        return (int) $query->fetchColumn() === 1;
    }

    public function assertOwned(): void
    {
        $query = $this->pdo?->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
        if (! $query) {
            throw new RuntimeException('Consumidor sem trava.');
        }
        $query->execute([$this->name]);
        if ((int) $query->fetchColumn() !== 1) {
            throw new RuntimeException('Consumidor perdeu a trava.');
        }
    }

    public function release(): void
    {
        try {
            if ($this->pdo) {
                $query = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                $query->execute([$this->name]);
            }
        } finally {
            $this->pdo = null;
            $this->connection = null;
            DB::purge('central_ia_telegram_lock');
        }
    }
}
