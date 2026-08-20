<?php

namespace Database\Seeders;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\TarefaEvento;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Um quadro de tarefas ENVELHECIDO, para o teste em staging valer alguma coisa.
 *
 * Com seed novo nada está velho: toda tarefa nasce com o relógio zerado, nenhum
 * card acende, nenhuma coluna estoura o WIP — e envelhecimento e WIP são
 * justamente o que o redesign mais mexeu. Um quadro recém-semeado passa a
 * impressão de que está tudo certo porque não há nada acontecendo, e não porque
 * a tela esteja funcionando.
 *
 * A idade mora no `entrou_em` de `tarefa_eventos`, e não no `created_at` da
 * tarefa: o chip do card, as réguas de envelhecimento e o tempo por etapa leem
 * o evento aberto. Recuar só o `created_at` deixaria uma tarefa "antiga" com
 * zero minuto na etapa — velha na lista e nova no card.
 *
 * FORA do `DatabaseSeeder` de propósito: isto é massa de demonstração, e o
 * deploy roda `migrate --force` sem semear. Rode à mão onde ela ajuda:
 *
 *     php artisan db:seed --class=QuadroDeTarefasSeeder
 */
class QuadroDeTarefasSeeder extends Seeder
{
    public function run(): void
    {
        $pessoas = $this->pessoas();
        $sistemas = Sistema::orderBy('id')->pluck('id')->all();

        foreach ($this->tarefas() as $receita) {
            $this->semear($receita, $pessoas, $sistemas);
        }
    }

