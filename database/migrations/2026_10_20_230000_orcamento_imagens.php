<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * As imagens do corpo da proposta (Felipe, 20/10/2026: "o pdf está cheio de texto [...]
     * replique [a do Igor], com páginas bem definidas e com imagens"). Uma lista no próprio
     * orçamento — arquivo, legenda e a página onde entra —, e não uma tabela: a revisão e a
     * cópia levam as imagens junto, como já levam as seções, sem duplicar arquivo.
     */
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->json('imagens')->nullable()->after('capa_imagem');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropColumn('imagens');
        });
    }
};
