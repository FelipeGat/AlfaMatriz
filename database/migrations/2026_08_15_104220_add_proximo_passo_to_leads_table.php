<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // O que falta para fechar / o que está sendo acordado agora — o
            // resumo de UMA linha que o card mostra sem abrir nada. O
            // histórico completo (quando mudou, quem disse o quê) mora em
            // `lead_comentarios`; este campo é só o estado atual.
            $table->text('proximo_passo')->nullable()->after('observacoes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('proximo_passo');
        });
    }
};
