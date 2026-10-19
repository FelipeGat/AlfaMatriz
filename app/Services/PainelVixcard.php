<?php

namespace App\Services;

use App\Models\Compromisso;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaEntrega;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Projetos VixCard (docs/specs/acesso-vixcard): um produto por linha, com o
 * que um gestor quer de relance — o que é, como está, quanto andou, o que
 * está rodando agora, o próximo prazo, a última entrega e a próxima reunião.
 *
 * Tudo sai do quadro e da Agenda, pelas mesmas consultas que o escopo
 * `EscopoVixcard` recorta: para a conta VixCard, nada de fora entra.
 */
class PainelVixcard
{
    public const EM_ANDAMENTO = ['em_desenvolvimento', 'em_revisao', 'em_staging', 'em_producao'];

    /**
     * Quanto cada etapa vale no andamento do produto (média das frentes). Só
     * contar concluídas deixava em 0% o produto que está em teste — e o gestor
     * quer ver quanto falta, não quantas caixinhas fecharam.
     */
    public const PESO_DA_ETAPA = ['aberta' => 0, 'backlog' => 0, 'em_desenvolvimento' => 25, 'em_revisao' => 50,
        'em_staging' => 75, 'em_producao' => 90, 'concluida' => 100];

    /** Sem movimento há mais que isso, o projeto pede atenção. */
    public const PARADO_DIAS = 14;

    /** @return Collection<int, array> */
    /**
     * As pastas da tela Comercial › Projetos (Felipe, 19/10/2026): a da VixCard e
     * a da Alfa Soluções, cada uma com as naturezas de sistema que a compõem.
     */
    public const PASTAS = [
        'vixcard' => ['Projetos VixCard', ['vixcard']],
        'alfa' => ['Projetos Alfa Soluções', ['produto', 'interno']],
    ];

    public function projetos(?CarbonImmutable $hoje = null, string $pasta = 'vixcard'): Collection
    {
        $hoje ??= CarbonImmutable::today();

        return Sistema::whereIn('natureza', self::PASTAS[$pasta][1] ?? ['vixcard'])->where('ativo', true)->orderBy('nome')->get()
            ->map(fn (Sistema $s) => $this->projeto($s, $hoje));
    }

    private function projeto(Sistema $s, CarbonImmutable $hoje): array
    {
        $tarefas = Tarefa::where('sistema_id', $s->id)->foraDoArquivo()->where('status', '!=', 'cancelada')
            ->with('responsavel:id,name')->orderBy('id')->get();
        // A tarefa-mãe (a primeira raiz) é o guarda-chuva do produto: dá o
        // resumo, e só conta no andamento se alguém estiver de fato tocando nela.
        $mae = $tarefas->first(fn (Tarefa $t) => $t->tarefa_pai_id === null);
        if ($mae && in_array($mae->status, ['aberta', 'backlog'], true)) {
            $tarefas = $tarefas->reject(fn (Tarefa $t) => $t->is($mae))->values();
        }
        $abertas = $tarefas->whereNotIn('status', Tarefa::STATUS_TERMINAIS);
        $concluidas = $tarefas->where('status', 'concluida')->count();
        $atrasadas = $abertas->filter(fn (Tarefa $t) => $t->prazo && $t->prazo->lt($hoje));
        $bloqueadas = $abertas->filter(fn (Tarefa $t) => $t->bloqueado_em !== null);
        $agora = $abertas->whereIn('status', self::EM_ANDAMENTO)->sortBy(fn ($t) => array_search($t->status, self::EM_ANDAMENTO, true))->values();
        $proximoPrazo = $abertas->filter(fn (Tarefa $t) => $t->prazo && $t->prazo->gte($hoje))->sortBy('prazo')->first();
        $entrega = TarefaEntrega::whereIn('tarefa_id', $tarefas->pluck('id'))->latest('id')->with('tarefa:id,titulo')->first();
        $reuniao = Compromisso::where(fn ($q) => $q->where('sistema_id', $s->id)->orWhereIn('tarefa_id', $tarefas->pluck('id')))
            ->whereDate('data', '>=', $hoje->toDateString())->orderBy('data')->orderBy('hora')->first();
        $ultimaAtividade = $tarefas->max('updated_at');
        $parado = $abertas->isNotEmpty() && $ultimaAtividade && CarbonImmutable::instance($ultimaAtividade)->lt($hoje->subDays(self::PARADO_DIAS));

        [$situacao, $motivo] = match (true) {
            $tarefas->isEmpty() => ['sem_tarefas', 'Nenhuma frente lançada'],
            $abertas->isEmpty() => ['concluido', 'Tudo entregue'],
            $atrasadas->isNotEmpty() => ['atrasado', $atrasadas->count().' tarefa(s) com prazo vencido'],
            $bloqueadas->isNotEmpty() => ['atencao', $bloqueadas->count().' tarefa(s) travada(s)'],
            $proximoPrazo && $proximoPrazo->prazo->lte($hoje->addDays(3)) => ['atencao', 'Prazo em '.$proximoPrazo->prazo->format('d/m')],
            $parado => ['atencao', 'Sem movimento há mais de '.self::PARADO_DIAS.' dias'],
            default => ['em_dia', $agora->isNotEmpty() ? $agora->count().' em andamento' : 'Na fila'],
        };

        return [
            'sistema' => $s,
            'resumo' => $mae?->resumo ?: $mae?->titulo,
            'situacao' => $situacao,
            'motivo' => $motivo,
            'progresso' => $tarefas->isEmpty() ? 0 : (int) round($tarefas->avg(fn (Tarefa $t) => self::PESO_DA_ETAPA[$t->status] ?? 0)),
            'total' => $tarefas->count(),
            'concluidas' => $concluidas,
            'abertas' => $abertas->count(),
            'atrasadas' => $atrasadas->count(),
            'agora' => $agora->take(3)->map(fn (Tarefa $t) => ['id' => $t->id, 'titulo' => $t->titulo, 'etapa' => Tarefa::STATUS[$t->status] ?? $t->status,
                'quem' => $t->responsavel?->name])->all(),
            'em_andamento' => $agora->count(),
            'proximo_prazo' => $proximoPrazo ? ['data' => $proximoPrazo->prazo, 'titulo' => $proximoPrazo->titulo] : null,
            'entrega' => $entrega ? ['em' => $entrega->created_at, 'titulo' => $entrega->tarefa?->titulo, 'texto' => $entrega->o_que_foi_feito] : null,
            'reuniao' => $reuniao,
            'atividade' => $ultimaAtividade,
        ];
    }

    /** Os números do topo. */
    public function totais(Collection $projetos, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();

        return [
            'projetos' => $projetos->count(),
            'em_andamento' => $projetos->sum('em_andamento'),
            'atrasadas' => $projetos->sum('atrasadas'),
            'entregas_semana' => TarefaEntrega::whereIn('tarefa_id', Tarefa::whereIn('sistema_id', $projetos->pluck('sistema.id'))->select('id'))
                ->where('created_at', '>=', $hoje->subDays(7))->count(),
        ];
    }
}
