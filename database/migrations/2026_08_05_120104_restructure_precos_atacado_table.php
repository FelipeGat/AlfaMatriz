<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('precos_atacado', function (Blueprint $table) {
            $table->renameColumn('valor_por_cliente_ativo', 'preco_base');
            $table->string('nome')->default('Único')->after('revenda_id');
            $table->unsignedInteger('unidades_inclusas')->nullable()->after('preco_base');
            $table->decimal('valor_excedente_unidade', 10, 2)->nullable()->after('unidades_inclusas');
            $table->unsignedInteger('limite_unidades')->nullable()->after('valor_excedente_unidade');
            $table->unsignedInteger('ordem')->default(0)->after('limite_unidades');
        });
    }

    public function down(): void
    {
        Schema::table('precos_atacado', function (Blueprint $table) {
            $table->dropColumn(['nome', 'unidades_inclusas', 'valor_excedente_unidade', 'limite_unidades', 'ordem']);
            $table->renameColumn('preco_base', 'valor_por_cliente_ativo');
        });
    }
};
