<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Support\Carbon;
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
     * @spec:AC-082 O quadro mostra as cinco etapas do trabalho EM CURSO, na ordem e com
     * a contagem — e nenhuma coluna terminal: encerrou, sai do quadro (AC-096).
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
            ['aberta', 'backlog', 'em_desenvolvimento', 'em_testes', 'ajustes_necessarios'],
            array_column($etapas, 'chave'),
            'O quadro é o trabalho em curso: concluída e cancelada não têm coluna.'
        );

        $quantidades = array_column($etapas, 'quantidade', 'chave');
        $this->assertSame(1, $quantidades['aberta']);
        $this->assertSame(2, $quantidades['backlog']);
        $this->assertSame(1, $quantidades['em_desenvolvimento']);
        $this->assertSame(3, $quantidades['em_testes']);
        $this->assertSame(1, $quantidades['ajustes_necessarios']);
        $this->assertArrayNotHasKey('concluida', $quantidades);
        $this->assertArrayNotHasKey('cancelada', $quantidades);

        // A ordem também vale na renderização, não só no array de apoio.
        $conteudo = $resposta->getContent();
        $rotulos = ['Aberta', 'Backlog', 'Em desenvolvimento', 'Em testes', 'Ajustes necessários'];
        $posicoes = collect($rotulos)->map(fn ($rotulo) => strpos($conteudo, $rotulo));

        $this->assertTrue($posicoes->every(fn ($p) => $p !== false));
        $this->assertSame($posicoes->sort()->values()->all(), $posicoes->values()->all());
    }

    /**
     * @spec:AC-127 Cada etapa tem a sua cor na COLUNA — faixa no topo e contador
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
        $emCurso = collect(Tarefa::STATUS)->keys()
            ->reject(fn ($s) => in_array($s, Tarefa::STATUS_TERMINAIS, true));

        foreach ($emCurso as $status) {
            $this->assertNotEmpty($etapas[$status]['cor'] ?? null, "A etapa {$status} precisa de uma cor.");
        }
        $coresDoFluxo = $emCurso->map(fn ($s) => $etapas[$s]['cor']);
        $this->assertGreaterThan(1, $coresDoFluxo->unique()->count(),
            'As etapas do fluxo ativo não podem compartilhar uma cor só — o quadro ficaria monocromático.');

        // A cor está na COLUNA: faixa no topo do <section> de cada etapa.
        foreach ($emCurso as $status) {
            $cor = $etapas[$status]['cor'];
            $this->assertStringContainsString(
                'border-top: 3px solid rgb(var(--'.$cor.'))',
                $html,
                "A coluna {$status} precisa da faixa de cor no topo."
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

    /**
     * @spec:AC-128 Dentro da coluna, a gravidade manda: uma crítica antiga fica acima
     * de uma baixa recente. No empate de prioridade, quem está parado há mais tempo
     * na etapa sobe — o mesmo instante que o card mostra no chip de tempo.
     */
    public function test_coluna_ordena_por_prioridade_e_depois_pelo_mais_parado(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00'));

        // Criadas fora de ordem de propósito: a crítica é a MAIS ANTIGA, que
        // no critério anterior (created_at desc) a jogaria para o fim.
        // `created_at` não é preenchível por atribuição em massa: sem o
        // forceFill as quatro nasceriam no mesmo instante e o desempate não
        // teria o que desempatar.
        $nascidaEm = function (Tarefa $tarefa, $quando) {
            return $tarefa->forceFill(['created_at' => $quando])->save() ? $tarefa : $tarefa;
        };

        $critica = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'aberta',
            'prioridade' => 'critica', 'titulo' => 'Crítica antiga',
        ]);
        $nascidaEm($critica, now()->subDays(30));

        $baixaRecente = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'aberta',
            'prioridade' => 'baixa', 'titulo' => 'Baixa de hoje',
        ]);
        $nascidaEm($baixaRecente, now()->subMinutes(5));

        // Duas de mesma prioridade: a mais parada precisa vir antes.
        $mediaParada = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'aberta',
            'prioridade' => 'media', 'titulo' => 'Média parada',
        ]);
        $nascidaEm($mediaParada, now()->subDays(9));

        $mediaNova = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'aberta',
            'prioridade' => 'media', 'titulo' => 'Média nova',
        ]);
        $nascidaEm($mediaNova, now()->subHours(2));

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();
        $ordem = $resposta->viewData('colunas')['aberta']->pluck('id')->all();

        $this->assertSame(
            [$critica->id, $mediaParada->id, $mediaNova->id, $baixaRecente->id],
            $ordem,
            'A coluna precisa descer da mais grave para a menos grave, e no empate da mais parada para a mais nova.'
        );

        Carbon::setTestNow();
    }

    /**
     * @spec:AC-132 As colunas dividem a largura disponível em vez de largura fixa —
     * com cinco colunas numa tela larga sobrava faixa vazia à direita do quadro —
     * e o `min-width` segura a largura de leitura quando a tela aperta.
     */
    public function test_colunas_dividem_a_largura_e_guardam_a_largura_minima(): void
    {
        $usuario = User::factory()->create();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('flex: 1 1 276px; min-width: 276px', $html,
            'A coluna precisa crescer com o espaço e manter a largura mínima de leitura.');
        $this->assertStringNotContainsString('style="width: 276px', $html,
            'Largura fixa deixa sobra à direita quando há menos colunas do que caberia.');
    }
}
