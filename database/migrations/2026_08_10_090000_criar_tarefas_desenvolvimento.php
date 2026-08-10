<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarefas', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->string('resumo')->nullable();
            $table->text('detalhes')->nullable();
            $table->foreignId('sistema_id')->nullable()->constrained('sistemas')->nullOnDelete();
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('criado_por_id')->constrained('users')->cascadeOnDelete();
            $table->enum('prioridade', ['baixa', 'media', 'alta'])->default('media');
            $table->string('status');
            $table->timestamp('iniciada_em')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('tarefa_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarefa_id')->constrained('tarefas')->cascadeOnDelete();
            $table->string('de_status')->nullable();
            $table->string('para_status');
            $table->text('motivo')->nullable();
            $table->timestamp('entrou_em');
            $table->timestamp('saiu_em')->nullable();
            $table->unsignedInteger('duracao_segundos')->nullable();
            $table->timestamps();

            $table->index(['tarefa_id', 'saiu_em']);
        });

        Schema::create('tarefa_relatorios_teste', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarefa_id')->constrained('tarefas')->cascadeOnDelete();
            $table->boolean('aprovado');
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->index('tarefa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarefa_relatorios_teste');
        Schema::dropIfExists('tarefa_eventos');
        Schema::dropIfExists('tarefas');
    }
};
