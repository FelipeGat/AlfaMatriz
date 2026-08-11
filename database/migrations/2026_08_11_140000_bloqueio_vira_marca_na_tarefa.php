<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bloqueio deixa de ser etapa e vira marca na tarefa.
     *
     * Como coluna, o bloqueio APAGAVA a etapa em que a tarefa estava — e o
     * fluxo tinha de reconstruir isso na mão, oferecendo Em testes como volta
     * de Bloqueada só para não devolver à bancada o código que estava em teste.
     * Era contorno em cima de informação jogada fora.
     *
     * Como marca, a tarefa não sai do lugar: ela fica na etapa, ganha o motivo
     * e o relógio do bloqueio, e some da conta de trabalho em curso — vaga
     * ocupada por tarefa parada não é trabalho andando.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->timestamp('bloqueado_em')->nullable()->after('status');
            $table->text('bloqueio_motivo')->nullable()->after('bloqueado_em');
        });

        $this->devolverTravadasParaAEtapaDeOrigem();
    }

    /**
     * Cada tarefa parada na coluna Bloqueada volta para a etapa de onde veio.
     *
     * O caminho de volta está no próprio registro: o evento aberto da tarefa
     * guarda em `de_status` a etapa que ela deixou ao ser bloqueada, e em
     * `motivo` o texto que foi exigido na hora.
     *
     * A restauração é literal — o evento do bloqueio é APAGADO e o evento
     * anterior é reaberto, com a saída e a duração desfeitas. Não é enfeite: o
     * modelo novo afirma que a tarefa nunca saiu da etapa, e deixar dois
     * registros dizendo que ela saiu e voltou faria o cronômetro contar duas
     * passagens onde houve uma. Depois desta migração, o tempo em etapa dessas
     * tarefas volta a correr desde a entrada original.
     *
     * O histórico das tarefas JÁ ENCERRADAS não é tocado: lá `bloqueada`
     * aconteceu mesmo, e reescrever isso seria apagar o que foi vivido.
     */
    private function devolverTravadasParaAEtapaDeOrigem(): void
    {
        $travadas = DB::table('tarefas')->where('status', 'bloqueada')->get(['id']);

        foreach ($travadas as $tarefa) {
            $bloqueio = DB::table('tarefa_eventos')
                ->where('tarefa_id', $tarefa->id)
                ->where('para_status', 'bloqueada')
                ->whereNull('saiu_em')
                ->orderByDesc('entrou_em')
                ->first();

            // Sem evento aberto não há de onde ler a origem. Em andamento é o
            // destino menos surpreendente: é de lá que quase todo bloqueio sai,
            // e é etapa de trabalho, não de fila — ninguém perde a tarefa.
            if (! $bloqueio) {
                DB::table('tarefas')->where('id', $tarefa->id)->update([
                    'status' => 'em_desenvolvimento',
                    'bloqueado_em' => now(),
                ]);

                continue;
            }

            $origem = $bloqueio->de_status ?: 'em_desenvolvimento';

            DB::table('tarefas')->where('id', $tarefa->id)->update([
                'status' => $origem,
                'bloqueado_em' => $bloqueio->entrou_em,
                'bloqueio_motivo' => $bloqueio->motivo,
            ]);

            // O evento da etapa de origem foi fechado no instante exato em que
            // o do bloqueio abriu — é por esse encaixe que se acha qual reabrir.
            $anterior = DB::table('tarefa_eventos')
                ->where('tarefa_id', $tarefa->id)
                ->where('para_status', $origem)
                ->where('saiu_em', $bloqueio->entrou_em)
                ->orderByDesc('entrou_em')
                ->first(['id']);

            if ($anterior) {
                DB::table('tarefa_eventos')->where('id', $anterior->id)
                    ->update(['saiu_em' => null, 'duracao_segundos' => null]);
            }

            DB::table('tarefa_eventos')->where('id', $bloqueio->id)->delete();
        }
    }

    public function down(): void
    {
        // As tarefas travadas voltam para a coluna, sem o evento que a migração
        // de subida apagou: o `down` devolve o formato, não o histórico.
        DB::table('tarefas')->whereNotNull('bloqueado_em')->update(['status' => 'bloqueada']);

        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropColumn(['bloqueado_em', 'bloqueio_motivo']);
        });
    }
};
