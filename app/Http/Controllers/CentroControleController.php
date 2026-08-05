<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\Lead;
use App\Models\Sistema;

class CentroControleController extends Controller
{
    public function index()
    {
        $hoje = now()->startOfDay();
        $alertas = [];

        // Despesas vencidas
        $despesasVencidas = ContaPagar::where('status', 'em_aberto')->whereDate('data_vencimento', '<', $hoje)->get();
        if ($despesasVencidas->isNotEmpty()) {
            $alertas[] = [
                'nivel' => 'critico',
                'mensagem' => $despesasVencidas->count().' despesa(s) vencida(s) — R$ '.number_format($despesasVencidas->sum('valor'), 2, ',', '.'),
                'rota' => route('contas-pagar.index', ['status' => 'em_aberto']),
            ];
        }

        // Receitas vencidas
        $receitasVencidas = Cobranca::where('status', 'pendente')->whereDate('data_vencimento', '<', $hoje)->get();
        if ($receitasVencidas->isNotEmpty()) {
            $alertas[] = [
                'nivel' => 'critico',
                'mensagem' => $receitasVencidas->count().' cobrança'.($receitasVencidas->count() > 1 ? 's' : '').' vencida'.($receitasVencidas->count() > 1 ? 's' : '').' e não paga — R$ '.number_format($receitasVencidas->sum('valor'), 2, ',', '.'),
                'rota' => route('cobrancas.index', ['status' => 'pendente']),
            ];
        }

        // Saldo negativo
        $saldoTotal = ContaFinanceira::where('ativo', true)->sum('saldo');
        if ($saldoTotal < 0) {
            $alertas[] = [
                'nivel' => 'critico',
                'mensagem' => 'Saldo em caixa negativo: R$ '.number_format($saldoTotal, 2, ',', '.'),
                'rota' => route('contas-financeiras.index'),
            ];
        }

        // Vencendo hoje
        $receitasHoje = Cobranca::where('status', 'pendente')->whereDate('data_vencimento', $hoje)->sum('valor');
        if ($receitasHoje > 0) {
            $alertas[] = ['nivel' => 'atencao', 'mensagem' => 'Receita prevista hoje: R$ '.number_format($receitasHoje, 2, ',', '.'), 'rota' => route('cobrancas.index')];
        }

        $despesasHoje = ContaPagar::where('status', 'em_aberto')->whereDate('data_vencimento', $hoje)->sum('valor');
        if ($despesasHoje > 0) {
            $alertas[] = ['nivel' => 'atencao', 'mensagem' => 'Despesa vencendo hoje: R$ '.number_format($despesasHoje, 2, ',', '.'), 'rota' => route('contas-pagar.index')];
        }

        // Leads frios / propostas paradas
        $leadsAbertos = Lead::whereNotIn('estagio', Lead::ESTAGIOS_TERMINAIS)->get();
        $leadsFrios = $leadsAbertos->filter(fn ($l) => $l->temperatura() === 'frio');
        if ($leadsFrios->isNotEmpty()) {
            $alertas[] = [
                'nivel' => 'atencao',
                'mensagem' => $leadsFrios->count().' lead'.($leadsFrios->count() > 1 ? 's' : '').' esfriando há mais de 15 dias sem avançar',
                'rota' => route('leads.index'),
            ];
        }

        $propostasParadas = $leadsAbertos->where('estagio', 'proposta')->filter(fn ($l) => $l->diasNoEstagio() > 5);
        if ($propostasParadas->isNotEmpty()) {
            $alertas[] = [
                'nivel' => 'atencao',
                'mensagem' => $propostasParadas->count().' proposta'.($propostasParadas->count() > 1 ? 's' : '').' aguardando retorno há mais de 5 dias',
                'rota' => route('leads.index'),
            ];
        }

        // Sistemas sem tier compatível pro volume atual de alguma revenda
        foreach (Sistema::where('ativo', true)->get() as $sistema) {
            $porRevenda = $sistema->clientes()
                ->where('clientes.ativo', true)->where('cliente_sistema.ativo', true)
                ->get(['clientes.id', 'clientes.revenda_id'])->groupBy('revenda_id');

            foreach ($porRevenda as $revendaId => $clientes) {
                if (! $sistema->tierParaVolume($clientes->count(), $revendaId)) {
                    $alertas[] = [
                        'nivel' => 'critico',
                        'mensagem' => "{$sistema->nome}: revenda estourou o maior tier configurado ({$clientes->count()} clientes ativos) — precisa de tier novo.",
                        'rota' => route('sistemas.edit', $sistema),
                    ];
                }
            }
        }

        // Positivos
        $novosClientesSemana = Cliente::where('created_at', '>=', now()->subDays(7))->count();
        if ($novosClientesSemana > 0) {
            $alertas[] = ['nivel' => 'positivo', 'mensagem' => "{$novosClientesSemana} cliente(s) novo(s) nos últimos 7 dias", 'rota' => route('clientes.index')];
        }

        $leadsGanhosSemana = Lead::where('estagio', 'cliente_ativo')->where('estagio_atualizado_em', '>=', now()->subDays(7))->count();
        if ($leadsGanhosSemana > 0) {
            $alertas[] = ['nivel' => 'positivo', 'mensagem' => "{$leadsGanhosSemana} lead(s) convertido(s) em cliente nos últimos 7 dias", 'rota' => route('leads.index')];
        }

        if (empty($alertas)) {
            $alertas[] = ['nivel' => 'positivo', 'mensagem' => 'Tudo em dia — nenhuma pendência crítica encontrada.', 'rota' => null];
        }

        // Ordena: critico > atencao > positivo
        $ordem = ['critico' => 0, 'atencao' => 1, 'positivo' => 2];
        usort($alertas, fn ($a, $b) => $ordem[$a['nivel']] <=> $ordem[$b['nivel']]);

        $resumo = [
            'mrr' => Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])->where('competencia', now()->format('Y-m'))->where('status', '!=', 'cancelado')->sum('valor'),
            'saldo' => $saldoTotal,
            'receitas_7dias' => Cobranca::where('status', 'pendente')->whereBetween('data_vencimento', [$hoje, $hoje->copy()->addDays(7)])->sum('valor'),
            'despesas_7dias' => ContaPagar::where('status', 'em_aberto')->whereBetween('data_vencimento', [$hoje, $hoje->copy()->addDays(7)])->sum('valor'),
            'pipeline_aberto' => $leadsAbertos->sum('valor_estimado'),
            'clientes_ativos' => Cliente::where('ativo', true)->count(),
        ];

        return view('centro-controle.index', compact('alertas', 'resumo'));
    }
}
