<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistema_revendas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();

            // Como o SISTEMA chama esse registro. Texto, não número: nem todo
            // sistema identifica por inteiro, e o contrato não impõe isso.
            $table->string('id_externo', 64);

            // O vínculo com a revenda da matriz. Nulo enquanto ninguém casou.
            $table->foreignId('revenda_id')->nullable()->constrained('revendas')->nullOnDelete();
            // Como o vínculo nasceu: um vínculo feito à mão nunca é
            // sobrescrito por uma execução automática.
            $table->string('vinculo_origem', 12)->nullable();

            $table->string('nome');
            // Só dígitos: a matriz normaliza para poder casar por documento.
            $table->string('cnpj', 14)->nullable();
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->boolean('ativo')->default(true);
            $table->unsignedInteger('clientes_ativos')->default(0);

            $table->timestamp('ausente_em_origem_em')->nullable();
            // A resposta crua: campo novo do contrato fica visível sem migração,
            // e a auditoria do que o sistema disse continua honesta.
            $table->json('payload')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();

            // É esta chave que torna a idempotência estrutural, e não uma
            // conferência que alguém pode esquecer de fazer antes de gravar.
            $table->unique(['sistema_id', 'id_externo']);
            $table->index(['sistema_id', 'cnpj']);
            $table->index('revenda_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistema_revendas');
    }
};