    /**
     * O elenco. Reaproveita quem já existe e completa o que faltar — semear
     * duas vezes não pode criar dois "Rafael Lima".
     *
     * @return array<string, User>
     */
    private function pessoas(): array
    {
        $nomes = ['Rafael Lima', 'Camila Reis', 'Marina Alves', 'Diego Prado'];
        $pessoas = [];

        foreach ($nomes as $nome) {
            $email = str($nome)->lower()->replace(' ', '.')->append('@alfamatriz.local')->value();

            $pessoas[$nome] = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $nome,
                    'password' => bcrypt(str()->random(32)),
                    'primeiro_acesso' => false,
                    'ativo' => true,
                    'email_verified_at' => now(),
                ]
            );
        }

        return $pessoas;
    }

    /**
     * As tarefas, com a idade em HORAS na etapa atual.
     *
     * As idades não são aleatórias: elas cercam os limiares de propósito. Em
     * andamento acende em 72h, os portões em 24h, e cada etapa tem um card
     * abaixo, um em cima e um no dobro — senão o teste visual só mostraria o
     * estado calmo, que é o único que já se sabia estar certo.
     *
     * Em revisão fica com quatro cards para estourar o WIP de 3: o alarme de
     * excesso é o que não aparece em quadro nenhum recém-semeado.
     *
     * @return list<array<string, mixed>>
     */
    private function tarefas(): array
    {
        return [
            ['titulo' => 'Corrigir baixa parcial no extrato', 'status' => 'aberta', 'horas' => 3,
                'prioridade' => 'nao_definida', 'dono' => null,
                'resumo' => 'Somatório do extrato diverge após baixa parcial.'],
            ['titulo' => 'Remover categoria com lançamento amarrado', 'status' => 'aberta', 'horas' => 52,
                'prioridade' => 'nao_definida', 'dono' => null,
                'resumo' => 'Hoje o vínculo some sem aviso.'],

            ['titulo' => 'Exportar faturamento em CSV', 'status' => 'backlog', 'horas' => 200,
                'prioridade' => 'baixa', 'dono' => 'Marina Alves'],

            ['titulo' => 'Sincronizar módulos do AlfaGym', 'status' => 'em_desenvolvimento', 'horas' => 20,
                'prioridade' => 'alta', 'dono' => 'Marina Alves',
                'resumo' => 'Âncora de licença some ao trocar de tier.'],
            ['titulo' => 'Recalcular tier no meio do ciclo', 'status' => 'em_desenvolvimento', 'horas' => 80,
                'prioridade' => 'critica', 'dono' => 'Rafael Lima',
                'resumo' => 'A troca de tier no dia 20 não reflete na cobrança.'],
            // Voltou do staging: a tarja de retorno com o portão nomeado.
            ['titulo' => 'Webhook de pagamento', 'status' => 'em_desenvolvimento', 'horas' => 30,
                'prioridade' => 'alta', 'dono' => 'Rafael Lima',
                'resumo' => 'Baixa automática ao receber o retorno do gateway.',
                'retorno' => ['de' => 'em_staging', 'motivo' => 'Quebrou ao subir: a migração não roda com dado antigo.']],

            // Quatro em revisão, com WIP de 3: a coluna estoura.
            ['titulo' => 'Filtro de competência no faturamento', 'status' => 'em_revisao', 'horas' => 6,
                'prioridade' => 'media', 'dono' => 'Camila Reis'],
            ['titulo' => 'Anexos na cobrança consolidada', 'status' => 'em_revisao', 'horas' => 30,
                'prioridade' => 'alta', 'dono' => 'Diego Prado'],
            // Pergunta aberta, na terceira rodada: o selo acende em vermelho.
            ['titulo' => 'Plano de contas em três níveis', 'status' => 'em_revisao', 'horas' => 62,
                'prioridade' => 'alta', 'dono' => 'Diego Prado',
                'resumo' => 'A hierarquia atual desce quatro níveis de indentação.',
                'pergunta' => ['de' => 'Camila Reis', 'rodadas' => 3, 'horas' => 38,
                    'corpo' => 'Dá para remover categoria com lançamento amarrado? O que acontece com o histórico?']],
            ['titulo' => 'Paginação do histórico de tarefas', 'status' => 'em_revisao', 'horas' => 10,
                'prioridade' => 'media', 'dono' => 'Marina Alves'],

            // Travada: sai da conta de WIP sem sair da etapa.
            ['titulo' => 'Integração com a Receita para CNPJ', 'status' => 'em_staging', 'horas' => 44,
                'prioridade' => 'media', 'dono' => 'Rafael Lima',
                'bloqueio' => ['horas' => 30, 'motivo' => 'Esperando o financeiro liberar a credencial da API.']],
            ['titulo' => 'Selo de checklist no card', 'status' => 'em_staging', 'horas' => 8,
                'prioridade' => 'baixa', 'dono' => 'Camila Reis'],

            // Staging aprovado e ainda no staging: é a espera pela tag, que
            // deixou de ser coluna própria. Sem ela na massa, o chip "N p/
            // subir" nasce zerado e o recorte que substituiu a fila do admin
            // fica sem prova.
            ['titulo' => 'Aging de receitas por faixa', 'status' => 'em_staging', 'horas' => 28,
                'prioridade' => 'alta', 'dono' => 'Diego Prado',
                'veredito' => ['por' => 'Camila Reis', 'aprovado' => true,
                    'notas' => 'Conferido no staging: faixas batem com o extrato.']],
            // Voltou do ar sem que o defeito fosse do código: a tag é que subiu
            // errada, e quem for mexer nela de novo precisa saber disso.
            ['titulo' => 'Rateio de despesa entre filiais', 'status' => 'em_staging', 'horas' => 9,
                'prioridade' => 'media', 'dono' => 'Rafael Lima',
                'resumo' => 'Divisão proporcional ao faturamento de cada filial.',
                'retorno' => ['de' => 'em_producao', 'motivo' => 'A tag subiu sem a migração; revertida com deploy/voltar.sh.']],

            // No ar, esperando conferência. O validador aqui é outra pessoa
            // que não o dono — é o caso que a massa precisa cobrir, porque o
            // outro (mesma pessoa) já se parece com o staging. As três idades
            // cercam o limiar de 24h: dentro do prazo, acima dele e no dobro.
            ['titulo' => 'Consolidado de mensalidades por turma', 'status' => 'em_producao', 'horas' => 6,
                'prioridade' => 'alta', 'dono' => 'Marina Alves', 'versao' => 'v1.4.2',
                'resumo' => 'Fecha o mês por turma, e não por aluno.',
                'validador' => 'Camila Reis'],
            ['titulo' => 'Fechamento de caixa por operador', 'status' => 'em_producao', 'horas' => 30,
                'prioridade' => 'media', 'dono' => 'Rafael Lima', 'versao' => 'v1.4.1',
                'validador' => 'Diego Prado'],
            // Reprovada no ar e ainda parada: o veredito já existe e o card
            // espera alguém decidir se volta para a bancada ou para a tag. É o
            // terceiro estado do banner, o que não aparece em quadro calmo.
            ['titulo' => 'Boleto com desconto por antecipação', 'status' => 'em_producao', 'horas' => 58,
                'prioridade' => 'critica', 'dono' => 'Marina Alves', 'versao' => 'v1.4.0',
                'resumo' => 'Desconto proporcional aos dias de antecipação.',
                'validador' => 'Camila Reis',
                'veredito' => ['por' => 'Camila Reis', 'aprovado' => false,
                    'notas' => 'O desconto sai dobrado quando a antecipação passa de 30 dias.']],

            ['titulo' => 'Renovar certificado do domínio', 'status' => 'em_desenvolvimento', 'horas' => 5,
                'prioridade' => 'media', 'dono' => 'Marina Alves', 'tipo' => 'operacional'],
        ];
    }

    /**
     * @param  array<string, mixed>  $receita
     * @param  array<string, User>  $pessoas
     * @param  list<int>  $sistemas
     */
    private function semear(array $receita, array $pessoas, array $sistemas): void
    {
        $entrouEm = Carbon::now()->subHours($receita['horas']);
        $dono = $receita['dono'] ? $pessoas[$receita['dono']] : null;

        $tarefa = Tarefa::create([
            'titulo' => $receita['titulo'],
            'resumo' => $receita['resumo'] ?? null,
            'tipo' => $receita['tipo'] ?? 'desenvolvimento',
            'status' => $receita['status'],
            'prioridade' => $receita['prioridade'],
            'responsavel_id' => $dono?->id,
            'criado_por_id' => $pessoas['Camila Reis']->id,
            'sistema_id' => $sistemas ? $sistemas[crc32($receita['titulo']) % count($sistemas)] : null,
        ]);

        // A tarefa nasce com o `created_at` de agora; recuá-lo junto é o que
        // impede o histórico de mostrar um ciclo mais curto que o tempo já
        // passado na etapa atual.
        $tarefa->forceFill(['created_at' => $entrouEm->copy()->subHours(6)])->save();

        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => null,
            'para_status' => $receita['status'],
            'entrou_em' => $entrouEm,
        ]);

        $this->aplicarMarcas($tarefa, $receita, $pessoas);
    }

    /**
     * @param  array<string, mixed>  $receita
     * @param  array<string, User>  $pessoas
     */
    private function aplicarMarcas(Tarefa $tarefa, array $receita, array $pessoas): void
    {
        $marcas = [];

        if (isset($receita['bloqueio'])) {
            $marcas['bloqueado_em'] = Carbon::now()->subHours($receita['bloqueio']['horas']);
            $marcas['bloqueio_motivo'] = $receita['bloqueio']['motivo'];
        }

        if (isset($receita['retorno'])) {
            $marcas['retorno_de'] = $receita['retorno']['de'];
            $marcas['retorno_motivo'] = $receita['retorno']['motivo'];
        }

        // A versão que subiu: sem ela o card em Em produção não diz O QUE está
        // no ar, que é a primeira coisa que quem vai conferir precisa saber.
        if (isset($receita['versao'])) {
            $marcas['versao_producao'] = $receita['versao'];
        }

        // Quem confere no ar. Ocupa o mesmo `interlocutor_id` da pergunta
        // porque é a mesma pergunta — com quem está a bola —, e nenhuma receita
        // pede as duas coisas: a conversa recomeça a cada portão.
        if (isset($receita['validador'])) {
            $marcas['interlocutor_id'] = $pessoas[$receita['validador']]->id;
        }

        // O veredito já registrado: o relatório se prende sozinho ao evento
        // aberto (`TarefaRelatorioTeste::booted`), que é a passagem desta etapa.
        if (isset($receita['veredito'])) {
            TarefaRelatorioTeste::create([
                'tarefa_id' => $tarefa->id,
                'user_id' => $pessoas[$receita['veredito']['por']]->id,
                'aprovado' => $receita['veredito']['aprovado'],
                'notas' => $receita['veredito']['notas'] ?? null,
            ]);
        }

        if (isset($receita['pergunta'])) {
            $quemPerguntou = $pessoas[$receita['pergunta']['de']];

            $marcas['rodadas'] = $receita['pergunta']['rodadas'];
            $marcas['interlocutor_id'] = $quemPerguntou->id;
            $marcas['pergunta_de_id'] = $quemPerguntou->id;
            $marcas['pergunta_para_id'] = $tarefa->responsavel_id;
            $marcas['pergunta_em'] = Carbon::now()->subHours($receita['pergunta']['horas']);

            // A tarja lê o último comentário marcado como pergunta: sem ele, o
            // card anuncia que alguém perguntou e não mostra o quê.
            $comentario = TarefaComentario::create([
                'tarefa_id' => $tarefa->id,
                'autor_id' => $quemPerguntou->id,
                'corpo' => $receita['pergunta']['corpo'],
                'pergunta' => true,
            ]);

            $comentario->forceFill(['created_at' => $marcas['pergunta_em']])->save();
        }

        if ($marcas) {
            $tarefa->forceFill($marcas)->save();
        }
    }
}
