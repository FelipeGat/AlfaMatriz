<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A devolução para correção passa a carregar imagens junto do motivo.
     *
     * O print é a metade do motivo que o texto não carrega — "o botão saiu do
     * lugar" é uma frase que só quem viu a tela entende, e é exatamente na
     * reprovação que essa frase mais aparece. As imagens em si são anexos
     * comuns da tarefa (`tarefa_anexos`), criados no mesmo POST que move o
     * card; esta coluna guarda só QUAIS deles vieram com a devolução ATUAL,
     * para o banner de retorno mostrá-los ao lado do motivo.
     *
     * A lista morre com a tarja — andar para a frente a apaga, como apaga o
     * motivo — mas os anexos ficam: eles são prova da tarefa, e a tarja é só
     * o aviso de que a devolução ainda não foi tratada.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->json('retorno_anexo_ids')->nullable()->after('retorno_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropColumn('retorno_anexo_ids');
        });
    }
};
