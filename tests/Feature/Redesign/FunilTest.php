<?php

namespace Tests\Feature\Redesign;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O quadro do funil.
 *
 * Duas coisas se provam aqui: que o quadro ocupa a tela em vez de ser
 * esticado pela coluna mais cheia, e que mover um lead funciona pelos DOIS
 * caminhos — arrastando e pelo menu. O menu não é resto do desenho antigo: é
 * o caminho de quem usa teclado, de quem está no celular e de quem precisa
 * declarar o motivo da perda.
 */
class FunilTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function lead(string $estagio = 'qualificacao', float $valor = 1200.00): Lead
    {
        return Lead::create([
            'nome' => 'Academia Vitória',
            'tipo_interesse' => 'saas',
            'origem' => 'Indicação',
            'valor_estimado' => $valor,
            'estagio' => $estagio,
            'estagio_atualizado_em' => now(),
        ]);
    }

    /**
     * @spec:AC-044 O quadro ocupa a tela e cada coluna rola por dentro — todas
     * com a mesma altura, sem a coluna cheia esticar a página.
     */
    public function test_o_quadro_ocupa_a_altura_da_janela_e_cada_coluna_rola_por_dentro(): void
    {
        $this->lead('lead');
        $this->lead('proposta');

        $resposta = $this->actingAs($this->operador())->get(route('leads.index'));
        $resposta->assertOk();

        $html = $resposta->getContent();

        // A tela se dimensiona pela janela, não pelo conteúdo.
        $this->assertStringContainsString('height: calc(100vh - 120px)', $html);

        // O quadro cresce dentro dela — `min-h-0` é o que permite encolher um
        // filho de flex abaixo do conteúdo dele; sem isso a rolagem interna
        // nunca acontece e a página inteira é que rola.
        $this->assertMatchesRegularExpression('/flex-1 min-h-0[^"]*bg-board/', $html);

        // Colunas com a mesma altura e rolagem horizontal do quadro.
        $this->assertMatchesRegularExpression('/items-stretch[^"]*overflow-x-auto/', $html);

        // E a lista de cards de cada coluna rola por dentro.
        $this->assertMatchesRegularExpression('/flex-1 min-h-0 overflow-y-auto/', $html);

        // Cada estágio conhece quantos leads tem e quanto está em jogo.
        $estagios = $resposta->viewData('estagios');
        $this->assertCount(count(Lead::ESTAGIOS), $estagios);

        $proposta = collect($estagios)->firstWhere('chave', 'proposta');
        $this->assertSame(1, $proposta['quantidade']);
        $this->assertEqualsWithDelta(1200.0, $proposta['valor'], 0.01);
        $resposta->assertSee('em jogo', escape: false);
    }

    /**
     * @spec:AC-044 A roda do mouse rola o quadro na horizontal — num kanban a
     * direção que importa é a lateral, e mouse comum não tem eixo X.
     *
     * O que este teste protege de verdade é a RESSALVA: a coluna sob o cursor
     * tem prioridade. Sequestrar a roda sempre tiraria o único jeito de ver o
     * fim de uma coluna cheia, trocando um incômodo por outro pior.
     */
    public function test_a_roda_rola_o_quadro_mas_cede_a_vez_para_a_coluna(): void
    {
        $this->lead('lead');

        $html = $this->actingAs($this->operador())->get(route('leads.index'))->getContent();

        // O quadro escuta a roda e sabe se apontar.
        $this->assertStringContainsString('@wheel="rolarQuadro($event)"', $html);
        $this->assertStringContainsString('x-ref="quadro"', $html);

        // Cada coluna se identifica, para o comportamento saber onde o cursor
        // está — sem isso não há como dar a vez a ela.
        $this->assertStringContainsString('data-coluna-lista', $html);

        // A coluna com o que mostrar fica com a roda: o quadro só assume
        // quando ela chegou ao fim (ou ao topo, subindo).
        $this->assertMatchesRegularExpression(
            '/const folgaAbaixo = coluna\.scrollHeight - coluna\.clientHeight - coluna\.scrollTop;/',
            $html,
            'Sem medir a folga da coluna, a roda a atropela.'
        );
        $this->assertMatchesRegularExpression('/if \(desce \|\| sobe\) return;/', $html);

        // Gesto horizontal de trackpad passa direto: ali o navegador já acerta.
        $this->assertStringContainsString(
            'Math.abs(evento.deltaX) > Math.abs(evento.deltaY)',
            $html
        );
    }

    /**
     * @spec:AC-045 Arrastar move o lead, e o menu faz o mesmo sem mouse — pelos
     * dois caminhos o lead muda de estágio e as colunas se reajustam.
     */
    public function test_o_lead_muda_de_estagio_pelos_dois_caminhos(): void
    {
        $operador = $this->operador();

        // ── Caminho 1: arrastar. O card solto envia o mesmo POST que o menu,
        // então o que se prova é o efeito no servidor.
        $arrastado = $this->lead('qualificacao');

        $this->actingAs($operador)
            ->post(route('leads.mover', $arrastado), ['estagio' => 'proposta'])
            ->assertRedirect(route('leads.index'));

        $this->assertSame('proposta', $arrastado->fresh()->estagio);

        // ── Caminho 2: o menu, com o motivo que só ele consegue perguntar.
        $perdido = $this->lead('proposta');

        $this->actingAs($operador)
            ->post(route('leads.mover', $perdido), ['estagio' => 'perdido', 'motivo_perda' => 'preco'])
            ->assertRedirect(route('leads.index'));

        $this->assertSame('perdido', $perdido->fresh()->estagio);

        // Perder um lead SEM motivo é recusado — é justamente por isso que a
        // coluna "perdido" não aceita o card solto.
        $this->actingAs($operador)
            ->post(route('leads.mover', $this->lead('proposta')), ['estagio' => 'perdido'])
            ->assertSessionHasErrors('motivo_perda');

        // As contagens acompanham o movimento.
        $resposta = $this->actingAs($operador)->get(route('leads.index'));
        $estagios = collect($resposta->viewData('estagios'));

        $this->assertSame(0, $estagios->firstWhere('chave', 'qualificacao')['quantidade']);
        $this->assertSame(1, $estagios->firstWhere('chave', 'perdido')['quantidade']);

        // E os dois caminhos existem na tela: o card é arrastável e o menu
        // continua lá.
        $html = $resposta->getContent();
        $this->assertStringContainsString('draggable="true"', $html);
        $this->assertMatchesRegularExpression('/@drop\.prevent="soltar\(/', $html);
        $this->assertStringContainsString('Mover ▾', $html);
        $this->assertStringContainsString('Motivo da perda', $html);
    }
}
