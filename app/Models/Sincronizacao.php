<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O registro de uma execução de sincronização.
 *
 * Existe para responder "o que aconteceu da última vez, e quando". Sem ele,
 * uma rotina que parou de rodar fica indistinguível de uma que rodou e não
 * encontrou nada — foi assim que o agendamento deste projeto ficou fora do ar
 * sem ninguém perceber.
 */
class Sincronizacao extends Model
{
    use HasFactory;

    protected $table = 'sincronizacoes';

    protected $fillable = [
        'sistema_id', 'escopo', 'competencia', 'origem', 'status',
        'iniciada_em', 'finalizada_em', 'duracao_ms',
        'itens_lidos', 'itens_criados', 'itens_atualizados', 'itens_ausentes',
        'erro_codigo', 'erro_mensagem', 'disparada_por',
    ];

    protected $attributes = [
        'escopo' => 'completa',
        'origem' => 'agendada',
        'status' => 'em_andamento',
        'itens_lidos' => 0,
        'itens_criados' => 0,
        'itens_atualizados' => 0,
        'itens_ausentes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'iniciada_em' => 'datetime',
            'finalizada_em' => 'datetime',
            'duracao_ms' => 'integer',
            'itens_lidos' => 'integer',
            'itens_criados' => 'integer',
            'itens_atualizados' => 'integer',
            'itens_ausentes' => 'integer',
        ];
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    public function disparadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disparada_por');
    }

    public function deuCerto(): bool
    {
        return $this->status === 'sucesso';
    }

    /**
     * Entrou em parte: alguma etapa falhou, mas o que já tinha entrado ficou.
     * Não é sucesso nem é falha, e tratar como um dos dois esconde o problema.
     */
    public function foiParcial(): bool
    {
        return $this->status === 'parcial';
    }

    public function scopeConcluidas(Builder $consulta): Builder
    {
        return $consulta->whereIn('status', ['sucesso', 'parcial', 'falha']);
    }
}
