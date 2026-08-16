<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O sistema que parou de sincronizar deixa de falhar em silêncio.
     *
     * O ciclo roda de hora em hora e a falha ia inteira para o stdout do cron:
     * um sistema fora do ar por um dia não deixava marca nenhuma no banco. A
     * marca é o que transforma 24 falhas idênticas em DOIS eventos com dono —
     * caiu (a primeira falha depois de um sucesso) e voltou (o primeiro
     * sucesso depois da queda). É o mesmo desenho do bloqueio da tarefa:
     * timestamp mais motivo, escritos só por quem tem a regra.
     */
    public function up(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            $table->timestamp('sincronizacao_caiu_em')->nullable();
            $table->string('sincronizacao_motivo')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            $table->dropColumn(['sincronizacao_caiu_em', 'sincronizacao_motivo']);
        });
    }
};
