<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            // Endereço completo (só tinha cidade/uf)
            $table->string('cep', 10)->nullable()->after('cpf_cnpj');
            $table->string('logradouro')->nullable()->after('cep');
            $table->string('numero', 20)->nullable()->after('logradouro');
            $table->string('bairro')->nullable()->after('numero');
            $table->string('complemento')->nullable()->after('cidade');
            $table->decimal('latitude', 10, 7)->nullable()->after('uf');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            // Fiscal
            $table->string('inscricao_estadual', 30)->nullable()->after('longitude');
            $table->string('inscricao_municipal', 30)->nullable()->after('inscricao_estadual');
            $table->boolean('nota_fiscal')->default(false)->after('inscricao_municipal');

            // Classificação comercial (espelha CONTRATO/AVULSO do Gestor.Alfa)
            $table->enum('tipo_cliente', ['CONTRATO', 'AVULSO'])->default('AVULSO')->after('ativo');
            $table->decimal('valor_mensal', 10, 2)->nullable()->after('tipo_cliente');
            $table->unsignedTinyInteger('dia_vencimento')->nullable()->after('valor_mensal');
            $table->enum('forma_pagamento_recebimento', ['boleto', 'faturado'])->default('faturado')->after('dia_vencimento');
            $table->date('data_cadastro')->nullable()->after('forma_pagamento_recebimento');

            $table->text('observacoes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn([
                'cep', 'logradouro', 'numero', 'bairro', 'complemento', 'latitude', 'longitude',
                'inscricao_estadual', 'inscricao_municipal', 'nota_fiscal',
                'tipo_cliente', 'valor_mensal', 'dia_vencimento', 'forma_pagamento_recebimento',
                'data_cadastro', 'observacoes',
            ]);
        });
    }
};
