<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A posição que alguém escolheu para o card dentro da coluna.
     *
     * O quadro já ordena sozinho — gravidade primeiro, e no empate o que está
     * mais parado na etapa (AC-128). Isso responde "o que é mais grave", que
     * não é a mesma pergunta que "qual eu pego primeiro": entre duas tarefas
     * altas, quem conhece o trabalho sabe que uma destrava a outra, e o quadro
     * não tem como saber.
     *
     * NULO é diferente de zero, e é por isso que a coluna é anulável: nulo
     * significa "ninguém posicionou este card à mão", e é o que permite a
     * coluna intocada continuar obedecendo à ordem automática em vez de virar
     * uma lista congelada na primeira renderização.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->unsignedInteger('ordem')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropColumn('ordem');
        });
    }
};
