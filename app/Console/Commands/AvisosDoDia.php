<?php

namespace App\Console\Commands;

use App\Models\ClienteSistema;
use App\Models\Cobranca;
use App\Models\ContaPagar;
use App\Models\Lead;
use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Os avisos ANTECIPATÓRIOS do dia — o que vai vencer, não o que já venceu.
 *
 * O sino guarda EVENTOS; as CONDIÇÕES ("3 receitas em atraso", "lead parado há
 * mais de 30 dias") já vivem na fila de ação do Centro de Controle, que as
 * recalcula. Este comando não pisa nessa linha: ele avisa ANTES — a despesa que
 * vence amanhã, a licença que expira em uma semana —, uma vez, pela véspera.
 * É o buraco que a fila não cobre: ela mostra o que já deu errado; isto avisa
 * para não dar.
 *
 * Roda uma vez por dia (ver `routes/console.php`), e é a própria cadência que
 * garante o "uma vez": cada item cruza o marco ("amanhã", "daqui a 7 dias")
 * numa única passada. Sem marca de deduplicação, como o lembrete de prazo.
 *
 * Quem recebe segue o padrão de `User::idsDeQuemVe`: quem enxerga a área. Só o
 * lead tem dono (o vendedor), e vai direto para ele.
 */
class AvisosDoDia extends Command
{
    protected $signature = 'avisos:do-dia';

    protected $description = 'Avisos antecipatórios: despesas, receitas e licenças a vencer, e leads parando';

    /**
     * Quando um lead sem movimento vira aviso para o vendedor.
     *
     * Sete dias: metade do caminho até o alarme global de 30 dias da fila de
     * ação, e cedo o bastante para o vendedor reagir antes de o lead esfriar de
     * vez. É para o DONO, não para a gestão — a fila já cuida da visão de cima.
     */
    public const DIAS_LEAD_PARADO = 7;

    /** Quantos dias antes a licença a expirar é anunciada. */
    public const DIAS_LICENCA = 7;

    public function handle(): int
    {
        $hoje = now()->startOfDay();
        $total = 0;

        $total += $this->despesasAVencer($hoje);
        $total += $this->receitasAVencer($hoje);
        $total += $this->licencasAExpirar($hoje);
        $total += $this->leadsParando($hoje);

        $this->info($total.' aviso(s) enviado(s).');

        return self::SUCCESS;
    }

    /** Despesas em aberto que vencem AMANHÃ → quem vê Despesas. */
    private function despesasAVencer(Carbon $hoje): int
    {
        $amanha = $hoje->copy()->addDay();

        $despesas = ContaPagar::query()
            ->where('status', 'em_aberto')
            ->whereDate('data_vencimento', $amanha->toDateString())
            ->get();

        if ($despesas->isEmpty()) {
            return 0;
        }

        return $this->avisarGrupo(
            User::idsDeQuemVe('contas_pagar', 'ler'),
            $this->titulo('Vence amanhã', 'Vencem amanhã', $despesas->count(), 'despesa', 'despesas'),
            $this->metaComTotal($despesas, 'descricao'),
            'trending-down',
            route('contas-pagar.index', ['status' => 'em_aberto']),
        );
    }

    /** Receitas pendentes que vencem AMANHÃ → quem vê Receitas. */
    private function receitasAVencer(Carbon $hoje): int
    {
        $amanha = $hoje->copy()->addDay();

        $receitas = Cobranca::query()
            ->where('status', 'pendente')
            ->whereDate('data_vencimento', $amanha->toDateString())
            ->get();

        if ($receitas->isEmpty()) {
            return 0;
        }

        return $this->avisarGrupo(
            User::idsDeQuemVe('cobrancas', 'ler'),
            $this->titulo('A receber vence amanhã', 'A receber amanhã', $receitas->count(), 'receita', 'receitas'),
            $this->metaComTotal($receitas, 'descricao'),
            'trending-up',
            route('cobrancas.index', ['status' => 'pendente']),
        );
    }

    /** Licenças que expiram em exatamente 7 dias → quem vê Clientes. */
    private function licencasAExpirar(Carbon $hoje): int
    {
        $alvo = $hoje->copy()->addDays(self::DIAS_LICENCA);

        $licencas = ClienteSistema::query()
            ->with(['cliente:id,nome', 'sistema:id,nome'])
            ->where('ativo', true)
            ->whereDate('licenca_fim_em', $alvo->toDateString())
            ->get();

        if ($licencas->isEmpty()) {
            return 0;
        }

        $rotulos = $licencas->map(fn (ClienteSistema $cs) => trim(
            ($cs->cliente?->nome ?? 'Cliente').' ('.($cs->sistema?->nome ?? 'sistema').')'
        ));

        return $this->avisarGrupo(
            User::idsDeQuemVe('clientes', 'ler'),
            $licencas->count() === 1
                ? 'Licença expira em '.self::DIAS_LICENCA.' dias: '.$rotulos->first()
                : $licencas->count().' licenças expiram em '.self::DIAS_LICENCA.' dias',
            $rotulos->implode(' · '),
            'cadeado-fechado',
            route('clientes.index'),
        );
    }

    /**
     * Leads abertos parados há exatamente 7 dias → o vendedor dono.
     *
     * `proximo_passo` é texto livre, não uma data, então não há "próximo passo
     * hoje" para disparar. O sinal de tempo que o lead tem é o tempo sem
     * mudança de estágio — e avisar o dono aos 7 dias é o alerta antecipatório
     * antes de o lead virar item de "parado há 30 dias" na fila de ação.
     */
    private function leadsParando(Carbon $hoje): int
    {
        $enviados = 0;

        $porVendedor = Lead::query()
            ->whereNotIn('estagio', Lead::ESTAGIOS_TERMINAIS)
            ->whereNotNull('vendedor_id')
            ->get()
            ->filter(fn (Lead $lead) => $lead->diasNoEstagio() === self::DIAS_LEAD_PARADO)
            ->groupBy('vendedor_id');

        foreach ($porVendedor as $vendedorId => $leads) {
            Notificacao::create([
                'destinatario_id' => $vendedorId,
                'tipo' => 'lembrete',
                'nivel' => 'atencao',
                'icone' => 'clock',
                'titulo' => $leads->count() === 1
                    ? 'Lead parado há '.self::DIAS_LEAD_PARADO.' dias: '.$leads->first()->nome
                    : $leads->count().' leads seus parados há '.self::DIAS_LEAD_PARADO.' dias',
                'meta' => $leads->pluck('nome')->implode(' · '),
                'rota' => route('leads.index'),
            ]);

            $enviados++;
        }

        return $enviados;
    }

    /**
     * Manda o MESMO aviso a cada destinatário de um conjunto — e conta quantos.
     *
     * Direto por `create`, e não por `Notificacao::avisar`: aviso do sistema não
     * tem autor a poupar. Conjunto vazio (ninguém com a permissão) não avisa
     * ninguém, como o prazo sem responsável.
     *
     * @param  Collection<int, int>  $destinatarios
     */
    private function avisarGrupo(Collection $destinatarios, string $titulo, string $meta, string $icone, string $rota): int
    {
        foreach ($destinatarios as $id) {
            Notificacao::create([
                'destinatario_id' => $id,
                'tipo' => 'lembrete',
                'nivel' => 'atencao',
                'icone' => $icone,
                'titulo' => $titulo,
                'meta' => $meta,
                'rota' => $rota,
            ]);
        }

        return $destinatarios->count();
    }

    /** "Vence amanhã: <descrição>" (1) ou "Vencem amanhã: N despesas" (N). */
    private function titulo(string $singular, string $plural, int $qtd, string $substSing, string $substPlur): string
    {
        return $qtd === 1
            ? $singular.': 1 '.$substSing
            : $plural.': '.$qtd.' '.$substPlur;
    }

    /** Os itens numa linha, com o total em reais no fim. */
    private function metaComTotal(Collection $itens, string $campo): string
    {
        $total = (float) $itens->sum('valor');

        return $itens->pluck($campo)->filter()->implode(' · ')
            .' · R$ '.number_format($total, 2, ',', '.');
    }
}
