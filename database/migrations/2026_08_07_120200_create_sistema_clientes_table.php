<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sistema_clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->string('id_externo', 64);

            // O vínculo com o cliente da matriz. Nulo enquanto ninguém casou —
            // e é a partir dele que o faturamento sabe quem cobrar.
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('sistema_revenda_id')->nullable()->constrained('sistema_revendas')->nullOnDelete();
            $table->string('vinculo_origem', 12)->nullable();

            $table->string('nome');
            $table->string('razao_social')->nullable();
            // Só dígitos: é a chave do casamento automático com a matriz.
            $table->string('cpf_cnpj', 14)->nullable();
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->string('cidade')->nullable();
            $table->char('uf', 2)->nullable();

            $table->boolean('ativo')->default(true);
            // ativo|pendente|bloqueado|cancelado, conforme o contrato.
            $table->string('status', 20)->default('ativo');
            $table->string('revenda_id_externo', 64)->nullable();

            // Quantas unidades DA UNIDADE DE COBRANÇA daquele sistema este
            // cliente representa. É o número que a matriz confronta com o que
            // a Alfa faturou da revenda — o campo mais importante do retrato.
            $table->unsignedInteger('unidades_ativas')->default(0);

            $table->timestamp('criado_em_origem')->nullable();
            $table->timestamp('atualizado_em_origem')->nullable();
            $table->timestamp('ausente_em_origem_em')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();

            $table->unique(['sistema_id', 'id_externo']);
            $table->index(['sistema_id', 'cpf_cnpj']);
            $table->index(['sistema_id', 'status']);
            $table->index('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistema_clientes');
    }
};
