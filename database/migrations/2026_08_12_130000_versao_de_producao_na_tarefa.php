<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A versão em que a tarefa chegou ao cliente.
     *
     * "Concluída" mentia: concluir a partir de Em testes marcava a tarefa como
     * pronta com o código parado no staging. Agora concluída significa EM
     * PRODUÇÃO, e o painel de conclusão pede a tag que o vigia aplicou
     * (`v1.4.2`) — é ela que responde "desde quando o cliente tem isso", a
     * pergunta que chega pelo suporte e que hoje só se responde procurando no
     * histórico do git.
     *
     * Anulável porque tarefa operacional não é taggeada: ela não passa por PR
     * nem por staging, e o painel de conclusão dela nem pede versão.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->string('versao_producao')->nullable()->after('iniciada_em');
        });
    }

    public function down(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropColumn('versao_producao');
        });
    }
};
