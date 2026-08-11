<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separa a fatura em licenciamento e módulos.
 *
 * `total` continua sendo o que vai para a cobrança — a chave única
 * (competencia, sistema_id, revenda_id) não muda, então a idempotência de hoje
 * continua valendo. O que entra é a decomposição: quem recebe a fatura precisa
 * ver "licenciamento R$ X + módulos R$ Y", não um número só.
 *
 * Sistema sem módulo tem `valor_modulos = 0` e `total` idêntico ao de antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faturamento_snapshot', function (Blueprint $table) {
            $table->decimal('valor_licenciamento', 12, 2)->default(0)->after('valor_unitario');
            $table->decimal('valor_modulos', 12, 2)->default(0)->after('valor_licenciamento');
            $table->json('detalhe_modulos')->nullable()->after('valor_modulos');
        });
    }

    public function down(): void
    {
        Schema::table('faturamento_snapshot', function (Blueprint $table) {
            $table->dropColumn(['valor_licenciamento', 'valor_modulos', 'detalhe_modulos']);
        });
    }
};
