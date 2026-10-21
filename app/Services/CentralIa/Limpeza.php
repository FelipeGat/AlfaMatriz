<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaAnexo;
use App\Models\CentralIaEntrega;
use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaPrevia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Apaga o que passou do prazo na Central de IA (T-378).
 *
 * Antes nada era apagado. Os prazos ficam em `central_ia.retencao` e foram
 * aceitos pelo dono em 14/10/2026: arquivos (anexos e prévias) 90 dias;
 * mensagens, pedidos, eventos e entregas 1 ano.
 *
 * "Mais de N dias" é estrito: o que tem exatamente N dias ainda fica, e sai
 * na volta seguinte. Assim o limite não depende do minuto em que o cron rodou.
 *
 * A ordem importa porque as chaves estrangeiras são `restrict`: mensagem só
 * sai quando nenhum pedido nem entrega que FICA aponta para ela. Pedido
 * pendente ou executando nunca sai, e segura a mensagem dele junto — um
 * pedido vivo com o texto apagado não teria o que executar.
 *
 * Arquivo antes da linha: se o disco recusa apagar, a linha fica e a próxima
 * volta tenta de novo. O contrário deixaria arquivo no disco sem ninguém que
 * soubesse dele.
 */
class Limpeza
{
    /** Abaixo disto, o prazo é tratado como erro de configuração, e nada é apagado. */
    public const MINIMO_DIAS = 7;

    private const LOTE = 500;

    /**
     * @return array<string,int> o que saiu (ou sairia, no ensaio), por tipo
     */
    public function executar(bool $ensaio = false, ?Carbon $agora = null): array
    {
        $agora ??= now();
        $diasArquivos = (int) config('central_ia.retencao.anexos_dias');
        $diasHistorico = (int) config('central_ia.retencao.historico_dias');
        if ($diasArquivos < self::MINIMO_DIAS || $diasHistorico < self::MINIMO_DIAS) {
            // Um 0 vindo de um .env errado apagaria tudo na primeira volta.
            throw new \InvalidArgumentException('Prazo de retenção da Central de IA abaixo de '.self::MINIMO_DIAS.' dias: nada foi apagado.');
        }

        $corteArquivos = $agora->copy()->subDays($diasArquivos);
        $corteHistorico = $agora->copy()->subDays($diasHistorico);

        $r = [
            'anexos' => $this->anexos(CentralIaAnexo::where('created_at', '<', $corteArquivos), $ensaio),
            'previas' => $this->previas(CentralIaPrevia::where('created_at', '<', $corteArquivos), $ensaio),
            'eventos' => $this->linhas(DB::table('central_ia_eventos')->where('created_at', '<', $corteHistorico), $ensaio),
            'entregas' => $this->linhas(CentralIaEntrega::where('created_at', '<', $corteHistorico)->toBase(), $ensaio),
        ];

        $execucoes = $this->execucoesVencidas($corteHistorico);
        // A prévia de um pedido que sai vai junto, mesmo antes dos 90 dias:
        // sem o pedido, ninguém chega a ela.
        // As já vencidas saíram acima; o filtro só evita contá-las duas vezes no ensaio.
        $r['previas'] += $this->previas(CentralIaPrevia::where('created_at', '>=', $corteArquivos)
            ->whereIn('execucao_id', $execucoes->clone()->select('id')), $ensaio);
        $r['execucoes'] = $this->linhas($execucoes->toBase(), $ensaio);

        $mensagens = $this->mensagensVencidas($corteHistorico);
        $r['anexos'] += $this->anexos(CentralIaAnexo::where('created_at', '>=', $corteArquivos)
            ->whereIn('mensagem_id', $mensagens->clone()->select('id')), $ensaio);
        $r['mensagens'] = $this->linhas($mensagens->toBase(), $ensaio);

        // Secretário: o toque deduplicado só importa por minutos (o Telegram
        // reentrega logo) e a proposta encerrada não serve a ninguém depois
        // de 30 dias. Itens e avisos NÃO saem: são o registro da pessoa.
        $r['secretario'] = $this->linhas(DB::table('secretario_toques')->where('created_at', '<', $agora->copy()->subDays(7)), $ensaio)
            + $this->linhas(DB::table('secretario_propostas')->where('updated_at', '<', $agora->copy()->subDays(30))
                ->where(fn ($q) => $q->where('status', '!=', 'pendente')->orWhere('expira_em', '<', $agora)), $ensaio);

        return $r;
    }

