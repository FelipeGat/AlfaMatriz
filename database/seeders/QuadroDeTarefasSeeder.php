<?php

namespace Database\Seeders;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\TarefaEvento;
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

            ['titulo' => 'Aging de receitas por faixa', 'status' => 'pronta_producao', 'horas' => 28,
                'prioridade' => 'alta', 'dono' => 'Diego Prado'],

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
