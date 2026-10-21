<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O pedido de gravação no Gestor ganha o modo (Felipe, 21/10/2026: conferir se não há
     * chamado em aberto ou anterior do mesmo assunto e, em vez de abrir outro, vincular ou
     * reabrir — garantia): novo, vincular ou reabrir, o nº do atendimento existente e quem
     * pediu, quando é da casa.
     */
    public function up(): void
    {
        Schema::table('suporte_gestor_aberturas', function (Blueprint $table) {
            $table->string('modo', 10)->default('novo')->after('assunto_id');
            $table->string('atendimento_numero', 30)->nullable()->after('modo');
            $table->string('pedido_por', 120)->nullable()->after('solicitante');
        });
    }

    public function down(): void
    {
        Schema::table('suporte_gestor_aberturas', function (Blueprint $table) {
            $table->dropColumn(['modo', 'atendimento_numero', 'pedido_por']);
        });
    }
};
