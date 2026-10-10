<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CentralIdentitySnapshot
{
    public function forSubject(string $subject): array
    {
        $record = DB::table('central_ia_subjects')->where('subject', $subject)->first();
        abort_unless($record, 404, 'identity_unavailable');
        $user = User::find($record->user_id);
        if (! $user) {
            return ['version' => 1, 'issuer' => 'alfamatriz', 'audience' => config('central_ia.audience'),
                'subject' => $subject, 'active' => false, 'ready' => false, 'session_epoch' => (int) $record->session_epoch, 'operations' => []];
        }

        return $this->forUser($user);
    }

    public function forUser(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current->esquecerPermissoes();
            $active = $current->ativo;
            $ready = ! $current->primeiro_acesso;
            $operations = [];
            if ($active && $ready && app(CentralOwnerPolicy::class)->allows($current) && $current->canPermissao('central_ia', 'ler')) {
                $operations[] = 'read';
                if ($current->canPermissao('central_ia', 'incluir')) {
                    $operations[] = 'send';
                }
                if ($current->canPermissao('central_ia', 'editar')) {
                    array_push($operations, 'pair', 'integrations', 'sessions');
                }
                if ($current->canPermissao('central_ia_execucao', 'incluir')) {
                    $operations[] = 'execute';
                }
            }
            $fingerprint = hash('sha256', json_encode([$active, $ready, $operations], JSON_THROW_ON_ERROR));
            $record = DB::table('central_ia_subjects')->where('user_id', $current->id)->lockForUpdate()->first();
            if (! $record) {
                $id = DB::table('central_ia_subjects')->insertGetId(['user_id' => $current->id, 'subject' => (string) Str::uuid(),
                    'session_epoch' => 1, 'access_fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
                $record = DB::table('central_ia_subjects')->find($id);
            } elseif (! hash_equals($record->access_fingerprint, $fingerprint)) {
                DB::table('central_ia_subjects')->where('id', $record->id)->update(['access_fingerprint' => $fingerprint,
                    'session_epoch' => $record->session_epoch + 1, 'updated_at' => now()]);
                $record = DB::table('central_ia_subjects')->find($record->id);
            }

            return ['version' => 1, 'issuer' => 'alfamatriz', 'audience' => config('central_ia.audience'),
                'subject' => $record->subject, 'active' => $active, 'ready' => $ready,
                'session_epoch' => (int) $record->session_epoch, 'operations' => $operations];
        });
    }
}
