<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de módulos contratáveis, por sistema.
 *
 * Módulo é um adicional cobrado à parte da licença — no AlfaControl,
 * FINANCEIRO, REFEITORIO, NOTIFICACOES. A tabela nasce escopada por
 * `sistema_id` para servir a qualquer produto, não só ao AlfaControl.
 *
 * A chave natural é (`sistema_id`, `codigo`), não o id numérico da origem: o
 * código é único e estável entre ambientes, o id não é.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->string('codigo', 40);
            $table->string('nome', 120);
            $table->text('descricao')->nullable();
            // Módulo descontinuado na origem vira inativo, nunca é apagado:
            // remover o catálogo levaria junto o histórico de contratações.
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['sistema_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modulos');
    }
};
