<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O relato da tarefa do tipo Defeito: quem foi afetado, quando, o que se
     * esperava e o que aconteceu (tarefa #204).
     *
     * As #185 e #191 chegaram só com o sintoma — sem aluno, sem data, uma sem
     * academia —, e defeito que afeta uma pessoa só não se investiga sem o
     * caso concreto: é o log daquele minuto, o registro daquele aluno. O print
     * não ganhou coluna porque já tem casa: é anexo da tarefa, que a criação
     * recebe junto desde o AC-234.
     *
     * Colunas, e não um texto montado dentro do resumo: o resumo tem 500
     * caracteres e é o que o card mostra, e "quem" e "quando" são exigidos na
     * abertura — exigência sobre um pedaço de texto livre não se confere.
     *
     * `defeito_quando` é `datetime` e não `date`, ao contrário do prazo: o que
     * se procura no log é a HORA, e "dia 30" num servidor que grava milhares
     * de linhas por dia ainda é palheiro.
     *
     * Sem backfill: nenhuma tarefa existente é do tipo Defeito, e as que
     * deveriam ter sido não têm de onde tirar quem e quando honestos.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->string('defeito_quem')->nullable()->after('detalhes');
            $table->dateTime('defeito_quando')->nullable()->after('defeito_quem');
            $table->text('defeito_esperado')->nullable()->after('defeito_quando');
            $table->text('defeito_ocorrido')->nullable()->after('defeito_esperado');
        });
    }

    public function down(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropColumn(['defeito_quem', 'defeito_quando', 'defeito_esperado', 'defeito_ocorrido']);
        });
    }
};
