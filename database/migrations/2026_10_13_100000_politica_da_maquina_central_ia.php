<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O que a máquina de cada pessoa impõe (teto de acesso, análise, mínimo de
 * confirmação, Telegram configurado), informado na batida — para a tela de
 * Ajustes mostrar. Só leitura aqui: quem manda é a máquina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_ia_runners', fn (Blueprint $t) => $t->json('politica')->nullable());
    }

    public function down(): void
    {
        Schema::table('central_ia_runners', fn (Blueprint $t) => $t->dropColumn('politica'));
    }
};
