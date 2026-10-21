<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A base do suporte (T-476; docs/specs/suporte-pedro-whatsapp/base-do-suporte.md).
     * Mora no Matriz, e não numa pasta do OneDrive (decisão do dono em 21/10/2026):
     * quem mais escreve nela são os próprios atendimentos, que o Pedro propõe e a
     * equipe confirma, e cada um precisa entrar na hora, sem depender da máquina
     * que espelha a pasta. Uma tabela só para os cinco tipos, porque o Pedro busca
     * em todos com a mesma régua e a tela os lista juntos.
     */
    public function up(): void
    {
        Schema::create('suporte_notas', function (Blueprint $table) {
            $table->id();
            // empresa | procedimento | conhecimento | cliente | atendimento
            $table->string('tipo', 15);
            // proposta (o Pedro escreveu, falta alguém confirmar) | ativa | descartada
            $table->string('estado', 12)->default('ativa');
            $table->string('titulo', 160);
            $table->string('quando_usar', 500)->nullable();
            $table->mediumText('corpo')->nullable();
            // As partes do atendimento: o relato não é fato; só o verificado é (FR-B7).
            $table->text('relato')->nullable();
            $table->text('verificado')->nullable();
            $table->text('solucao')->nullable();
            // em_curso | resolvido | encerrado_sem_confirmacao | cancelado (só atendimento)
            $table->string('situacao', 30)->nullable();
            $table->string('codigo', 30)->nullable()->unique();
            $table->unsignedBigInteger('gestor_cliente_id')->nullable()->index();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes', 'id', 'sup_nota_cliente_fk')->nullOnDelete();
            $table->foreignId('conversa_id')->nullable()->constrained('suporte_conversas', 'id', 'sup_nota_conversa_fk')->nullOnDelete();
            $table->foreignId('tarefa_id')->nullable()->constrained('tarefas', 'id', 'sup_nota_tarefa_fk')->nullOnDelete();
            // De qual atendimento saiu o conhecimento ("virar conhecimento").
            $table->foreignId('origem_id')->nullable()->constrained('suporte_notas', 'id', 'sup_nota_origem_fk')->nullOnDelete();
            // rascunho | validado (procedimento, conhecimento e empresa)
            $table->string('validacao', 10)->default('rascunho');
            $table->foreignId('validado_por')->nullable()->constrained('users', 'id', 'sup_nota_validou_fk')->nullOnDelete();
            $table->timestamp('validado_em')->nullable();
            $table->foreignId('confirmado_por')->nullable()->constrained('users', 'id', 'sup_nota_confirmou_fk')->nullOnDelete();
            $table->timestamp('confirmado_em')->nullable();
            // Sem autor: foi o Pedro.
            $table->foreignId('autor_id')->nullable()->constrained('users', 'id', 'sup_nota_autor_fk')->nullOnDelete();
            $table->foreignId('atualizado_por')->nullable()->constrained('users', 'id', 'sup_nota_atualizou_fk')->nullOnDelete();
            // As palavras da nota, sem acento e entre espaços, para a busca por palavra
            // inteira valer igual no MySQL e no SQLite dos testes.
            $table->text('termos')->nullable();
            $table->timestamps();
            $table->index(['tipo', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suporte_notas');
    }
};
