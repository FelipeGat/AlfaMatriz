<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistema_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->foreignId('sistema_cliente_id')->constrained('sistema_clientes')->cascadeOnDelete();
            $table->string('id_externo', 64);

            $table->string('nome');
            $table->string('email')->nullable();
            $table->string('papel', 40)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamp('ultimo_acesso_em')->nullable();

            $table->timestamp('ausente_em_origem_em')->nullable();
            // NENHUMA credencial é guardada aqui: o contrato proíbe o sistema
            // de mandar senha, resumo de senha ou token, e o retrato não seria
            // lugar para isso nem se ele mandasse.
            $table->json('payload')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();

            $table->unique(['sistema_id', 'id_externo']);
            $table->index('sistema_cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistema_usuarios');
    }
};
