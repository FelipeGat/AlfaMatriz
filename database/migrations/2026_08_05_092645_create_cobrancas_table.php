<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobrancas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revenda_id')->nullable()->constrained('revendas')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('sistema_id')->nullable()->constrained('sistemas')->nullOnDelete();
            $table->foreignId('conta_financeira_id')->nullable()->constrained('contas_financeiras')->nullOnDelete();
            $table->string('descricao');
            $table->decimal('valor', 12, 2);
            $table->date('data_vencimento');
            $table->date('data_pagamento')->nullable();
            $table->decimal('valor_pago', 12, 2)->nullable();
            $table->enum('status', ['pendente', 'pago', 'cancelado'])->default('pendente');
            $table->enum('tipo', ['locacao_sistema', 'avulsa', 'direta'])->default('avulsa');
            $table->string('competencia', 7)->nullable()->comment('formato AAAA-MM');
            $table->string('forma_pagamento')->nullable();
            $table->text('detalhamento')->nullable()->comment('JSON com o detalhamento por sistema quando gerado pelo motor');
            $table->timestamps();

            $table->index(['status', 'data_vencimento']);
            $table->index('competencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobrancas');
    }
};
