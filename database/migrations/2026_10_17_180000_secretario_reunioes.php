<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secretário, Etapa 4 (docs/specs/secretario-reunioes): reuniões e
     * ditados por áudio. O áudio fica só até a transcrição (decisão do dono,
     * 17/10/2026); trechos e análise ficam com a conta.
     */
    public function up(): void
    {
        Schema::create('secretario_reunioes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('titulo', 200);
            $table->string('origem', 10);
            $table->string('modo', 8)->default('reuniao');
            $table->string('status', 14)->default('aguardando');
            $table->string('erro', 80)->nullable();
            $table->string('arquivo')->nullable();
            $table->string('mime', 60);
            $table->unsignedInteger('tamanho');
            $table->string('sha256', 64);
            $table->unsignedInteger('duracao_s')->nullable();
            $table->string('maquina', 64)->nullable();
            $table->dateTime('reservada_em')->nullable();
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->string('tipo_reuniao', 16)->nullable();
            $table->json('contexto')->nullable();
            $table->text('resumo')->nullable();
            $table->json('participantes')->nullable();
            $table->json('proximos_passos')->nullable();
            $table->json('roteiro')->nullable();
            $table->boolean('parcial')->default(false);
            $table->unsignedBigInteger('execucao_id')->nullable();
            $table->bigInteger('telegram_update_id')->nullable()->unique();
            $table->dateTime('transcrita_em')->nullable();
            $table->dateTime('analisada_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'reservada_em']);
        });

        Schema::create('secretario_reuniao_trechos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reuniao_id')->constrained('secretario_reunioes')->cascadeOnDelete();
            $table->unsignedInteger('ordem');
            $table->decimal('inicio', 9, 2);
            $table->decimal('fim', 9, 2);
            $table->text('texto');

            $table->unique(['reuniao_id', 'ordem']);
        });

        Schema::create('secretario_reuniao_propostas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reuniao_id')->constrained('secretario_reunioes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('categoria', 10);
            $table->string('texto', 500);
            $table->string('responsavel', 120)->nullable();
            $table->string('prazo', 16)->nullable();
            $table->string('prioridade', 8)->default('normal');
            $table->string('classificacao', 12);
            $table->json('evidencias');
            $table->string('status', 10)->default('pendente');
            $table->foreignId('item_id')->nullable()->constrained('secretario_itens')->nullOnDelete();
            $table->timestamps();

            $table->index(['reuniao_id', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secretario_reuniao_propostas');
        Schema::dropIfExists('secretario_reuniao_trechos');
        Schema::dropIfExists('secretario_reunioes');
    }
};
