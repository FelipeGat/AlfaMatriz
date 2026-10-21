<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A equipe no retrato do Gestor (Felipe, 20/10/2026): quem da casa escreve
     * para o 4042 é reconhecido pelo telefone do cadastro de funcionários, e o
     * Pedro oferece ajuda com os atendimentos em nome dele.
     */
    public function up(): void
    {
        Schema::table('suporte_gestor_retrato', function (Blueprint $table) {
            $table->longText('equipe')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suporte_gestor_retrato', function (Blueprint $table) {
            $table->dropColumn('equipe');
        });
    }
};
