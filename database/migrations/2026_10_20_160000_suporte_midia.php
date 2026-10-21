<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O Pedro vê a foto e ouve o áudio (docs/specs/suporte-pedro-whatsapp/estagiario.md,
     * Fase 2): a mídia vem do Gestor (que a baixou da Meta) e fica aqui, privada;
     * a transcrição do áudio, feita no runner, fica registrada na mensagem.
     */
    public function up(): void
    {
        Schema::table('suporte_mensagens', function (Blueprint $table) {
            $table->string('midia_path', 255)->nullable();
            $table->string('midia_mime', 100)->nullable();
            $table->text('transcricao')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suporte_mensagens', function (Blueprint $table) {
            $table->dropColumn(['midia_path', 'midia_mime', 'transcricao']);
        });
    }
};
