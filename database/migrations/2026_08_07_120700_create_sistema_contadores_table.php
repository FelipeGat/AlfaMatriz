<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistema_contadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->char('competencia', 7);

            // Copiada do sistema a cada coleta, não lida do cadastro: se a
            // unidade de cobrança mudar, os meses antigos continuam contando o
            // que contavam quando foram medidos.
            $table->string('unidade_cobranca')->nullable();

            $table->unsignedInteger('clientes_total')->default(0);
            $table->unsignedInteger('clientes_ativos')->default(0);
            $table->unsignedInteger('clientes_pendentes')->default(0);
            $table->unsignedInteger('clientes_bloqueados')->default(0);
            $table->unsignedInteger('unidades_ativas')->default(0);
            $table->unsignedInteger('licencas_ativas')->default(0);
            $table->unsignedInteger('licencas_vencendo')->default(0);
            $table->unsignedInteger('licencas_vencidas')->default(0);

            // A quebra por revenda vem pronta do sistema para a tela de
            // divergências resolver a comparação em UMA chamada. Só USO: o
            // dinheiro vive na matriz, e pedi-lo a cinco sistemas seria manter
            // cinco verdades sobre a mesma coisa.
            $table->json('por_revenda')->nullable();

            $table->timestamp('coletado_em')->nullable();
            $table->timestamps();

            $table->unique(['sistema_id', 'competencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistema_contadores');
    }
};
