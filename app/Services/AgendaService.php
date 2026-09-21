<?php

namespace App\Services;

use App\Models\Compromisso;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Quem monta a Agenda — as três visões, o drawer do dia e a carga por pessoa.
 *
 * Existe como serviço, e não dentro do controller, porque a MESMA lista de
 * itens alimenta quatro superfícies com formatos diferentes: a coluna da
 * Semana, a célula do Mês, a linha da Lista e o drawer de um dia. Montada em
 * cada lugar, ela divergiria no primeiro caso de borda — e os casos de borda
 * aqui (tarefa travada, prazo sem reunião, compromisso que vira o dia) são
 * exatamente o que a tela existe para mostrar.
 *
 * O princípio que atravessa o arquivo: **as duas fontes nunca se misturam**.
 * Prazo de tarefa e compromisso compartilham a mesma lista e a mesma célula,
 * mas mantêm rótulo, meta e destino de clique próprios — o `tipo` de cada item
 * é o que a tela lê para não confundir "precisa estar pronto" com "as pessoas
 * se encontram".
 */
class AgendaService
{
    /**
     * Quantos dias a visão de Lista mostra.
     *
     * Vinte e um: três semanas é o horizonte em que ainda se decide alguma
     * coisa. Sete deixava a Lista igual à Semana, e um mês inteiro a enchia de
     * linhas sobre as quais ninguém age hoje.
     */
    public const DIAS_DA_LISTA = 21;

    /**
     * Os itens de uma faixa de dias, já ordenados e rotulados.
     *
     * `$pessoas` é o filtro de chips, vazio quando ninguém filtrou — e vazio
     * quer dizer TODO MUNDO, não ninguém. A diferença importa porque a tela
     * abre sem filtro nenhum, e a leitura errada faria a Agenda nascer em
     * branco.
     *
     * @param  array<int, int>  $pessoas
     * @return Collection<int, array<string, mixed>>
     */
    public function itens(Carbon $de, Carbon $ate, array $pessoas = [], ?Carbon $hoje = null): Collection
    {
        $hoje = $hoje ? $hoje->copy()->startOfDay() : now()->startOfDay();

        return $this->prazos($de, $ate, $pessoas, $hoje)
            ->concat($this->compromissos($de, $ate, $pessoas))
            // Uma chave só, concatenada, e não um `sortBy([fn, fn])`: passando
            // um ARRAY, o Laravel trata cada closure como COMPARADOR
            // (`$fn($a, $b)`), não como extrator de chave — e uma closure de um
            // argumento devolvendo "2026-09-18" vira o resultado da comparação,
            // sempre positivo. A lista saía embaralhada por dia, com outubro
            // antes de setembro. Com uma closure só, o Laravel usa o caminho de
            // extrator, que é o que se quer aqui.
            //
            // `Y-m-d` seguido de `H:i` ordena como texto exatamente como ordena
            // no tempo. O prazo não tem hora e recebe '' — que vem antes de
            // qualquer horário, e é o que se quer: o que vence naquele dia abre
            // o dia, e as reuniões seguem na ordem em que acontecem.
            ->sortBy(fn (array $item) => $item['data'].' '.$item['ordenacao'])
            ->values();
    }

