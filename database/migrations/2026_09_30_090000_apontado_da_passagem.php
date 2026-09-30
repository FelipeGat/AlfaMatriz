<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem foi apontado para examinar a passagem — e, por isso, o único que
     * registra o veredito dela.
     *
     * O apontamento já existia, mas só como aviso: gravado em
     * `tarefas.interlocutor_id`, que a conversa reescreve a cada pergunta e
     * resposta. Como trava, ele não serve — depois de uma ida e volta o
     * "interlocutor" pode ser o próprio responsável. A passagem é o recorte
     * certo, o mesmo que o relatório de teste já usa (`testeDestaPassagem`):
     * o apontado vale para esta entrada no portão e morre com ela.
     *
     * Sem backfill de propósito: para as passagens abertas hoje, o único dado
     * é o `interlocutor_id` que a conversa pode ter reescrito, e travar a
     * tarefa em quem não foi apontado é pior do que deixá-la como fila.
     */
    public function up(): void
    {
        Schema::table('tarefa_eventos', function (Blueprint $table) {
            $table->foreignId('apontado_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tarefa_eventos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('apontado_id');
        });
    }
};
