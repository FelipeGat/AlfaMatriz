<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POPs das IAs (dono, 12/10/2026): o que a IA aprendeu para operar cada
 * sistema — tabelas, serviços, comandos testados, cuidados —, para o próximo
 * pedido do mesmo contexto não redescobrir tudo. Da conta do dono, como o
 * resto da Central: nenhum administrador vê, e nada vai para repositório.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_ia_pops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('sistema', 128);
            $table->string('titulo', 160);
            $table->mediumText('conteudo');
            $table->unsignedInteger('versao')->default(1);
            $table->string('autor', 80);
            $table->unsignedBigInteger('execucao_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'sistema', 'titulo']);
        });
        Schema::create('central_ia_pop_versoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pop_id')->constrained('central_ia_pops')->cascadeOnDelete();
            $table->unsignedInteger('versao');
            $table->mediumText('conteudo');
            $table->string('autor', 80);
            $table->unsignedBigInteger('execucao_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_ia_pop_versoes');
        Schema::dropIfExists('central_ia_pops');
    }
};
