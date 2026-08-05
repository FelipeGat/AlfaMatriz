<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faturamento_snapshot', function (Blueprint $table) {
            $table->id();
            $table->string('competencia', 7)->comment('formato AAAA-MM');
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->foreignId('revenda_id')->constrained('revendas')->cascadeOnDelete();
            $table->unsignedInteger('clientes_ativos');
            $table->decimal('valor_unitario', 10, 2);
            $table->decimal('total', 12, 2);
            $table->foreignId('cobranca_id')->nullable()->constrained('cobrancas')->nullOnDelete();
            $table->timestamps();

            $table->unique(['competencia', 'sistema_id', 'revenda_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faturamento_snapshot');
    }
};
