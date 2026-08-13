<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O rastro de quem fez o quê — a única tabela do sistema que só cresce.
     *
     * Linha gravada no instante do fato, e não estado derivado, pelo mesmo
     * motivo das notificações: o registro atual responde "como está", nunca
     * "como estava ontem, e quem mudou". Quando alguém pergunta por que o valor
     * mensal deste cliente caiu pela metade, não há consulta ao cadastro que
     * responda — o valor antigo não existe mais em lugar nenhum.
     *
     * NÃO reaproveita `tarefa_eventos`. Aquilo é o fluxo de uma tarefa entre
     * etapas, com duração por etapa e reabertura de evento anterior; isto é o
     * antes/depois de um campo qualquer, sem noção de etapa. Forçar os dois na
     * mesma tabela deixaria metade das colunas nula em cada uso.
     */
    public function up(): void
    {
        Schema::create('auditorias', function (Blueprint $table) {
            $table->id();

            // Quem fez. Nulo de propósito em dois casos que importam: a senha
            // recusada (ainda não há usuário) e a rotina que roda sem ninguém
            // logado. Solta o vínculo em vez de apagar a linha — auditoria que
            // some junto com o auditado não serve para nada.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // O nome do ator CONGELADO no momento do fato, e não lido da conta
            // na hora de exibir. A conta é renomeada, é excluída, e o e-mail de
            // uma tentativa recusada nunca virou conta nenhuma: em todos esses
            // casos a linha continua dizendo quem era. É também o único lugar
            // onde a tentativa de login com e-mail inexistente tem identidade.
            $table->string('usuario_nome');

            // O MESMO vocabulário de `permissoes.recurso` (`clientes`,
            // `cobrancas`, `usuarios`…), e não uma lista paralela: é o que
            // permite a tela de auditoria filtrar pelo mesmo nome que o menu e
            // a grade de permissões usam. Dois vocabulários fariam o filtro
            // "Receitas" achar menos coisa do que a permissão de receitas
            // alcança.
            //
            // Com UM valor a mais, `acesso`, que não é recurso de permissão
            // nenhum: entrar no painel não é algo que se conceda por recurso.
            // Ele fica separado de `usuarios` de propósito — login é o evento
            // mais frequente do sistema, e jogado junto empurraria para fora da
            // primeira página toda mudança de conta que aconteceu no meio.
            $table->string('recurso');

            // Verbo no passado (`criou`, `alterou`, `excluiu`, `entrou`,
            // `recusado`…). Texto, e não enum: enum exigiria uma migração a
            // cada ação nova, e este é o ponto do sistema que mais ganha
            // eventos com o tempo.
            $table->string('acao');

            // O alvo, quando existe um registro. Polimórfico porque a mesma
            // pergunta ("o que aconteceu com ISTO?") é feita de cliente, de
            // receita, de sistema e de conta — uma coluna por tabela seria uma
            // coluna nula a mais a cada tela nova.
            //
            // Sem chave estrangeira, e é a decisão central desta tabela: o
            // registro auditado PODE ser excluído, e a linha que conta a
            // exclusão não pode ir junto. É exatamente o momento em que a
            // auditoria mais importa.
            $table->nullableMorphs('auditavel');

            // Como o alvo se chamava no momento do fato. Sem isto, a linha de
            // uma exclusão não teria como se apresentar: o registro não existe
            // mais para ser consultado, e sobraria "Cliente #4712" na tela.
            $table->string('descricao')->nullable();

            // O antes/depois, campo a campo: `{"valor_mensal": {"de": "800.00",
            // "para": "400.00"}}`. Nulo quando o fato não mexe em dado — entrar
            // no painel e baixar um anexo não têm antes nem depois.
            $table->json('alteracoes')->nullable();

            // De onde veio. 45 caracteres porque IPv6 mapeado em IPv4 chega a
            // 45 — o `string` curto padrão truncaria endereço de verdade.
            $table->string('ip', 45)->nullable();
            $table->string('agente')->nullable();

            // Só `created_at`. A linha nunca é alterada — `updated_at` seria um
            // campo que só sabe repetir o outro, e um convite a alterá-la.
            $table->timestamp('created_at')->useCurrent();

            // A tela abre no mais recente e filtra por período: sem este
            // índice, a primeira página custa uma varredura da tabela que mais
            // cresce no sistema.
            $table->index('created_at');

            // Os dois recortes que a tela oferece. Compostos com `created_at`
            // porque a ordenação é sempre a mesma — o índice só de `recurso`
            // resolveria o filtro e deixaria a ordenação para o banco fazer em
            // memória.
            $table->index(['recurso', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditorias');
    }
};
