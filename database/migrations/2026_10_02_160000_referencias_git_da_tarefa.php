<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os commits e PRs que o GitHub ligou à tarefa (#211).
     *
     * O dev trabalha por PR e o que ele fez não chegava ao quadro: hashes e
     * links acabavam colados à mão no resumo ou num comentário. O webhook do
     * GitHub agora traz cada commit e PR que cita `T-N` — e eles moram AQUI, e
     * não na conversa: vinte commits num dia virariam vinte comentários
     * empurrando a pergunta de alguém para fora da tela, e comentário não serve
     * para pré-preencher a entrega (#210).
     *
     * `chave` é o que torna a chegada idempotente — o GitHub reentrega, o
     * mesmo commit volta no merge e no rebase, e nada disso pode duplicar:
     * - commit: o sha inteiro. Sem o repositório, de propósito: o mesmo commit
     *   chega pelo fork do dev e depois pelo repositório oficial, e é UM só.
     * - PR: `dono/repo#N`. O número só é único dentro do repositório.
     *
     * Sem chave estrangeira para usuário: quem fez é um login do GitHub, que
     * não tem de ser alguém do sistema.
     */
    public function up(): void
    {
        Schema::create('tarefa_referencias_git', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarefa_id')->constrained('tarefas')->cascadeOnDelete();
            $table->string('tipo', 10);
            $table->string('repositorio', 150);
            $table->string('chave', 191);
            $table->unsignedInteger('numero')->nullable();
            $table->string('titulo', 255);
            $table->string('url', 500);
            $table->string('autor_github', 100)->nullable();
            $table->string('estado', 20)->nullable();
            $table->string('branch', 255)->nullable();
            $table->timestamps();

            $table->unique(['tarefa_id', 'tipo', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarefa_referencias_git');
    }
};
