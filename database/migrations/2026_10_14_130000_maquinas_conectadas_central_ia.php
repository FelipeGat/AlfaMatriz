<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qual token a máquina usou na última batida (T-374). É o que liga a linha de
 * "Conectar minha máquina" (o token que a pessoa gerou pela tela) à máquina
 * que de fato apareceu, e o que deixa o "Desconectar" apagar as duas juntas.
 *
 * Sem chave estrangeira de propósito: o token é do Sanctum e some no
 * revogar; a máquina fica com o número solto até a próxima batida, que já
 * não passa pela porta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_ia_runners', function (Blueprint $t) {
            $t->unsignedBigInteger('token_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('central_ia_runners', function (Blueprint $t) {
            $t->dropIndex(['token_id']);
            $t->dropColumn('token_id');
        });
    }
};