    /**
     * Os prazos de tarefa da faixa.
     *
     * `with('compromissos')` não é otimização opcional: `prazoSemReuniao`
     * pergunta por reunião vinculada em cada tarefa, e sem a carga prévia a
     * tela faria uma consulta por card.
     *
     * @param  array<int, int>  $pessoas
     * @return Collection<int, array<string, mixed>>
     */
    private function prazos(Carbon $de, Carbon $ate, array $pessoas, Carbon $hoje): Collection
    {
        $consulta = Tarefa::query()
            ->with(['sistema', 'responsavel', 'compromissos'])
            ->whereNotNull('prazo')
            // `whereDate` e não `whereBetween` com as datas cruas: o cast
            // `date` do Eloquent grava sempre `Y-m-d H:i:s`, e o MySQL trunca
            // isso na coluna DATE enquanto o SQLite guarda a string inteira.
            // Comparar texto com texto acerta num banco e erra no outro — e o
            // que erra é o dos testes. É a mesma escolha que o resto do
            // repositório já faz (ver `CentroControleController::filaDeAcao`).
            ->whereDate('prazo', '>=', $de->toDateString())
            ->whereDate('prazo', '<=', $ate->toDateString())
            // Tarefa encerrada sai da Agenda: o prazo dela já não é uma
            // combinação, é história. Quem quer o que foi entregue vai ao
            // histórico do quadro, que é onde isso mora.
            ->whereNotIn('status', Tarefa::STATUS_TERMINAIS);

        if ($pessoas !== []) {
            $consulta->whereIn('responsavel_id', $pessoas);
        }

        return $consulta->get()->map(function (Tarefa $tarefa) use ($hoje) {
            $marca = $tarefa->marcaDaAgenda($hoje);

            return [
                'tipo' => 'tarefa',
                'id' => $tarefa->id,
                'data' => Carbon::parse($tarefa->prazo)->toDateString(),
                // Sem hora: o prazo é o dia inteiro. String vazia para ordenar
                // antes dos compromissos daquele dia — ver `itens`.
                'ordenacao' => '',
                'titulo' => $tarefa->titulo,
                'rotulo' => $marca ? 'Tarefa · '.$marca['sufixo'] : 'Tarefa',
                'tom' => $marca['tom'] ?? Tarefa::TOM_DA_PRIORIDADE[$tarefa->prioridade] ?? 'neutro',
                'meta' => collect([
                    $tarefa->sistema?->nome,
                    $tarefa->responsavel?->name,
                ])->filter()->implode(' · '),
                'atrasada' => Carbon::parse($tarefa->prazo)->lt($hoje),
                'pessoas' => array_filter([$tarefa->responsavel_id]),
            ];
        });
    }

    /**
     * Os compromissos da faixa.
     *
     * @param  array<int, int>  $pessoas
     * @return Collection<int, array<string, mixed>>
     */
    private function compromissos(Carbon $de, Carbon $ate, array $pessoas): Collection
    {
        return Compromisso::query()
            ->with('participantes')
            ->naFaixa($de, $ate)
            ->deParticipantes($pessoas)
            ->get()
            // `flatMap` e não `map`: um compromisso que dura vários dias vira UM
            // item POR DIA que ocupa dentro da faixa — antes ele só aparecia na
            // coluna do dia em que começava. Cada segmento diz que parte do
            // intervalo é aquele dia (começa às…, o dia todo, até…).
            ->flatMap(fn (Compromisso $c) => $this->segmentosDoCompromisso($c, $de, $ate));
    }

