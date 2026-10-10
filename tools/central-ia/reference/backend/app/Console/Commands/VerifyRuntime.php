<?php

namespace App\Console\Commands;

use App\Jobs\RuntimeProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class VerifyRuntime extends Command
{
    protected $signature = 'central:verify-runtime {--check : Confere persistência do último probe sem enfileirar}';

    protected $description = 'Verifica PostgreSQL, Redis e worker próprios, sem usuário ou Telegram real.';

    public function handle(): int
    {
        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'central' || config('database.connections.pgsql.host') !== 'db') {
            $this->error('Verificação recusada fora do banco próprio.');

            return self::FAILURE;
        }
        DB::select('select 1');
        Redis::ping();
        if ($this->option('check')) {
            $requested = DB::table('central_states')->where('key', 'runtime_probe_requested')->value('value');
            $completed = DB::table('central_states')->where('key', 'runtime_probe_completed')->value('value');
            if (! $requested || $requested !== $completed) {
                $this->error('Probe não confirmado.');

                return self::FAILURE;
            }
            $this->info('PASS: PostgreSQL/Redis acessíveis e probe persistente confirmado.');

            return self::SUCCESS;
        }
        $id = (string) Str::uuid();
        DB::table('central_states')->updateOrInsert(['key' => 'runtime_probe_requested'], ['value' => $id]);
        RuntimeProbe::dispatch($id)->onQueue('telegram');
        for ($i = 0; $i < 100; $i++) {
            if (DB::table('central_states')->where('key', 'runtime_probe_completed')->value('value') === $id) {
                $this->info('PASS: PostgreSQL + Redis + worker real processaram o probe.');

                return self::SUCCESS;
            }
            usleep(100000);
        }
        $this->error('Worker não confirmou em 10 segundos.');

        return self::FAILURE;
    }
}
