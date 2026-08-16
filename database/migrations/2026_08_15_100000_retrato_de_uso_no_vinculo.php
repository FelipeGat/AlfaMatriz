<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O retrato de uso de um cliente num sistema — quantas unidades ele realmente
 * usa, medido na origem via `/api/matriz/v1/uso`.
 *
 * A Matriz só sabia contar clientes, o que serve para cobrar por academia ou
 * por condomínio, mas não para sistema metrado: o AlfaJornada cobra por
 * funcionário ativo, e esse número só existe dentro dele. Mora no vínculo, ao
 * lado do retrato de licença, porque é a mesma natureza de dado: o espelho da
 * última leitura, que o ciclo refaz de hora em hora.
 *
 * Sem backfill: o retrato nasce vazio e o primeiro ciclo com a capacidade
 * `sincroniza_uso` ligada o preenche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliente_sistema', function (Blueprint $table) {
            // A contagem na unidade de cobrança do próprio sistema (funcionário
            // ativo no AlfaJornada, 1 por condomínio no AlfaControl).
            $table->unsignedInteger('uso_unidades')->nullable()->after('licenca_id_externo');

            // Contadores informativos que a origem quiser mandar (pessoas,
            // alunos, dispositivos, CNPJs...). Mapa livre: um sistema novo
            // acrescenta contador sem migração aqui.
            $table->json('uso_metricas')->nullable()->after('uso_unidades');

            $table->timestamp('uso_medido_em')->nullable()->after('uso_metricas');
        });
    }

    public function down(): void
    {
        Schema::table('cliente_sistema', function (Blueprint $table) {
            $table->dropColumn(['uso_unidades', 'uso_metricas', 'uso_medido_em']);
        });
    }
};
