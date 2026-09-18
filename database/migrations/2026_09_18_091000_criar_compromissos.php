<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compromisso: a hora marcada com outras pessoas.
     *
     * É a segunda fonte da Agenda, e ela existe separada do prazo da tarefa
     * porque as duas respondem perguntas diferentes: prazo é QUANDO precisa
     * estar pronto, compromisso é QUANDO as pessoas se encontram. Guardar reunião
     * como "tarefa com hora" faria o quadro ganhar cards que ninguém desenvolve
     * — sem etapa, sem responsável único, sem envelhecimento que signifique algo.
     *
     * `duracao_modo` e `duracao_horas` são PERSISTIDOS, e não derivados de
     * `hora_fim - hora`. A diferença aparece na hora de editar: quem marcou
     * "1,5 hora" e depois adia o início de 10h para 11h espera que o término vá
     * junto, para 12:30; quem marcou "termina às 11:30" espera que o término
     * fique onde está. A subtração devolve 1,5 nos dois casos e perde qual dos
     * dois campos é a fonte da verdade — então o modo é dado de entrada, não
     * conclusão. Reabrir o compromisso devolve o modo em que ele foi criado.
     *
     * `decimal(4,2)` nas horas porque o campo aceita fração (`step=0.25`,
     * mínimo 0.25): meia hora e 45 minutos são as durações mais comuns do time,
     * e um inteiro obrigaria a arredondar reunião de 30 minutos para 0 ou 1.
     *
     * `data_fim` existe junto com `hora_fim` por causa da virada de meia-noite:
     * sem ela, um compromisso das 23h com duas horas de duração terminaria "à
     * 01:00" do mesmo dia — antes do próprio começo. Com a data separada, a
     * tela tem como dizer "(dia seguinte)" em vez de mentir.
     *
     * `tarefa_id` é o vínculo dos dois caminhos de conversão — Reservar tempo
     * (da tarefa para o compromisso) e Virar tarefa (do compromisso para a
     * tarefa). `nullOnDelete` porque a reunião aconteceu de verdade: apagar a
     * tarefa não desmarca o que já foi ao calendário de quatro pessoas.
     *
     * `criado_por_id` não é o dono do compromisso, é quem o marcou — quem
     * participa está em `compromisso_participantes`. A distinção é o que faz a
     * regra de perfil funcionar: membro edita o que CRIOU, e não o que apenas
     * frequenta, senão qualquer participante poderia remarcar a reunião dos
     * outros. `cascadeOnDelete` aqui, ao contrário da tarefa: conta excluída
     * leva junto o que ela marcou, porque sem autor não há quem edite.
     *
     * Os três índices são as três consultas da tela, e nenhuma a mais:
     * `(data)` é a faixa que Semana, Mês e Lista pedem; `(tarefa_id)` são as
     * reuniões vinculadas no detalhe da tarefa; e `(user_id, data)` na tabela de
     * participantes é a carga por pessoa do drawer do dia e o filtro de chips.
     */
    public function up(): void
    {
        Schema::create('compromissos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->text('descricao')->nullable();

            $table->date('data');
            $table->time('hora');
            $table->date('data_fim');
            $table->time('hora_fim');

            // Verdadeiro = o término é calculado a partir das horas; falso = o
            // término foi digitado à mão. Ver o docblock acima.
            $table->boolean('duracao_modo')->default(true);
            $table->decimal('duracao_horas', 4, 2)->nullable();

            $table->foreignId('criado_por_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tarefa_id')->nullable()->constrained('tarefas')->nullOnDelete();

            $table->timestamps();

            $table->index('data');
            $table->index('tarefa_id');
        });

        Schema::create('compromisso_participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compromisso_id')->constrained('compromissos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // A data repetida aqui é de propósito: a carga por pessoa e o
            // filtro de chips perguntam "quem tem o quê no dia X", e sem ela
            // toda resposta passaria por um join com `compromissos` só para
            // ler uma coluna que não muda. Quem escreve mantém as duas em dia
            // — é o modelo (`Compromisso::sincronizarParticipantes`) que faz
            // isso, e não cada chamador.
            $table->date('data');

            $table->unique(['compromisso_id', 'user_id']);
            $table->index(['user_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compromisso_participantes');
        Schema::dropIfExists('compromissos');
    }
};
