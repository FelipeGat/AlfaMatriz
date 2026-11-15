<?php

namespace App\Services\Executivo;

use App\Models\Notificacao;
use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioEmail;
use App\Models\SecretarioItem;
use App\Models\SecretarioReuniao;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\OQueEsperaVoce;
use App\Services\Secretario\CentralDeGestao;
use App\Services\Secretario\ConsultaDeEmails;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Acompanhamentos para a API Executiva (spec `api-executiva-acompanhamentos`).
 *
 * Camada fina sobre o que a tela já usa. Duas regras que a tela não tem:
 * a consulta é COMPLETA antes de qualquer limite (o `total` é o do conjunto
 * inteiro, nunca o da página), e a ordem é estável (prazo, prioridade,
 * atualização, id) para a página 2 não repetir nem pular item.
 *
 * Nada é escondido nem fundido: notificação e a pendência que a originou
 * voltam as DUAS, ligadas por `notificacao_relacionada` e `possivel_duplicata_de`
 * (FR-103/104). Quem junta na narrativa é o adaptador.
 */
class Acompanhamentos
{
    public const SECOES = ['decisoes', 'perguntas', 'pontos', 'alertas', 'emails', 'notificacoes', 'atividade'];

    public const LIMITE_PADRAO = 50;

    public const LIMITE_MAXIMO = 100;

    /** Janela do histórico e da atividade, em dias: o que é mais velho não é "recente". */
    private const DIAS_RECENTES = 7;

    private const PESO_PRIORIDADE = ['alta' => 0, 'normal' => 1, 'baixa' => 2];

    /**
     * Tipo do aviso => tipo da pendência que ele anuncia. Só liga o que a regra
     * do quadro prova: aviso de outro tipo (comentário, bloqueio...) na mesma
     * tarefa não é a mesma obrigação e fica sem ligação.
     */
    private const PENDENCIA_DO_AVISO = ['pergunta' => 'pergunta', 'retorno' => 'retorno', 'teste_staging' => 'validar'];

    /**
     * Permissão exigida por seção — a mesma da tela. Acompanhamentos é do
     * Secretário (`secretario`,`ler`); decisões e perguntas vêm do quadro
     * (`OQueEsperaVoce::podeVer`, que exige `tarefas`,`ler`). `alertas` junta
     * os dois: os pontos do Secretário e o "sem validador" do quadro, que só
     * entra se o quadro também puder ser lido.
     */
    private const PERMISSAO_DA_SECAO = [
        'decisoes' => 'quadro', 'perguntas' => 'quadro',
        'pontos' => 'secretario', 'alertas' => 'secretario', 'emails' => 'secretario',
        'notificacoes' => 'secretario', 'atividade' => 'secretario',
    ];

    /** @var Collection<int, Notificacao>|null avisos da requisição em curso */
    private ?Collection $avisosDaRequisicao = null;

    public function __construct(
        private OQueEsperaVoce $espera,
        private ItemExecutivo $itens,
        private ConsultaDeEmails $emails,
    ) {}

