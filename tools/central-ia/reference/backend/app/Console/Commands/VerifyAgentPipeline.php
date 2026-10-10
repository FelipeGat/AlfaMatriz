<?php

namespace App\Console\Commands;

use App\Models\AgentRun;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class VerifyAgentPipeline extends Command
{
    protected $signature = 'central:verify-agent-pipeline';

    protected $description = 'Registra teste identificado no histórico e verifica fila/executor simulado; sem IA.';

    public function handle(ConversationService $service): int
    {
        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'central' || config('database.connections.pgsql.host') !== 'db' || config('central.agent_mode') !== 'mock' || ! config('central.agents_enabled')) {
            $this->error('Diagnóstico recusado fora do banco isolado central ou modo simulado.');

            return self::FAILURE;
        }
        $user = User::firstOrFail();
        $message = $service->post($user, '[Teste técnico de integração — simulação] Validar Laravel, fila, executor e histórico compartilhado. Nenhuma chamada real de IA.', (string) Str::uuid());
        for ($i = 0; $i < 30; $i++) {
            $run = AgentRun::where('message_id', $message->id)->first();
            if ($run && in_array($run->status, ['completed', 'failed', 'uncertain', 'rejected'], true)) {
                $this->line('run='.$run->id.' mode='.$run->mode.' status='.$run->status.' real_inference=false');

                return $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
            }
            sleep(1);
        }
        $this->error('Diagnóstico pendente; não reenviar automaticamente.');

        return self::FAILURE;
    }
}
