<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Pronta p/ produção" sai do quadro: subir e testar viram uma etapa só.
     *
     * A coluna existia por um critério certo — ela é o ponto em que a bola passa
     * do dev para o admin que corta a tag. O que ela não tinha era simetria: o
     * staging não ganhou uma "Pronta p/ staging", embora ali a bola também
     * espere um deploy. Com Em produção do outro lado, o quadro passou a ter
     * duas colunas com "produção" no nome dizendo coisas opostas sobre estar no
     * ar, e a fila era a que não correspondia a nenhuma etapa do processo de
     * quem usa: desenvolvimento → revisão → staging → produção.
     *
     * A espera pela tag não some, muda de lugar: ela passa a acontecer DENTRO de
     * Em staging, como a espera pelo merge acontece dentro de Em revisão. O card
     * já diz "Staging aprovado por Fulano", que é o sinal de que aquele está
     * pronto para subir, e o chip do cabeçalho passa a contar exatamente esses.
     *
     * Sem mudança de esquema: `status` é string desde a criação da tabela. Esta
     * migração existe pelos DADOS — as tarefas paradas na fila precisam de
     * destino antes de o fluxo deixar de oferecer a etapa.
     */
    public function up(): void
    {
        // Em staging é o destino honesto, e não o palpite menos ruim: a tarefa
        // na fila TINHA o staging validado, e é lá que essa espera passa a
        // morar. Nada se perde — o relatório de teste dela continua preso ao
        // evento da passagem pelo staging, que é o que reabre abaixo.
        DB::table('tarefas')->where('status', 'pronta_producao')->update(['status' => 'em_staging']);

        // O evento ABERTO acompanha, porque ele descreve o presente: a tarefa
        // não mudou de etapa, a etapa é que deixou de existir embaixo dela.
        // Reescrito e não fechado — fechar abriria uma passagem nova e zeraria
        // o relógio de quem está esperando há dias, que é justamente quem essa
        // coluna existia para mostrar.
        //
        // Os eventos FECHADOS não são tocados: neles a fila aconteceu de
        // verdade, e o rótulo continua legível por `ETAPAS_APOSENTADAS`.
        DB::table('tarefa_eventos')
            ->where('para_status', 'pronta_producao')
            ->whereNull('saiu_em')
            ->update(['para_status' => 'em_staging']);

        // `retorno_de` NÃO é remapeado, ao contrário do que a migração de
        // `em_testes` fez com o dela. A tarja diz de onde a tarefa voltou, e ela
        // voltou da porta da produção mesmo — reescrever para "Voltou do
        // staging" trocaria um fato por outro que não aconteceu. O rótulo
        // sobrevive em `RETORNO_POR_ORIGEM`, que continua com a entrada dela
        // pelo mesmo motivo que `ETAPAS_APOSENTADAS` existe: a etapa sai do
        // fluxo, não do vocabulário.
    }

    public function down(): void
    {
        // A volta é o que dá para devolver: as tarefas que estão em Em staging
        // COM o staging aprovado nesta passagem são as que estariam na fila.
        // Não é exato — uma tarefa aprovada e ainda não movida também cai aqui
        // —, e é por isso que o `down` devolve o formato, não o histórico.
        $naFila = DB::table('tarefas as t')
            ->where('t.status', 'em_staging')
            ->whereExists(fn ($q) => $q
                ->selectRaw(1)
                ->from('tarefa_relatorios_teste as r')
                ->join('tarefa_eventos as e', 'e.id', '=', 'r.tarefa_evento_id')
                ->whereColumn('r.tarefa_id', 't.id')
                ->whereNull('e.saiu_em')
                ->where('r.aprovado', true))
            ->pluck('t.id');

        if ($naFila->isEmpty()) {
            return;
        }

        DB::table('tarefas')->whereIn('id', $naFila)->update(['status' => 'pronta_producao']);

        DB::table('tarefa_eventos')
            ->whereIn('tarefa_id', $naFila)
            ->where('para_status', 'em_staging')
            ->whereNull('saiu_em')
            ->update(['para_status' => 'pronta_producao']);
    }
};