    /**
     * @param  array{pagina?: int, limite?: int}  $p
     * @return array<string, mixed> o envelope
     */
    public function montar(User $user, ?string $secao, array $p = []): array
    {
        $fonte = 'Acompanhamentos da Matriz (quadro, Secretário e avisos)';

        // Fora do `proteger`: ali a exceção viraria `falha`, e seção desconhecida é pedido errado (422).
        if ($secao !== null && ! in_array($secao, self::SECOES, true)) {
            throw ValidationException::withMessages(['secao' => 'Seção desconhecida.']);
        }

        $this->avisosDaRequisicao = null;

        return Envelope::proteger($fonte, null, function () use ($user, $secao, $p, $fonte) {
            $agora = CarbonImmutable::now();
            $periodo = 'condição atual; histórico e atividade dos últimos '.self::DIAS_RECENTES.' dias';

            if ($secao === null) {
                $totais = [];
                $semAcesso = [];
                foreach (self::SECOES as $s) {
                    $totais[$s] = $this->podeVerSecao($user, $s) ? count($this->conjunto($user, $s, $agora)) : null;
                    if ($totais[$s] === null) {
                        $semAcesso[] = $s;
                    }
                }

                // Seção sem permissão sai `null` (e listada), nunca 0: zero diria "nada pendente".
                return Envelope::montar($fonte, $periodo, null, 'ok', $semAcesso ? 'Sem permissão de leitura em: '.implode(', ', $semAcesso).'.' : null,
                    ['secoes' => $totais, 'sem_acesso' => $semAcesso, 'cobertura' => $this->cobertura()]);
            }

            if (! $this->podeVerSecao($user, $secao)) {
                return Envelope::montar($fonte, $periodo, null, 'nao_configurado', 'Sem permissão de leitura nesta seção.', [
                    'secao' => $secao, 'total' => null, 'retornados' => 0, 'limite' => null, 'pagina' => 1,
                    'truncado' => false, 'itens' => [], 'cobertura' => $this->cobertura(),
                ]);
            }

            $limite = min(self::LIMITE_MAXIMO, max(1, (int) ($p['limite'] ?? self::LIMITE_PADRAO)));
            $pagina = max(1, (int) ($p['pagina'] ?? 1));

            $todos = $this->ordenar($this->conjunto($user, $secao, $agora));
            $total = $todos->count();
            $pagina_itens = $todos->slice(($pagina - 1) * $limite, $limite)->values()->all();
            $situacao = 'ok';
            $motivo = null;

            if ($secao === 'emails') {
                $estado = $this->emails->situacaoEmail($user)['estado'] ?? 'nao_configurado';
                [$situacao, $motivo] = match ($estado) {
                    'ok' => ['ok', null],
                    'nao_configurado' => ['nao_configurado', 'Nenhuma caixa de e-mail configurada.'],
                    default => ['desatualizado', 'A leitura do e-mail está com problema ('.$estado.'): pode faltar mensagem.'],
                };
            }

            return Envelope::montar($fonte, $periodo, null, $situacao, $motivo, [
                'secao' => $secao,
                'total' => $total,
                'retornados' => count($pagina_itens),
                'limite' => $limite,
                'pagina' => $pagina,
                'truncado' => $total > $pagina * $limite,
                'itens' => $pagina_itens,
                'cobertura' => $this->cobertura(),
            ]);
        });
    }

    private function podeVerSecao(User $user, string $secao): bool
    {
        return match (self::PERMISSAO_DA_SECAO[$secao]) {
            'quadro' => $this->espera->podeVer($user),
            default => $user->canPermissao('secretario', 'ler'),
        };
    }

