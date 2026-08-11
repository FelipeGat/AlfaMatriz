<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O quadro deixa de ser só do ciclo de desenvolvimento.
     *
     * O `tipo` não é rótulo de filtro: é ele que escolhe por onde a tarefa pode
     * andar. "Entrar em contato com o fabricante" não é desenvolvida nem
     * testada, e até aqui teria de fingir as duas coisas para poder ser
     * concluída — o `ASM-034` já admitia trabalho que não pertence a produto
     * nenhum, mas o fluxo não acompanhou.
     *
     * O que já existe nasce `desenvolvimento`: toda tarefa cadastrada até hoje
     * veio do ciclo de desenvolvimento, e mudar o fluxo delas por baixo seria
     * afrouxar o portão do teste em tarefa que passou por ele.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->string('tipo')->default('desenvolvimento')->after('detalhes');
        });

        // De que PASSAGEM por Em testes este relatório é.
        //
        // Sem o vínculo, o portão da conclusão lia o último relatório da tarefa
        // inteira: uma tarefa aprovada, concluída, reaberta e remexida
        // reconcluía apoiada no "aprovado" do ciclo anterior — o teste que
        // provava o código de antes valendo como prova do código de depois.
        //
        // O recorte é o evento de etapa, e não a data, porque data não separa o
        // que acontece no mesmo segundo — e reabrir, mexer e reconcluir em
        // segundos é justamente o que acontece quando alguém está corrigindo
        // algo pequeno.
        Schema::table('tarefa_relatorios_teste', function (Blueprint $table) {
            $table->foreignId('tarefa_evento_id')->nullable()->after('tarefa_id')
                ->constrained('tarefa_eventos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tarefa_relatorios_teste', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tarefa_evento_id');
        });

        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
