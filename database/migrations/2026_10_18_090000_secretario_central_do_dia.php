<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secretário, Etapa 8 (docs/specs/secretario-central-do-dia): a empresa do
     * ponto de gestão lançado à mão (VixCard ainda não tem sistema) e a marca
     * de lido do e-mail, lida no servidor sem alterá-la.
     */
    public function up(): void
    {
        Schema::table('secretario_itens', function (Blueprint $table) {
            $table->string('empresa', 40)->nullable();
        });
        Schema::table('secretario_emails', function (Blueprint $table) {
            $table->boolean('lido')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('secretario_emails', function (Blueprint $table) {
            $table->dropColumn('lido');
        });
        Schema::table('secretario_itens', function (Blueprint $table) {
            $table->dropColumn('empresa');
        });
    }
};