    private function execucoesVencidas(Carbon $corte): Builder
    {
        return CentralIaExecucao::where('created_at', '<', $corte)
            ->whereNotIn('status', [CentralIaExecucao::PENDENTE, CentralIaExecucao::EXECUTANDO]);
    }

    /**
     * Mensagem vencida que ninguém que fica segura. No ensaio, os pedidos e as
     * entregas vencidos ainda estão lá; por isso a regra olha para os que
     * FICAM, e o ensaio conta o mesmo que a volta de verdade apagaria.
     */
    private function mensagensVencidas(Carbon $corte): Builder
    {
        $execucoesQueFicam = fn ($q) => $q->select(DB::raw(1))->from('central_ia_execucoes as e')
            ->where(fn ($w) => $w->whereColumn('e.pedido_id', 'central_ia_mensagens.id')->orWhereColumn('e.resposta_id', 'central_ia_mensagens.id'))
            ->where(fn ($w) => $w->where('e.created_at', '>=', $corte)->orWhereIn('e.status', [CentralIaExecucao::PENDENTE, CentralIaExecucao::EXECUTANDO]));
        $entregasQueFicam = fn ($q) => $q->select(DB::raw(1))->from('central_ia_entregas as en')
            ->whereColumn('en.mensagem_id', 'central_ia_mensagens.id')
            ->where('en.created_at', '>=', $corte);

        return CentralIaMensagem::where('created_at', '<', $corte)
            ->whereNotExists($execucoesQueFicam)
            ->whereNotExists($entregasQueFicam);
    }

    private function linhas(\Illuminate\Database\Query\Builder $q, bool $ensaio): int
    {
        if ($ensaio) {
            return $q->count();
        }
        $total = 0;
        // Em lotes: um DELETE de um ano inteiro de uma vez travaria a tabela.
        do {
            $ids = (clone $q)->orderBy('id')->limit(self::LOTE)->pluck('id');
            $total += $ids->isEmpty() ? 0 : DB::table($q->from)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::LOTE);

        return $total;
    }

    private function anexos(Builder $q, bool $ensaio): int
    {
        if ($ensaio) {
            return $q->count();
        }
        $total = 0;
        // chunkById, e não chunk/each: apagar no meio de uma paginação por
        // deslocamento pula metade das linhas.
        $q->chunkById(self::LOTE, function ($lote) use (&$total) {
            foreach ($lote as $a) {
                if ($this->apagarArquivo($a->caminho)) {
                    $a->delete();
                    $total++;
                }
            }
        });

        return $total;
    }

    private function previas(Builder $q, bool $ensaio): int
    {
        if ($ensaio) {
            return $q->count();
        }
        $total = 0;
        $q->chunkById(self::LOTE, function ($lote) use (&$total) {
            foreach ($lote as $p) {
                if ($this->apagarArquivo("central-ia/previas/{$p->user_id}/{$p->execucao_id}/{$p->caminho}")) {
                    $p->delete();
                    $total++;
                }
            }
            // A pasta do pedido some quando a última prévia dele sai.
            foreach ($lote->unique('execucao_id') as $p) {
                if (! CentralIaPrevia::where('execucao_id', $p->execucao_id)->exists()) {
                    Storage::disk('central_ia')->deleteDirectory("central-ia/previas/{$p->user_id}/{$p->execucao_id}");
                }
            }
        });

        return $total;
    }

    private function apagarArquivo(string $caminho): bool
    {
        $disco = Storage::disk('central_ia');

        return ! $disco->exists($caminho) || $disco->delete($caminho);
    }
}
