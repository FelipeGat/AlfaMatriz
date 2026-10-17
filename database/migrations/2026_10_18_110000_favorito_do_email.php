<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A estrela da tela de Mensagens (desenho de 17/10/2026): marca só daqui, nunca vai ao servidor de e-mail. */
    public function up(): void
    {
        Schema::table('secretario_emails', function (Blueprint $table) {
            $table->boolean('favorito')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('secretario_emails', function (Blueprint $table) {
            $table->dropColumn('favorito');
        });
    }
};
