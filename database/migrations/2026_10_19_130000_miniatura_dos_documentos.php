<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Miniatura do documento (Felipe, 19/10/2026: "identificar o arquivo sem
     * precisar abrir, igual ocorre no Windows"). Nula = ainda não tentada;
     * `ok` = existe; `sem` = não deu, e não se tenta de novo até o arquivo mudar.
     */
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $table) {
            $table->string('miniatura', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $table) {
            $table->dropColumn('miniatura');
        });
    }
};
