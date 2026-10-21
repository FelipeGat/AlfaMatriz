<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A empresa da reunião (tela de Reuniões, desenho de 17/10/2026): filtro e coluna da lista. */
    public function up(): void
    {
        Schema::table('secretario_reunioes', function (Blueprint $table) {
            $table->string('empresa', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('secretario_reunioes', function (Blueprint $table) {
            $table->dropColumn('empresa');
        });
    }
};
