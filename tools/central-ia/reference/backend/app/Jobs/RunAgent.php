<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\ChannelBinding;
use App\Models\Message;
use App\Models\MessageDelivery;
use App\Services\AgentExecutor;
use App\Services\Identity\ExecutionAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RunAgent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 125;

    public function __construct(public int $runId) {}

    public function handle(AgentExecutor $executor): void
    {
        $lock = Cache::lock('central:agent-execution', 150);
        if (! $lock->get()) {
            // Leave the durable run queued; dispatcher schedules it later without starting inference.
            return;
        }
        $personalLock = null;
        try {
            $run = DB::transaction(function () {
                $run = AgentRun::whereKey($this->runId)->lockForUpdate()->first();
                if (! $run || $run->status !== 'queued') {
                    return null;
                }
                if (! config('central.agents_enabled')) {
                    return null;
                }
                app(ExecutionAccess::class)->authorize($run);
                $run->update(['status' => 'running', 'started_at' => now()]);

                return $run;
            });
            if (! $run) {
                return;
            }
            $personalLock = Cache::lock('central:agent-execution:user:'.$run->owner_id, 150);
            if (! $personalLock->get()) {
                $run->update(['status' => 'queued', 'started_at' => null]);

                return;
            }
            $context = [];
            $remaining = config('central.agent_context_chars');
            foreach (Message::where('owner_id', $run->owner_id)->where('conversation_id', $run->conversation_id)->where('id', '<=', $run->message_id)->orderByDesc('id')->limit(40)->get() as $m) {
                if (mb_strlen($m->content) > $remaining) {
                    break;
                }
                $remaining -= mb_strlen($m->content);
                $context[] = ['author' => $m->actor_name, 'role' => $m->actor_type, 'text' => $m->content];
            }
            $result = $executor->run($run->id, array_reverse($context));
            DB::transaction(function () use ($run, $result) {
                $current = AgentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'running') {
                    return;
                }
                app(ExecutionAccess::class)->authorize($current);
                if (! $result['ok']) {
                    $current->update(['status' => $result['error'] === 'executor_uncertain' ? 'uncertain' : 'failed', 'error_code' => $result['error'], 'finished_at' => now()]);

                    return;
                }
                $message = Message::create(['owner_id' => $run->owner_id, 'conversation_id' => $run->conversation_id, 'actor_type' => 'agent', 'actor_name' => 'Codex (simulado)', 'origin' => 'agent', 'kind' => 'text', 'content' => $result['text'], 'metadata' => ['run_id' => $run->id, 'provider' => 'codex', 'simulation' => true]]);
                $binding = ChannelBinding::where('user_id', $run->owner_id)->where('conversation_id', $run->conversation_id)->whereNull('revoked_at')->first();
                if ($binding) {
                    $delivery = MessageDelivery::create(['owner_id' => $run->owner_id, 'message_id' => $message->id, 'channel_binding_id' => $binding->id]);
                    DB::afterCommit(function () use ($delivery) {
                        try {
                            DeliverTelegramMessage::dispatch($delivery->id)->onQueue('telegram');
                        } catch (\Throwable) { /* Persistent outbox handles redis outages. */
                        }
                    });
                }
                $current->update(['status' => 'completed', 'response_message_id' => $message->id, 'usage' => $result['usage'], 'finished_at' => now()]);
            });
        } catch (\Throwable) {
            AgentRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted', 'finished_at' => now()]);
        } finally {
            $personalLock?->release();
            $lock->release();
        }
    }
}
