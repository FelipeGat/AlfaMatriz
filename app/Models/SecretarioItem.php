<?php

namespace App\Models;

use App\Services\Secretario\Datas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Item pessoal do Secretário (docs/specs/secretario-executivo). De UMA conta:
 * nunca entra na Agenda nem no Quadro do time, nem para administrador.
 */
class SecretarioItem extends Model
{
    public const TIPOS = ['tarefa', 'lembrete', 'compromisso', 'acompanhamento'];
    public const ABERTO = 'aberto';
    public const CONCLUIDO = 'concluido';
    public const CANCELADO = 'cancelado';

    protected $table = 'secretario_itens';

    /** Os padrões da tabela também no objeto recém-criado (sem eles, `status` nulo parecia encerrado). */
    protected $attributes = ['status' => self::ABERTO, 'so_data' => false, 'prioridade' => 'normal', 'fuso' => 'America/Sao_Paulo'];

    protected $fillable = [
        'user_id', 'tipo', 'titulo', 'descricao', 'quando', 'so_data', 'fuso', 'antecedencia_min', 'prioridade',
        'status', 'recorrencia', 'origem', 'origem_ref', 'concluido_em', 'cancelado_em', 'empresa',
    ];

    protected function casts(): array
    {
        return [
            'quando' => 'datetime', 'so_data' => 'boolean', 'recorrencia' => 'array',
            'concluido_em' => 'datetime', 'cancelado_em' => 'datetime',
        ];
    }

    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function avisos(): HasMany
    {
        return $this->hasMany(SecretarioAviso::class, 'item_id');
    }

    public function encerrado(): bool
    {
        return $this->status !== self::ABERTO;
    }

    /** O que a tela e a API mostram — sem `user_id`. */
    public function paraTela(): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'titulo' => $this->titulo,
            'descricao' => $this->descricao,
            'quando' => $this->quando?->format('Y-m-d\TH:i:s'),
            'quando_texto' => $this->quando ? Datas::porExtenso($this->quando, $this->so_data) : null,
            'so_data' => $this->so_data,
            'prioridade' => $this->prioridade,
            'status' => $this->status,
            'recorrencia_texto' => $this->recorrencia ? Datas::recorrencia($this->recorrencia, $this->quando) : null,
            'atrasado' => $this->status === self::ABERTO && $this->quando !== null
                && ($this->so_data ? $this->quando->copy()->endOfDay() : $this->quando)->isPast(),
        ];
    }
}
