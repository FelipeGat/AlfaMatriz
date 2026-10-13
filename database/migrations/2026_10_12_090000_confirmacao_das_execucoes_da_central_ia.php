<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado da confirmação que a MÁQUINA da pessoa pede antes de modificar algo
 * (dono, 12/10/2026: "tem que ser inviolável"). Quem confere o código é o
 * runner; aqui fica só o que a tela precisa mostrar e o código digitado até o
 * runner buscá-lo — nunca o segredo nem o código esperado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_ia_execucoes', function (Blueprint $table) {
            $table->json('confirmacao')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('central_ia_execucoes', fn (Blueprint $table) => $table->dropColumn('confirmacao'));
    }
};
