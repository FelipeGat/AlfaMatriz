<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarefa_comentarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarefa_id')->constrained('tarefas')->cascadeOnDelete();
            // O comentário sobrevive à saída do autor: quem escreveu pode
            // deixar a empresa, mas o porquê de uma decisão continua sendo o
            // que a tarefa tem de mais caro. Sem autor, a linha se apresenta
            // como "Autor removido" em vez de sumir junto.
            $table->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('corpo');
            $table->timestamps();

            // A leitura é sempre "os comentários desta tarefa, do mais antigo
            // ao mais novo" — a conversa só faz sentido na ordem em que foi
            // escrita.
            $table->index(['tarefa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarefa_comentarios');
    }
};
