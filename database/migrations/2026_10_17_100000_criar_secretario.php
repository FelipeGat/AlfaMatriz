<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secretário Executivo, Etapa 1 (docs/specs/secretario-executivo).
     *
     * Tabelas próprias, e não `compromissos` nem `tarefas`: a Agenda do time
     * mostra o compromisso de todo mundo para quem tem `agenda`
     * (AgendaService::itens, filtro vazio = todos), e um lembrete pessoal lá
     * vazaria — o dono pediu, 17/10/2026, "cuidado para não ter dados vazados".
     * Tudo tem `user_id`, e toda consulta é escopada por ele.
     */
    public function up(): void
    {
        Schema::create('secretario_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 16);
            $table->string('titulo', 200);
            $table->text('descricao')->nullable();
            $table->dateTime('quando')->nullable();
            $table->boolean('so_data')->default(false);
            $table->string('fuso', 40)->default('America/Sao_Paulo');
            $table->unsignedSmallInteger('antecedencia_min')->nullable();
            $table->string('prioridade', 8)->default('normal');
            $table->string('status', 12)->default('aberto');
            $table->json('recorrencia')->nullable();
            $table->string('origem', 16);
            $table->string('origem_ref', 64)->nullable();
            $table->dateTime('concluido_em')->nullable();
            $table->dateTime('cancelado_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'quando']);
        });

        Schema::create('secretario_avisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('secretario_itens')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('para');
            $table->string('canal', 10);
            $table->string('status', 10)->default('pendente');
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->dateTime('proxima_tentativa_em')->nullable();
            $table->boolean('atrasado')->default(false);
            $table->bigInteger('telegram_message_id')->nullable();
            $table->string('erro', 40)->nullable();
            $table->dateTime('entregue_em')->nullable();
            $table->timestamps();

            // A segunda entrega do mesmo aviso morre aqui, e não numa checagem
            // que dois despachos simultâneos passariam juntos.
            $table->unique(['item_id', 'para', 'canal']);
            $table->index(['status', 'para']);
        });

        Schema::create('secretario_propostas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('origem', 16);
            $table->text('texto');
            $table->json('dados')->nullable();
            $table->string('falta', 16)->nullable();
            $table->string('status', 12)->default('pendente');
            $table->dateTime('expira_em');
            $table->foreignId('item_id')->nullable()->constrained('secretario_itens')->nullOnDelete();
            $table->unsignedBigInteger('execucao_id')->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expira_em']);
        });

        Schema::create('secretario_preferencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('auto_confirmar')->default(false);
            $table->unsignedSmallInteger('antecedencia_padrao')->default(30);
            $table->boolean('telegram')->default(true);
            $table->timestamps();
        });

        // O Telegram reentrega o toque quando a resposta se perde: o único
        // aqui é o que impede adiar duas vezes.
        Schema::create('secretario_toques', function (Blueprint $table) {
            $table->id();
            $table->string('callback_id', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('created_at')->nullable();
        });

        // Recurso e perfil próprios, como a Central: o admin NÃO recebe de
        // fábrica (constitution I). O dono liga o perfil na própria conta.
        $permissaoId = DB::table('permissoes')->where('recurso', 'secretario')->value('id')
            ?? DB::table('permissoes')->insertGetId([
                'recurso' => 'secretario',
                'descricao' => 'Secretário (lembretes e tarefas pessoais)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $perfilId = DB::table('perfis')->where('slug', 'secretario')->value('id')
            ?? DB::table('perfis')->insertGetId([
                'nome' => 'Secretário',
                'slug' => 'secretario',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('perfil_permissao')->updateOrInsert(
            ['perfil_id' => $perfilId, 'permissao_id' => $permissaoId],
            ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => false, 'excluir' => true],
        );
    }

    public function down(): void
    {
        $permissaoId = DB::table('permissoes')->where('recurso', 'secretario')->value('id');
        $perfilId = DB::table('perfis')->where('slug', 'secretario')->value('id');
        if ($permissaoId) {
            DB::table('perfil_permissao')->where('permissao_id', $permissaoId)->delete();
            DB::table('permissoes')->where('id', $permissaoId)->delete();
        }
        if ($perfilId) {
            DB::table('perfil_user')->where('perfil_id', $perfilId)->delete();
            DB::table('perfis')->where('id', $perfilId)->delete();
        }

        Schema::dropIfExists('secretario_toques');
        Schema::dropIfExists('secretario_preferencias');
        Schema::dropIfExists('secretario_propostas');
        Schema::dropIfExists('secretario_avisos');
        Schema::dropIfExists('secretario_itens');
    }
};
