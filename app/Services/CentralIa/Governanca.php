<?php

namespace App\Services\CentralIa;

use App\Models\{CentralIaExecucao, CentralIaPreferencia, User};
use Illuminate\Support\Facades\DB;

/** Administração de capacidade; não concede leitura de conversas ou credenciais. */
class Governanca
{
    public function administra(User $user): bool
    {
        return $user->ativo && ! $user->temEscopoDeRevenda()
            && $user->canPermissao('usuarios', 'ler') && $user->canPermissao('usuarios', 'editar');
    }

    public function usuarios(User $admin): array
    {
        if (! $this->administra($admin)) return [];
        return User::query()->where('id', '!=', $admin->id)->orderBy('name')->get(['id', 'name', 'ativo'])
            ->map(fn ($u) => ['id' => $u->id, 'nome' => $u->name, 'ativo' => (bool) $u->ativo])->all();
    }

    public function padrao(): array
    {
        return ['revisao' => 0, 'habilitados' => ['claude' => false, 'codex' => false, 'copilot' => false],
            'pedidos_dia' => ['claude' => 0, 'codex' => 0, 'copilot' => 0], 'limite_total' => 0, 'paralelo' => 1,
            // Não há executor com ACL comprovada nesta versão. Não existe opção
            // de "liberar" pelo navegador que finja que o isolamento foi feito.
            'execucao_bloqueada' => true, 'deploy' => false, 'onedrive' => false];
    }

    public function execucaoBloqueada(User $user, ?array $politica = null): bool
    {
        // Sem isolamento comprovado, acesso amplo legado é apenas para a
        // conta administradora já autorizada. Não nasce acesso amplo ao membro.
        return ! $this->administra($user) || ($politica['execucao_bloqueada'] ?? false);
    }

    public function politica(User $user): ?array
    {
        if (! app(Preferencias::class)->pronta()) return null;
        $r = CentralIaPreferencia::where('user_id', $user->id)->value('regras');
        return is_array($r) && isset($r['governanca']) ? $r['governanca'] : null;
    }

    public function resumo(User $admin, User $user): array
    {
        abort_unless($this->administra($admin), 403);
        $uso = [];
        foreach (array_keys(CentralIaExecucao::AGENTES) as $a) $uso[$a] = app(Preferencias::class)->usados($user, $a);
        $execucoes = CentralIaExecucao::where('user_id', $user->id)->latest('id')->limit(100)
            ->get(['id', 'agente', 'modo', 'status', 'created_at', 'iniciada_em', 'concluida_em'])
            ->map(fn ($e) => ['id' => $e->id, 'agente' => CentralIaExecucao::AGENTES[$e->agente] ?? $e->agente,
                'modo' => $e->modo, 'status' => $e->status, 'em' => $e->created_at->toIso8601String(),
                'iniciada_em' => $e->iniciada_em?->toIso8601String(), 'concluida_em' => $e->concluida_em?->toIso8601String()])->all();
        $eventos = DB::table('central_ia_eventos')->where('user_id', $user->id)->latest('id')->limit(100)
            ->get(['id', 'tipo', 'nivel', 'created_at'])->map(fn ($e) => ['id' => $e->id, 'tipo' => $e->tipo,
                'nivel' => $e->nivel, 'em' => \Illuminate\Support\Carbon::parse($e->created_at)->toIso8601String()])->all();
        return ['usuario' => ['id' => $user->id, 'nome' => $user->name, 'ativo' => (bool) $user->ativo],
            'politica' => $this->politica($user) ?? $this->padrao(), 'configurada' => $this->politica($user) !== null,
            'uso_hoje' => $uso, 'execucoes' => $execucoes, 'eventos' => $eventos];
    }
}
