<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Até quando o aviso "O que espera você" fica quieto para esta pessoa (#300).
     *
     * Uma coluna só responde aos dois botões do aviso: "Ok, vi" grava o fim do
     * dia e "Lembrar mais tarde" grava agora + 2 horas. Nulo = nunca silenciou,
     * e o aviso aparece na primeira tela que ela abrir com algo pendente.
     *
     * Na conta, e não na sessão nem no navegador: o "já vi hoje" precisa valer
     * no celular e no computador. Guardado na sessão, cada aparelho — e cada
     * login — mostraria o aviso de novo.
     *
     * O que o aviso MOSTRA não é guardado em lugar nenhum: é condição,
     * recalculada a cada abertura (`App\Services\OQueEsperaVoce`). Só a
     * resposta da pessoa ao aviso precisa de memória.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('espera_silenciada_ate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('espera_silenciada_ate');
        });
    }
};
