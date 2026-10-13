<?php

namespace Tests\Feature\CentralIa;

use App\Services\CentralIa\TelegramApi;
use App\Services\CentralIa\TelegramConsumerLock;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TelegramConsumerLockTest extends TestCase
{
    private function sqlLock(int $acquired, int $owned = 1): TelegramConsumerLock
    {
        $pdo = Mockery::mock(\PDO::class);
        foreach (['SELECT GET_LOCK(?, 0)' => $acquired,
            'SELECT IS_USED_LOCK(?) = CONNECTION_ID()' => $owned,
            'SELECT RELEASE_LOCK(?)' => 1] as $sql => $value) {
            $statement = Mockery::mock(\PDOStatement::class);
            $statement->shouldReceive('execute')->with(Mockery::on(fn ($a) => count($a) === 1 && str_starts_with($a[0], 'central-ia:telegram:')))->andReturn(true);
            $statement->shouldReceive('fetchColumn')->andReturn($value);
            $pdo->shouldReceive('prepare')->with($sql)->andReturn($statement);
        }
        $default = Mockery::mock(Connection::class);
        $default->shouldReceive('getDriverName')->andReturn('mysql');
        $default->shouldReceive('getConfig')->andReturn(['driver' => 'mysql', 'database' => 'teste']);
        $default->shouldReceive('getDatabaseName')->andReturn('teste');
        $dedicated = Mockery::mock(Connection::class);
        $dedicated->shouldReceive('getPdo')->once()->andReturn($pdo);
        DB::shouldReceive('connection')->withNoArgs()->andReturn($default);
        DB::shouldReceive('connection')->with('central_ia_telegram_lock')->andReturn($dedicated);
        DB::shouldReceive('purge')->with('central_ia_telegram_lock')->andReturnNull();
        return new TelegramConsumerLock;
    }

    public function test_segundo_consumidor_nao_adquire_a_trava(): void
    {
        $lock = $this->sqlLock(0);
        $this->assertFalse($lock->acquire());
        $lock->release();
    }

    public function test_nao_expira_apos_60_segundos_e_libera_ao_terminar(): void
    {
        $lock = $this->sqlLock(1);
        $this->assertTrue($lock->acquire());
        $this->travel(10)->minutes();
        $lock->assertOwned();
        $lock->release();
        $this->addToAssertionCount(1);
    }

    public function test_perda_da_trava_impede_chamada_ao_telegram(): void
    {
        $lock = $this->sqlLock(1, 0);
        $this->assertTrue($lock->acquire());
        $api = app(TelegramApi::class);
        $api->protegerConsumidor(fn () => $lock->assertOwned());
        Http::fake();
        try {
            $api->chamar('getUpdates');
            $this->fail('Um consumidor sem trava não pode ler o bot.');
        } catch (RuntimeException $e) {
            $this->assertSame('Consumidor perdeu a trava.', $e->getMessage());
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_driver_sem_exclusividade_real_falha_fechado(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exclusividade por conexão');
        (new TelegramConsumerLock)->acquire();
    }
}
