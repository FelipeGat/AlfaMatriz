<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * De onde veio a resposta do Pedro (T-476, docs/specs/suporte-pedro-whatsapp/contexto-do-suporte.md,
     * FR-C5): os arquivos da base "Contexto do Suporte" que ele usou. Fica na
     * própria mensagem enviada, que é o que a equipe lê na conversa.
     */
    public function up(): void
    {
        Schema::table('suporte_mensagens', function (Blueprint $table) {
            $table->json('fontes')->nullable()->after('transcricao');
        });
    }

    public function down(): void
    {
        Schema::table('suporte_mensagens', function (Blueprint $table) {
            $table->dropColumn('fontes');
        });
    }
};
