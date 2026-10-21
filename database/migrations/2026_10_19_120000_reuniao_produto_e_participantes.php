<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reunião vinculada ao produto e compartilhada com quem participou
     * (docs/specs/secretario-reuniao-produto). O acesso do participante é a
     * linha desta tabela, e não o perfil (FR-002).
     */
    public function up(): void
    {
        Schema::table('secretario_reunioes', function (Blueprint $table) {
            $table->foreignId('sistema_id')->nullable()->constrained('sistemas', 'id', 'sec_reunioes_sistema_fk')->nullOnDelete();
        });

        Schema::create('secretario_reuniao_participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reuniao_id')->constrained('secretario_reunioes', 'id', 'sec_reu_part_reuniao_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'sec_reu_part_user_fk')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['reuniao_id', 'user_id'], 'sec_reu_part_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secretario_reuniao_participantes');
        Schema::table('secretario_reunioes', function (Blueprint $table) {
            $table->dropForeign('sec_reunioes_sistema_fk');
            $table->dropColumn('sistema_id');
        });
    }
};
