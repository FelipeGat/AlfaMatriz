<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consumo das IAs e sessões abertas no VS Code, como o runner leu dos
     * registros locais de cada agente (só contagens e metadados, nunca o
     * texto das conversas).
     *
     * As sessões são o que permite continuar pelo Telegram uma conversa que
     * começou no VS Code: a tarefa nasce apontando para a sessão de origem.
     */
    public function up(): void
    {
        Schema::table('central_ia_runners', function (Blueprint $table) {
            $table->json('consumo')->nullable()->after('estado');
            $table->json('sessoes')->nullable()->after('consumo');
        });
    }

    public function down(): void
    {
        Schema::table('central_ia_runners', fn (Blueprint $t) => $t->dropColumn(['consumo', 'sessoes']));
    }
};
