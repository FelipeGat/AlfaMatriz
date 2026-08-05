<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_fixas_pagar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centro_custo_id')->nullable()->constrained('centros_custo')->nullOnDelete();
            $table->foreignId('conta_id')->nullable()->constrained('contas')->nullOnDelete();
            $table->foreignId('fornecedor_id')->nullable()->constrained('fornecedores')->nullOnDelete();
            $table->foreignId('conta_financeira_id')->nullable()->constrained('contas_financeiras')->nullOnDelete();
            $table->string('descricao');
            $table->decimal('valor', 12, 2);
            $table->unsignedTinyInteger('dia_vencimento');
            $table->date('data_inicio');
            $table->date('data_fim')->nullable();
            $table->string('forma_pagamento')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contas_fixas_pagar');
    }
};
