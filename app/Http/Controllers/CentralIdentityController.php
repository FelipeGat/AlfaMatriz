<?php

namespace App\Http\Controllers;

use App\Services\CentralIdentitySnapshot;
use App\Services\CentralIdentityTickets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CentralIdentityController extends Controller
{
    public function issue(Request $request, CentralIdentitySnapshot $identity)
    {
        abort_unless(config('central_ia.enabled') && config('central_ia.browser_tickets_enabled'), 404);
        abort_unless($request->isSecure(), 403, 'secure_transport_required');
        $data = $this->fields($request, ['flow' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);

        return $this->privateJson(app(CentralIdentityTickets::class)->issue($request->user(), $data['flow']));
    }

    public function inspect(Request $request, CentralIdentitySnapshot $identity)
    {
        $data = $this->fields($request, ['subject' => ['required', 'uuid']]);

        return $this->privateJson($identity->forSubject($data['subject']));
    }

    public function redeem(Request $request, CentralIdentitySnapshot $identity)
    {
        $data = $this->fields($request, ['ticket' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'flow' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);
        $snapshot = DB::transaction(function () use ($data, $identity) {
            $record = DB::table('central_ia_tickets')->where('ticket_hash', hash('sha256', $data['ticket']))->lockForUpdate()->first();
            abort_unless($record && $record->used_at === null && now()->lt($record->expires_at)
                && $record->audience === config('central_ia.audience') && hash_equals($record->flow_hash, hash('sha256', $data['flow'])), 403, 'ticket_refused');
            $subject = DB::table('central_ia_subjects')->where('id', $record->subject_id)->value('subject');
            $current = $identity->forSubject($subject);
            abort_unless(in_array('read', $current['operations'], true), 403, 'access_denied');
            abort_unless($current['session_epoch'] === (int) $record->session_epoch, 403, 'ticket_refused');
            DB::table('central_ia_tickets')->where('id', $record->id)->update(['used_at' => now(), 'updated_at' => now()]);

            return $current;
        });

        return $this->privateJson($snapshot);
    }

    private function fields(Request $request, array $rules): array
    {
        abort_if(array_diff(array_keys($request->except('_token')), array_keys($rules)) !== [], 422, 'unexpected_identity_fields');

        return $request->validate($rules);
    }

    private function privateJson(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
