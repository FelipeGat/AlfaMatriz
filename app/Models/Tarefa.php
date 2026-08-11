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

    /**
     * Ordem do quadro.
     *
     * "Em andamento" e não mais "Em desenvolvimento": a coluna passou a receber
     * também tarefa operacional, que não é desenvolvida — e um telefonema para
     * o fabricante parado numa coluna chamada "Em desenvolvimento" faria a
     * coluna mentir. A CHAVE continua `em_desenvolvimento` de propósito: ela
     * está gravada em milhares de linhas de `tarefa_eventos`, e renomear o dado
     * para arrumar um rótulo de tela reescreveria histórico.
     */
    public const STATUS = [
        'aberta' => 'Aberta',
        'backlog' => 'Backlog',
        'em_desenvolvimento' => 'Em andamento',
        'bloqueada' => 'Bloqueada',
        'em_testes' => 'Em testes',
        'ajustes_necessarios' => 'Ajustes necessários',
        'concluida' => 'Concluída',
        'cancelada' => 'Cancelada',
    ];

    /**
     * O tipo escolhe o fluxo da tarefa (ver `FluxoTarefaService`).
     *
     * Não é o mesmo eixo da prioridade: "crítica" dizia ao mesmo tempo que a
     * tarefa é grave e que ela é do ciclo de desenvolvimento. Separando, a
     * gravidade continua sendo gravidade e o tipo passa a responder por onde a
     * tarefa anda — e se ela precisa de teste aprovado para fechar.
     */
    public const TIPOS = [
        'desenvolvimento' => 'Desenvolvimento',
        'operacional' => 'Operacional',
    ];

    public const STATUS_TERMINAIS = ['concluida', 'cancelada'];

    public const PRIORIDADES = [
        'baixa' => 'Baixa',
        'media' => 'Média',
        'alta' => 'Alta',
        'critica' => 'Crítica',
    ];

    protected $fillable = [
        'titulo', 'resumo', 'detalhes', 'tipo', 'sistema_id', 'responsavel_id',
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
            $tarefa->tipo ??= 'desenvolvimento';
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

    /**
     * A conversa da tarefa, do mais antigo ao mais novo (US-049).
     *
     * A ordem vive na relação, e não em cada tela, porque comentário fora de
     * ordem não é uma lista bagunçada — é uma resposta antes da pergunta.
     */
    public function comentarios(): HasMany
    {
        return $this->hasMany(TarefaComentario::class)->oldest();
    }

    /**
     * Duração em forma curta: "agora", "40m", "3h", "12d".
     *
     * Uma régua só para o quadro e o histórico: o chip do card mede o tempo na
     * etapa atual e a linha do histórico mede o ciclo inteiro, mas os dois
     * precisam falar a mesma língua — "3h" tem de querer dizer a mesma coisa
     * nas duas telas.
     */
    public static function duracaoCurta(int $segundos): string
    {
        return match (true) {
            $segundos < 60 => 'agora',
            $segundos < 3600 => intdiv($segundos, 60).'m',
            $segundos < 86400 => intdiv($segundos, 3600).'h',
            default => intdiv($segundos, 86400).'d',
        };
    }

    /**
     * Quanto a tarefa levou da criação até entrar na etapa terminal (AC-133).
     *
     * É o número que justifica cronometrar cada etapa: sem ele, os eventos
     * seriam registro que ninguém lê. Devolve null enquanto a tarefa não
     * encerrou — aí não há ciclo fechado para medir.
     */
    public function duracaoDoCiclo(): ?int
    {
        if (! in_array($this->status, self::STATUS_TERMINAIS, true)) {
            return null;
        }

        $encerramento = $this->eventos
            ->filter(fn (TarefaEvento $evento) => in_array($evento->para_status, self::STATUS_TERMINAIS, true))
            ->sortByDesc('entrou_em')
            ->first()?->entrou_em ?? $this->updated_at;

        return max(0, (int) $this->created_at->diffInSeconds($encerramento));
    }

    public function relatoriosTeste(): HasMany
    {
        return $this->hasMany(TarefaRelatorioTeste::class);
    }
}