    /**
     * Um compromisso vira um item por dia que ele cobre dentro da faixa.
     *
     * O de um dia só é o caso comum e sai igual a antes. O de vários dias se
     * reparte: a coluna do início mostra o horário de começo, as do meio "o dia
     * todo", e a do fim "até" a hora de término — cada coluna diz honestamente
     * que fatia daquele intervalo cai ali.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function segmentosDoCompromisso(Compromisso $compromisso, Carbon $de, Carbon $ate): Collection
    {
        $inicio = $compromisso->comecaEm()->copy()->startOfDay();
        $fim = $compromisso->terminaEm()->copy()->startOfDay();

        // Só os dias do compromisso que caem dentro da janela pedida.
        $primeiro = $inicio->greaterThan($de) ? $inicio : $de->copy()->startOfDay();
        $ultimo = $fim->lessThan($ate) ? $fim : $ate->copy()->startOfDay();

        $participantes = $compromisso->participantes->pluck('name')->implode(', ') ?: null;
        $ids = $compromisso->participantes->pluck('id')->all();
        $tom = $compromisso->corToken();

        $itens = collect();

        // `daysUntil` INCLUI a data final — sem `addDay`, ao contrário do que o
        // hábito pede.
        foreach ($primeiro->daysUntil($ultimo) as $dia) {
            $ehInicio = $dia->isSameDay($compromisso->comecaEm());
            $ehFim = $dia->isSameDay($compromisso->terminaEm());
            $umDiaSo = $compromisso->comecaEm()->isSameDay($compromisso->terminaEm());

            $tempo = match (true) {
                $umDiaSo => $compromisso->intervalo(),
                $ehInicio => 'começa às '.$compromisso->comecaEm()->format('H:i'),
                $ehFim => 'até '.$compromisso->terminaEm()->format('H:i'),
                default => 'o dia todo',
            };

            $itens->push([
                'tipo' => 'compromisso',
                'id' => $compromisso->id,
                'data' => $dia->toDateString(),
                // No dia de início, a hora de começo; nos dias seguintes o
                // compromisso ocupa desde 00:00, então ordena no topo do dia.
                'ordenacao' => $ehInicio ? $compromisso->comecaEm()->format('H:i') : '00:00',
                'titulo' => $compromisso->titulo,
                'rotulo' => 'Compromisso',
                'tom' => $tom,
                'meta' => collect([$tempo, $participantes])->filter()->implode(' · '),
                'atrasada' => false,
                'pessoas' => $ids,
            ]);
        }

        return $itens;
    }

    /**
     * A semana como GRADE DE HORÁRIOS, no estilo Google Agenda.
     *
     * Cada dia ganha duas partes: a faixa "dia inteiro" no topo (prazos de
     * tarefa e compromissos de vários dias — o que não cabe num horário) e os
     * blocos posicionados na régua de horas, com altura proporcional à duração.
     *
     * As posições saem em PORCENTAGEM do dia (24h = 100%), não em pixels: a
     * grade pode ter qualquer altura na tela e continuar certa, e o CSS não
     * precisa saber quantos pixels vale uma hora. Sobreposições viram colunas
     * lado a lado — ver `posicionarBlocos`.
     *
     * @param  array<int, int>  $pessoas
     * @return array{dias: Collection<int, array<string, mixed>>, hojeIso: string, agoraPct: float|null}
     */
    public function gradeSemana(Carbon $de, Carbon $ate, array $pessoas = []): array
    {
        // O AGORA de verdade, com hora — é o que posiciona a linha vermelha. Não
        // vem por parâmetro de propósito: o controller passa a data zerada em
        // meia-noite para o resto da tela, e usá-la aqui prenderia a linha às
        // 00:00. `now()` respeita o `Carbon::setTestNow` dos testes.
        $agora = now();
        $inicioDia = $de->copy()->startOfDay();
        $fimDia = $ate->copy()->startOfDay();

        // Só os prazos de tarefa vão para a faixa "dia inteiro" — compromisso
        // com horário, mesmo de vários dias, mora na régua (ver abaixo).
        $inteiroPorDia = $this->prazos($inicioDia, $fimDia, $pessoas, $agora->copy()->startOfDay())
            ->groupBy('data');

        $blocosPorDia = [];

        $compromissos = Compromisso::query()
            ->with('participantes')
            ->naFaixa($de, $ate)
            ->deParticipantes($pessoas)
            ->get();

        foreach ($compromissos as $c) {
            $ini = $c->comecaEm()->hour * 60 + $c->comecaEm()->minute;
            $fim = $c->terminaEm()->hour * 60 + $c->terminaEm()->minute;
            $umDia = $c->comecaEm()->isSameDay($c->terminaEm());

            $meta = collect([
                $c->intervalo(),
                $c->participantes->pluck('name')->implode(', ') ?: null,
            ])->filter()->implode(' · ');

            // Todo compromisso vira bloco na régua, inclusive o de vários dias.
            // A janela [hora → hora_fim] se REPETE em cada dia coberto — um curso
            // 08–18 pinta 08–18 no dia 1 E no dia 2, não estica até a meia-noite.
            // Só o que vira o dia de verdade (hora_fim <= hora, ex.: 23h–01h) é
            // que se divide: vai até 24h no primeiro dia e começa em 0h no último.
            $primeiro = $c->comecaEm()->copy()->startOfDay()->max($inicioDia);
            $ultimo = $c->terminaEm()->copy()->startOfDay()->min($fimDia);

            foreach ($primeiro->daysUntil($ultimo) as $dia) {
                if ($umDia || $fim > $ini) {
                    $bIni = $ini;
                    $bFim = max($fim, $ini + 15); // salvaguarda: nunca altura zero
                } else {
                    // Vira o dia: primeiro dia até 24h, último a partir de 0h,
                    // dias do meio ocupam o dia inteiro.
                    $bIni = $dia->isSameDay($c->comecaEm()) ? $ini : 0;
                    $bFim = $dia->isSameDay($c->terminaEm()) ? max($fim, $bIni + 15) : 1440;
                }

                $blocosPorDia[$dia->toDateString()][] = [
                    'id' => $c->id,
                    'titulo' => $c->titulo,
                    'token' => $c->corToken(),
                    'meta' => $meta,
                    'ini' => $bIni,
                    'fim' => $bFim,
                ];
            }
        }

        $dias = collect();

        foreach ($de->daysUntil($ate) as $dia) {
            $iso = $dia->toDateString();

            $dias->push([
                'data' => $iso,
                'nome' => $dia->translatedFormat('D'),
                'numero' => $dia->format('j'),
                'ehHoje' => $iso === $agora->toDateString(),
                'inteiroDia' => ($inteiroPorDia[$iso] ?? collect())->values(),
                'blocos' => $this->posicionarBlocos($blocosPorDia[$iso] ?? []),
            ]);
        }

        return [
            'dias' => $dias,
            'hojeIso' => $agora->toDateString(),
            // A linha do "agora" só existe se hoje está na semana à vista.
            'agoraPct' => $agora->betweenIncluded($inicioDia, $fimDia->copy()->endOfDay())
                ? round(($agora->hour * 60 + $agora->minute) / 1440 * 100, 3)
                : null,
        ];
    }

