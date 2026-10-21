<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A conexão da conta com o VixOrganize (docs/specs/secretario-vixorganize):
     * endereço, chave de escrita cifrada e o último retrato da fila. Uma por conta.
     */
    public function up(): void
    {
        Schema::create('secretario_vixorganize', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique('sec_vixorg_user_unique')->constrained('users')->cascadeOnDelete();
            $table->string('url', 190);
            $table->text('chave');
            $table->string('autor_email', 190)->nullable();
            // O chat de Business do VixOrganize (criado ao conectar).
            $table->foreignId('tarefa_id')->nullable()->constrained('central_ia_tarefas', 'id', 'sec_vixorg_tarefa_fk')->nullOnDelete();
            $table->json('retrato')->nullable();
            $table->timestamp('lido_em')->nullable();
            $table->string('erro', 190)->nullable();
            $table->timestamp('erro_em')->nullable();
            $table->timestamps();
        });

        // O que a IA propôs enviar ao VixOrganize, esperando o "sim" da pessoa.
        Schema::create('secretario_vixorganize_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'sec_vixped_user_fk')->cascadeOnDelete();
            $table->foreignId('tarefa_id')->nullable()->constrained('central_ia_tarefas', 'id', 'sec_vixped_tarefa_fk')->nullOnDelete();
            $table->string('projeto', 128)->default('');
            $table->unsignedBigInteger('execucao_id')->nullable();
            $table->json('acoes');
            $table->string('status', 20)->default('pendente');
            $table->json('resultado')->nullable();
            $table->timestamp('expira_em');
            $table->timestamps();
            $table->index(['user_id', 'status'], 'sec_vixped_user_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secretario_vixorganize_pedidos');
        Schema::dropIfExists('secretario_vixorganize');
    }
};
