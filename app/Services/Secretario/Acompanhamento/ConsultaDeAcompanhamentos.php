<?php

namespace App\Services\Secretario\Acompanhamento;

use App\Models\SecretarioAcompanhamento;
use App\Models\User;
use App\Services\Secretario\Memoria\BuscaNaMemoria;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * "O que preciso acompanhar?" pelo chat e pelo Telegram (FR-009), sem IA. As
 * regras rodam na hora (sem enviar nada), para a resposta não esperar a
 * próxima conferência de 5 min.
 */
class ConsultaDeAcompanhamentos
{
    private const PERGUNTA = '/^((o que|oque|que)\s+(eu\s+)?(preciso|tenho que|devo)\s+acompanhar|(meus|quais (sao )?(os )?meus)\s+acompanhamentos|acompanhamentos)$/u';
    public const MAX = 15;

    public function __construct(private Motor $motor) {}

    public function responder(User $user, string $texto, ?CarbonImmutable $agora = null): ?string
    {
        $t = preg_replace('/^(matriz|secretario)[,:!]?\s+/u', '', rtrim(BuscaNaMemoria::normalizar(trim($texto)), ' ?!.'));
        if (! preg_match(self::PERGUNTA, $t)) {
            return null;
        }
        $this->motor->rodar($user, $agora ?? CarbonImmutable::now());
        $ativos = SecretarioAcompanhamento::de($user)->ativos()->get();
        if ($ativos->isEmpty()) {
            return '🔔 Nada pedindo acompanhamento agora, pelas regras ligadas. Regras: '.route('secretario.acompanhamentos');
        }
        $linhas = ['🔔 Para acompanhar ('.$ativos->count().'):'];
        foreach ($ativos->take(self::MAX) as $a) {
            $linhas[] = $a->marca().' '.Str::limit($a->texto, 180).($a->adiado() ? ' (adiado até '.$a->adiado_ate->format('d/m H:i').')' : '');
        }
        if ($ativos->count() > self::MAX) {
            $linhas[] = '+'.($ativos->count() - self::MAX).' na tela';
        }
        $linhas[] = 'Ver e resolver: '.route('secretario.acompanhamentos');

        return implode("\n", $linhas);
    }
}
