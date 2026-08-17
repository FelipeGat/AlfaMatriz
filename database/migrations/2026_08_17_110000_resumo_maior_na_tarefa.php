<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O resumo da tarefa passa de 255 para 500 caracteres.
     *
     * 255 era o padrão do `string()`, não uma decisão: quem escreve o resumo
     * some com o contexto no meio da frase para caber, e o que sobra vira um
     * título repetido. Quinhentos dão duas ou três frases — o suficiente para
     * dizer o que precisa acontecer sem abrir a tarefa — e ainda mantêm o
     * campo como resumo, e não como um segundo `detalhes`.
     *
     * A coluna continua `varchar`: o card procura o resumo no `LIKE` da busca
     * (`TarefaController::filtrar`), e `TEXT` ali cobraria o preço sem que
     * ninguém tenha pedido resumo ilimitado.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->string('resumo', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        $this->cortarOsResumosQueNaoCabemMaisEm255();

        Schema::table('tarefas', function (Blueprint $table) {
            $table->string('resumo', 255)->nullable()->change();
        });
    }

    /**
     * Encolher a coluna com texto maior dentro dela é erro no MySQL em modo
     * estrito: sem este corte, a reversão morreria no meio em qualquer banco
     * que já tivesse recebido um resumo longo. Perder o excedente é
     * consequência conhecida de voltar atrás — por isso ele vai explícito
     * aqui, e não como truncamento silencioso do driver.
     *
     * O corte é em PHP, e não em `SUBSTRING`, porque a suíte roda em sqlite e
     * a produção em MySQL: `mb_substr` conta caracteres do mesmo jeito nos
     * dois, e um resumo com acento não pode perder letra a mais num banco que
     * no outro.
     */
    private function cortarOsResumosQueNaoCabemMaisEm255(): void
    {
        DB::table('tarefas')
            ->whereNotNull('resumo')
            ->orderBy('id')
            ->chunkById(200, function ($tarefas) {
                foreach ($tarefas as $tarefa) {
                    if (mb_strlen($tarefa->resumo) <= 255) {
                        continue;
                    }

                    DB::table('tarefas')
                        ->where('id', $tarefa->id)
                        ->update(['resumo' => mb_substr($tarefa->resumo, 0, 255)]);
                }
            });
    }
};
