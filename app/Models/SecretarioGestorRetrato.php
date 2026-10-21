<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** O último retrato das pendências do Gestor, mandado pela máquina da pessoa. */
class SecretarioGestorRetrato extends Model
{
    public const BLOCOS = [
        'orcamentos_aprovacao' => 'orçamento(s) aguardando aprovação',
        'orcamentos_elaboracao' => 'orçamento(s) em elaboração',
        'cobrancas_vencidas' => 'cobrança(s) vencida(s)',
        'cobrancas_hoje' => 'cobrança(s) vencendo hoje',
        'pagar_vencendo' => 'conta(s) a pagar vencida(s) ou de hoje',
        'reunioes_proximo_passo' => 'reunião(ões) de cliente com próximo passo',
        'funil_parado' => 'lead(s) parado(s) no funil há mais de 7 dias',
    ];
    public const VELHO_HORAS = 3;

    protected $table = 'secretario_gestor_retratos';

    protected $fillable = ['user_id', 'maquina', 'lido_em', 'dados'];

    protected function casts(): array
    {
        return ['lido_em' => 'datetime', 'dados' => 'array'];
    }

    public function velho(): bool
    {
        return $this->lido_em->lessThan(now()->subHours(self::VELHO_HORAS));
    }
}
