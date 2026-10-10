<?php

namespace App\Console\Commands;

use App\Jobs\RunAgent;
use App\Models\AgentRun;
use Illuminate\Console\Command;

class DispatchAgents extends Command
{
    protected $signature = 'central:agents-dispatch';

    protected $description = 'Recupera solicitações persistentes; nunca repete execução de resultado incerto.';

    public function handle(): int
    {
        AgentRun::where('status', 'running')->where('started_at', '<', now()->subSeconds(150))->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted', 'finished_at' => now()]);
        if (config('central.agents_enabled')) {
            foreach (AgentRun::where('status', 'queued')->orderBy('id')->limit(20)->get() as $run) {
                RunAgent::dispatch($run->id)->onQueue('agents');
            }
        }

        return self::SUCCESS;
    }
}
