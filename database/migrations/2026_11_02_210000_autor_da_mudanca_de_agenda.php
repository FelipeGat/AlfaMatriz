<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * pedro-dia-do-tecnico, bugfix de 02/11/2026: quem pediu a mudança é o autor dela no Gestor. Antes
     * a máquina gravava com a conta do Pedro, sempre a mesma — um pedido do Wallas saía no nome do
     * Felipe. Nula para as linhas de antes: essas seguem com a conta, como foram confirmadas.
     */
    public function up(): void
    {
        Schema::table('suporte_gestor_agenda', function (Blueprint $table) {
            $table->string('autor_email', 255)->nullable()->after('pedido_por');
        });
    }

    public function down(): void
    {
        Schema::table('suporte_gestor_agenda', function (Blueprint $table) {
            $table->dropColumn('autor_email');
        });
    }
};