    /**
     * Dá a cada bloco top/altura (em % do dia) e reparte os que se sobrepõem em
     * colunas lado a lado.
     *
     * O algoritmo é o clássico de agenda: agrupa os blocos em CLUSTERS que se
     * encadeiam por sobreposição, e dentro de cada cluster distribui em "faixas"
     * (a primeira faixa livre onde o bloco não bate no anterior). A largura de
     * cada bloco é 1/faixas do seu cluster — dois eventos ao mesmo tempo ficam
     * meia coluna cada, três ficam um terço, e assim por diante.
     *
     * @param  array<int, array<string, mixed>>  $blocos
     * @return Collection<int, array<string, mixed>>
     */
    private function posicionarBlocos(array $blocos): Collection
    {
        if ($blocos === []) {
            return collect();
        }

        usort($blocos, fn ($a, $b) => [$a['ini'], $a['fim']] <=> [$b['ini'], $b['fim']]);

        $clusters = [];
        $atual = [];
        $maiorFim = -1;

        foreach ($blocos as $b) {
            // Começou depois do fim mais tardio do cluster: abre cluster novo.
            if ($atual !== [] && $b['ini'] >= $maiorFim) {
                $clusters[] = $atual;
                $atual = [];
                $maiorFim = -1;
            }

            $atual[] = $b;
            $maiorFim = max($maiorFim, $b['fim']);
        }

        if ($atual !== []) {
            $clusters[] = $atual;
        }

        $saida = collect();

        foreach ($clusters as $grupo) {
            $faixas = []; // índice da faixa => fim do último bloco nela

            foreach ($grupo as $i => $b) {
                $col = null;
                foreach ($faixas as $f => $fimDaFaixa) {
                    if ($b['ini'] >= $fimDaFaixa) {
                        $col = $f;
                        $faixas[$f] = $b['fim'];
                        break;
                    }
                }

                if ($col === null) {
                    $col = count($faixas);
                    $faixas[] = $b['fim'];
                }

                $grupo[$i]['col'] = $col;
            }

            $cols = count($faixas);

            foreach ($grupo as $b) {
                $saida->push([
                    'id' => $b['id'],
                    'titulo' => $b['titulo'],
                    'token' => $b['token'],
                    'meta' => $b['meta'],
                    'topPct' => round($b['ini'] / 1440 * 100, 3),
                    'altPct' => round(($b['fim'] - $b['ini']) / 1440 * 100, 3),
                    'col' => $b['col'],
                    'cols' => $cols,
                ]);
            }
        }

        return $saida;
    }

    /**
     * A carga de cada pessoa num dia — o dado do drawer.
     *
     * É o número que nem o card nem o dia isolado mostram: um prazo mais duas
     * reuniões pesa tanto quanto três prazos, e só somando as duas fontes isso
     * aparece. Tarefa conta para o RESPONSÁVEL, compromisso conta para CADA
     * participante — é por isso que a soma de um dia costuma passar do número
     * de itens dele.
     *
     * Três ou mais no mesmo dia fica âmbar: é o ponto em que o dia deixa de
     * caber sem alguém remarcar alguma coisa.
     *
     * @param  Collection<int, array<string, mixed>>  $itens
     * @return Collection<int, array{id: int, nome: string, qtd: int, cheio: bool}>
     */
    public function cargaPorPessoa(Collection $itens): Collection
    {
        $contagem = [];

        foreach ($itens as $item) {
            foreach ($item['pessoas'] as $id) {
                $contagem[$id] = ($contagem[$id] ?? 0) + 1;
            }
        }

        if ($contagem === []) {
            return collect();
        }

        $nomes = User::whereIn('id', array_keys($contagem))->pluck('name', 'id');

        return collect($contagem)
            ->map(fn (int $qtd, int $id) => [
                'id' => $id,
                'nome' => $nomes[$id] ?? '—',
                'qtd' => $qtd,
                'cheio' => $qtd >= 3,
            ])
            ->sortByDesc('qtd')
            ->values();
    }

