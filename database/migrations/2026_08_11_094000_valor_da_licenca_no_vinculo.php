<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O valor da licença que o sistema de origem informa.
 *
 * Fica separado de `clientes.valor_mensal` de propósito: aquele é o acordo
 * comercial entre a Alfa e o cliente, preenchido por quem vende; este é o preço
 * da licença registrado no sistema de origem. Misturar os dois faria o ticket
 * médio da Matriz passar a medir outra coisa.
 *
 * Nulo é o normal: o contrato do AlfaGym ainda não expõe `valor`, e a coluna
 * simplesmente fica vazia para ele — acrescentar o campo lá depois continua
 * sendo v1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliente_sistema', function (Blueprint $table) {
            $table->decimal('licenca_valor', 12, 2)->nullable()->after('plano');
        });
    }

    public function down(): void
    {
        Schema::table('cliente_sistema', fn (Blueprint $table) => $table->dropColumn('licenca_valor'));
    }
};
