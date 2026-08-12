<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pergunta da revisão passa a ter estado próprio na tarefa.
     *
     * Dúvida durante a revisão não é bloqueio nem correção: o PR continua
     * aberto, a tarefa continua no WIP e responder é questão de minutos. Tratar
     * isso como impedimento diluiria o sinal — uma dúvida de vinte minutos e um
     * bloqueio de seis dias apareceriam com a mesma tarja.
     *
     * O ponteiro rastreia DE QUEM É A VEZ, não perguntas: cinco dúvidas viram
     * um comentário e uma rodada, e perguntar de novo sem ter recebido resposta
     * é a MESMA rodada. Só conta rodada nova quem estava com a bola.
     *
     * `rodadas` e `interlocutor_id` vivem FORA do ponteiro de propósito.
     * Responder apaga `pergunta_de_id` / `pergunta_para_id` / `pergunta_em`; se
     * a contagem morasse ali, toda rodada nova recomeçaria do 1 e o alerta de
     * terceira rodada — o que diz que o PR está grande demais ou a tarefa mal
     * especificada — nunca dispararia. Pelo mesmo motivo o interlocutor é
     * persistido: sem ele, responder faz o sistema esquecer com quem estava
     * falando.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->unsignedInteger('rodadas')->default(0)->after('retorno_motivo');

            // Sobrevive à saída da pessoa: a conversa é da tarefa, e perder o
            // interlocutor não pode levar a tarefa junto.
            $table->foreignId('interlocutor_id')->nullable()->after('rodadas')
                ->constrained('users')->nullOnDelete();

            $table->foreignId('pergunta_de_id')->nullable()->after('interlocutor_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('pergunta_para_id')->nullable()->after('pergunta_de_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('pergunta_em')->nullable()->after('pergunta_para_id');
        });

        Schema::table('tarefa_comentarios', function (Blueprint $table) {
            // Qual comentário abriu a rodada. Sem a marca, a linha do tempo
            // mostra a pergunta como comentário comum e a resposta perde a que
            // ela responde.
            $table->boolean('pergunta')->default(false)->after('corpo');
        });
    }

    public function down(): void
    {
        Schema::table('tarefa_comentarios', function (Blueprint $table) {
            $table->dropColumn('pergunta');
        });

        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pergunta_para_id');
            $table->dropConstrainedForeignId('pergunta_de_id');
            $table->dropConstrainedForeignId('interlocutor_id');
            $table->dropColumn(['rodadas', 'pergunta_em']);
        });
    }
};
