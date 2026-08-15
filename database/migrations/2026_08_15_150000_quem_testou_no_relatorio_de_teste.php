<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem testou, no relatório de teste do staging.
     *
     * O relatório sempre soube dizer o veredito e de qual passagem ele é — mas
     * não quem testou. Enquanto validar era gesto de quem movia o card, o
     * evento do movimento respondia por tabela; agora que o teste pode ser
     * registrado por quem não move (o testador do time não é o responsável),
     * o "aprovado" que o admin lê antes de subir a tag precisa de assinatura
     * própria.
     *
     * ANULÁVEL de propósito, e sem backfill: o autor nunca foi gravado e não
     * há de onde recuperá-lo — relatório antigo aparece sem autor, que é a
     * verdade. `nullOnDelete` segue `tarefa_eventos.user_id`: conta excluída
     * vira relatório sem autor, como o movimento dela já vira movimento sem
     * autor.
     */
    public function up(): void
    {
        Schema::table('tarefa_relatorios_teste', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('tarefa_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tarefa_relatorios_teste', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
