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
        $regras = array_replace_recursive([
            'revisao_config' => 0,
            'coordenador_codex' => false,
            'padroes' => ['implementacao' => 'claude', 'investigacao' => 'codex', 'revisao' => 'codex', 'planejamento' => 'codex', 'relatorio' => 'copilot', 'apoio' => 'copilot'],
            'habilitados' => ['claude' => true, 'codex' => true, 'copilot' => true],
            'pedidos_dia' => ['claude' => null, 'codex' => null, 'copilot' => null],
            'limite_total' => config('central_ia.agentes.limite_diario_ativo') ? (int) config('central_ia.agentes.limite_diario') : null,
            'paralelo' => max(1, (int) config('central_ia.agentes.paralelo')),
            'fallback' => (bool) config('central_ia.agentes.fallback_automatico'),
            'niveis' => config('central_ia.niveis'),
        ], is_array($salvas) ? $salvas : []);
        $regras['execucao_bloqueada'] = app(Governanca::class)->execucaoBloqueada($user, $salvas['governanca'] ?? null);
        if ($g = $salvas['governanca'] ?? null) {
            foreach (array_keys(CentralIaExecucao::AGENTES) as $a) {
                $regras['habilitados'][$a] = $regras['habilitados'][$a] && ($g['habilitados'][$a] ?? false);
                $regras['pedidos_dia'][$a] = $this->menor($regras['pedidos_dia'][$a], array_key_exists($a, $g['pedidos_dia'] ?? []) ? $g['pedidos_dia'][$a] : 0);
            }
            $regras['limite_total'] = $this->menor($regras['limite_total'], array_key_exists('limite_total', $g) ? $g['limite_total'] : 0);
            $regras['paralelo'] = min($regras['paralelo'], $g['paralelo'] ?? 1);
        }
        return $regras;
    }

    public const NIVEIS = ['sem_ia', 'leve', 'medio', 'forte'];

    public const ESFORCOS = ['low', 'medium', 'high'];

    /**
     * Modelo e esforço do agente naquele nível, para esta conta. `sem_ia` não
     * aciona modelo nenhum.
     *
     * @return array{modelo: ?string, esforco: ?string}
     */
    public function modelo(User $user, string $nivel, string $agente): array
    {
        if ($nivel === 'sem_ia') return ['modelo' => null, 'esforco' => null];
        $m = $this->ler($user)['niveis'][$nivel][$agente] ?? config("central_ia.niveis.{$nivel}.{$agente}") ?? [];

        return ['modelo' => $m['modelo'] ?? null, 'esforco' => $m['esforco'] ?? null];
    }

    private function menor(?int $a, ?int $b): ?int
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }

    public function ordem(User $user, string $categoria): array
    {
        $r = $this->ler($user);
        if ($r['execucao_bloqueada']) return [];
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
        if ($r['execucao_bloqueada']) return false;
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
