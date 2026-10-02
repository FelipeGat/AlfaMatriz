<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arquivar tarefa (#208): tirar do quadro o que não vai andar AGORA, sem
     * encerrar.
     *
     * O quadro tinha três saídas para isso, e nenhuma servia: bloquear deixa
     * o card na coluna por meses, cancelar encerra uma decisão que não foi
     * tomada, e excluir perde a conversa. O que faltava era "agora não" e
     * "não sabemos" — a ideia boa para depois, o pedido sem retorno de quem
     * abriu, o defeito que talvez já esteja resolvido.
     *
     * Marca, e não etapa, pela mesma razão do bloqueio (decisão do dono do
     * produto em 02/10/2026): como etapa, arquivar APAGARIA onde a tarefa
     * estava, e desarquivar teria de perguntar para onde ela volta. Como
     * marca, a tarefa guarda a coluna e o responsável, e volta exatamente
     * para lá.
     *
     * Sem backfill: nenhuma tarefa nasce arquivada.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            // Índice porque o quadro inteiro passa a filtrar por ela.
            $table->timestamp('arquivada_em')->nullable()->index()->after('bloqueio_motivo');
            $table->foreignId('arquivada_por_id')->nullable()->after('arquivada_em')
                ->constrained('users')->nullOnDelete();
            $table->string('arquivamento_motivo', 20)->nullable()->after('arquivada_por_id');
            $table->text('arquivamento_nota')->nullable()->after('arquivamento_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('arquivada_por_id');
            $table->dropIndex(['arquivada_em']);
            $table->dropColumn(['arquivada_em', 'arquivamento_motivo', 'arquivamento_nota']);
        });
    }
};
