<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quando o comentário foi corrigido pelo autor.
     *
     * Coluna própria, e não o `updated_at`: o carimbo de edição é DITO na tela,
     * então precisa querer dizer exatamente uma coisa — "o texto mudou depois
     * de publicado". `updated_at` se move por qualquer gravação futura na
     * linha, e aí a tarefa passaria a acusar edição onde não houve nenhuma.
     */
    public function up(): void
    {
        Schema::table('tarefa_comentarios', function (Blueprint $table) {
            $table->timestamp('editado_em')->nullable()->after('corpo');
        });
    }

    public function down(): void
    {
        Schema::table('tarefa_comentarios', function (Blueprint $table) {
            $table->dropColumn('editado_em');
        });
    }
};
