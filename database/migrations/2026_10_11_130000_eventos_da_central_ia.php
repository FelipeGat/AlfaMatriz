<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O log do que acontece entre as sessões (pedido do dono em 10/10/2026:
     * "algo deu errado, quem foi que fez, qual agente, em qual projeto").
     *
     * Com acesso total liberado aos agentes, este registro é o que permite
     * reconstruir depois quem fez o quê. Só se acrescenta; nada aqui é editado.
     */
    public function up(): void
    {
        Schema::create('central_ia_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('tipo', 32);
            $table->string('nivel', 8)->default('info');
            $table->string('origem', 16)->nullable();
            $table->string('agente', 16)->nullable();
            $table->string('projeto', 128)->nullable();
            $table->foreignId('tarefa_id')->nullable()->constrained('central_ia_tarefas')->nullOnDelete();
            $table->foreignId('execucao_id')->nullable()->constrained('central_ia_execucoes')->nullOnDelete();
            $table->string('descricao', 500);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_ia_eventos');
    }
};
