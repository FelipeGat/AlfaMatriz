<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de atendimento pelo WhatsApp com pré-cliente e horário (Felipe, 22/10/2026, pedido
     * do Wallas para a Revizza): o cliente do Gestor passa a aceitar pré-cliente (id NEGATIVO no
     * retrato, por isso a coluna deixa de ser sem sinal), o pedido pode criar um pré-cliente novo,
     * e a agenda leva título e horário de início e de fim, em vez de só o período.
     */
    public function up(): void
    {
        Schema::table('suporte_gestor_aberturas', function (Blueprint $table) {
            $table->bigInteger('gestor_cliente_id')->nullable()->change();
            $table->string('titulo', 120)->nullable()->after('descricao');
            $table->json('novo_pre')->nullable()->after('titulo');
            $table->string('hora_inicio', 5)->nullable()->after('agendar_periodo');
            $table->string('hora_fim', 5)->nullable()->after('hora_inicio');
        });
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->bigInteger('gestor_cliente_id')->nullable()->change();
        });
        // Os outros lugares que guardam o cliente do Gestor (atendimento e base de notas do Pedro).
        foreach (['suporte_atendimentos', 'suporte_notas'] as $tabela) {
            if (Schema::hasColumn($tabela, 'gestor_cliente_id')) {
                Schema::table($tabela, function (Blueprint $table) {
                    $table->bigInteger('gestor_cliente_id')->nullable()->change();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('suporte_gestor_aberturas', function (Blueprint $table) {
            $table->dropColumn(['titulo', 'novo_pre', 'hora_inicio', 'hora_fim']);
        });
    }
};
