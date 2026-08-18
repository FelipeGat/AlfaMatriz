<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tarefa vinculada a tarefa — e vínculo simétrico, não hierarquia (US-097).
 *
 * O vínculo não entra no fluxo: não move, não trava, não conta no WIP. Ele
 * responde a uma pergunta só — "com o que mais isto tem a ver" — e é a mesma
 * resposta dos dois lados.
 */
class VinculoEntreTarefasTest extends TestCase
{
    use RefreshDatabase;

    private function criarTarefa(array $atributos = []): Tarefa
    {
        return Tarefa::factory()->create(array_merge([
            'criado_por_id' => User::factory(),
            'status' => 'em_desenvolvimento',
        ], $atributos));
    }

    /**
     * @spec:AC-366 O vínculo vale nos dois sentidos: quem abre a OUTRA tarefa descobre a
     * ligação sem que ninguém a tenha repetido lá — é isso que separa o vínculo do
     * número escrito no resumo. E desfazer de um lado desfaz nos dois: meia ligação
     * deixaria a outra tarefa mostrando um vínculo que já não existe.
     */
    public function test_vinculo_aparece_nos_dois_lados_e_some_dos_dois(): void
    {
        $usuario = User::factory()->create();
        $a = $this->criarTarefa(['titulo' => 'Corrigir importação']);
        $b = $this->criarTarefa(['titulo' => 'Importador trava com CSV grande']);

        $this->actingAs($usuario)
            ->post(route('tarefas.vinculos.store', $a), ['tarefa' => (string) $b->id])
            ->assertSessionHasNoErrors();

        $this->assertSame([$b->id], $a->fresh()->vinculadas->pluck('id')->all());
        $this->assertSame([$a->id], $b->fresh()->vinculadas->pluck('id')->all());

        // Desfaz pelo lado B, que não foi o que vinculou.
        $this->actingAs($usuario)
            ->delete(route('tarefas.vinculos.destroy', [$b, $a]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($a->fresh()->vinculadas->isEmpty());
        $this->assertTrue($b->fresh()->vinculadas->isEmpty());
        $this->assertDatabaseCount('tarefa_vinculos', 0);
    }

    /**
     * @spec:AC-367 Vincula-se pelo NÚMERO — "412", "#412" ou a linha inteira que a
     * sugestão do navegador preenche. Um `<select>` teria de decidir quais tarefas
     * caber nele, e todo recorte deixa sem caminho quem aponta para a tarefa antiga
     * que EXPLICA esta.
     */
    public function test_as_tres_formas_de_digitar_o_numero_vinculam_a_mesma_tarefa(): void
    {
        $usuario = User::factory()->create();
        $alvo = $this->criarTarefa(['titulo' => 'Corrigir importação']);

        $formas = [
            (string) $alvo->id,
            '#'.$alvo->id,
            '#'.$alvo->id.' — Corrigir importação',
        ];

        foreach ($formas as $digitado) {
            $origem = $this->criarTarefa();

            $this->actingAs($usuario)
                ->post(route('tarefas.vinculos.store', $origem), ['tarefa' => $digitado])
                ->assertSessionHasNoErrors();

            $this->assertSame(
                [$alvo->id],
                $origem->fresh()->vinculadas->pluck('id')->all(),
                "A forma \"{$digitado}\" não vinculou a tarefa.",
            );
        }
    }

    /**
     * @spec:AC-367 Número inexistente é recusado com a frase que explica, em vez de
     * vincular outra coisa. E o número é lido do COMEÇO do que se digitou: sem a
     * âncora, "corrigir o importador da v2" viraria a tarefa 2 — um vínculo
     * silenciosamente errado é pior que uma recusa, porque ninguém vai conferir.
     */
    public function test_numero_que_nao_existe_e_recusado_sem_vincular(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($usuario)
            ->post(route('tarefas.vinculos.store', $tarefa), ['tarefa' => '99999'])
            ->assertSessionHas('erro', 'Não existe tarefa com esse número.');

        $this->assertTrue($tarefa->fresh()->vinculadas->isEmpty());

        // Texto sem número na frente não vira vínculo por acaso.
        $this->actingAs($usuario)
            ->post(route('tarefas.vinculos.store', $tarefa), ['tarefa' => 'corrigir o importador da v2'])
            ->assertSessionHas('erro', 'Não existe tarefa com esse número.');

        $this->assertTrue($tarefa->fresh()->vinculadas->isEmpty());
    }

    /**
     * @spec:AC-366 Tarefa não se vincula a si mesma, e vincular a mesma dupla duas vezes
     * não cria um segundo par — o card contaria dois vínculos onde há um. O aviso muda
     * na repetição: "vinculadas" com uma lista que não cresceu faria procurar defeito
     * numa gravação que não tinha o que fazer.
     */
    public function test_recusa_o_vinculo_consigo_mesma_e_nao_duplica_o_par(): void
    {
        $usuario = User::factory()->create();
        $a = $this->criarTarefa();
        $b = $this->criarTarefa();

        $this->actingAs($usuario)
            ->post(route('tarefas.vinculos.store', $a), ['tarefa' => (string) $a->id])
            ->assertSessionHas('erro', 'Uma tarefa não se vincula a si mesma.');

        $this->assertDatabaseCount('tarefa_vinculos', 0);

        $this->actingAs($usuario)->post(route('tarefas.vinculos.store', $a), ['tarefa' => (string) $b->id]);
        $this->actingAs($usuario)
            ->post(route('tarefas.vinculos.store', $a), ['tarefa' => (string) $b->id])
            ->assertSessionHas('status', "A tarefa {$b->codigo()} já estava vinculada.");

        // Duas linhas, uma por sentido — e só duas.
        $this->assertDatabaseCount('tarefa_vinculos', 2);
        $this->assertSame(1, $a->fresh()->vinculadas->count());
    }

    /**
     * @spec:AC-368 O vínculo nasce junto com a tarefa, pelo mesmo argumento do checklist
     * e dos anexos: a tarefa irmã está na cabeça de quem abre. Número inválido no meio
     * da lista é ignorado sem derrubar a criação — recusá-la custaria o título, o
     * checklist e os anexos já preenchidos.
     */
    public function test_tarefa_nasce_ja_vinculada_e_numero_invalido_nao_derruba_a_criacao(): void
    {
        $usuario = User::factory()->create();
        $irma = $this->criarTarefa(['titulo' => 'Importador trava com CSV grande']);

        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Corrigir importação',
            'vinculadas' => [
                '#'.$irma->id.' — Importador trava com CSV grande',
                '99999',
                '',
            ],
        ])->assertSessionHasNoErrors();

        $nova = Tarefa::where('titulo', 'Corrigir importação')->firstOrFail();

        $this->assertSame([$irma->id], $nova->vinculadas->pluck('id')->all());
        $this->assertSame([$nova->id], $irma->fresh()->vinculadas->pluck('id')->all());
    }

