<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `locacao_sistema` é a fatura consolidada por revenda (motor agregado); esta
 * migration acrescenta `locacao_cliente` — a cobrança gerada por
 * `ClienteContrato`, uma linha por cliente final. Os dois nunca colidem: cada
 * motor cria sua própria `Cobranca` com seu próprio `tipo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->enum('tipo', ['locacao_sistema', 'locacao_cliente', 'avulsa', 'direta'])->default('avulsa')->change();
        });
    }

    public function down(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->enum('tipo', ['locacao_sistema', 'avulsa', 'direta'])->default('avulsa')->change();
        });
    }
};
