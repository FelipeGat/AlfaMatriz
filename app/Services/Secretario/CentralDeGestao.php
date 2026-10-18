<?php

namespace App\Services\Secretario;

use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioEmail;
use App\Models\SecretarioGestorRetrato;
use App\Models\SecretarioItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A Central de gestão em Acompanhamentos (docs/specs/secretario-central-do-dia,
 * FR-003/FR-004): os números do dia e um bloco por empresa — o Grupo Soluções
 * pelo retrato do Gestor, a VixCard (ainda sem sistema) pelos pontos que o
 * dono lança. Tudo da própria conta, sem IA.
 */
class CentralDeGestao
{
    public const EMPRESAS = ['grupo_solucoes' => 'Grupo Soluções', 'vixcard' => 'VixCard', 'invest' => 'Invest', 'delta' => 'Delta'];

    /** As que têm calendário próprio na Minha agenda; as demais caem em "Outros". */
    public const COM_CALENDARIO = ['grupo_solucoes', 'vixcard'];

    public function __construct(private AgendaConsolidada $agenda) {}

    /** @return array{compromissos: int, da_agenda: int, do_secretario: int, nao_lidos: int, prioritarios: int, vencidas: int, alertas: int} */
    public function numeros(User $user, CarbonImmutable $agora): array
    {
        $hoje = $this->agenda->periodo($user, $agora->startOfDay(), $agora->endOfDay())
            ->reject(fn ($i) => $i['origem'] === 'secretario' && $i['dia_inteiro']);

        $naoLidos = SecretarioEmail::de($user)->where('enviado', false)->where('lido', false)
            ->where('recebido_em', '>=', $agora->subDays(\App\Services\Secretario\Email\LeitorDeEmails::DIAS_NAO_LIDOS)->startOfDay());

        return [
            'compromissos' => $hoje->count(),
            'da_agenda' => $hoje->where('origem', '!=', 'secretario')->count(),
            'do_secretario' => $hoje->where('origem', 'secretario')->count(),
            'nao_lidos' => (clone $naoLidos)->count(),
            'prioritarios' => (clone $naoLidos)->where(fn ($q) => $q->where('urgencia', 'alta')->orWhere(fn ($w) => $w->where('pede_retorno', true)->where('respondido', false)))->count(),
            'vencidas' => $this->vencidas($user, $agora)->count(),
            'alertas' => SecretarioAcompanhamento::de($user)->where('situacao', SecretarioAcompanhamento::ATIVO)->count(),
        ];
    }

    /** O retrato do Gestor, bloco a bloco, com a hora; null se a máquina ainda não leu. */
    public function grupoSolucoes(User $user): ?array
    {
        $r = SecretarioGestorRetrato::where('user_id', $user->id)->first();
        if (! $r) {
            return null;
        }
        $blocos = [];
        foreach (SecretarioGestorRetrato::BLOCOS as $chave => $rotulo) {
            $b = $r->dados['blocos'][$chave] ?? null;
            if ($b) {
                $itens = collect($b['itens'] ?? [])->sortBy(fn ($i) => $i['data'] ?? '9999')->take(5)->values()->all();
                $blocos[$chave] = ['rotulo' => $rotulo, 'quantidade' => (int) $b['quantidade'], 'valor' => $b['valor'] ?? null, 'itens' => $itens];
            }
        }

        return ['lido_em' => $r->lido_em, 'velho' => $r->velho(), 'blocos' => $blocos];
    }

    /** @return Collection<int, SecretarioItem> pontos lançados à mão, abertos, da empresa */
    public function pontos(User $user, string $empresa): Collection
    {
        return SecretarioItem::de($user)->where('empresa', $empresa)->where('status', SecretarioItem::ABERTO)
            ->orderByRaw('quando is null')->orderBy('quando')->orderBy('id')->get();
    }

    public function vencidas(User $user, CarbonImmutable $agora): Collection
    {
        return SecretarioItem::de($user)->where('status', SecretarioItem::ABERTO)->whereIn('tipo', ['tarefa', 'acompanhamento'])
            ->whereNotNull('quando')->where('quando', '<', $agora)->get()
            ->filter(fn ($i) => ($i->so_data ? CarbonImmutable::instance($i->quando)->endOfDay() : CarbonImmutable::instance($i->quando))->lessThan($agora))->values();
    }

    public static function atrasado(SecretarioItem $i, ?CarbonImmutable $agora = null): bool
    {
        $agora ??= CarbonImmutable::now();

        return $i->quando !== null && ($i->so_data ? CarbonImmutable::instance($i->quando)->endOfDay() : CarbonImmutable::instance($i->quando))->lessThan($agora);
    }

