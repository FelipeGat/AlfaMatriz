<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_sistema', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->boolean('ativo')->default(true);
            $table->date('ativado_em')->nullable();
            $table->timestamps();

            $table->unique(['cliente_id', 'sistema_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_sistema');
    }
};
