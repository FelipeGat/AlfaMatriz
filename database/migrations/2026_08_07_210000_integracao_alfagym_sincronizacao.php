<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Identificador do registro na origem (AlfaGym), para a sincronização
        // ser idempotente por (sistema, id_externo) — nunca por nome.
        Schema::table('revendas', function (Blueprint $table) {
            $table->string('id_externo_origem')->nullable()->after('id');
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->string('id_externo_origem')->nullable()->after('id');
        });

        // A licença de um cliente num sistema mora no vínculo. As colunas
        // novas são o "retrato" do que o AlfaGym responde em /licencas.
        Schema::table('cliente_sistema', function (Blueprint $table) {
            $table->string('licenca_status')->nullable()->after('sistema_id');
            $table->string('plano')->nullable()->after('licenca_status');
            $table->date('licenca_inicio_em')->nullable()->after('plano');
            $table->date('licenca_fim_em')->nullable()->after('licenca_inicio_em');
            $table->boolean('bloqueia_acesso')->nullable()->after('licenca_fim_em');
            $table->string('licenca_id_externo')->nullable()->after('bloqueia_acesso');
        });
    }

    public function down(): void
    {
        Schema::table('revendas', fn (Blueprint $table) => $table->dropColumn('id_externo_origem'));
        Schema::table('clientes', fn (Blueprint $table) => $table->dropColumn('id_externo_origem'));
        Schema::table('cliente_sistema', function (Blueprint $table) {
            $table->dropColumn([
                'licenca_status', 'plano', 'licenca_inicio_em',
                'licenca_fim_em', 'bloqueia_acesso', 'licenca_id_externo',
            ]);
        });
    }
};
