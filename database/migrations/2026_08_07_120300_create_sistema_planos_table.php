<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistema_planos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->string('id_externo', 64);

            $table->string('nome');
            $table->boolean('ativo')->default(true);
            $table->decimal('preco_mensal', 12, 2)->nullable();
            $table->char('moeda', 3)->default('BRL');
            // Livre de propósito: a matriz guarda e mostra o que o sistema
            // declara como limite, sem tentar interpretar.
            $table->json('limites')->nullable();

            $table->timestamp('ausente_em_origem_em')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();

            $table->unique(['sistema_id', 'id_externo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistema_planos');
    }
};
