<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A data em que a tarefa precisa estar pronta — a base da Agenda.
     *
     * O quadro sempre soube ONDE a tarefa está e HÁ QUANTO TEMPO; nunca soube
     * PARA QUANDO. A coluna diz a etapa e o envelhecimento diz o atraso
     * relativo ("parada há 6 dias"), mas nenhum dos dois responde "o que vence
     * esta semana" — que é a pergunta que a Agenda existe para responder.
     *
     * Nulo é o normal, e não uma lacuna a preencher: a maior parte do quadro é
     * trabalho sem data combinada, e obrigar um prazo em toda tarefa
     * transformaria o campo em ruído inventado na hora do cadastro. Só entra na
     * Agenda quem tem prazo — tarefa sem data continua vivendo no quadro, que é
     * onde ela é olhada.
     *
     * `date` e não `datetime` de propósito: prazo é o DIA combinado. A hora
     * pertence ao compromisso (`compromissos`), que é quando as pessoas se
     * encontram; misturar as duas coisas na mesma coluna faria a Agenda ter de
     * escolher entre mostrar "quinta" e mostrar "quinta às 00:00", e a segunda
     * é uma precisão que ninguém digitou.
     *
     * Índice porque a Agenda consulta por FAIXA de data em toda visão — a
     * semana, o mês e os próximos 21 dias da Lista são três `whereBetween`
     * sobre esta coluna, e é a consulta mais repetida da tela.
     *
     * Sem backfill: não há de onde tirar um prazo honesto para o que já está no
     * quadro. Derivar de `iniciada_em` ou do envelhecimento seria inventar uma
     * combinação que ninguém fez, e a Agenda nasceria cheia de datas falsas —
     * pior que nascer vazia, porque a falsa ninguém confere.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->date('prazo')->nullable()->after('iniciada_em')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropIndex(['prazo']);
            $table->dropColumn('prazo');
        });
    }
};
