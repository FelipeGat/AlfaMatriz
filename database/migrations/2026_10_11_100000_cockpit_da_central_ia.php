<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Central vira cockpit de vários projetos ao mesmo tempo (pedido do dono
     * em 10/10/2026: "às vezes estou mexendo em até 5 projetos").
     *
     * A TAREFA é a unidade de trabalho: pertence a um projeto, junta os
     * pedidos e as respostas, e guarda o dossiê — objetivo, decisões,
     * branch, arquivos, testes, próximos passos. É o dossiê, e não a sessão
     * interna de cada IA, que permite trocar de agente no meio sem perder o
     * fio: a sessão do Claude não serve ao Codex, o dossiê serve aos dois.
     */
    public function up(): void
    {
        Schema::create('central_ia_tarefas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('projeto', 128);
            $table->string('titulo');
            $table->text('objetivo');
            $table->string('status', 20)->default('aberta');
            $table->string('agente_atual', 16)->nullable();
            // Uma worktree e uma branch POR TAREFA, e não por execução: o
            // agente que assume depois de uma troca continua nos mesmos
            // arquivos. `commit_base` é de onde a branch saiu.
            $table->string('worktree')->nullable();
            $table->string('branch', 128)->nullable();
            $table->string('commit_base', 64)->nullable();
            $table->json('dossie')->nullable();
            $table->timestamp('ultima_atividade_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'projeto', 'status']);
        });

        Schema::table('central_ia_mensagens', function (Blueprint $table) {
            // Nulo é a conversa "Geral" — tudo o que existia antes do cockpit.
            $table->string('projeto', 128)->nullable()->after('conversa_id');
            $table->foreignId('tarefa_id')->nullable()->after('projeto')->constrained('central_ia_tarefas')->nullOnDelete();
            $table->index(['user_id', 'projeto', 'id']);
        });

        Schema::table('central_ia_execucoes', function (Blueprint $table) {
            $table->foreignId('tarefa_id')->nullable()->after('runner_id')->constrained('central_ia_tarefas')->nullOnDelete();
            // Por que este agente: a regra que casou, ou "escolha manual".
            $table->json('roteamento')->nullable()->after('modo');
            // O estado real do git ao fim da execução, medido pelo runner — e
            // não o que o agente disse que fez.
            $table->json('checkpoint')->nullable()->after('branch');
        });

        Schema::table('central_ia_runners', function (Blueprint $table) {
            // Por projeto: branch, alterações pendentes, último commit, se há
            // sessão de agente aberta na pasta e o retrato do painel-avanco.
            $table->json('estado')->nullable()->after('projetos');
        });

        // Os projetos que a pessoa quer sempre à vista no cockpit.
        Schema::create('central_ia_projetos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('projeto', 128);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'projeto']);
        });

        Schema::table('central_ia_telegram_vinculos', function (Blueprint $table) {
            // `/usar alfahome` e `/auto`: o que vale para as próximas
            // mensagens daquele chat.
            $table->string('projeto_atual', 128)->nullable();
            $table->string('agente_atual', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('central_ia_telegram_vinculos', fn (Blueprint $t) => $t->dropColumn(['projeto_atual', 'agente_atual']));
        Schema::dropIfExists('central_ia_projetos');
        Schema::table('central_ia_runners', fn (Blueprint $t) => $t->dropColumn('estado'));
        Schema::table('central_ia_execucoes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tarefa_id');
            $t->dropColumn(['roteamento', 'checkpoint']);
        });
        Schema::table('central_ia_mensagens', function (Blueprint $t) {
            $t->dropIndex(['user_id', 'projeto', 'id']);
            $t->dropConstrainedForeignId('tarefa_id');
            $t->dropColumn('projeto');
        });
        Schema::dropIfExists('central_ia_tarefas');
    }
};
