<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem fez o movimento que o evento registra.
     *
     * O evento sempre soube dizer o quê, quando e por quanto tempo — mas não
     * quem. A linha do tempo do modal de histórico é a primeira tela a exibir
     * os eventos, e "quem moveu" é a primeira pergunta de quem audita.
     *
     * ANULÁVEL de propósito, e sem backfill: o autor nunca foi gravado, e não
     * há de onde recuperá-lo — evento antigo aparece sem autor, o que é a
     * verdade. `nullOnDelete` segue o padrão de `tarefas.responsavel_id`, e
     * não o de `auditorias.usuario_nome` (que congela o nome): o rastro legal
     * do sistema já é a auditoria; aqui, conta excluída vira movimento sem
     * autor, como a tarefa dela já vira tarefa sem responsável.
     */
    public function up(): void
    {
        Schema::table('tarefa_eventos', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('tarefa_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tarefa_eventos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
