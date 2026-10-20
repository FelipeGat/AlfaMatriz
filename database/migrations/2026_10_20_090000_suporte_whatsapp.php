<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O WhatsApp do suporte (docs/specs/suporte-pedro-whatsapp), etapa 1: as
     * conversas do número de suporte pela Cloud API oficial — nada de biblioteca
     * não oficial, que é o que leva o número a ser banido.
     */
    public function up(): void
    {
        Schema::create('suporte_conversas', function (Blueprint $table) {
            $table->id();
            $table->string('telefone', 20)->unique();
            $table->string('nome_perfil', 120)->nullable();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes', 'id', 'sup_conv_cliente_fk')->nullOnDelete();
            // pedro: o Pedro atende; humano: alguém assumiu (pelo app ou pelo Matriz) e o Pedro fica quieto.
            $table->string('situacao', 10)->default('pedro');
            $table->foreignId('humano_id')->nullable()->constrained('users', 'id', 'sup_conv_humano_fk')->nullOnDelete();
            $table->timestamp('humano_ate')->nullable();
            // A janela de 24 h da Meta conta da última mensagem DO CLIENTE.
            $table->timestamp('ultima_entrada_em')->nullable();
            $table->timestamp('ultima_mensagem_em')->nullable();
            $table->unsignedInteger('nao_lidas')->default(0);
            $table->foreignId('tarefa_id')->nullable()->constrained('tarefas', 'id', 'sup_conv_tarefa_fk')->nullOnDelete();
            $table->timestamps();
            $table->index('ultima_mensagem_em', 'sup_conv_ultima');
        });

        Schema::create('suporte_mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('suporte_conversas', 'id', 'sup_msg_conversa_fk')->cascadeOnDelete();
            // O id da Meta (wamid): a Meta reentrega o webhook, e a mesma mensagem não pode entrar duas vezes.
            $table->string('wamid', 128)->nullable()->unique('sup_msg_wamid');
            $table->string('direcao', 14);
            $table->string('tipo', 20)->default('text');
            $table->text('texto')->nullable();
            $table->foreignId('autor_id')->nullable()->constrained('users', 'id', 'sup_msg_autor_fk')->nullOnDelete();
            $table->string('status', 12)->nullable();
            $table->string('erro', 255)->nullable();
            $table->timestamp('enviada_em')->nullable();
            $table->timestamps();
        });

        $permissaoId = DB::table('permissoes')->where('recurso', 'suporte')->value('id')
            ?? DB::table('permissoes')->insertGetId(['recurso' => 'suporte', 'descricao' => 'Suporte pelo WhatsApp (conversas do número de suporte)', 'created_at' => now(), 'updated_at' => now()]);
        if ($adminId = DB::table('perfis')->where('slug', 'admin')->value('id')) {
            DB::table('perfil_permissao')->updateOrInsert(['perfil_id' => $adminId, 'permissao_id' => $permissaoId],
                ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suporte_mensagens');
        Schema::dropIfExists('suporte_conversas');
        if ($permissaoId = DB::table('permissoes')->where('recurso', 'suporte')->value('id')) {
            DB::table('perfil_permissao')->where('permissao_id', $permissaoId)->delete();
            DB::table('permissoes')->where('id', $permissaoId)->delete();
        }
    }
};
