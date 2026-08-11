<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TarefaRelatorioTeste extends Model
{
    protected $table = 'tarefa_relatorios_teste';

    protected $fillable = [
        'tarefa_id', 'tarefa_evento_id', 'aprovado', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'aprovado' => 'boolean',
        ];
    }

    /**
     * O relatório se carimba com a etapa em que a tarefa está.
     *
     * O carimbo é resolvido AQUI, e não em quem chama, porque ele não é um dado
     * que alguém escolhe: é sempre a etapa aberta da tarefa naquele instante. Se
     * dependesse de cada chamador lembrar de passá-lo, o dia em que alguém
     * esquecesse não daria erro nenhum — daria um relatório que serve para
     * qualquer passagem, que é exatamente o problema que ele existe para
     * resolver.
     *
     * Tarefa sem evento nenhum (nunca se moveu) fica com o vínculo nulo, e o
     * portão compara nulo com nulo: também é uma passagem, a primeira.
     */
    protected static function booted(): void
    {
        static::creating(function (TarefaRelatorioTeste $relatorio): void {
            if ($relatorio->tarefa_evento_id !== null) {
                return;
            }

            $relatorio->tarefa_evento_id = TarefaEvento::query()
                ->where('tarefa_id', $relatorio->tarefa_id)
                ->whereNull('saiu_em')
                ->latest('entrou_em')
                ->value('id');
        });
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    /** A passagem por Em testes de que este relatório é a prova. */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(TarefaEvento::class, 'tarefa_evento_id');
    }
}
