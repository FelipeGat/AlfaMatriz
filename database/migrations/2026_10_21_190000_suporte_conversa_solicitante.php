<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem escreve na conversa (T-478, decisão do dono em 21/10/2026): o Pedro só ajuda
     * depois que a pessoa diz o nome dela e o da empresa. O cliente já mora em
     * cliente_id/gestor_cliente_id; aqui fica a PESSOA, que vai como solicitante no
     * atendimento. Sem backfill: quem já conversava se identifica na próxima mensagem.
     */
    public function up(): void
    {
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->string('solicitante_nome', 120)->nullable()->after('nome_perfil');
            $table->string('solicitante_funcao', 60)->nullable()->after('solicitante_nome');
            $table->timestamp('identificada_em')->nullable()->after('solicitante_funcao');
        });
    }

    public function down(): void
    {
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->dropColumn(['solicitante_nome', 'solicitante_funcao', 'identificada_em']);
        });
    }
};
