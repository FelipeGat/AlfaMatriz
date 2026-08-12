<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checklist da tarefa — e checklist, não subtarefa.
     *
     * A diferença não é de tamanho, é de identidade. Subtarefa obrigaria a
     * responder em que coluna ela mora, se conta no limite de WIP, quem é o
     * responsável dela e se o pai anda sozinho quando a filha trava — quatro
     * perguntas que o quadro não precisa fazer para o que é, quase sempre, uma
     * lista de conferência dentro de uma tarefa só.
     *
     * Trabalho que precisa de dono próprio vira tarefa irmã, não item.
     */
    public function up(): void
    {
        Schema::create('tarefa_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarefa_id')->constrained('tarefas')->cascadeOnDelete();
            $table->string('texto');
            $table->boolean('feito')->default(false);

            // A ordem é escolhida por quem escreve, e não pela data de criação:
            // um checklist é uma sequência — "conferir isto ANTES daquilo" — e
            // ordenar por `id` faria o item lembrado depois cair no fim, mesmo
            // quando ele é o primeiro passo.
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();

            $table->index(['tarefa_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarefa_itens');
    }
};
