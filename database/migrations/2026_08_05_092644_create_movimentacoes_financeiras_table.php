<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimentacoes_financeiras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_financeira_id')->constrained('contas_financeiras')->cascadeOnDelete();
            $table->enum('tipo', ['entrada', 'saida', 'transferencia', 'ajuste']);
            $table->string('descricao');
            $table->decimal('valor', 12, 2);
            $table->decimal('saldo_resultante', 12, 2);
            $table->date('data');
            $table->nullableMorphs('origem');
            $table->timestamps();

            $table->index('data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimentacoes_financeiras');
    }
};
