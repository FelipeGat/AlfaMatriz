<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarefa vinculada a tarefa — e vínculo simétrico, não hierarquia.
     *
     * A migração do checklist já tinha dito metade disto: "trabalho que precisa
     * de dono próprio vira tarefa irmã, não item". Faltava a irmandade ter onde
     * morar. Sem ela, o que amarrava duas tarefas era o número escrito no
     * resumo — que só vale numa direção, porque quem abre a OUTRA tarefa não
     * tem como saber que foi citada.
     *
     * Simétrico, e não pai/filho, porque a pergunta que se faz ao vínculo é
     * "com o que mais isto tem a ver" — e hierarquia obrigaria a responder
     * quatro perguntas que ninguém fez: se a mãe conclui com filha aberta, se a
     * filha conta no WIP, de quem é o responsável e o que acontece quando a
     * mãe é cancelada. As mesmas quatro que tiraram a subtarefa do escopo.
     *
     * DUAS linhas por vínculo, uma em cada direção, e não uma linha canônica
     * com o menor id primeiro. A linha única economizaria metade do espaço e
     * cobraria em todo o resto: `belongsToMany` não sabe ler par canônico, e
     * cada leitura viraria união de duas consultas — inclusive a do quadro, que
     * carrega o vínculo de sessenta cards de uma vez. Quem mantém o par são
     * `vincularCom` e `desvincularDe`, no modelo: fora deles ninguém escreve
     * aqui.
     */
    public function up(): void
    {
        Schema::create('tarefa_vinculos', function (Blueprint $table) {
            $table->id();

            // As duas pontas caem junto com a tarefa. `cascadeOnDelete` só
            // dispara na exclusão de verdade, e a do quadro é reversível
            // (`SoftDeletes`) — mas o vínculo some da tela mesmo assim, porque
            // a relação lê `tarefas` e o escopo de exclusão reversível já
            // esconde a linha apagada. Uma tarefa restaurada volta com os
            // vínculos que tinha, que é o que restaurar deveria significar.
            $table->foreignId('tarefa_id')->constrained('tarefas')->cascadeOnDelete();
            $table->foreignId('vinculada_id')->constrained('tarefas')->cascadeOnDelete();

            // Quem ligou as duas. `nullOnDelete` pelo mesmo motivo do resto do
            // painel: pessoa que sai da empresa não pode levar o vínculo junto.
            $table->foreignId('criado_por_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // O par é único nos dois sentidos porque as duas linhas existem:
            // vincular a mesma dupla de novo não pode virar um segundo par, ou
            // o card contaria dois vínculos onde há um.
            $table->unique(['tarefa_id', 'vinculada_id']);

            // A ponta de trás também é lida: desvincular apaga as duas linhas,
            // e sem este índice a segunda vira varredura da tabela inteira.
            $table->index('vinculada_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarefa_vinculos');
    }
};