    /**
     * Quem já tem compromisso sobreposto a este intervalo, entre os candidatos.
     *
     * Serve ao chip âmbar com "· conflito" no modal. A sobreposição é estrita
     * nas pontas (`fim > inicio` e `comeco < fim`): reunião que termina às 10h
     * e outra que começa às 10h se encostam, não se chocam — e marcar isso como
     * conflito faria o aviso disparar no caso mais comum da agenda de todo
     * mundo, que é uma reunião atrás da outra.
     *
     * `$ignorar` é o próprio compromisso ao ser editado: sem ele, reabrir uma
     * reunião salva acusaria todos os participantes de conflitarem com ela
     * mesma.
     *
     * @return array<int, int> ids de quem tem conflito
     */
    public function conflitos(Carbon $inicio, Carbon $fim, array $candidatos, ?int $ignorar = null): array
    {
        if ($candidatos === []) {
            return [];
        }

        return Compromisso::query()
            ->with('participantes:id')
            ->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar))
            // A faixa de dias primeiro, para o índice de `data` trabalhar: a
            // comparação fina é feita em PHP porque o instante mora em duas
            // colunas, e recompô-lo em SQL custaria o índice.
            ->whereDate('data', '>=', $inicio->copy()->subDay()->toDateString())
            ->whereDate('data', '<=', $fim->copy()->addDay()->toDateString())
            ->get()
            ->filter(fn (Compromisso $c) => $c->comecaEm()->lt($fim) && $c->terminaEm()->gt($inicio))
            ->flatMap(fn (Compromisso $c) => $c->participantes->pluck('id'))
            ->unique()
            ->intersect($candidatos)
            ->values()
            ->all();
    }

    /**
     * A carga de cada candidato no dia — o "· 2 no dia" dos chips do modal.
     *
     * Pergunta só pelo DIA, e não pelo intervalo: o chip informa quanto aquela
     * pessoa já tem marcado naquele dia, que é contexto para decidir se vale
     * convidá-la. O choque de horário é outra pergunta, e quem a responde é
     * `conflitos`.
     *
     * @return array<int, int> id da pessoa => quantos itens no dia
     */
    public function cargaNoDia(Carbon $dia, array $candidatos): array
    {
        if ($candidatos === []) {
            return [];
        }

        $itens = $this->itens($dia, $dia, $candidatos);

        $contagem = array_fill_keys($candidatos, 0);

        foreach ($itens as $item) {
            foreach ($item['pessoas'] as $id) {
                if (array_key_exists($id, $contagem)) {
                    $contagem[$id]++;
                }
            }
        }

        return $contagem;
    }

    /**
     * As 42 células do Mês — seis semanas fixas, sempre.
     *
     * Fixas de propósito: um mês que às vezes tem cinco linhas e às vezes seis
     * faz a grade inteira mudar de altura ao navegar, e a célula de um dia
     * muda de tamanho conforme o mês em que se está olhando. Com 42 a moldura
     * não se mexe, e o preço é uma linha às vezes inteiramente do mês vizinho.
     *
     * @return array{de: Carbon, ate: Carbon}
     */
    public function faixaDoMes(Carbon $referencia): array
    {
        $de = $referencia->copy()->startOfMonth()->startOfWeek(Carbon::SUNDAY);

        return ['de' => $de, 'ate' => $de->copy()->addDays(41)];
    }

    /** @return array{de: Carbon, ate: Carbon} */
    public function faixaDaSemana(Carbon $referencia): array
    {
        $de = $referencia->copy()->startOfWeek(Carbon::SUNDAY);

        return ['de' => $de, 'ate' => $de->copy()->addDays(6)];
    }

    /**
     * A faixa da Lista: de hoje até 21 dias à frente.
     *
     * Não navega — por isso a barra esconde as setas e o "Hoje" quando a Lista
     * está aberta. O que ela ganha em troca são as ATRASADAS, que vêm de antes
     * de hoje e não caberiam numa faixa que começasse hoje: por isso o início é
     * recuado o suficiente para alcançá-las.
     *
     * @return array{de: Carbon, ate: Carbon}
     */
    public function faixaDaLista(Carbon $hoje): array
    {
        return [
            // Um ano para trás: a Agenda não é o lugar de caçar atraso antigo,
            // mas esconder o que venceu mês passado seria pior — a tarefa some
            // da tela justamente quando mais precisa aparecer.
            'de' => $hoje->copy()->subYear(),
            'ate' => $hoje->copy()->addDays(self::DIAS_DA_LISTA),
        ];
    }
}
