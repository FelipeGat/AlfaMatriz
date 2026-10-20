<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quanto da transcrição a máquina já fez (Felipe, 19/10/2026: "o áudio tem
     * 30 min, tem como mostrar o progresso?"). O `progresso_em` serve também de
     * sinal de vida: transcrição longa que ainda avança não é tida como presa.
     */
    public function up(): void
    {
        Schema::table('secretario_reunioes', function (Blueprint $table) {
            $table->unsignedTinyInteger('progresso')->nullable();
            $table->timestamp('progresso_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('secretario_reunioes', function (Blueprint $table) {
            $table->dropColumn(['progresso', 'progresso_em']);
        });
    }
};
