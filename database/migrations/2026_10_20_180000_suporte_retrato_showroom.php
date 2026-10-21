<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A biblioteca do Showroom comercial do Gestor no retrato (Felipe, 20/10/2026):
     * o Pedro pesquisa nela quando o cliente quer saber mais de um serviço.
     */
    public function up(): void
    {
        Schema::table('suporte_gestor_retrato', function (Blueprint $table) {
            $table->longText('showroom')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suporte_gestor_retrato', function (Blueprint $table) {
            $table->dropColumn('showroom');
        });
    }
};
