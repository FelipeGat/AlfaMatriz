<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O quadro do ciclo de desenvolvimento: a rota abre para quem tem permissão
 * de `tarefas`, e as sete colunas do ciclo aparecem na ordem certa, cada uma
 * com a contagem de tarefas que está nela (T-060).
 */
class QuadroTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-081 A rota tarefas.index abre o quadro para usuário da matriz com permissão de tarefas.
     */
    public function test_rota_do_quadro_abre_para_usuario_da_matriz(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();
    }

    /**
     * @spec:AC-082 O quadro mostra as etapas do ciclo na ordem, com a contagem de cada uma.
     */
    public function test_quadro_mostra_etapas_na_ordem_com_contagem(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $sistema = Sistema::factory()->create();

        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'sistema_id' => $sistema->id, 'status' => 'aberta']);
        Tarefa::factory()->count(2)->create(['criado_por_id' => $criador->id, 'status' => 'backlog']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento']);
        Tarefa::factory()->count(3)->create(['criado_por_id' => $criador->id, 'status' => 'em_testes']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'ajustes_necessarios']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'concluida']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'cancelada']);

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();

        $etapas = $resposta->viewData('etapas');

        $this->assertSame(
            ['aberta', 'backlog', 'em_desenvolvimento', 'em_testes', 'ajustes_necessarios', 'concluida', 'cancelada'],
            array_column($etapas, 'chave')
        );

        $quantidades = array_column($etapas, 'quantidade', 'chave');
        $this->assertSame(1, $quantidades['aberta']);
        $this->assertSame(2, $quantidades['backlog']);
        $this->assertSame(1, $quantidades['em_desenvolvimento']);
        $this->assertSame(3, $quantidades['em_testes']);
        $this->assertSame(1, $quantidades['ajustes_necessarios']);
        $this->assertSame(1, $quantidades['concluida']);
        $this->assertSame(1, $quantidades['cancelada']);

        // A ordem também vale na renderização, não só no array de apoio.
        $conteudo = $resposta->getContent();
        $rotulos = ['Aberta', 'Backlog', 'Em desenvolvimento', 'Em testes', 'Ajustes necessários', 'Concluída', 'Cancelada'];
        $posicoes = collect($rotulos)->map(fn ($rotulo) => strpos($conteudo, $rotulo));

        $this->assertTrue($posicoes->every(fn ($p) => $p !== false));
        $this->assertSame($posicoes->sort()->values()->all(), $posicoes->values()->all());
    }

    /**
     * @spec:AC-114 Cada etapa tem a sua cor na COLUNA — faixa no topo e contador
     * tingido, como no Funil de Vendas — e essa cor não invade a borda do card,
     * que continua reservada ao aviso de tarefa esquecida (AC-093).
     */
    public function test_cada_etapa_tem_cor_propria_na_coluna_e_nao_no_card(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        // Uma tarefa em cada etapa, para todas as colunas renderizarem card.
        foreach (array_keys(Tarefa::STATUS) as $status) {
            Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => $status]);
        }

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();
        $html = $resposta->getContent();

        $etapas = collect($resposta->viewData('etapas'))->keyBy('chave');

        // Toda etapa traz um token de cor, e o fluxo ativo não é monocromático.
        foreach (Tarefa::STATUS as $status => $label) {
            $this->assertNotEmpty($etapas[$status]['cor'] ?? null, "A etapa {$label} precisa de uma cor.");
        }
        $coresDoFluxo = collect(Tarefa::STATUS)->keys()
            ->reject(fn ($s) => in_array($s, Tarefa::STATUS_TERMINAIS, true))
            ->map(fn ($s) => $etapas[$s]['cor']);
        $this->assertGreaterThan(1, $coresDoFluxo->unique()->count(),
            'As etapas do fluxo ativo não podem compartilhar uma cor só — o quadro ficaria monocromático.');

        // A cor está na COLUNA: faixa no topo do <section> de cada etapa.
        foreach (Tarefa::STATUS as $status => $label) {
            $cor = $etapas[$status]['cor'];
            $this->assertStringContainsString(
                'border-top: 3px solid rgb(var(--'.$cor.'))',
                $html,
                "A coluna {$label} precisa da faixa de cor no topo."
            );
        }

        // E NÃO no card: o <article> do card não pinta borda com token de etapa.
        preg_match_all('/<article[^>]*style="border-color:([^"]*)"/', $html, $bordas);
        foreach ($bordas[1] as $borda) {
            foreach (['accent', 'brand', 'good'] as $tokenDeEtapa) {
                $this->assertStringNotContainsString('--'.$tokenDeEtapa.')', $borda,
                    'A borda do card não pode carregar a cor da etapa — ela é do aviso de esquecida.');
            }
        }
    }
}
