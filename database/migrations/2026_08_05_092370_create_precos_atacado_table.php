<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('precos_atacado', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->foreignId('revenda_id')->nullable()->constrained('revendas')->cascadeOnDelete();
            $table->decimal('valor_por_cliente_ativo', 10, 2);
            $table->date('vigencia_inicio');
            $table->date('vigencia_fim')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('precos_atacado');
    }
};
