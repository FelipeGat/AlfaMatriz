<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfil_permissao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perfil_id')->constrained('perfis')->cascadeOnDelete();
            $table->foreignId('permissao_id')->constrained('permissoes')->cascadeOnDelete();
            $table->boolean('ler')->default(false);
            $table->boolean('incluir')->default(false);
            $table->boolean('imprimir')->default(false);
            $table->boolean('excluir')->default(false);
            $table->timestamps();
            $table->unique(['perfil_id', 'permissao_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfil_permissao');
    }
};