    /** Categoria (aba) de cada regra de alerta. */
    private const CATEGORIA_DA_REGRA = ['tarefa_vencida' => 'tarefas', 'entrega_proxima' => 'tarefas', 'reuniao_proxima' => 'reunioes',
        'revisao_de_reuniao' => 'reunioes', 'conflito' => 'reunioes', 'cliente_sem_resposta' => 'emails', 'proposta_parada' => 'propostas'];

    /** bloco do Gestor => [aba, status, prioridade] */
    private const BLOCO_DO_GESTOR = [
        'orcamentos_aprovacao' => ['propostas', 'Aguardando aprovação', 'normal'], 'orcamentos_elaboracao' => ['propostas', 'Em elaboração', 'baixa'],
        'cobrancas_vencidas' => ['financeiro', 'Vencido', 'alta'], 'cobrancas_hoje' => ['financeiro', 'Vence hoje', 'alta'],
        'pagar_vencendo' => ['financeiro', 'A pagar', 'alta'], 'reunioes_proximo_passo' => ['clientes', 'Próximo passo', 'normal'],
        'funil_parado' => ['clientes', 'Parado', 'baixa'],
    ];

    public const ABAS = ['pontos' => 'Pontos de gestão', 'projetos' => 'Projetos', 'clientes' => 'Clientes', 'propostas' => 'Propostas', 'tarefas' => 'Tarefas',
        'reunioes' => 'Reuniões', 'emails' => 'E-mails', 'financeiro' => 'Financeiro'];

    /**
     * A tabela da Central (desenho de 17/10/2026): pontos lançados, alertas
     * das regras e os itens do retrato do Gestor numa lista só, cada linha com
     * aba, origem, prazo, situação e prioridade.
     *
     * @return Collection<int, array>
     */
    public function linhas(User $user, CarbonImmutable $agora): Collection
    {
        $linhas = collect();
        foreach (SecretarioItem::de($user)->whereNotNull('empresa')->where('status', SecretarioItem::ABERTO)->get() as $i) {
            $quando = $i->quando ? CarbonImmutable::instance($i->quando) : null;
            $atrasado = self::atrasado($i, $agora);
            $linhas->push(['aba' => 'pontos', 'icone' => 'flag', 'assunto' => $i->titulo, 'sub' => 'Lançado por você',
                'origem' => self::EMPRESAS[$i->empresa] ?? $i->empresa, 'chip' => 'Ponto de gestão', 'quando' => $quando, 'so_data' => $i->so_data,
                'status' => $atrasado ? 'Vencido' : 'Em aberto', 'prioridade' => $i->prioridade, 'link' => null,
                'acoes' => [['Concluir', route('secretario.acompanhamentos.pontos.concluir', $i->id)]]]);
        }
        foreach (SecretarioAcompanhamento::de($user)->ativos()->where('chave', '!=', 'proposta_parada:gestor')->get() as $a) {
            $linhas->push(['aba' => self::CATEGORIA_DA_REGRA[$a->regra] ?? 'tarefas', 'icone' => ['cliente_sem_resposta' => 'envelope', 'proposta_parada' => 'document',
                'reuniao_proxima' => 'calendar', 'revisao_de_reuniao' => 'speaker-wave', 'conflito' => 'calendar'][$a->regra] ?? 'check-circle',
                'assunto' => $a->texto, 'sub' => \App\Services\Secretario\Acompanhamento\Regras::REGRAS[$a->regra][0] ?? $a->regra,
                'origem' => 'Secretário', 'chip' => 'Alerta · regra', 'quando' => $a->referencia_em ? CarbonImmutable::instance($a->referencia_em) : null, 'so_data' => false,
                'status' => $a->adiado() ? 'Adiado' : ($a->regra === 'tarefa_vencida' ? 'Vencido' : 'Em aberto'), 'prioridade' => $a->prioridade, 'link' => $a->link,
                'acoes' => array_values(array_filter([['Resolvido', route('secretario.acompanhamentos.resolver', $a->id)], $a->adiado() ? null : ['Adiar 1 dia', route('secretario.acompanhamentos.adiar', $a->id)]]))]);
        }
        $gestor = $this->grupoSolucoes($user);
        foreach ($gestor['blocos'] ?? [] as $chave => $b) {
            [$aba, $status, $prioridade] = self::BLOCO_DO_GESTOR[$chave] ?? ['financeiro', 'Em aberto', 'normal'];
            foreach ($this->itensDoBloco($user, $chave) as $it) {
                [$assunto, $cliente] = array_pad(explode(' — ', (string) $it['texto'], 2), 2, null);
                // Cobranças e contas vêm "CLIENTE — descrição"; orçamentos, "Orç. N — CLIENTE".
                if ($aba === 'financeiro' && $cliente !== null) {
                    [$assunto, $cliente] = [$cliente, $assunto];
                }
                $linhas->push(['aba' => $aba, 'icone' => $aba === 'financeiro' ? 'banknotes' : ($aba === 'propostas' ? 'document' : 'users'),
                    'assunto' => $assunto, 'sub' => $b['rotulo'].(isset($it['valor']) ? ' · R$ '.number_format($it['valor'], 2, ',', '.') : ''),
                    'origem' => $cliente ?: 'Grupo Soluções', 'chip' => 'Gestor', 'quando' => isset($it['data']) ? CarbonImmutable::parse($it['data']) : null,
                    'so_data' => true, 'status' => $status, 'prioridade' => $prioridade, 'link' => null, 'acoes' => []]);
            }
        }
        // Projetos abertos da VixCard, do retrato do VixOrganize
        // (docs/specs/secretario-vixorganize): a posição na fila vira prioridade.
        $vix = \App\Models\SecretarioVixOrganize::daConta($user);
        foreach ($vix?->projetos() ?? [] as $p) {
            if (($p['status'] ?? '') === 'concluido') {
                continue;
            }
            $prazo = ! empty($p['due_date']) ? CarbonImmutable::parse($p['due_date']) : null;
            $linhas->push(['aba' => 'projetos', 'icone' => 'view-grid', 'assunto' => $p['name'],
                'sub' => '#'.$p['rank'].' na fila · '.($p['current_phase'] ?? 'sem fase').' · '.(int) $p['progress'].'%',
                'origem' => 'VixCard', 'chip' => 'VixOrganize', 'quando' => $prazo, 'so_data' => true,
                'status' => $prazo && $prazo->endOfDay()->lessThan($agora) ? 'Vencido' : ($p['status_label'] ?? 'Em aberto'),
                'prioridade' => $p['rank'] <= 1 ? 'alta' : ($p['rank'] <= 3 ? 'normal' : 'baixa'),
                'link' => route('secretario.vixorganize', ['projeto' => $p['slug']]), 'acoes' => []]);
        }
        $peso = ['alta' => 0, 'normal' => 1, 'baixa' => 2];

        return $linhas->map(fn ($l) => $l + ['prazo' => PainelDoDia::prazo($l['quando'], $agora)])
            ->sortBy(fn ($l) => sprintf('%d%d%s', $l['status'] === 'Vencido' ? 0 : 1, $peso[$l['prioridade']] ?? 1, $l['quando']?->format('YmdHi') ?? '99999999'))
            ->values();
    }

