<?php

namespace App\Services;

use App\Jobs\RunAgent;
use App\Models\AgentIntegration;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Identity\Access;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AgentService
{
    public function scheduleAutomatic(Message $message): ?AgentRun
    {
        try {
            return $this->schedule($message);
        } catch (HttpException $error) {
            if ($error->getStatusCode() === 403) {
                // Permission to send a message does not imply permission to execute an agent.
                return null;
            }
            throw $error;
        }
    }

    public function schedule(Message $message): ?AgentRun
    {
        $message->refresh();
        if (! config('central.agents_enabled') || $message->actor_type !== 'user' || $message->kind !== 'text' || ! in_array($message->origin, ['web', 'telegram'], true)) {
            return null;
        }
        abort_unless(config('central.agent_mode') === 'mock', 403, 'real_inference_disabled');
        $owner = User::findOrFail($message->owner_id);
        $identity = app(Access::class)->authorize($owner, 'execute');
        $conversation = Conversation::findOrFail($message->conversation_id);
        app(Access::class)->owns($owner, $conversation->user_id);
        $run = DB::transaction(function () use ($message, $owner, $identity) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            Conversation::whereKey($message->conversation_id)->lockForUpdate()->firstOrFail();
            if ($existing = AgentRun::where('message_id', $message->id)->first()) {
                app(Access::class)->owns($owner, (int) $existing->owner_id);

                return $existing;
            }
            // A simulation may exist without a connected account. Never provision credentials.
            $integration = AgentIntegration::firstOrCreate(['owner_id' => $owner->id, 'provider' => 'codex']);
            $integration->refresh();
            abort_if($integration->status === 'revoked', 403, 'integration_revoked');
            $day = now('America/Sao_Paulo')->toDateString();
            DB::table('agent_daily_usage')->insertOrIgnore(['day' => $day, 'requests' => 0]);
            DB::table('agent_user_daily_usage')->insertOrIgnore(['owner_id' => $owner->id, 'day' => $day, 'requests' => 0]);
            $global = DB::table('agent_daily_usage')->where('day', $day)->lockForUpdate()->first();
            $personal = DB::table('agent_user_daily_usage')->where('owner_id', $owner->id)->where('day', $day)->lockForUpdate()->first();
            $error = $global->requests >= config('central.agent_daily_limit') ? 'global_daily_limit'
                : ($personal->requests >= config('central.agent_user_daily_limit') ? 'user_daily_limit' : null);
            if ($error === null) {
                DB::table('agent_daily_usage')->where('day', $day)->increment('requests');
                DB::table('agent_user_daily_usage')->where('owner_id', $owner->id)->where('day', $day)->increment('requests');
            }

            return AgentRun::create(['owner_id' => $owner->id, 'message_id' => $message->id, 'conversation_id' => $message->conversation_id,
                'integration_id' => $integration->id, 'integration_generation' => $integration->generation,
                'session_epoch' => $identity->session_epoch, 'mode' => 'mock', 'status' => $error ? 'rejected' : 'queued',
                'error_code' => $error, 'finished_at' => $error ? now() : null]);
        });
        if ($run->status === 'queued') {
            DB::afterCommit(function () use ($run) {
                try {
                    RunAgent::dispatch($run->id)->onQueue('agents');
                } catch (\Throwable) { /* Durable queued row is recovered, never log payloads. */
                }
            });
        }

        return $run;
    }
}
