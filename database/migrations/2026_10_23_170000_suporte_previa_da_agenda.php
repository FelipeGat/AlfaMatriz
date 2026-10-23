<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * pedro-dia-do-tecnico, FASE 2: os orçamentos em aberto no retrato do Gestor (o relato pode ser
     * de um orçamento) e a prévia do dia guardada na conversa até o "sim" (FASE 4 grava).
     */
    public function up(): void
    {
        Schema::table('suporte_gestor_retrato', function (Blueprint $table) {
            $table->longText('orcamentos')->nullable();
        });
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->json('previa_agenda')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suporte_gestor_retrato', function (Blueprint $table) {
            $table->dropColumn('orcamentos');
        });
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->dropColumn('previa_agenda');
        });
    }
};
