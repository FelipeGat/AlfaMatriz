<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O modelo do PDF (docs/specs/orcamentos-vixcard/modelo-vixhouse.md). Felipe, 25/10/2026:
     * "sempre usar esse mesmo modelo de proposta para TODOS os clientes que for sistema" — o da
     * proposta conceito do Gustavo (Inove), com a marca VixHouse. O desenho de até aqui continua
     * como "clássico": os orçamentos que já existem ficam nele, para o PDF de um rascunho ou de
     * um histórico importado não mudar de cara sem ninguém pedir.
     */
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->string('modelo', 20)->default('vixhouse')->after('tipo');
        });
        DB::table('orcamentos')->update(['modelo' => 'classico']);
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropColumn('modelo');
        });
    }
};
