<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A agenda dos técnicos no retrato do Gestor (pedro-dia-do-tecnico, 23/10/2026): as visitas de
     * 7 dias para trás a 14 para frente, para o Pedro mostrar o dia de um técnico.
     */
    public function up(): void
    {
        Schema::table('suporte_gestor_retrato', function (Blueprint $table) {
            $table->longText('visitas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suporte_gestor_retrato', function (Blueprint $table) {
            $table->dropColumn('visitas');
        });
    }
};
