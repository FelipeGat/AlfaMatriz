<?php

namespace App\Http\Controllers;

use App\Models\AgentIntegration;
use App\Models\AgentRun;
use App\Models\Message;
use App\Services\AgentService;
use App\Services\Identity\Revocation;
use App\Services\Identity\Sessions;
use Illuminate\Http\Request;

class PrivateResourcesController extends Controller
{
    public function message(Request $request, int $message)
    {
        $record = Message::where('owner_id', $request->user()->id)->findOrFail($message);

        return response()->json($record->only(['id', 'conversation_id', 'actor_type', 'actor_name', 'origin', 'content', 'created_at']));
    }

    public function run(Request $request, int $run)
    {
        $record = AgentRun::where('owner_id', $request->user()->id)->findOrFail($run);

        return response()->json($record->only(['id', 'message_id', 'status', 'provider', 'mode', 'error_code', 'usage']));
    }

    public function execute(Request $request, int $message, AgentService $agents)
    {
        $record = Message::where('owner_id', $request->user()->id)->findOrFail($message);
        $run = $agents->schedule($record);
        abort_if($run === null, 409, 'execution_disabled');

        return response()->json(['id' => $run->id, 'status' => $run->status]);
    }

    public function integrations(Request $request)
    {
        return response()->json(AgentIntegration::where('owner_id', $request->user()->id)->get(['id', 'provider', 'status']));
    }

    public function revokeIntegration(Request $request, int $integration, Revocation $revocation)
    {
        $revocation->integration($request->user(), $integration);

        return response()->noContent();
    }

    public function revokeSession(Request $request, int $session, Sessions $sessions)
    {
        $sessions->revoke($request->user(), $session);

        return response()->noContent();
    }

    public function revokeTelegram(Request $request, Revocation $revocation)
    {
        $revocation->telegram($request->user());

        return response()->noContent();
    }
}
