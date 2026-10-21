<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fila dos atendentes (Felipe, 21/10/2026: "os atendimentos não podem ficar agarrados
     * sempre com o Pedro"): desde quando a conversa espera uma pessoa, por quê, e quando a
     * equipe foi lembrada pela última vez.
     */
    public function up(): void
    {
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->timestamp('fila_desde')->nullable()->after('humano_ate');
            $table->string('fila_motivo', 255)->nullable()->after('fila_desde');
            $table->timestamp('fila_lembrada_em')->nullable()->after('fila_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->dropColumn(['fila_desde', 'fila_motivo', 'fila_lembrada_em']);
        });
    }
};
