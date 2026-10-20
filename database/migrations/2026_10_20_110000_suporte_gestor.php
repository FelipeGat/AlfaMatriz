<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O Pedro e o Gestor (docs/specs/suporte-pedro-whatsapp; Felipe, 20/10/2026):
     * a máquina do Felipe lê o Gestor de produção a cada 10 min (só leitura) e
     * manda um retrato dos clientes e dos atendimentos recentes; o atendimento de
     * cliente do Gestor (portão, câmera, interfone) é aberto NO Gestor, pela
     * ponte, numa fila que a máquina executa.
     */
    public function up(): void
    {
        Schema::create('suporte_gestor_retrato', function (Blueprint $table) {
            $table->id();
            $table->string('maquina', 64)->nullable();
            $table->timestamp('lido_em')->nullable();
            $table->longText('clientes');
            $table->longText('atendimentos');
            $table->longText('assuntos')->nullable();
            $table->timestamps();
        });

        Schema::table('suporte_conversas', function (Blueprint $table) {
            // O cliente do Gestor confirmado na conversa (id do Gestor, não do Matriz).
            $table->unsignedBigInteger('gestor_cliente_id')->nullable();
            $table->string('gestor_atendimento', 30)->nullable();
        });

        Schema::create('suporte_gestor_aberturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('suporte_conversas', 'id', 'sup_ga_conversa_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('gestor_cliente_id');
            // Assunto e empresa EXISTENTES no Gestor, escolhidos da lista do retrato (o Pedro nunca cria assunto).
            $table->unsignedBigInteger('assunto_id');
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->text('descricao');
            $table->string('solicitante', 120);
            $table->string('telefone', 20);
            $table->string('prioridade', 10)->default('media');
            $table->string('situacao', 12)->default('pendente');
            $table->string('numero', 30)->nullable();
            $table->string('erro', 255)->nullable();
            $table->timestamp('reservado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suporte_gestor_aberturas');
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->dropColumn(['gestor_cliente_id', 'gestor_atendimento']);
        });
        Schema::dropIfExists('suporte_gestor_retrato');
    }
};
