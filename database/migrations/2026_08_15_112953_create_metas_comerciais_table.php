<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('metas_comerciais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendedor_id')->constrained('users');
            // 'AAAA-MM', mesmo formato que `competencia` usa em Cobranca e
            // ContaPagar — o resto do sistema já sabe ler essa string.
            $table->string('competencia', 7);
            $table->decimal('valor_meta', 12, 2);
            $table->timestamps();

            // Uma meta por vendedor por mês: a segunda gravação EDITA a
            // primeira, não empilha uma linha nova para o mesmo período.
            $table->unique(['vendedor_id', 'competencia']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metas_comerciais');
    }
};