    /** Os itens que o retrato trouxe do bloco (até 10 por bloco, os que a máquina lê). */
    private function itensDoBloco(User $user, string $chave): array
    {
        $r = SecretarioGestorRetrato::where('user_id', $user->id)->first();

        return $r->dados['blocos'][$chave]['itens'] ?? [];
    }

    /** O que aconteceu por último: e-mails, itens, reuniões e alertas novos. @return Collection<int, array{titulo: string, sub: string, em: CarbonImmutable, icone: string}> */
    public function atividade(User $user, int $quantas = 6): Collection
    {
        $a = collect();
        foreach (SecretarioEmail::de($user)->where('enviado', false)->latest('recebido_em')->limit($quantas)->get() as $e) {
            $a->push(['titulo' => 'E-mail recebido', 'sub' => ($e->de_nome ?: $e->de_email).' · '.$e->assunto, 'em' => CarbonImmutable::instance($e->recebido_em), 'icone' => 'envelope']);
        }
        foreach (SecretarioItem::de($user)->latest('id')->limit($quantas)->get() as $i) {
            $a->push(['titulo' => $i->origem === 'anotacao' ? 'Anotação' : ($i->empresa ? 'Ponto lançado' : ucfirst($i->tipo).' criado'), 'sub' => $i->titulo,
                'em' => CarbonImmutable::instance($i->created_at), 'icone' => $i->empresa ? 'flag' : 'check-circle']);
        }
        foreach (\App\Models\SecretarioReuniao::de($user)->latest('id')->limit($quantas)->get() as $r) {
            $a->push(['titulo' => 'Reunião registrada', 'sub' => $r->titulo, 'em' => CarbonImmutable::instance($r->created_at), 'icone' => 'speaker-wave']);
        }

        return $a->sortByDesc(fn ($x) => $x['em']->timestamp)->take($quantas)->values();
    }
}
