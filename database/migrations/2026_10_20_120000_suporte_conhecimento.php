<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O que o Pedro sabe do negócio e o registro de cada atendimento que ele abre
     * (docs/specs/suporte-pedro-whatsapp/conhecimento.md; Felipe, 20/10/2026):
     * roteiros por situação, escritos pelo Felipe, com prioridade e prazo; a
     * apresentação da empresa; e o atendimento com o nº do Gestor que quem
     * atende é obrigado a informar.
     */
    public function up(): void
    {
        Schema::create('suporte_roteiros', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 80);
            $table->text('quando');
            $table->text('orientacao')->nullable();
            $table->text('mensagem')->nullable();
            $table->string('prioridade', 12)->nullable();
            $table->unsignedSmallInteger('prazo_horas')->nullable();
            $table->boolean('abre_atendimento')->default(false);
            $table->boolean('ativo')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->foreignId('atualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('suporte_ajustes', function (Blueprint $table) {
            $table->string('chave', 40)->primary();
            $table->longText('valor')->nullable();
            $table->timestamps();
        });

        Schema::create('suporte_atendimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarefa_id')->unique()->constrained('tarefas')->cascadeOnDelete();
            $table->foreignId('conversa_id')->constrained('suporte_conversas', 'id', 'sup_at_conversa_fk')->cascadeOnDelete();
            $table->foreignId('roteiro_id')->nullable()->constrained('suporte_roteiros', 'id', 'sup_at_roteiro_fk')->nullOnDelete();
            $table->string('prioridade', 12)->nullable();
            $table->timestamp('prazo_em')->nullable();
            // "gestor": equipamento de cliente do Gestor — quem atende lança lá e informa o número aqui.
            $table->string('destino', 10)->nullable();
            $table->unsignedBigInteger('gestor_cliente_id')->nullable();
            $table->string('gestor_numero', 30)->nullable();
            $table->foreignId('gestor_registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('gestor_registrado_em')->nullable();
            $table->timestamp('ultima_cobranca_em')->nullable();
            $table->unsignedInteger('cobrancas')->default(0);
            $table->timestamps();
        });

        // O roteiro que o Pedro seguiu em cada resposta, mesmo sem abrir atendimento (o boleto, por exemplo).
        Schema::table('suporte_pedidos', function (Blueprint $table) {
            $table->unsignedBigInteger('roteiro_id')->nullable();
        });

        $agora = now();
        // Os três exemplos do Felipe, editáveis na tela. Nada de link ou nome que ele não deu.
        DB::table('suporte_roteiros')->insert([
            ['titulo' => 'Portão parado', 'ordem' => 1, 'prioridade' => 'critica', 'prazo_horas' => 3, 'abre_atendimento' => true,
                'quando' => 'Portão (de pedestre ou de garagem) que não abre, não fecha ou travou.',
                'orientacao' => 'É urgente. Confirme o cliente, pergunte qual portão e o que acontece, e abra o atendimento sem esperar mais detalhes.',
                'mensagem' => 'Entendi, portão parado é prioridade para nós: a equipe técnica tem até 3 horas para atender. Já estou encaminhando.',
                'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['titulo' => 'Boleto / segunda via', 'ordem' => 2, 'prioridade' => null, 'prazo_horas' => null, 'abre_atendimento' => false,
                'quando' => 'Cliente pede boleto, segunda via ou onde pagar.',
                'orientacao' => "Para cliente do Gestor (manutenção e segurança eletrônica): indique o portal do cliente. Informe o e-mail de login SÓ se ele aparecer em CLIENTE como \"Login do portal\"; senão, oriente o \"Esqueci minha senha\".\nPara cliente dos sistemas (Matriz) ou sem login no portal: abra atendimento para o financeiro.",
                'mensagem' => 'Você baixa o boleto no portal do cliente: https://gestor.alfa.solucoesgrupo.com — entre com o seu e-mail e senha e vá em Financeiro › Boletos. Se não lembrar a senha, use "Esqueci minha senha" na tela de entrada.',
                'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['titulo' => 'Comercial', 'ordem' => 3, 'prioridade' => 'media', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Quem não é cliente e quer conhecer, contratar ou pedir orçamento.',
                'orientacao' => 'Apresente a empresa com o que está em A EMPRESA (ramos de atuação, site e folders), pergunte o interesse e o nome, e encaminhe para um atendente comercial.',
                'mensagem' => null,
                'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora],
        ]);
        DB::table('suporte_ajustes')->insert([
            ['chave' => 'cobranca_minutos', 'valor' => '60', 'created_at' => $agora, 'updated_at' => $agora],
        ]);
    }

    public function down(): void
    {
        Schema::table('suporte_pedidos', fn (Blueprint $table) => $table->dropColumn('roteiro_id'));
        Schema::dropIfExists('suporte_atendimentos');
        Schema::dropIfExists('suporte_ajustes');
        Schema::dropIfExists('suporte_roteiros');
    }
};
