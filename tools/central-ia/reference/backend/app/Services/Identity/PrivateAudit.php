<?php

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PrivateAudit
{
    public function record(User $owner, string $event, array $ids = []): void
    {
        if (! in_array($event, ['identity_linked', 'identity_revoked', 'integration_revoked', 'session_revoked', 'telegram_revoked'], true)
            || array_diff(array_keys($ids), ['identity_id', 'integration_id', 'session_id']) !== []) {
            throw new \RuntimeException('audit_metadata_refused');
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw new \RuntimeException('audit_metadata_refused');
            }
        }
        DB::table('audit_events')->insert(['user_id' => $owner->id, 'event' => $event,
            'metadata' => $ids ? json_encode($ids, JSON_THROW_ON_ERROR) : null, 'created_at' => now()]);
    }
}
