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
        Schema::create('cobranca_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobranca_id')->constrained('cobrancas')->cascadeOnDelete();
            $table->string('tipo');
            $table->string('nome_original');
            $table->string('nome_arquivo');
            $table->string('caminho');
            $table->unsignedInteger('tamanho');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cobranca_anexos');
    }
};
