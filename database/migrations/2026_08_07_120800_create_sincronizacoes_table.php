<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sincronizacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();

            // completa|revendas|clientes|planos|usuarios|licencas|financeiro|contadores
            $table->string('escopo', 20)->default('completa');
            $table->char('competencia', 7)->nullable();
            // agendada|manual|comando
            $table->string('origem', 10)->default('agendada');
            // em_andamento|sucesso|parcial|falha
            $table->string('status', 12)->default('em_andamento');

            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('finalizada_em')->nullable();
            $table->unsignedInteger('duracao_ms')->nullable();

            $table->unsignedInteger('itens_lidos')->default(0);
            $table->unsignedInteger('itens_criados')->default(0);
            $table->unsignedInteger('itens_atualizados')->default(0);
            $table->unsignedInteger('itens_ausentes')->default(0);

            $table->string('erro_codigo', 60)->nullable();
            $table->text('erro_mensagem')->nullable();

            $table->foreignId('disparada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Sem este registro ninguém descobre que a rotina morreu — é
            // exatamente o defeito que o projeto tinha com o agendamento, que
            // ficou anos sem rodar sem que nada acusasse.
            $table->index(['sistema_id', 'escopo', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sincronizacoes');
    }
};
