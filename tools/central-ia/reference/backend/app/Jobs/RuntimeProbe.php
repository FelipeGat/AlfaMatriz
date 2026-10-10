<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RuntimeProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $probeId) {}

    public function handle(): void
    {
        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'central') {
            throw new \RuntimeException('Runtime probe refused outside Central database.');
        }
        DB::table('central_states')->updateOrInsert(['key' => 'runtime_probe_completed'], ['value' => $this->probeId]);
    }
}
