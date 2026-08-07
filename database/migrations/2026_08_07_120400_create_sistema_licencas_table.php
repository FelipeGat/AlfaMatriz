<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistema_licencas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->foreignId('sistema_cliente_id')->constrained('sistema_clientes')->cascadeOnDelete();

            // NUNCA nulo. Sistema que não tem entidade de licença própria manda
            // um derivado do cliente ("cliente:128"), conforme o contrato.
            // Chave única sobre coluna que aceita nulo tem semântica diferente
            // entre o banco dos testes e o de produção — é assim que um teste
            // verde esconde duplicata em produção.
            $table->string('id_externo', 64);

            // ativa|pendente|vencida|bloqueada|cancelada
            $table->string('status', 20)->default('pendente');
            $table->string('plano')->nullable();
            $table->string('plano_id_externo', 64)->nullable();
            // mensal|anual
            $table->string('tipo', 10)->nullable();

            $table->date('inicio_em')->nullable();
            // Nulo significa sem expiração.
            $table->date('fim_em')->nullable();

            // Se vencer a licença REALMENTE barra o acesso naquele sistema. Em
            // alguns, vencer é decorativo — e a tela de confirmação lê este
            // campo para não prometer um efeito que não acontece.
            $table->boolean('bloqueia_acesso')->default(false);

            $table->string('liberada_por')->nullable();
            $table->timestamp('liberada_em')->nullable();

            $table->timestamp('ausente_em_origem_em')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();

            $table->unique(['sistema_id', 'id_externo']);
            $table->index(['sistema_id', 'status']);
            $table->index('fim_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistema_licencas');
    }
};
