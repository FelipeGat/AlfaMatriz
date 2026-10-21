<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O Pedro no (27) 4042-4157, que o Gestor atende (Gestor: docs/specs/whatsapp-pedro;
     * Felipe, 20/10/2026). O Gestor repassa cada evento e é ele quem envia; aqui
     * fica a conversa do Gestor a que cada uma corresponde e o setor dela lá
     * (comercial e financeiro são atendidos no Gestor, e o Pedro fica quieto).
     */
    public function up(): void
    {
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->unsignedBigInteger('gestor_conversa_id')->nullable();
            $table->string('gestor_setor', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->dropColumn(['gestor_conversa_id', 'gestor_setor']);
        });
    }
};
