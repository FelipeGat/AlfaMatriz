<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * O tipo "Defeito" passa a se chamar "Bug" — o nome e a CHAVE (tarefa #204,
     * ajuste pedido pelo dono do produto em 01/10/2026, no mesmo dia em que o
     * tipo subiu): é a palavra que o time já fala, e uma chave que diz
     * `defeito` enquanto a tela diz "Bug" seria a tradução que cada pessoa nova
     * teria de aprender.
     *
     * Só a coluna `tipo` muda. As colunas do relato continuam `defeito_*` de
     * propósito: renomear coluna com a produção em azul/verde quebra a cor que
     * ainda está no ar durante a troca, e o nome da coluna ninguém vê.
     *
     * Converter em vez de aceitar as duas chaves: com `defeito` e `bug`
     * convivendo, cada comparação do código precisaria lembrar das duas, e a
     * que esquecesse deixaria um bug antigo pular os portões.
     *
     * A janela de troca: a migração roda antes de a cor nova entrar, e por
     * alguns minutos a cor antiga vê tarefas `bug` sem conhecer a chave — sem
     * selo no card e sem a trava do staging no encerramento. O mapa de
     * transições não muda (tipo desconhecido cai no do desenvolvimento). É
     * janela de minutos sobre um tipo que tem um dia de vida; aceitável, mas
     * convém não encerrar bug no meio da publicação.
     */
    public function up(): void
    {
        DB::table('tarefas')->where('tipo', 'defeito')->update(['tipo' => 'bug']);
    }

    public function down(): void
    {
        DB::table('tarefas')->where('tipo', 'bug')->update(['tipo' => 'defeito']);
    }
};
