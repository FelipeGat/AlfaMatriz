<?php

namespace App\Services;

use App\Models\Cobranca;
use App\Models\ContaFixaPagar;
use App\Models\ContaPagar;
use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * O que ainda não foi lançado, mas vai ser (Felipe, 18/10/2026: "o previsto a
 * receber e a pagar do mês que vem está vazio; tem que mostrar como projeção").
 *
 * Nada aqui grava: são linhas calculadas para os meses cujo lançamento ainda
 * não nasceu — despesa fixa vigente sem a conta da competência, e a cobrança
 * da revenda "por cliente" pela prévia de hoje (faturáveis × preço). Quando o
 * lançamento real existe, a projeção daquele mês some sozinha.
 */
class ProjecaoFinanceira
{
    /** Sem período na tela, projeta até este número de meses à frente. */
    public const MESES_SEM_PERIODO = 2;

    /** @return Collection<int, array{descricao: string, fornecedor: ?string, valor: float, vencimento: Carbon, competencia: string}> */
    public function despesas(?Carbon $de, ?Carbon $ate): Collection
    {
        [$de, $ate] = $this->janela($de, $ate);
        $fixas = ContaFixaPagar::with('fornecedor')->where('ativo', true)->get();
        $itens = collect();
        foreach ($this->competencias($de, $ate) as $competencia) {
            $geradas = ContaPagar::where('competencia', $competencia)->whereNotNull('conta_fixa_pagar_id')->pluck('conta_fixa_pagar_id')->all();
            foreach ($fixas as $f) {
                if (in_array($f->id, $geradas, true) || ! $f->vigenteNaCompetencia($competencia)) {
                    continue;
                }
                $venc = Carbon::parse($f->dataVencimentoParaCompetencia($competencia));
                if ($venc->betweenIncluded($de, $ate)) {
                    $itens->push(['descricao' => $f->descricao, 'fornecedor' => $f->fornecedor?->nome_fantasia ?: $f->fornecedor?->razao_social, 'valor' => (float) $f->valor,
                        'vencimento' => $venc, 'competencia' => $competencia]);
                }
            }
        }

        return $itens->sortBy(fn ($i) => $i['vencimento']->timestamp)->values();
    }

    /** @return Collection<int, array{descricao: string, revenda: string, valor: float, vencimento: Carbon, competencia: string}> */
    public function receitas(?Carbon $de, ?Carbon $ate): Collection
    {
        [$de, $ate] = $this->janela($de, $ate);
        $itens = collect();
        foreach (Revenda::where('ativo', true)->where('cobranca_por_cliente', true)->get() as $r) {
            $valor = $this->previaPorCliente($r);
            if ($valor <= 0) {
                continue;
            }
            // A competência M vence no mês M+1: olha um mês para trás do início da janela.
            foreach ($this->competencias($de->copy()->subMonthNoOverflow(), $ate) as $competencia) {
                $mes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();
                if ($mes->copy()->endOfMonth()->lt(now()->startOfMonth()->subMonthNoOverflow())) {
                    continue;
                }
                $venc = FaturamentoService::vencimentoDaRevenda($r, $mes, $mes->copy()->endOfMonth()->addDays(5));
                $jaTem = Cobranca::where('revenda_id', $r->id)->where('competencia', $competencia)->where('tipo', 'locacao_sistema')->exists();
                if (! $jaTem && $venc->betweenIncluded($de, $ate)) {
                    $itens->push(['descricao' => 'Licenciamento de sistemas Alfa — competência '.$mes->format('m/Y'), 'revenda' => $r->nome,
                        'valor' => $valor, 'vencimento' => $venc, 'competencia' => $competencia]);
                }
            }
        }

        return $itens->sortBy(fn ($i) => $i['vencimento']->timestamp)->values();
    }

    /** A prévia da revenda "por cliente": clientes faturáveis × preço por cliente, por produto. */
    public function previaPorCliente(Revenda $r): float
    {
        $total = 0.0;
        foreach (Sistema::produtos()->where('ativo', true)->get() as $s) {
            $preco = $s->tiersVigentes($r->id)->first(fn ($t) => $t->revenda_id === $r->id);
            if (! $preco) {
                continue;
            }
            $n = $s->clientes()->where('revenda_id', $r->id)->where('clientes.ativo', true)
                ->where('cliente_sistema.ativo', true)->where('cliente_sistema.faturavel', true)->count();
            $total += $n * (float) $preco->valor_excedente_unidade;
        }

        return round($total, 2);
    }

    /** Projeção nunca olha para trás: do início do mês corrente em diante. @return array{0: Carbon, 1: Carbon} */
    private function janela(?Carbon $de, ?Carbon $ate): array
    {
        $inicio = now()->startOfMonth();
        $de = $de && $de->gt($inicio) ? $de->copy()->startOfDay() : $inicio->copy();
        $ate = $ate ? $ate->copy()->endOfDay() : now()->addMonthsNoOverflow(self::MESES_SEM_PERIODO)->endOfMonth();

        return [$de, $ate];
    }

    /** @return list<string> competências 'Y-m' de $de até $ate */
    private function competencias(Carbon $de, Carbon $ate): array
    {
        $saida = [];
        for ($m = $de->copy()->startOfMonth(); $m->lte($ate); $m->addMonthNoOverflow()) {
            $saida[] = $m->format('Y-m');
        }

        return $saida;
    }
}
