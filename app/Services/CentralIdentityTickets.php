<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class CentralIdentityTickets
{
    public function issue(User $user, string $flow): array
    {
        $snapshot = app(CentralIdentitySnapshot::class)->forUser($user);
        abort_unless(in_array('read', $snapshot['operations'], true), 403, 'access_denied');
        $ticket = bin2hex(random_bytes(32));
        DB::table('central_ia_tickets')->insert([
            'subject_id' => DB::table('central_ia_subjects')->where('subject', $snapshot['subject'])->value('id'),
            'ticket_hash' => hash('sha256', $ticket), 'flow_hash' => hash('sha256', $flow),
            'audience' => config('central_ia.audience'), 'expires_at' => now()->addSeconds(config('central_ia.ticket_seconds')),
            'session_epoch' => $snapshot['session_epoch'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['ticket' => $ticket, 'expires_in' => config('central_ia.ticket_seconds')];
    }
}
