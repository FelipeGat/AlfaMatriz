<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tarefa que é o mesmo pedido de outra (#205).
     *
     * A #185 foi aberta no sistema errado e, quatro dias depois, o mesmo defeito
     * entrou de novo como #191, com outra prioridade. A saída que havia era
     * cancelar com "duplicada" escrito no motivo — e o número da original ficava
     * numa frase que só a cancelada guardava: quem abria a #185 não tinha como
     * saber que alguém já a pedira de novo.
     *
     * Uma coluna, e não a volta do `tarefa_vinculos` que saiu em 20/08/2026:
     * aquele era simétrico e sem dono, e foi justamente isso que o fez virar a
     * segunda lista de irmãs ao lado da subtarefa. Duplicada tem direção — uma
     * é a original, a outra sai do quadro — e cada tarefa é duplicada de UMA
     * só. O lado da original é a relação inversa (`Tarefa::duplicadas`), sem
     * tabela própria.
     *
     * `nullOnDelete` pelo mesmo motivo da `tarefa_pai_id`: excluir a original
     * não pode levar junto o registro de que a outra foi cancelada.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->foreignId('duplicada_de_id')->nullable()->after('tarefa_pai_id')
                ->constrained('tarefas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicada_de_id');
        });
    }
};
