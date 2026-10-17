<?php

namespace App\Services\Secretario\Acompanhamento;

use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioAcompanhamentoEnvio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Quando um resumo pode sair (FR-005, Fase 8.2): alta sai logo, normal
 * espera o intervalo, baixa nunca sai; nada no silêncio nem acima do teto do
 * dia; a mesma situação só repete depois do intervalo e até o limite.
 */
class Politica
{
    public function emSilencio(array $c, CarbonImmutable $agora): bool
    {
        if ($c['fim_de_semana'] && $agora->isWeekend()) {
            return true;
        }
        $h = $agora->format('H:i');
        [$ini, $fim] = [$c['silencio_inicio'], $c['silencio_fim']];

        return $ini === $fim ? false : ($ini < $fim ? ($h >= $ini && $h < $fim) : ($h >= $ini || $h < $fim));
    }

    /** Os que podem entrar num resumo agora, sem olhar silêncio, teto e intervalo. */
    public function devidos(User $user, array $c, CarbonImmutable $agora): Collection
    {
        return SecretarioAcompanhamento::de($user)->ativos()->where('prioridade', '!=', 'baixa')
            ->where(fn ($q) => $q->whereNull('adiado_ate')->orWhere('adiado_ate', '<=', $agora))
            ->where('avisos', '<', (int) $c['repeticoes'])
            ->where(fn ($q) => $q->whereNull('avisado_em')->orWhere('avisado_em', '<=', $agora->subHours((int) $c['repetir_h'])))
            ->get();
    }

    /** O que sai agora (vazio = nada sai), já aplicado silêncio, teto e intervalo. */
    public function resumo(User $user, CarbonImmutable $agora): Collection
    {
        $c = Regras::de($user);
        if ($this->emSilencio($c, $agora)) {
            return collect();
        }
        $devidos = $this->devidos($user, $c, $agora);
        if ($devidos->isEmpty()) {
            return $devidos;
        }
        // O sino sai em todo resumo: ele é a régua do teto e do intervalo.
        $doDia = SecretarioAcompanhamentoEnvio::de($user)->where('canal', 'sino')->where('created_at', '>=', $agora->startOfDay());
        if ((clone $doDia)->count() >= (int) $c['teto_dia']) {
            return collect();
        }
        $ultimo = SecretarioAcompanhamentoEnvio::de($user)->where('canal', 'sino')->latest('created_at')->value('created_at');
        $temAlta = $devidos->contains('prioridade', 'alta');
        if (! $temAlta && $ultimo && CarbonImmutable::parse($ultimo)->greaterThan($agora->subHours((int) $c['intervalo_h']))) {
            return collect();
        }

        return $devidos;
    }
}
