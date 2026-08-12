<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data de entrada de revendas e sistemas.
 *
 * `created_at` responde "quando a LINHA nasceu", não "desde quando existe".
 * A base veio de importação, então ele marca o dia da migração para todo mundo
 * de uma vez — e qualquer curva de crescimento tirada dele vira um degrau, com
 * meses zerados e tudo aparecendo no último ponto. Os clientes já tinham
 * `data_cadastro` por isso; revendas e sistemas não.
 *
 * Fica NULA de propósito. Não dá para inventar a data de quem já está lá: onde
 * ela falta, quem lê cai de volta no `created_at` (é o que
 * `expressaoDeEntrada()` faz nos dois modelos). Preencher o histórico
 * conhecido é trabalho de quem tem a informação, e passa a ser possível a
 * partir daqui.
 *
 * Só acrescenta coluna anulável: a versão anterior do sistema continua rodando
 * em cima deste banco sem enxergá-la, que é o exigido pelo azul/verde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revendas', function (Blueprint $table) {
            $table->date('data_cadastro')->nullable()->after('ativo');
        });

        Schema::table('sistemas', function (Blueprint $table) {
            $table->date('data_cadastro')->nullable()->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('revendas', function (Blueprint $table) {
            $table->dropColumn('data_cadastro');
        });

        Schema::table('sistemas', function (Blueprint $table) {
            $table->dropColumn('data_cadastro');
        });
    }
};
