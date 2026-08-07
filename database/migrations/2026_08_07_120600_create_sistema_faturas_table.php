<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistema_faturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->foreignId('sistema_cliente_id')->constrained('sistema_clientes')->cascadeOnDelete();
            $table->foreignId('sistema_revenda_id')->nullable()->constrained('sistema_revendas')->nullOnDelete();
            $table->string('id_externo', 80);

            $table->char('competencia', 7);
            $table->decimal('valor', 12, 2)->default(0);
            $table->char('moeda', 3)->default('BRL');
            // pago|aberto|vencido|cancelado
            $table->string('status', 12)->default('aberto');
            $table->date('vencimento_em')->nullable();
            $table->date('pago_em')->nullable();
            $table->integer('dias_em_atraso')->default(0);
            $table->unsignedInteger('unidades_cobradas')->default(0);
            $table->string('plano')->nullable();
            $table->string('licenca_id_externo', 64)->nullable();

            // titulo|derivado. "derivado" marca que o sistema não tem título de
            // cobrança de verdade e a linha foi inferida da licença — a tela de
            // divergências não pode acusar diferença em cima disso, seria falso
            // alarme contra um número que o próprio sistema não considera oficial.
            $table->string('origem', 10)->default('titulo');

            $table->timestamp('ausente_em_origem_em')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();

            $table->unique(['sistema_id', 'id_externo']);
            $table->index(['sistema_id', 'competencia']);
            $table->index(['competencia', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistema_faturas');
    }
};
