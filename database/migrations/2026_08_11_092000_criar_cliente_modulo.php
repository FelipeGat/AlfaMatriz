<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O que cada cliente tem contratado — o espelho do `cliente_modulo` da origem.
 *
 * O nome da tabela espelha `cliente_sistema` (o vínculo de licença) e também a
 * tabela de origem no AlfaControl: o mapa mental sai de graça.
 *
 * Sem coluna `id_externo`: a âncora mora em `origens_externas`, como a
 * migration `2026_08_08_090000` estabeleceu ao remover de propósito as colunas
 * próprias de id externo. `sistema_id` também não é denormalizado aqui — sai do
 * módulo por join, e coluna duplicada é coluna que diverge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_modulo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('modulo_id')->constrained('modulos')->cascadeOnDelete();
            $table->string('status', 20)->default('ativo'); // ativo|inativo|suspenso
            $table->date('data_inicio')->nullable();
            $table->date('data_fim')->nullable();
            $table->decimal('valor_mensal', 12, 2)->nullable();
            $table->string('observacao', 500)->nullable();
            $table->timestamps();

            $table->unique(['cliente_id', 'modulo_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_modulo');
    }
};
