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
        Schema::create('lead_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('autor_id')->constrained('users');
            $table->string('nome_original');
            $table->string('nome_arquivo');
            $table->string('mime')->nullable();
            $table->string('caminho');
            $table->unsignedBigInteger('tamanho');
            $table->timestamps();

            $table->index('lead_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_anexos');
    }
};