    /**
     * @spec:AC-366 Vincular e desvincular acontecem com o modal ABERTO, então voltam
     * pelo caminho parcial: as duas regiões do vínculo redesenhadas e o card, sem o
     * quadro inteiro e sem redirect. Sem os ENVIOS de volta, o ✕ do vínculo recém-criado
     * apontaria para um formulário que não existe.
     */
    public function test_vincular_devolve_os_pedacos_do_vinculo_e_nao_um_redirect(): void
    {
        $usuario = User::factory()->create();
        $a = $this->criarTarefa(['titulo' => 'Corrigir importação']);
        $b = $this->criarTarefa(['titulo' => 'Importador trava com CSV grande']);

        $resposta = $this->actingAs($usuario)
            ->postJson(route('tarefas.vinculos.store', $a), ['tarefa' => '#'.$b->id]);

        $resposta->assertOk();
        $resposta->assertJsonPath('tarefa', $a->id);

        // O quadro inteiro não volta — vincular não move card de coluna.
        $resposta->assertJsonPath('quadro', null);

        $pedacos = $resposta->json('pedacos');

        $this->assertArrayHasKey("vinculos-{$a->id}", $pedacos);
        $this->assertArrayHasKey("vinculos-envios-{$a->id}", $pedacos);
        $this->assertStringContainsString('Importador trava com CSV grande', $pedacos["vinculos-{$a->id}"]);
        $this->assertStringContainsString("desvincular-{$a->id}-{$b->id}", $pedacos["vinculos-envios-{$a->id}"]);

        // E o card volta com o selo atualizado, sem o quadro junto.
        $this->assertArrayHasKey("card-{$a->id}", $pedacos);
        $this->assertStringContainsString('1 tarefa vinculada', $pedacos["card-{$a->id}"]);
    }

    /**
     * @spec:AC-369 O selo cai dos DOIS cards. O vínculo é a única ação do quadro que
     * mexe em duas tarefas ao mesmo tempo — as outras devolvem o card de uma só, e
     * seguir esse molde deixava a tarefa irmã contando, no card ao lado, o vínculo que
     * acabou de ser desfeito. E a assinatura do quadro muda, senão quem está com a tela
     * aberta ao lado nunca descobriria: vincular não toca a linha da tarefa.
     */
    public function test_o_card_da_irma_tambem_volta_e_a_assinatura_muda(): void
    {
        $usuario = User::factory()->create();
        $a = $this->criarTarefa(['titulo' => 'Corrigir importação']);
        $b = $this->criarTarefa(['titulo' => 'Importador trava com CSV grande']);

        $antes = $this->actingAs($usuario)->getJson(route('tarefas.atualizacoes'))->json('assinatura');

        $vinculou = $this->actingAs($usuario)
            ->postJson(route('tarefas.vinculos.store', $a), ['tarefa' => (string) $b->id]);

        $pedacos = $vinculou->json('pedacos');

        $this->assertArrayHasKey("card-{$a->id}", $pedacos);
        $this->assertArrayHasKey("card-{$b->id}", $pedacos);
        $this->assertStringContainsString('1 tarefa vinculada', $pedacos["card-{$b->id}"]);

        $this->assertNotSame($antes, $vinculou->json('assinatura'));

        // E ao desfazer, o card da irmã volta sem o selo.
        $desfez = $this->actingAs($usuario)->deleteJson(route('tarefas.vinculos.destroy', [$a, $b]));

        $pedacos = $desfez->json('pedacos');

        $this->assertArrayHasKey("card-{$b->id}", $pedacos);
        $this->assertStringNotContainsString('tarefa vinculada', $pedacos["card-{$b->id}"]);
    }

