<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\ChannelBinding;
use App\Models\Message;
use App\Services\ConversationService;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request, ConversationService $service)
    {
        $conversation = $service->forUser($request->user());
        $binding = ChannelBinding::where('user_id', $request->user()->id)->whereNull('revoked_at')->exists();

        return view('conversation', compact('conversation', 'binding'));
    }

    public function messages(Request $request, ConversationService $service)
    {
        $data = $request->validate(['after' => 'nullable|integer|min:0', 'before' => 'nullable|integer|min:1']);
        abort_if(isset($data['before'], $data['after']), 422);
        $query = Message::with('delivery')->where('owner_id', $request->user()->id)->where('conversation_id', $service->forUser($request->user())->id);
        if (isset($data['after'])) {
            $query->where('id', '>', $data['after']);
        }
        if (isset($data['before'])) {
            $query->where('id', '<', $data['before']);
        }
        $deliveryStates = Message::with('delivery')->where('owner_id', $request->user()->id)->where('conversation_id', $service->forUser($request->user())->id)->orderByDesc('id')->limit(100)->get()->filter(fn ($m) => $m->delivery)->map(fn ($m) => ['id' => $m->id, 'status' => $m->delivery->status])->values();
        $ascending = isset($data['after']);
        $messages = ($ascending ? $query->orderBy('id') : $query->orderByDesc('id'))->limit(51)->get();
        $hasMore = $messages->count() > 50;
        $messages = $messages->take(50);
        if (! $ascending) {
            $messages = $messages->reverse()->values();
        }

        $runs = AgentRun::where('owner_id', $request->user()->id)->where('conversation_id', $service->forUser($request->user())->id)->orderByDesc('id')->limit(20)->get(['id', 'message_id', 'response_message_id', 'provider', 'mode', 'status', 'error_code', 'usage', 'started_at', 'finished_at']);

        return response()->json(['has_more' => $hasMore, 'agent_runs' => $runs, 'messages' => $messages->map(fn ($m) => [
            'id' => $m->id, 'client_id' => $m->client_id, 'actor_type' => $m->actor_type, 'actor_name' => $m->actor_name, 'kind' => $m->kind,
            'origin' => $m->origin, 'content' => $m->content, 'created_at' => $m->created_at->toIso8601String(),
            'delivery_status' => $m->delivery?->status,
        ]), 'deliveries' => $deliveryStates, 'telegram_paired' => ChannelBinding::where('user_id', $request->user()->id)->whereNull('revoked_at')->exists()]);
    }

    public function store(Request $request, ConversationService $service)
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:3000', function ($attribute, $value, $fail) {
            if (trim($value) === '') {
                $fail('Escreva uma mensagem.');
            }
        }], 'client_id' => 'required|uuid']);
        $message = $service->post($request->user(), $data['content'], $data['client_id']);

        return response()->json(['id' => $message->id], 201);
    }
}
