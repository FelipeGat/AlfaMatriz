<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tarefa extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Ordem do quadro do ciclo de desenvolvimento. */
    public const STATUS = [
        'aberta' => 'Aberta',
        'backlog' => 'Backlog',
        'em_desenvolvimento' => 'Em desenvolvimento',
        'em_testes' => 'Em testes',
        'ajustes_necessarios' => 'Ajustes necessários',
        'concluida' => 'Concluída',
        'cancelada' => 'Cancelada',
    ];

    public const STATUS_TERMINAIS = ['concluida', 'cancelada'];

    public const PRIORIDADES = [
        'baixa' => 'Baixa',
        'media' => 'Média',
        'alta' => 'Alta',
        'critica' => 'Crítica',
    ];

    protected $fillable = [
        'titulo', 'resumo', 'detalhes', 'sistema_id', 'responsavel_id',
        'criado_por_id', 'prioridade', 'status', 'iniciada_em',
    ];

    protected function casts(): array
    {
        return [
            'iniciada_em' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tarefa $tarefa): void {
            $tarefa->status ??= $tarefa->responsavel_id ? 'backlog' : 'aberta';
        });
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(TarefaEvento::class);
    }

    public function relatoriosTeste(): HasMany
    {
        return $this->hasMany(TarefaRelatorioTeste::class);
    }
}