    /**
     * @spec:AC-369 O card avisa que há irmã sem obrigar a abrir, e o elo fica na LINHA DO
     * NÚMERO — não no rodapé. Ele nasceu lá e não aparecia: a tira de selos recebe 49px e
     * a soma dava 51, então o elo caía para a segunda linha que o `overflow-hidden` corta
     * e sumia por inteiro. O assert é por posição de propósito: um `assertStringContains`
     * passaria com o elo de volta no rodapé, invisível.
     */
    public function test_o_quadro_mostra_o_selo_na_linha_do_numero_e_o_modal_lista_as_vinculadas(): void
    {
        $usuario = User::factory()->create();
        $a = $this->criarTarefa(['titulo' => 'Corrigir importação']);
        $b = $this->criarTarefa(['titulo' => 'Importador trava com CSV grande']);

        $a->vincularCom($b, $usuario->id);

        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('1 tarefa vinculada', $quadro);

        // Entre o número e o fim do parágrafo do título — que é o que prova que
        // o elo está na linha de cima, e não lá embaixo com os selos do rodapé.
        $this->assertMatchesRegularExpression(
            '/'.preg_quote($a->codigo(), '/').'<\/span>(?:(?!<\/p>).)*?title="1 tarefa vinculada"/s',
            $quadro,
        );

        $modal = $this->actingAs($usuario)->get(route('tarefas.modal', $a))->assertOk()->getContent();
        $this->assertStringContainsString('Tarefas vinculadas', $modal);
        $this->assertStringContainsString('Importador trava com CSV grande', $modal);
        $this->assertStringContainsString("Abrir a tarefa {$b->codigo()}", $modal);
    }

    /**
     * @spec:AC-367 A lista de sugestão só existe no modal de EDIÇÃO. O de nova tarefa é
     * desenhado junto com o quadro, em toda carga da página, e a sugestão levaria para
     * dentro dele o título de toda tarefa aberta — inclusive os que o filtro acabou de
     * esconder, esvaziando o recorte que a barra de filtros promete.
     */
    public function test_a_sugestao_de_vinculo_nao_viaja_na_pagina_do_quadro(): void
    {
        $usuario = User::factory()->create();
        $this->criarTarefa(['titulo' => 'Tarefa que o filtro esconderia']);

        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O campo de vincular está lá; a lista de títulos, não.
        $this->assertStringContainsString('name="vinculadas[]"', $quadro);
        $this->assertStringNotContainsString('sugestoes-de-vinculo', $quadro);
    }

    /**
     * @spec:AC-370 Vincular não prende: mover, bloquear ou encerrar uma não mexe na
     * outra. O vínculo não entra no fluxo nem na conta de trabalho em curso.
     */
    public function test_mover_e_encerrar_uma_nao_mexe_na_outra(): void
    {
        $usuario = User::factory()->create();
        $a = $this->criarTarefa(['status' => 'em_desenvolvimento']);
        $b = $this->criarTarefa(['status' => 'aberta']);

        $a->vincularCom($b, $usuario->id);

        $a->update(['status' => 'concluida']);

        $this->assertSame('aberta', $b->fresh()->status);

        // E a tarefa encerrada continua vinculada: o vínculo é registro, e o
        // histórico o mostra do mesmo jeito que mostra o checklist.
        $this->assertSame([$b->id], $a->fresh()->vinculadas->pluck('id')->all());
    }

    /**
     * @spec:AC-366 Tarefa excluída some da lista da irmã sem levar o vínculo embora: a
     * exclusão do quadro é reversível, e restaurar a tarefa devolve os vínculos que ela
     * tinha — que é o que restaurar deveria significar.
     */
    public function test_tarefa_excluida_some_da_lista_e_volta_ao_ser_restaurada(): void
    {
        $usuario = User::factory()->create();
        $a = $this->criarTarefa();
        $b = $this->criarTarefa();

        $a->vincularCom($b, $usuario->id);

        $b->delete();

        $this->assertTrue($a->fresh()->vinculadas->isEmpty());
        $this->assertDatabaseCount('tarefa_vinculos', 2);

        $b->restore();

        $this->assertSame([$b->id], $a->fresh()->vinculadas->pluck('id')->all());
    }
}
