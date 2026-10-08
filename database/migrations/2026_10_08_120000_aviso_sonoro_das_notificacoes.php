<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O som do sino (#312).
 *
 * `notificacoes.sonora` marca o aviso que DEPENDE da pessoa — ganhou a tarefa,
 * tem de validar, recebeu pergunta, voltou para corrigir. É gravado no evento,
 * e não decidido no navegador pelo tipo, porque o mesmo tipo pode valer som
 * para um e não para outro: o comentário toca para o responsável e o validador,
 * e chega mudo para quem só abriu a tarefa.
 *
 * Sem backfill: o que já está no sino chegou antes do som existir, e o som só
 * toca para o que chega com a página aberta.
 *
 * `users.aviso_sonoro` é a preferência da conta, e não do navegador: quem
 * desliga no notebook não quer o som voltando no computador do escritório.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificacoes', function (Blueprint $table) {
            $table->boolean('sonora')->default(false)->after('nivel');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('aviso_sonoro')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('notificacoes', function (Blueprint $table) {
            $table->dropColumn('sonora');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('aviso_sonoro');
        });
    }
};
