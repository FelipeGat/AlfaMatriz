<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarefa dentro de tarefa — agora com hierarquia, e não só irmandade.
     *
     * O vínculo simétrico (`tarefa_vinculos`) respondia "com o que mais isto
     * tem a ver". A pergunta que chegou é outra: revisar uma atualização e
     * achar oito bugs produz oito trabalhos que SÓ existem por causa daquela
     * revisão — cada um com dono, etapa e print próprios. Irmandade achata isso
     * num monte de setas entre iguais, e checklist não serve porque item não
     * tem dono nem etapa ("o que precisa de dono próprio vira tarefa").
     *
     * A migração do vínculo listou as quatro perguntas que a hierarquia obriga
     * a responder, e adiou a subtarefa por não terem sido feitas. Foram feitas
     * em 20/08/2026, e as respostas são estas:
     *
     * 1. **A mãe conclui com filha aberta?** Não. A mãe é guarda-chuva: não sai
     *    do quadro — nem concluindo, nem cancelando — enquanto houver filha
     *    aberta. Cancelar uma filha a tira da conta, que é a saída para o bug
     *    que não vai ser corrigido.
     * 2. **A filha conta no WIP?** Conta. É trabalho de verdade, e uma revisão
     *    que gera oito bugs gerou oito trabalhos — se isso estoura o limite, é
     *    o quadro dizendo a verdade sobre o que o time acabou de receber.
     * 3. **De quem é o responsável?** Dela, e decidido na criação: a subtarefa
     *    nasce pelo formulário inteiro, com a mãe amarrada num campo escondido,
     *    e por isso já sai com resumo, prioridade, responsável e anexos
     *    próprios. Da mãe ela herda só o palpite de sistema e tipo, que os dois
     *    selects trazem escolhidos e trocáveis. Quem não faz triagem continua
     *    caindo na fila (`semTriagemDeQuemNaoTriaga`), como em toda criação.
     * 4. **O que acontece quando a mãe é cancelada?** Nada, porque não pode:
     *    é a mesma recusa da conclusão. A decisão sobre cada filha é tomada uma
     *    a uma, e não em massa por um clique na mãe.
     *
     * Um nível só. `tarefa_pai_id` aceitaria a corrente inteira, e é o motor
     * que recusa a neta (`Tarefa::podeReceberSubtarefa`): profundidade livre
     * multiplicaria as quatro perguntas acima por quantos níveis alguém criar,
     * e nenhuma delas foi respondida para "avó".
     *
     * `nullOnDelete` e não `cascadeOnDelete`, ao contrário das pontas do
     * vínculo: apagar a mãe não pode levar junto oito bugs que existem por
     * conta própria. Na prática a exclusão do quadro é reversível e também
     * recusada com filha aberta — isto aqui é a rede do `forceDelete`.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->foreignId('tarefa_pai_id')->nullable()->after('sistema_id')
                ->constrained('tarefas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tarefa_pai_id');
        });
    }
};
