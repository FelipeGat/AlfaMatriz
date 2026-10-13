<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_ia_anexos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mensagem_id')->nullable()->constrained('central_ia_mensagens')->cascadeOnDelete();
            $table->string('nome', 180);
            $table->string('mime', 80);
            $table->unsignedBigInteger('tamanho');
            $table->string('caminho');
            $table->string('sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_ia_anexos');
    }
};
