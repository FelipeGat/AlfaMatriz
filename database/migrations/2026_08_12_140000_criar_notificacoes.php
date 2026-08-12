<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os eventos que alguém precisa saber sem estar olhando para o quadro.
     *
     * Linha gravada, e não estado derivado. A fila de ação do Centro de
     * Controle recalcula tudo a cada carregamento — "3 receitas em atraso" é
     * uma pergunta feita ao banco agora —, e isso funciona para CONDIÇÕES, que
     * são verdadeiras enquanto durarem. Não funciona para EVENTOS: "Camila
     * perguntou" aconteceu num instante e não é mais consultável depois que a
     * pergunta é respondida. Sem a linha, não há o que marcar como lida, e o
     * badge não teria o que contar.
     *
     * É também o que permite a entrega por e-mail e Slack sair do mesmo evento:
     * o registro é o ponto único de onde os canais leem.
     */
    public function up(): void
    {
        Schema::create('notificacoes', function (Blueprint $table) {
            $table->id();

            // A notificação é DE alguém. Sem a pessoa não há a quem notificar,
            // e guardar o aviso órfão só encheria a tabela de linhas que nunca
            // mais serão lidas por ninguém.
            $table->foreignId('destinatario_id')->constrained('users')->cascadeOnDelete();

            $table->string('tipo');

            // O mesmo vocabulário de gravidade da fila de ação do Centro de
            // Controle (`critico`, `atencao`, `marca`), de propósito: as duas
            // listas falam das mesmas coisas, e dois vocabulários fariam o
            // mesmo aviso mudar de cor conforme a tela em que aparece.
            $table->string('nivel')->default('marca');
            $table->string('icone')->default('bell');

            $table->string('titulo');
            $table->string('meta')->nullable();

            // Para onde o item leva. Guardado como rota resolvida porque o
            // destino é do momento do evento: a tarefa pode mudar de etapa
            // depois, e o aviso continua falando do que aconteceu.
            $table->string('rota')->nullable();

            // A origem, quando é uma tarefa. Apaga junto: aviso sobre tarefa
            // excluída é link para lugar nenhum.
            $table->foreignId('tarefa_id')->nullable()->constrained('tarefas')->cascadeOnDelete();

            $table->timestamp('lida_em')->nullable();
            $table->timestamps();

            // A leitura é sempre "as não lidas desta pessoa, mais recentes
            // primeiro" — é o que o sino pergunta a cada carregamento de tela.
            $table->index(['destinatario_id', 'lida_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacoes');
    }
};
