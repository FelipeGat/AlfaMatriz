<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            // Denormalizado de propósito: "atualizado há X" aparece em todas as
            // telas de integração e não pode custar uma subconsulta em cada uma.
            $table->timestamp('sincronizado_em')->nullable()->after('token');

            // Quantas tentativas seguidas falharam. É o que permite a tela
            // dizer "fora do ar desde X" em vez de só "não atualizou".
            $table->unsignedInteger('falhas_consecutivas')->default(0)->after('sincronizado_em');

            // Quando o cadastro que já existia no sistema foi trazido para cá.
            $table->timestamp('importado_em')->nullable()->after('falhas_consecutivas');

            // O CORTE: desde quando a matriz é dona do cadastro deste sistema.
            // Nulo = a matriz ainda apenas observa. É o marco que a feature de
            // escrita lê para saber se pode mandar alguma coisa para lá.
            $table->timestamp('cadastro_na_matriz_desde')->nullable()->after('importado_em');
        });
    }

    public function down(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            $table->dropColumn([
                'sincronizado_em',
                'falhas_consecutivas',
                'importado_em',
                'cadastro_na_matriz_desde',
            ]);
        });
    }
};
