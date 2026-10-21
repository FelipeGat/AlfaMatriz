<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Técnico e agendamento no pedido de gravação no Gestor (Felipe, 21/10/2026: "existem
     * 3 empresas e vários técnicos [...] nem todo atendimento já fica agendado"). Sem data,
     * não agenda; o resultado da agenda volta da máquina.
     */
    public function up(): void
    {
        Schema::table('suporte_gestor_aberturas', function (Blueprint $table) {
            $table->unsignedBigInteger('funcionario_id')->nullable()->after('empresa_id');
            $table->date('agendar_data')->nullable()->after('funcionario_id');
            $table->string('agendar_periodo', 12)->nullable()->after('agendar_data');
            $table->string('agendamento', 255)->nullable()->after('numero');
        });
    }

    public function down(): void
    {
        Schema::table('suporte_gestor_aberturas', function (Blueprint $table) {
            $table->dropColumn(['funcionario_id', 'agendar_data', 'agendar_periodo', 'agendamento']);
        });
    }
};
