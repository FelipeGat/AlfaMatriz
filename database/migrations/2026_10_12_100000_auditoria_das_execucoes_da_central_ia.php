<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem viu o quê e quem alterou o quê, por execução (dono, 12/10/2026): os
 * agentes trabalham com acesso total, e a pessoa confere depois no Contexto
 * e no log. Vem do runner — eventos da CLI e o git medido antes e depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_ia_execucoes', function (Blueprint $table) {
            $table->json('auditoria')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('central_ia_execucoes', fn (Blueprint $table) => $table->dropColumn('auditoria'));
    }
};
