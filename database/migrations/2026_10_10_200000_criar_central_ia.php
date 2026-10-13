<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Central de IA mora no AlfaMatriz, no mesmo banco — decisão do dono em
     * 10/10/2026: um sistema só para projetos e para os agentes. A primeira
     * versão vivia numa aplicação separada (PostgreSQL próprio, tickets entre
     * as duas), e o vaivém Matriz → Central → Matriz foi o que derrubou a tela
     * com 503 quando o PHP ficou sem worker livre.
     *
     * Toda linha tem DONO (`user_id`), inclusive a mensagem, que já pertence a
     * uma conversa com dono: a consulta filtra pela coluna da própria tabela e
     * não depende de alguém lembrar do join. Não há exceção para administrador
     * — a conversa de cada um é só dele, como as notas (§18).
     */
    public function up(): void
    {
        Schema::create('central_ia_conversas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('titulo')->nullable();
            $table->timestamps();
        });

        Schema::create('central_ia_mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('central_ia_conversas')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Gerado no navegador antes do envio. Quem não recebeu resposta
            // (rede caiu, timeout) reenvia com o MESMO id, e o único por dono
            // devolve a mensagem que já entrou em vez de gravar outra.
            $table->uuid('client_id')->nullable();
            $table->string('autor_tipo', 16);
            $table->string('autor_nome')->nullable();
            $table->string('origem', 16);
            $table->text('conteudo');
            // O Telegram reentrega o mesmo update quando a confirmação se
            // perde; o único aqui é o que impede a mensagem dobrada.
            $table->unsignedBigInteger('telegram_update_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
            $table->index(['conversa_id', 'id']);
        });

        // Recurso e perfil próprios, e não uma linha no Administrador: as
        // permissões aqui são por perfil, e o Rossini também é admin. Quem usa
        // a Central recebe o perfil "Central de IA" na tela de usuários, conta
        // por conta. O admin não ganha de fábrica (ver PerfilPermissaoSeeder).
        $permissaoId = DB::table('permissoes')->where('recurso', 'central_ia')->value('id')
            ?? DB::table('permissoes')->insertGetId([
                'recurso' => 'central_ia',
                'descricao' => 'Central de IA (conversa com os agentes)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $perfilId = DB::table('perfis')->where('slug', 'central_ia')->value('id')
            ?? DB::table('perfis')->insertGetId([
                'nome' => 'Central de IA',
                'slug' => 'central_ia',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('perfil_permissao')->updateOrInsert(
            ['perfil_id' => $perfilId, 'permissao_id' => $permissaoId],
            ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => false, 'excluir' => false],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('central_ia_mensagens');
        Schema::dropIfExists('central_ia_conversas');

        $perfilId = DB::table('perfis')->where('slug', 'central_ia')->value('id');
        $permissaoId = DB::table('permissoes')->where('recurso', 'central_ia')->value('id');

        DB::table('perfil_permissao')->where('perfil_id', $perfilId)->orWhere('permissao_id', $permissaoId)->delete();
        DB::table('perfil_user')->where('perfil_id', $perfilId)->delete();
        DB::table('perfis')->where('id', $perfilId)->delete();
        DB::table('permissoes')->where('id', $permissaoId)->delete();
    }
};
