<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O vínculo simétrico entre tarefas sai, dois dias depois de entrar.
     *
     * Ele nasceu para responder "com o que mais isto tem a ver", e a resposta
     * era boa: o número escrito no resumo só valia numa direção, e quem abria a
     * OUTRA tarefa não tinha como saber que fora citado.
     *
     * O que mudou foi a subtarefa. Ela respondeu a pergunta que o time de fato
     * fazia — "de que trabalho maior isto é pedaço" —, e com ela na mesa o
     * vínculo passou a ser a segunda lista de tarefas irmãs no mesmo modal,
     * parecida com a primeira e com o efeito OPOSTO: uma prende a mãe, a outra
     * não prende ninguém. Duas seções que se parecem e se comportam ao
     * contrário custam uma decisão a cada uso, e quem erra descobre tarde.
     *
     * Fica dito o que se perde, porque a decisão foi tomada sabendo:
     *
     * - apontar para uma tarefa JÁ ENCERRADA (subtarefa exige mãe no quadro);
     * - ligar duas em que nenhuma é mãe da outra, sem que uma trave a outra;
     * - pendurar a mesma tarefa em mais de um contexto (mãe é uma só).
     *
     * O dono do produto olhou as três em 20/08/2026 e disse que não são o dia a
     * dia dele. Se voltarem a ser, o `down` recria a tabela — mas não os pares:
     * `dropIfExists` leva o conteúdo junto, e não há de onde reconstruí-lo.
     */
    public function up(): void
    {
        Schema::dropIfExists('tarefa_vinculos');
    }

    public function down(): void
    {
        Schema::create('tarefa_vinculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarefa_id')->constrained('tarefas')->cascadeOnDelete();
            $table->foreignId('vinculada_id')->constrained('tarefas')->cascadeOnDelete();
            $table->foreignId('criado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tarefa_id', 'vinculada_id']);
            $table->index('vinculada_id');
        });
    }
};