    /** O que esta API NÃO cobre — dito em toda resposta (FR-108). @return list<string> */
    private function cobertura(): array
    {
        return [
            'Próximos passos de clientes, chamados e propostas vêm do Gestor, não daqui.',
            'Cobranças item a item da Matriz: ainda não nesta versão.',
            'E-mail: classe sempre indefinida; nenhuma mensagem prova demanda de cliente.',
            'Texto livre (títulos, assuntos, remetentes) é omitido de propósito.',
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function conjunto(User $user, string $secao, CarbonImmutable $agora): Collection
    {
        $itens = match ($secao) {
            'decisoes' => $this->pendencias($user, $agora, ['validar', 'retorno', 'prazo'], $this->avisos($user, $agora)),
            'perguntas' => $this->pendencias($user, $agora, ['pergunta'], $this->avisos($user, $agora)),
            'pontos' => $this->pontos($user, $agora),
            'alertas' => $this->alertas($user, $agora)->concat($this->pendencias($user, $agora, ['sem_validador'], collect())),
            'emails' => $this->emails($user),
            'notificacoes' => $this->notificacoes($this->avisos($user, $agora), $user),
            'atividade' => $this->atividade($user, $agora),
        };

        return $itens->map(fn (array $c) => $this->itens->montar($c));
    }

    // ───────────────────────── seções

    /**
     * @param  list<string>  $tipos
     * @param  Collection<int, Notificacao>  $avisos
     * @return Collection<int, array<string, mixed>>
     */
    private function pendencias(User $user, CarbonImmutable $agora, array $tipos, Collection $avisos): Collection
    {
        if (! $this->espera->podeVer($user)) {
            return collect();
        }

        $tipoDoItem = ['pergunta' => 'pergunta', 'validar' => 'validacao', 'retorno' => 'retorno', 'prazo' => 'prazo', 'sem_validador' => 'alerta'];
        $hoje = $agora->startOfDay();

        return $this->espera->pendencias($user)->filter(fn ($i) => in_array($i['tipo'], $tipos, true))->map(function (array $i) use ($tipoDoItem, $avisos, $hoje) {
            /** @var Tarefa $t */
            $t = $i['tarefa'];
            // Vários avisos da mesma tarefa: só os do tipo desta pendência, o ainda aberto antes do já tratado.
            $aviso = $avisos
                ->filter(fn (Notificacao $n) => $n->tarefa_id === $t->id && (self::PENDENCIA_DO_AVISO[$n->tipo] ?? null) === $i['tipo'])
                ->sortBy([fn ($a, $b) => ($a->lida_em !== null) <=> ($b->lida_em !== null), fn ($a, $b) => $b->created_at <=> $a->created_at, fn ($a, $b) => $b->id <=> $a->id])
                ->first();
            $semValidador = $i['tipo'] === 'sem_validador';

            return [
                'id' => 'matriz:tarefa:'.$t->id.':'.$i['tipo'],
                'tipo' => $tipoDoItem[$i['tipo']] ?? 'alerta',
                // Quem deve agir é a conta do token — a regra de `OQueEsperaVoce` já filtrou por ela.
                // Sem validador é de organizar o quadro, nunca aprovação pessoal (FR-115).
                'ator_esperado' => $semValidador ? 'indefinido' : 'felipe',
                'responsavel_id' => $t->responsavel_id,
                'situacao' => match (true) {
                    $i['tipo'] === 'pergunta' => 'aguardando_resposta',
                    $i['tipo'] === 'validar' => 'aguardando_aprovacao',
                    $i['tipo'] === 'prazo' && $t->prazo && CarbonImmutable::parse($t->prazo)->startOfDay()->lt($hoje) => 'vencida',
                    default => ItemExecutivo::SITUACAO_DA_TAREFA[$t->status] ?? 'desconhecida',
                },
                'prioridade' => ItemExecutivo::PRIORIDADE_DA_TAREFA[$t->prioridade] ?? null,
                'prazo' => $t->prazo ? CarbonImmutable::parse($t->prazo) : null,
                'atualizado_em' => $t->updated_at,
                'aberta' => true,
                'notificacao_relacionada' => $aviso ? 'matriz:notificacao:'.$aviso->id : null,
                'link' => route('tarefas.index', ['tarefa' => $t->id], false),
            ];
        })->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function pontos(User $user, CarbonImmutable $agora): Collection
    {
        $desde = $agora->subDays(self::DIAS_RECENTES);

        return SecretarioItem::de($user)->whereNotNull('empresa')
            ->where(fn ($q) => $q->where('status', SecretarioItem::ABERTO)->orWhere('updated_at', '>=', $desde))
            ->get()->map(function (SecretarioItem $i) {
                $aberto = $i->status === SecretarioItem::ABERTO;

                return [
                    'id' => 'matriz:ponto:'.$i->id,
                    'tipo' => 'ponto_gestao',
                    'ator_esperado' => 'felipe',
                    'responsavel_id' => $i->user_id,
                    // Só a empresa do grupo, de lista fechada; fora dela, null.
                    'cliente_empresa' => CentralDeGestao::EMPRESAS[$i->empresa] ?? null,
                    'situacao' => match ($i->status) {
                        SecretarioItem::ABERTO => $i->quando && CentralDeGestao::atrasado($i) ? 'vencida' : 'aberta',
                        SecretarioItem::CONCLUIDO => 'concluida',
                        SecretarioItem::CANCELADO => 'cancelada',
                        default => 'desconhecida',
                    },
                    'prioridade' => $i->prioridade,
                    'prazo' => $i->quando,
                    'atualizado_em' => $i->updated_at,
                    'aberta' => $aberto,
                ];
            })->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function alertas(User $user, CarbonImmutable $agora): Collection
    {
        return SecretarioAcompanhamento::de($user)->ativos()->get()->map(fn (SecretarioAcompanhamento $a) => [
            'id' => 'matriz:alerta:'.$a->id,
            'tipo' => 'alerta',
            'ator_esperado' => 'felipe',
            'responsavel_id' => $a->user_id,
            'situacao' => 'aberta',
            'prioridade' => $a->prioridade,
            'prazo' => $a->referencia_em,
            'atualizado_em' => $a->updated_at,
            'aberta' => true,
            // A rota do alerta é texto gravado; a tela de Acompanhamentos é o destino seguro.
            'link' => route('secretario.acompanhamentos', [], false),
        ])->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function emails(User $user): Collection
    {
        return SecretarioEmail::de($user)->aguardando()->get()->map(fn (SecretarioEmail $e) => [
            'id' => 'matriz:email:'.$e->id,
            'tipo' => 'email',
            // Nenhum metadado prova demanda de cliente nem obrigação do Felipe (FR-105).
            'classe' => 'indefinida',
            'ator_esperado' => 'indefinido',
            'situacao' => 'aguardando_resposta',
            'atualizado_em' => $e->recebido_em,
            'aberta' => true,
            'link' => route('secretario.emails.index', [], false),
        ])->values();
    }

    /**
     * Abertas (não lidas) e as tratadas há pouco. Cada uma aponta a pendência
     * que a originou, se há; a pendência aponta de volta (em `pendencias`).
     *
     * @param  Collection<int, Notificacao>  $avisos
     * @return Collection<int, array<string, mixed>>
     */
    private function notificacoes(Collection $avisos, User $user): Collection
    {
        $pendentes = $this->espera->podeVer($user) ? $this->espera->pendencias($user) : collect();

        return $avisos->map(function (Notificacao $n) use ($pendentes) {
            $aberta = $n->lida_em === null;
            $tipoDaPendencia = self::PENDENCIA_DO_AVISO[$n->tipo] ?? null;
            $pendencia = $n->tarefa_id && $tipoDaPendencia
                ? $pendentes->first(fn ($p) => $p['tarefa']->id === $n->tarefa_id && $p['tipo'] === $tipoDaPendencia)
                : null;

            return [
                'id' => 'matriz:notificacao:'.$n->id,
                'tipo' => 'notificacao',
                'ator_esperado' => 'indefinido',
                // Aviso não lido é só um aviso: não prova que a obrigação continua aberta (FR-115).
                'situacao' => $aberta ? 'aberta' : 'concluida',
                'atualizado_em' => $n->lida_em ?? $n->created_at,
                'aberta' => $aberta,
                'possivel_duplicata_de' => $pendencia ? 'matriz:tarefa:'.$pendencia['tarefa']->id.':'.$pendencia['tipo'] : null,
                'link' => $n->tarefa_id ? route('tarefas.index', ['tarefa' => $n->tarefa_id], false) : null,
            ];
        })->values();
    }

    /** Só tipo e hora: o que aconteceu, sem dizer o quê. @return Collection<int, array<string, mixed>> */
    private function atividade(User $user, CarbonImmutable $agora): Collection
    {
        $desde = $agora->subDays(self::DIAS_RECENTES);
        $evento = fn (string $tipo, int $id, CarbonInterface $em) => [
            'id' => 'matriz:atividade:'.$tipo.':'.$id,
            'tipo' => 'atividade',
            'ator_esperado' => 'indefinido',
            'situacao' => 'desconhecida',
            'atualizado_em' => $em,
            'aberta' => false,
        ];

        return collect()
            ->concat(SecretarioEmail::de($user)->where('enviado', false)->where('recebido_em', '>=', $desde)->get()
                ->map(fn ($e) => $evento('email', $e->id, $e->recebido_em)))
            ->concat(SecretarioItem::de($user)->where('created_at', '>=', $desde)->get()
                ->map(fn ($i) => $evento('item', $i->id, $i->created_at)))
            ->concat(SecretarioReuniao::de($user)->where('created_at', '>=', $desde)->get()
                ->map(fn ($r) => $evento('reuniao', $r->id, $r->created_at)));
    }

    /** @return Collection<int, Notificacao> */
    private function avisos(User $user, CarbonImmutable $agora): Collection
    {
        // Uma leitura por requisição: sem `secao`, sete conjuntos dividem a mesma consulta.
        return $this->avisosDaRequisicao ??= Notificacao::query()->where('destinatario_id', $user->id)
            ->where(fn ($q) => $q->whereNull('lida_em')->orWhere('lida_em', '>=', $agora->subDays(self::DIAS_RECENTES)))
            ->get();
    }

    // ───────────────────────── ordem

    /**
     * A ordem estável da spec: prazo (nulo por último), prioridade (nula por
     * último), atualização (mais nova antes) e id como desempate.
     *
     * @param  Collection<int, array<string, mixed>>  $itens
     * @return Collection<int, array<string, mixed>>
     */
    private function ordenar(Collection $itens): Collection
    {
        return $itens->sort(function (array $a, array $b) {
            return [$a['prazo'] === null, $a['prazo'] ?? ''] <=> [$b['prazo'] === null, $b['prazo'] ?? '']
                ?: [self::peso($a['prioridade'])] <=> [self::peso($b['prioridade'])]
                ?: strcmp((string) ($b['atualizado_em'] ?? ''), (string) ($a['atualizado_em'] ?? ''))
                ?: strcmp($a['id'], $b['id']);
        })->values();
    }

    private static function peso(?string $prioridade): int
    {
        return $prioridade === null ? 99 : (self::PESO_PRIORIDADE[$prioridade] ?? 99);
    }
}
