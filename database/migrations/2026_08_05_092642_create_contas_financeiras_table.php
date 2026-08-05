<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_financeiras', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->enum('tipo', ['corrente', 'poupanca', 'cartao', 'caixa'])->default('corrente');
            $table->string('banco_codigo', 10)->nullable();
            $table->string('agencia')->nullable();
            $table->string('numero_conta')->nullable();
            $table->decimal('saldo', 12, 2)->default(0);
            $table->decimal('limite_cartao', 12, 2)->nullable();
            $table->unsignedTinyInteger('dia_fechamento_cartao')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contas_financeiras');
    }
};
