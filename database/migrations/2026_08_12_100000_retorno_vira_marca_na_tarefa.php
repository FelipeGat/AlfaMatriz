<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Ajustes necessários" deixa de ser etapa e vira marca de retorno.
     *
     * A etapa tinha uma saída só — Em andamento, com o mesmo dono — e por isso
     * nunca respondeu à pergunta que uma coluna existe para responder: quem
     * está segurando a tarefa. Ela também achatava três reprovações diferentes
     * numa só, porque reprovar na revisão do PR, reprovar no staging e reprovar
     * na porta da produção chegavam todas ao mesmo lugar sem dizer de onde
     * vinham.
     *
     * Como marca, a reprovação devolve a tarefa direto para Em andamento e
     * carrega o portão que a recusou (`retorno_de`) e o texto exigido na hora
     * (`retorno_motivo`). O retrabalho volta a aparecer no WIP de quem está
     * corrigindo — parado em Ajustes, ele não aparecia em lugar nenhum.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->string('retorno_de')->nullable()->after('bloqueio_motivo');
            $table->text('retorno_motivo')->nullable()->after('retorno_de');
        });

        $this->devolverReprovadasParaABancada();
    }

    /**
     * Cada tarefa parada em Ajustes volta para Em andamento carimbada.
     *
     * O portão que reprovou está no `de_status` do evento aberto, e o motivo no
     * `motivo` — os dois foram exigidos na hora da recusa e continuam lá.
     *
     * O evento aberto é REESCRITO em vez de fechado: no modelo novo a tarefa
     * voltou para a bancada no instante da reprovação, e é esse instante que o
     * `entrou_em` já guarda. Fechar este evento e abrir outro agora zeraria o
     * relógio da etapa — a tarefa reprovada há seis dias apareceria como recém
     * chegada, e o envelhecimento é justamente o sinal que o redesign passou a
     * cobrar do quadro.
     *
     * O histórico das tarefas JÁ ENCERRADAS não é tocado: nelas a passagem por
     * Ajustes aconteceu, e o rótulo continua legível por
     * `Tarefa::ETAPAS_APOSENTADAS`.
     */
    private function devolverReprovadasParaABancada(): void
    {
        $reprovadas = DB::table('tarefas')->where('status', 'ajustes_necessarios')->get(['id']);

        foreach ($reprovadas as $tarefa) {
            $retorno = DB::table('tarefa_eventos')
                ->where('tarefa_id', $tarefa->id)
                ->where('para_status', 'ajustes_necessarios')
                ->whereNull('saiu_em')
                ->orderByDesc('entrou_em')
                ->first();

            // Sem evento aberto não há portão de origem para carimbar. A tarefa
            // volta para a bancada mesmo assim — ficar numa etapa que o fluxo
            // novo não tem seria pior do que voltar sem a tarja.
            if (! $retorno) {
                DB::table('tarefas')->where('id', $tarefa->id)
                    ->update(['status' => 'em_desenvolvimento']);

                continue;
            }

            DB::table('tarefas')->where('id', $tarefa->id)->update([
                'status' => 'em_desenvolvimento',
                'retorno_de' => $retorno->de_status ?: 'em_testes',
                'retorno_motivo' => $retorno->motivo,
            ]);

            DB::table('tarefa_eventos')->where('id', $retorno->id)
                ->update(['para_status' => 'em_desenvolvimento']);
        }
    }

    public function down(): void
    {
        // O caminho de volta usa a própria marca: quem tem `retorno_de` é
        // exatamente quem esta migração tirou da coluna.
        $marcadas = DB::table('tarefas')->whereNotNull('retorno_de')->get(['id']);

        foreach ($marcadas as $tarefa) {
            DB::table('tarefas')->where('id', $tarefa->id)
                ->update(['status' => 'ajustes_necessarios']);

            $evento = DB::table('tarefa_eventos')
                ->where('tarefa_id', $tarefa->id)
                ->where('para_status', 'em_desenvolvimento')
                ->whereNull('saiu_em')
                ->orderByDesc('entrou_em')
                ->first(['id']);

            if ($evento) {
                DB::table('tarefa_eventos')->where('id', $evento->id)
                    ->update(['para_status' => 'ajustes_necessarios']);
            }
        }

        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropColumn(['retorno_de', 'retorno_motivo']);
        });
    }
};
