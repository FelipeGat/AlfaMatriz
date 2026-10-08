<?php

namespace App\Services;

use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "O que espera você" — o que no quadro está parado esperando ESTA pessoa (#300).
 *
 * Nasceu de as pessoas não verem o que era delas: perguntas respondidas só
 * quando alguém cobrava, e tarefas semanas em staging sem ninguém apontado
 * para validar. O sino já avisava cada um desses EVENTOS — mas aviso lido some,
 * e a tarefa continua esperando. Isto aqui é a CONDIÇÃO, recalculada a cada
 * abertura, como a fila de ação do Centro de Controle: nada é guardado, e por
 * isso nada fica velho.
 *
 * Uma regra só para as três portas — o painel do Centro de Controle, o botão
 * do quadro e o aviso ao entrar —, para as três nunca discordarem.
 *
 * Em toda consulta: só o que está em curso (encerrada não espera ninguém) e
 * fora do arquivo (arquivar foi tirar da frente de propósito, #208).
 *
 * Duas consultas no total, e não uma por pergunta: o Centro de Controle tem
 * orçamento de 30 (AC-245), e cinco perguntas mais os carregamentos de cada
 * uma o estouravam. Uma consulta traz toda tarefa que pode interessar à pessoa,
 * já com o apontado e a entrada da passagem aberta; a outra diz quais delas já
 * foram aprovadas nesta passagem. O resto é filtro em memória.
 */
class OQueEsperaVoce
{
    /** Quanto o "Lembrar mais tarde" do aviso adia — decisão do dono, 07/10/2026. */
    public const HORAS_DO_ADIAMENTO = 2;

    /** @var array<int, Collection<int, Tarefa>> */
    private array $carregadas = [];

    /**
     * Quem vê o painel: quem lê o quadro, menos a conta de exibição.
     *
     * A exibição é o painel de parede — uma tela de TV não tem nada esperando
     * por ela, e o painel ali seria uma caixa vazia ocupando o lugar do quadro.
     * A revenda também fica de fora: o quadro é da matriz.
     */
    public function podeVer(?User $usuario): bool
    {
        return $usuario !== null
            && ! $usuario->temEscopoDeRevenda()
            && $usuario->canPermissao('tarefas', 'ler')
            && ! $usuario->ehContaDeExibicao();
    }

    /**
     * Quem recebe o aviso ao entrar: só quem pode AGIR no quadro, e não
     * silenciou.
     *
     * O aviso cobra uma ação — responder, validar, corrigir. Mostrá-lo a quem
     * não pode mover nem responder seria cobrar o que a rota vai recusar.
     */
    public function avisaAoEntrar(?User $usuario): bool
    {
        return $this->podeVer($usuario)
            && $usuario->podeMexerNoQuadro()
            && ($usuario->espera_silenciada_ate === null || $usuario->espera_silenciada_ate->isPast());
    }

    /**
     * O que pede uma ação desta pessoa — o conteúdo do aviso e do painel.
     *
     * A ordem é a da urgência para quem lê: primeiro o que trava alguém
     * esperando uma resposta dela, depois o exame, a correção e o prazo. "Sem
     * validador" vem por último porque é de organizar, não de fazer.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pendencias(User $usuario, ?Carbon $agora = null): Collection
    {
        $agora ??= now();
        $tarefas = $this->carregar($usuario);

        return collect()
            ->concat($this->perguntas($tarefas, $usuario, $agora))
            ->concat($this->validacoes($tarefas, $usuario, $agora))
            ->concat($this->retornos($tarefas, $usuario, $agora))
            ->concat($this->prazos($tarefas, $usuario, $agora))
            ->concat($this->semValidador($tarefas, $usuario, $agora))
            ->values();
    }

    /**
     * As tarefas em que esta pessoa está envolvida: faz, abriu ou valida.
     *
     * "Valida" é o apontado da passagem, e não o `interlocutor_id` — o
     * interlocutor é de quem está a vez na CONVERSA e muda a cada pergunta.
     * As mais paradas primeiro: é o tempo parado que esta lista existe para
     * mostrar.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function minhas(User $usuario, ?Carbon $agora = null): Collection
    {
        $agora ??= now();

        return $this->carregar($usuario)
            ->filter(fn (Tarefa $t) => $t->responsavel_id === $usuario->id
                || $t->criado_por_id === $usuario->id
                || $this->apontado($t) === $usuario->id)
            ->map(function (Tarefa $tarefa) use ($usuario, $agora) {
                $desde = $this->entrouNaEtapa($tarefa);
                $segundos = $desde ? (int) $desde->diffInSeconds($agora, true) : 0;

                $papeis = array_values(array_filter([
                    $tarefa->responsavel_id === $usuario->id ? 'você faz' : null,
                    $this->apontado($tarefa) === $usuario->id ? 'você valida' : null,
                    $tarefa->criado_por_id === $usuario->id ? 'você abriu' : null,
                ]));

                return [
                    'tarefa' => $tarefa,
                    'etapa' => Tarefa::rotuloDaEtapa($tarefa->status),
                    'cor' => Tarefa::corDaEtapa($tarefa->status),
                    'papel' => implode(' · ', $papeis),
                    'parada' => $desde ? Tarefa::duracaoCurta($segundos) : null,
                    'segundos' => $segundos,
                    'nivel' => $this->nivelDoTempoParado($tarefa, $desde, $agora),
                    'rota' => $this->rota($tarefa),
                ];
            })
            ->sortByDesc('segundos')
            ->values();
    }

    /** "Ok, vi": quieto até o fim do dia. */
    public function marcarVisto(User $usuario): void
    {
        $usuario->forceFill(['espera_silenciada_ate' => now()->endOfDay()])->save();
    }

    /** "Lembrar mais tarde": quieto por algumas horas. */
    public function adiar(User $usuario): void
    {
        $usuario->forceFill(['espera_silenciada_ate' => now()->addHours(self::HORAS_DO_ADIAMENTO)])->save();
    }

    // ── A carga ─────────────────────────────────────────────────────────────

    /**
     * Toda tarefa em curso que pode interessar a esta pessoa, numa consulta.
     *
     * O apontado e a entrada da passagem aberta vêm como subconsulta, e não
     * como relação carregada: são dois valores por tarefa, e carregar os
     * eventos custaria uma consulta a mais para trazê-los. Lidos do EVENTO
     * aberto — o mesmo lugar de `Tarefa::apontadoDestaPassagem`.
     *
     * Guardada por pessoa durante a requisição: o painel e o "Minhas tarefas"
     * saem da mesma carga.
     *
     * @return Collection<int, Tarefa>
     */
    private function carregar(User $usuario): Collection
    {
        if (isset($this->carregadas[$usuario->id])) {
            return $this->carregadas[$usuario->id];
        }

        $passagemAberta = fn (string $coluna) => TarefaEvento::query()
            ->select($coluna)
            ->whereColumn('tarefa_eventos.tarefa_id', 'tarefas.id')
            ->whereNull('saiu_em')
            ->orderByDesc('entrou_em')
            ->limit(1);

        $triagem = $usuario->podeTriarTarefas();

        $tarefas = Tarefa::query()
            ->whereNotIn('status', Tarefa::STATUS_TERMINAIS)
            ->foraDoArquivo()
            ->where(fn (Builder $q) => $q
                ->where('pergunta_para_id', $usuario->id)
                ->orWhere('responsavel_id', $usuario->id)
                ->orWhere('criado_por_id', $usuario->id)
                ->orWhere(fn (Builder $q) => $q->apontadaPara($usuario->id))
                // Quem faz triagem também precisa dos portões sem examinador.
                ->when($triagem, fn (Builder $q) => $q->orWhere(fn (Builder $q) => $q
                    ->whereIn('status', Tarefa::PORTOES_DE_EXAME)
                    ->semApontado())))
            ->select('tarefas.*')
            ->addSelect([
                'espera_apontado_id' => $passagemAberta('apontado_id'),
                'espera_entrou_em' => $passagemAberta('entrou_em'),
                'espera_pergunta_de' => User::query()->select('name')
                    ->whereColumn('users.id', 'tarefas.pergunta_de_id')->limit(1),
            ])
            ->get();

        // Aprovada nesta passagem não espera mais o exame — espera a tag ou a
        // conclusão. Pergunta feita só quando há tarefa num portão.
        $nosPortoes = $tarefas->whereIn('status', Tarefa::PORTOES_DE_EXAME)->pluck('id');
        $aprovadas = $nosPortoes->isEmpty() ? collect() : Tarefa::query()
            ->whereIn('id', $nosPortoes)
            ->aprovadaNestaPassagem()
            ->pluck('id');

        $tarefas->each(fn (Tarefa $t) => $t->setAttribute('espera_aprovada', $aprovadas->contains($t->id)));

        return $this->carregadas[$usuario->id] = $tarefas;
    }

    // ── As cinco perguntas ──────────────────────────────────────────────────

    private function perguntas(Collection $tarefas, User $usuario, Carbon $agora): Collection
    {
        return $tarefas
            ->filter(fn (Tarefa $t) => $t->pergunta_em !== null && $t->pergunta_para_id === $usuario->id)
            ->sortBy('pergunta_em')
            ->map(fn (Tarefa $tarefa) => $this->item($tarefa, [
                'tipo' => 'pergunta',
                'motivo' => 'Pergunta'.($tarefa->espera_pergunta_de ? ' de '.$tarefa->espera_pergunta_de : '').' esperando você',
                'desde' => $tarefa->pergunta_em,
                'nivel' => 'atencao',
                'icone' => 'duvida',
                'acao' => 'Responder',
            ], $agora));
    }

    /** Apontada para ela examinar, e ainda sem veredito aprovado. */
    private function validacoes(Collection $tarefas, User $usuario, Carbon $agora): Collection
    {
        return $tarefas
            ->filter(fn (Tarefa $t) => in_array($t->status, Tarefa::PORTOES_DE_EXAME, true)
                && $this->apontado($t) === $usuario->id
                && ! $t->espera_aprovada)
            ->sortBy('espera_entrou_em')
            ->map(function (Tarefa $tarefa) use ($agora) {
                $desde = $this->entrouNaEtapa($tarefa);

                return $this->item($tarefa, [
                    'tipo' => 'validar',
                    'motivo' => 'Para você '.($tarefa->status === 'em_revisao' ? 'revisar' : 'validar')
                        .' · '.Tarefa::rotuloDaEtapa($tarefa->status),
                    'desde' => $desde,
                    'nivel' => $this->nivelDoTempoParado($tarefa, $desde, $agora) ?? 'marca',
                    'icone' => 'eye',
                    'acao' => $tarefa->status === 'em_revisao' ? 'Revisar' : 'Validar',
                ], $agora);
            });
    }

    /** Voltou de um portão e está com ela para corrigir. */
    private function retornos(Collection $tarefas, User $usuario, Carbon $agora): Collection
    {
        return $tarefas
            ->filter(fn (Tarefa $t) => $t->retorno_de !== null && $t->responsavel_id === $usuario->id)
            ->sortBy('espera_entrou_em')
            ->map(fn (Tarefa $tarefa) => $this->item($tarefa, [
                'tipo' => 'retorno',
                'motivo' => $tarefa->rotuloDoRetorno(),
                'desde' => $this->entrouNaEtapa($tarefa),
                'nivel' => 'atencao',
                'icone' => 'arrow-uturn-left',
                'acao' => 'Corrigir',
            ], $agora));
    }

    /** Prazo vencido, de hoje ou de amanhã — o de amanhã ainda dá para salvar. */
    private function prazos(Collection $tarefas, User $usuario, Carbon $agora): Collection
    {
        $hoje = $agora->copy()->startOfDay();

        return $tarefas
            ->filter(fn (Tarefa $t) => $t->prazo !== null
                && $t->responsavel_id === $usuario->id
                && Carbon::parse($t->prazo)->startOfDay()->lte($hoje->copy()->addDay()))
            ->sortBy(fn (Tarefa $t) => Carbon::parse($t->prazo)->toDateString())
            ->map(function (Tarefa $tarefa) use ($hoje, $agora) {
                $dias = (int) $hoje->diffInDays(Carbon::parse($tarefa->prazo)->startOfDay(), false);

                return $this->item($tarefa, [
                    'tipo' => 'prazo',
                    'motivo' => match (true) {
                        $dias < 0 => 'Prazo venceu há '.abs($dias).' dia'.(abs($dias) > 1 ? 's' : ''),
                        $dias === 0 => 'Prazo vence hoje',
                        default => 'Prazo vence amanhã',
                    },
                    'desde' => null,
                    'nivel' => $dias < 0 ? 'critico' : 'atencao',
                    'icone' => 'clock',
                    'acao' => 'Abrir',
                ], $agora);
            });
    }

    /**
     * Em portão sem ninguém apontado — para quem organiza o quadro.
     *
     * Sem apontado a coluna vira fila de ninguém: foi assim que duas tarefas
     * passaram 40 dias em staging. Vai para quem faz triagem, que é quem
     * escolhe quem examina, e não para todo mundo — cobrar a equipe inteira
     * pelo que só a triagem resolve ensina todos a ignorar o aviso.
     */
    private function semValidador(Collection $tarefas, User $usuario, Carbon $agora): Collection
    {
        if (! $usuario->podeTriarTarefas()) {
            return collect();
        }

        return $tarefas
            ->filter(fn (Tarefa $t) => in_array($t->status, Tarefa::PORTOES_DE_EXAME, true)
                && $this->apontado($t) === null
                && ! $t->espera_aprovada)
            ->sortBy('espera_entrou_em')
            ->map(function (Tarefa $tarefa) use ($agora) {
                $desde = $this->entrouNaEtapa($tarefa);

                return $this->item($tarefa, [
                    'tipo' => 'sem_validador',
                    'motivo' => 'Ninguém apontado para '.($tarefa->status === 'em_revisao' ? 'revisar' : 'validar')
                        .' · '.Tarefa::rotuloDaEtapa($tarefa->status),
                    'desde' => $desde,
                    'nivel' => $this->nivelDoTempoParado($tarefa, $desde, $agora) ?? 'marca',
                    'icone' => 'user-plus',
                    'acao' => 'Apontar',
                ], $agora);
            });
    }

    // ── Peças ───────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $dados */
    private function item(Tarefa $tarefa, array $dados, Carbon $agora): array
    {
        $desde = $dados['desde'] ? Carbon::parse($dados['desde']) : null;

        return $dados + [
            'tarefa' => $tarefa,
            'ha' => $desde ? Tarefa::duracaoCurta((int) $desde->diffInSeconds($agora, true)) : null,
            'rota' => $this->rota($tarefa),
        ];
    }

    private function apontado(Tarefa $tarefa): ?int
    {
        return $tarefa->espera_apontado_id !== null ? (int) $tarefa->espera_apontado_id : null;
    }

    private function entrouNaEtapa(Tarefa $tarefa): ?Carbon
    {
        return $tarefa->espera_entrou_em ? Carbon::parse($tarefa->espera_entrou_em) : null;
    }

    /**
     * A mesma régua do card: passou do limiar da etapa é atenção, do dobro é
     * crítico. Null quando a etapa não envelhece ou ainda está no prazo.
     */
    private function nivelDoTempoParado(Tarefa $tarefa, ?Carbon $desde, Carbon $agora): ?string
    {
        $limiar = Tarefa::HORAS_ATE_ENVELHECER[$tarefa->status] ?? null;

        if ($limiar === null || $desde === null) {
            return null;
        }

        $horas = $desde->diffInHours($agora, true);

        return match (true) {
            $horas >= $limiar * 2 => 'critico',
            $horas >= $limiar => 'atencao',
            default => null,
        };
    }

    /** O quadro com o detalhe da tarefa já aberto. */
    private function rota(Tarefa $tarefa): string
    {
        return route('tarefas.index', ['tarefa' => $tarefa->id]);
    }
}
