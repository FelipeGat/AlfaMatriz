<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A entrega que acompanha a tarefa para a revisão (#210).
     *
     * O que foi feito ficava espalhado: hash de commit no resumo, número do PR
     * no motivo do bloqueio, commits colados à mão num comentário — e quem
     * revisa e testa no staging não tinha um lugar fixo para ler o que mudou e
     * como conferir.
     *
     * Tabela, e não colunas na tarefa: a tarefa que volta para correção e sobe
     * de novo faz OUTRA entrega, e a primeira precisa continuar legível — é
     * ela que explica o que a revisão reprovou. Colunas seriam reescritas a
     * cada subida. É o mesmo desenho do relatório de teste: um registro por
     * passagem, preso ao evento em que nasceu.
     *
     * Sem backfill, de propósito: tarefa que já está nos portões não tem como
     * ter a entrega reconstruída, e inventar uma seria pior que mostrar nada.
     * Só a próxima passagem pela revisão cobra.
     *
     * `nullOnDelete` no autor e no evento pelo motivo de sempre: apagar uma
     * pessoa ou um evento não pode levar junto o registro do que foi entregue.
     */
    public function up(): void
    {
        Schema::create('tarefa_entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarefa_id')->constrained('tarefas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tarefa_evento_id')->nullable()->constrained('tarefa_eventos')->nullOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->text('o_que_foi_feito');
            $table->text('como_testar');
            $table->text('pr_commits')->nullable();
            $table->timestamps();

            $table->index(['tarefa_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarefa_entregas');
    }
};
