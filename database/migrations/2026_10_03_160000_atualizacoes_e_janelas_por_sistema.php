<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * As abas Atualizações e Programadas da tela de Manutenção (#225).
     *
     * - `atualizacoes`: o changelog que vai ao Telegram, guardado aqui também.
     *   Até hoje ele só existia no grupo e num `.txt` do repositório — quem
     *   quisesse saber o que a versão de semana passada mudou rolava o chat.
     *   O texto fica no MESMO HTML do Telegram, sem conversão: é o que foi
     *   publicado, e uma segunda redação divergiria na primeira correção.
     *   `chave` é o hash do texto por sistema: publicar de novo o mesmo arquivo
     *   (uma parte que falhou, a importação dos antigos rodada duas vezes) não
     *   duplica.
     * - `atualizacao_tarefa`: as tarefas que entraram na versão, como o script
     *   as achou nos commits (`T-N`). Tabela, e não texto, para a tarefa
     *   apontar de volta para a versão em que saiu.
     * - `compromissos.sistema_id`: a janela de manutenção é DE um sistema, e a
     *   aba Programadas lista por sistema. Opcional: reunião interna não é de
     *   sistema nenhum.
     */
    public function up(): void
    {
        Schema::create('atualizacoes', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $tabela->date('data');
            $tabela->string('versao', 60)->nullable();
            $tabela->string('titulo', 255)->nullable();
            $tabela->longText('texto');
            $tabela->char('chave', 64);
            // De onde veio: `script` (publicar-changelog.sh na hora do envio)
            // ou `importado` (os `.txt` de antes desta tela).
            $tabela->string('origem', 20)->default('script');
            $tabela->string('arquivo', 255)->nullable();
            $tabela->foreignId('registrado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $tabela->timestamps();

            $tabela->unique(['sistema_id', 'chave']);
            $tabela->index(['sistema_id', 'data']);
        });

        Schema::create('atualizacao_tarefa', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->foreignId('atualizacao_id')->constrained('atualizacoes')->cascadeOnDelete();
            $tabela->foreignId('tarefa_id')->constrained('tarefas')->cascadeOnDelete();

            $tabela->unique(['atualizacao_id', 'tarefa_id']);
        });

        if (! Schema::hasColumn('compromissos', 'sistema_id')) {
            Schema::table('compromissos', function (Blueprint $tabela) {
                $tabela->foreignId('sistema_id')->nullable()->after('tarefa_id')->constrained('sistemas')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('compromissos', 'sistema_id')) {
            Schema::table('compromissos', function (Blueprint $tabela) {
                $tabela->dropConstrainedForeignId('sistema_id');
            });
        }

        Schema::dropIfExists('atualizacao_tarefa');
        Schema::dropIfExists('atualizacoes');
    }
};
