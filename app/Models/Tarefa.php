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
        'em_testes' => 'Em testes',
        'ajustes_necessarios' => 'Ajustes necessários',
        'concluida' => 'Concluída',
        'cancelada' => 'Cancelada',
    ];

    /**
     * Etapas que existiram e não existem mais.
     *
     * `bloqueada` foi coluna por um dia e virou marca (`bloqueado_em`). Ela sai
     * do fluxo, mas não some do passado: as tarefas encerradas antes da mudança
     * têm eventos que apontam para ela, e apagar esse rótulo faria o histórico
     * delas exibir a chave crua no lugar do nome da etapa.
     */
    public const ETAPAS_APOSENTADAS = [
        'bloqueada' => 'Bloqueada',
    ];

    /** O nome de uma etapa, inclusive das que já não existem no fluxo. */
    public static function rotuloDaEtapa(string $status): string
    {
        return self::STATUS[$status] ?? self::ETAPAS_APOSENTADAS[$status] ?? $status;
    }

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

    /**
     * Quantas tarefas ANDANDO cabem numa etapa antes de o quadro reclamar.
     *
     * Só as etapas de trabalho têm limite. Fila não tem WIP: encher o Backlog
     * não atrapalha ninguém, e um alarme ali só ensinaria a ignorar o alarme.
     */
    public const LIMITE_DE_WIP = [
        'em_desenvolvimento' => 3,
        'em_testes' => 3,
    ];

    /**
     * A partir de quantas horas parada numa etapa a tarefa vira aviso.
     *
     * Cada etapa tem a sua régua porque o que é normal em uma é sintoma na
     * outra: três dias escrevendo código é trabalho, três dias esperando alguém
     * testar é fila. O AC-093 media só Aberta e Em testes — mas a tarefa que
     * mais apodrece é a de Em andamento parada há dias, e ela não era medida.
     *
     * Backlog fica de fora: lá a tarefa está esperando a vez, e ficar parada é
     * o que ela deve fazer.
     */
    public const HORAS_ATE_ENVELHECER = [
        'aberta' => 24,
        'em_desenvolvimento' => 72,
        'em_testes' => 24,
        'ajustes_necessarios' => 48,
    ];

    /**
     * A escala de gravidade, do mais discreto ao mais grave — e, no fim, a
     * ausência de decisão.
     *
     * "A definir" não é um grau da escala: é a tarefa que ainda não foi
     * triada. Ela existe para que quem abre uma tarefa sem poder priorizá-la
     * não empurre o cadastro para "Média" por omissão, transformando o padrão
     * numa afirmação que ninguém fez.
     */
    public const PRIORIDADES = [
        'baixa' => 'Baixa',
        'media' => 'Média',
        'alta' => 'Alta',
        'critica' => 'Crítica',
        'nao_definida' => 'A definir',
    ];

    /**
     * O tom do selo de cada prioridade, num lugar só.
     *
     * Vivia duplicado no card e no histórico, e a escala já tinha errado uma
     * vez por isso — dois níveis no mesmo neutro, indistinguíveis. Uma escala
     * copiada é uma escala que vai divergir.
     */
    public const TOM_DA_PRIORIDADE = [
        'baixa' => 'neutro',
        'media' => 'marca',
        'alta' => 'ambar',
        'critica' => 'critico',
        'nao_definida' => 'atencao',
    ];

    protected $fillable = [
        'titulo', 'resumo', 'detalhes', 'tipo', 'sistema_id', 'responsavel_id',
        'criado_por_id', 'prioridade', 'status', 'iniciada_em',
    ];

    protected function casts(): array
    {
        return [
            'iniciada_em' => 'datetime',
            'bloqueado_em' => 'datetime',
        ];
    }

    /**
     * A tarefa está travada esperando alguém?
     *
     * O bloqueio é marca e não etapa: a tarefa continua na coluna em que
     * estava, e é `bloqueado_em` que responde por ele. Fora do `fillable` de
     * propósito — quem bloqueia passa pelo `FluxoTarefaService`, que exige o
     * motivo; um `update` de formulário não deveria conseguir travar tarefa.
     */
    public function estaBloqueada(): bool
    {
        return $this->bloqueado_em !== null;
    }

    /** Há quanto tempo está travada, na régua curta do quadro ("3h", "2d"). */
    public function bloqueadaHa(): ?string
    {
        if (! $this->estaBloqueada()) {
            return null;
        }

        return self::duracaoCurta((int) $this->bloqueado_em->diffInSeconds(now()));
    }

    /**
     * O texto da tarja: "Bloqueada agora" ou "Bloqueada há 2d".
     *
     * O "há" some no primeiro minuto porque a régua curta devolve a palavra
     * "agora" para ele, e "bloqueada há agora" não é frase — é o que sai quando
     * se concatena rótulo com medida sem olhar o resultado na tela.
     */
    public function rotuloDoBloqueio(): ?string
    {
        $duracao = $this->bloqueadaHa();

        return match (true) {
            $duracao === null => null,
            $duracao === 'agora' => 'Bloqueada agora',
            default => 'Bloqueada há '.$duracao,
        };
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

    /**
     * O checklist da tarefa, na ordem escolhida por quem escreveu.
     *
     * A ordem vive na relação, e não em cada tela, pelo mesmo motivo dos
     * comentários: uma lista de conferência fora de ordem não é uma lista
     * bagunçada — é um passo antes do passo que o habilita.
     */
    public function itens(): HasMany
    {
        return $this->hasMany(TarefaItem::class)->orderBy('ordem')->orderBy('id');
    }

    /**
     * O progresso do checklist, ou null quando não há checklist.
     *
     * Devolve null em vez de "0 de 0" porque tarefa sem checklist não está com
     * o checklist zerado — ela não tem um, e o card não deve anunciar um vazio
     * como se fosse pendência.
     *
     * @return array{feitos: int, total: int}|null
     */
    public function progressoDoChecklist(): ?array
    {
        $total = $this->itens->count();

        if ($total === 0) {
            return null;
        }

        return ['feitos' => $this->itens->where('feito', true)->count(), 'total' => $total];
    }
}
