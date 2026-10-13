<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os agentes da Central (Claude, GPT/Codex, Copilot).
     *
     * Quem EXECUTA não é o AlfaMatriz: é o runner na máquina de cada pessoa,
     * com o login de IA dela (decisão do dono em 10/10/2026 — o Rossini tem
     * as IAs e a WSL dele, o Felipe as suas). O banco guarda o pedido, o
     * estado e a resposta; nenhuma credencial de IA passa por aqui.
     */
    public function up(): void
    {
        // Resposta de agente passa fácil dos 64 KB do TEXT (análise de projeto
        // inteiro). A mensagem de gente continua limitada a 3000 caracteres na
        // validação; a coluna é que não pode cortar a do agente.
        Schema::table('central_ia_mensagens', function (Blueprint $table) {
            $table->mediumText('conteudo')->change();
        });

        // O que o runner contou na última batida: quais agentes a máquina tem
        // e quais projetos alcança. É o que a tela oferece no seletor.
        Schema::create('central_ia_runners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('maquina', 64);
            $table->json('agentes');
            $table->json('projetos');
            $table->timestamp('visto_em');
            $table->timestamps();

            $table->unique(['user_id', 'maquina']);
        });

        Schema::create('central_ia_execucoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('pedido_id')->unique()->constrained('central_ia_mensagens')->restrictOnDelete();
            $table->foreignId('resposta_id')->nullable()->constrained('central_ia_mensagens')->restrictOnDelete();
            $table->foreignId('runner_id')->nullable()->constrained('central_ia_runners')->nullOnDelete();
            $table->string('agente', 16);
            $table->string('projeto', 128);
            $table->string('modo', 16);
            $table->text('instrucao');
            $table->string('status', 16)->default('pendente');
            // A sessão do próprio agente (id do Claude, do Codex, do Copilot),
            // para o pedido seguinte no mesmo projeto continuar a conversa.
            $table->string('sessao_agente', 128)->nullable();
            $table->string('branch', 128)->nullable();
            $table->string('erro', 255)->nullable();
            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'agente', 'projeto', 'id']);
        });

        // Conversar e acionar agente são permissões separadas: acionar gasta
        // a assinatura de IA da pessoa e mexe em projeto. O perfil "Central
        // de IA" leva as duas; quem um dia tiver só a conversa, não executa.
        $permissaoId = DB::table('permissoes')->where('recurso', 'central_ia_agentes')->value('id')
            ?? DB::table('permissoes')->insertGetId([
                'recurso' => 'central_ia_agentes',
                'descricao' => 'Central de IA: acionar agentes nos projetos',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        if ($perfilId = DB::table('perfis')->where('slug', 'central_ia')->value('id')) {
            DB::table('perfil_permissao')->updateOrInsert(
                ['perfil_id' => $perfilId, 'permissao_id' => $permissaoId],
                ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => false, 'excluir' => false],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('central_ia_execucoes');
        Schema::dropIfExists('central_ia_runners');

        $permissaoId = DB::table('permissoes')->where('recurso', 'central_ia_agentes')->value('id');
        DB::table('perfil_permissao')->where('permissao_id', $permissaoId)->delete();
        DB::table('permissoes')->where('id', $permissaoId)->delete();
    }
};
