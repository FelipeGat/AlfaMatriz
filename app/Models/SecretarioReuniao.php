<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reunião (ou ditado) por áudio de UMA conta (docs/specs/secretario-reunioes).
 * O áudio só existe até a transcrição: depois `arquivo` fica nulo.
 */
class SecretarioReuniao extends Model
{
    public const AGUARDANDO = 'aguardando';
    public const TRANSCREVENDO = 'transcrevendo';
    public const ANALISANDO = 'analisando';
    public const PRONTA = 'pronta';
    public const REVISADA = 'revisada';
    public const FALHOU = 'falhou';

    public const TIPOS = ['comercial', 'diretoria', 'tecnica', 'financeira', 'planejamento', 'atendimento', 'brainstorming', 'interna', 'outro'];
    public const ROTULOS_STATUS = [
        'aguardando' => 'Aguardando sua máquina', 'transcrevendo' => 'Transcrevendo', 'analisando' => 'Analisando',
        'pronta' => 'Pronta para revisão', 'revisada' => 'Revisada', 'falhou' => 'Falhou',
    ];

    protected $table = 'secretario_reunioes';

    protected $fillable = [
        'user_id', 'titulo', 'origem', 'modo', 'status', 'erro', 'arquivo', 'mime', 'tamanho', 'sha256', 'duracao_s', 'maquina',
        'reservada_em', 'tentativas', 'tipo_reuniao', 'contexto', 'resumo', 'participantes', 'proximos_passos', 'roteiro', 'parcial',
        'execucao_id', 'telegram_update_id', 'transcrita_em', 'analisada_em',
    ];

    protected $attributes = ['status' => self::AGUARDANDO, 'modo' => 'reuniao', 'tentativas' => 0, 'parcial' => false];

    protected function casts(): array
    {
        return [
            'contexto' => 'array', 'participantes' => 'array', 'proximos_passos' => 'array', 'roteiro' => 'array', 'parcial' => 'boolean',
            'reservada_em' => 'datetime', 'transcrita_em' => 'datetime', 'analisada_em' => 'datetime',
        ];
    }

    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function trechos(): HasMany
    {
        return $this->hasMany(SecretarioReuniaoTrecho::class, 'reuniao_id')->orderBy('ordem');
    }

    public function propostas(): HasMany
    {
        return $this->hasMany(SecretarioReuniaoProposta::class, 'reuniao_id')->orderBy('id');
    }
}
