<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Passar na frente" (docs/specs/central-ia-fila-por-projeto): a fila
     * deixa de ser só ordem de chegada. O pedido urgente e a retomada do que
     * ele pausou ganham 1 e saem antes dos demais; entre eles, vale a chegada.
     */
    public function up(): void
    {
        Schema::table('central_ia_execucoes', function (Blueprint $table) {
            $table->unsignedTinyInteger('prioridade')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('central_ia_execucoes', function (Blueprint $table) {
            $table->dropColumn('prioridade');
        });
    }
};
