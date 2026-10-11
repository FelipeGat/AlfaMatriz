<?php

namespace App\Services\CentralIa;

use App\Models\{CentralIaExecucao, CentralIaPreferencia, User};
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Regras administrativas da conta; não substituem isolamento do sistema operacional. */
class Preferencias
{
    public function pronta(): bool
    {
        return Schema::hasTable('central_ia_preferencias');
    }

    public function ler(User $user): array
    {
        $salvas = $this->pronta() ? CentralIaPreferencia::where('user_id', $user->id)->value('regras') : null;
        return array_replace_recursive([
            'revisao_config' => 0,
            'padroes' => ['implementacao' => 'claude', 'investigacao' => 'codex', 'revisao' => 'codex', 'planejamento' => 'codex', 'relatorio' => 'copilot', 'apoio' => 'copilot'],
            'habilitados' => ['claude' => true, 'codex' => true, 'copilot' => true],
            'pedidos_dia' => ['claude' => null, 'codex' => null, 'copilot' => null],
            'limite_total' => config('central_ia.agentes.limite_diario_ativo') ? (int) config('central_ia.agentes.limite_diario') : null,
            'paralelo' => max(1, (int) config('central_ia.agentes.paralelo')),
            'fallback' => (bool) config('central_ia.agentes.fallback_automatico'),
        ], is_array($salvas) ? $salvas : []);
    }

    public function ordem(User $user, string $categoria): array
    {
        $r = $this->ler($user);
        $primeiro = $r['padroes'][$categoria] ?? 'codex';
        return array_values(array_filter(array_unique([$primeiro, ...config('central_ia.roteamento.'.$categoria, ['codex', 'claude', 'copilot'])]), fn ($a) => $r['habilitados'][$a] ?? false));
    }

    public function usados(User $user, ?string $agente = null): int
    {
        // Inclui tentativas alternativas: também consomem a assinatura do provedor.
        return CentralIaExecucao::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())
            ->when($agente, fn ($q) => $q->where('agente', $agente))
            // Antes de salvar regras próprias, mantém a semântica do teto legado.
            ->when(! $agente && (! $this->pronta() || ! CentralIaPreferencia::where('user_id', $user->id)->exists()),
                fn ($q) => $q->whereHas('pedido', fn ($p) => $p->where('autor_tipo', \App\Models\CentralIaMensagem::AUTOR_USUARIO)))
            ->count();
    }

    public function pode(User $user, string $agente): bool
    {
        $r = $this->ler($user);
        $limite = $r['pedidos_dia'][$agente] ?? null;
        return ($r['habilitados'][$agente] ?? false) && ($limite === null || $this->usados($user, $agente) < $limite)
            && ($r['limite_total'] === null || $this->usados($user) < $r['limite_total']);
    }

    public function exigir(User $user, string $agente): void
    {
        if (! $this->pode($user, $agente)) {
            throw ValidationException::withMessages(['agente' => 'IA desabilitada ou limite individual de solicitações atingido. Confira seus Ajustes.']);
        }
    }
}
