<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Em testes" se abre em três etapas: revisão, staging e porta da produção.
     *
     * A etapa guardava dois portões com revisor, artefato e modo de falha
     * diferentes — o admin lendo o código de um PR e o dev validando o que já
     * subiu para o staging. Juntos no mesmo balde, o quadro não respondia quem
     * esperava por quem, e a tarefa reprovada na leitura era indistinguível da
     * que quebrou rodando.
     *
     * `pronta_producao` é a terceira porque é o único ponto do quadro em que a
     * bola troca de mão de verdade: o dev termina e o admin aplica a tag. Esse
     * é o critério que separa coluna de marca aqui — coluna só se justifica se
     * muda quem está segurando a tarefa.
     *
     * Não há mudança de esquema: `status` é string desde a criação da tabela, e
     * as etapas vivem em `Tarefa::STATUS`. Esta migração existe pelos DADOS —
     * as tarefas que estão em `em_testes` precisam de destino antes de o fluxo
     * novo deixar de oferecer a etapa.
     */
    public function up(): void
    {
        // Em revisão é o destino honesto: sem informação extra, ninguém sabe se
        // aquele card já tinha subido para o staging, e supor que sim pularia
        // um portão. Voltar meio passo custa uma leitura de PR; pular custa
        // código não revisado seguindo em frente.
        DB::table('tarefas')->where('status', 'em_testes')->update(['status' => 'em_revisao']);

        // O evento ABERTO acompanha, porque ele descreve o presente: a tarefa
        // não mudou de etapa, a etapa é que se abriu embaixo dela. Deixá-lo
        // dizendo `em_testes` com o card em Em revisão é a mesma cisão que a
        // migração do bloqueio evitou — dois registros discordando sobre onde a
        // tarefa está.
        //
        // Os eventos FECHADOS não são tocados: neles `em_testes` aconteceu, e o
        // rótulo continua legível por `Tarefa::ETAPAS_APOSENTADAS`. O
        // `de_status` do evento aberto é história pela mesma razão — ela veio de
        // Em testes mesmo.
        DB::table('tarefa_eventos')
            ->where('para_status', 'em_testes')
            ->whereNull('saiu_em')
            ->update(['para_status' => 'em_revisao']);

        // A marca de retorno aponta para um portão do fluxo, não para o
        // histórico: uma tarja "Voltou de Em testes" nomearia uma etapa que o
        // quadro não tem mais, e o painel de motivo não teria copy para ela.
        DB::table('tarefas')->where('retorno_de', 'em_testes')->update(['retorno_de' => 'em_revisao']);
    }

    public function down(): void
    {
        // As três voltam para a etapa de onde saíram. `em_staging` e
        // `pronta_producao` nasceram depois desta migração e não têm casa
        // anterior — `em_testes` é a etapa que as continha, e é para lá que
        // elas voltam sem inventar um lugar novo.
        DB::table('tarefas')
            ->whereIn('status', ['em_revisao', 'em_staging', 'pronta_producao'])
            ->update(['status' => 'em_testes']);

        DB::table('tarefa_eventos')
            ->whereIn('para_status', ['em_revisao', 'em_staging', 'pronta_producao'])
            ->whereNull('saiu_em')
            ->update(['para_status' => 'em_testes']);

        DB::table('tarefas')
            ->whereIn('retorno_de', ['em_revisao', 'em_staging', 'pronta_producao'])
            ->update(['retorno_de' => 'em_testes']);
    }
};
