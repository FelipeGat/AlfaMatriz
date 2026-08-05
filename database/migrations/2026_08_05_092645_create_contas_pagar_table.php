<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_pagar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centro_custo_id')->nullable()->constrained('centros_custo')->nullOnDelete();
            $table->foreignId('conta_id')->nullable()->constrained('contas')->nullOnDelete();
            $table->foreignId('fornecedor_id')->nullable()->constrained('fornecedores')->nullOnDelete();
            $table->foreignId('conta_financeira_id')->nullable()->constrained('contas_financeiras')->nullOnDelete();
            $table->string('descricao');
            $table->decimal('valor', 12, 2);
            $table->date('data_vencimento');
            $table->date('data_pagamento')->nullable();
            $table->decimal('valor_pago', 12, 2)->nullable();
            $table->enum('status', ['em_aberto', 'pago', 'cancelado'])->default('em_aberto');
            $table->enum('tipo', ['avulsa', 'fixa'])->default('avulsa');
            $table->string('forma_pagamento')->nullable();
            $table->timestamps();

            $table->index(['status', 'data_vencimento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contas_pagar');
    }
};
