<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O faturamento das revendas.
 *
 * A pergunta que esta tela responde não é "quanto dá?", é "posso gerar isso
 * com segurança?". Por isso os testes cobram o que torna o número conferível:
 * a conta de cada linha escrita por extenso, o subtotal somado das linhas (e
 * não digitado), e a linha sem tier declarada em vez de silenciosamente
 * cobrada por um preço que ninguém configurou.
 */
class FaturamentoTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function sistema(string $nome, string $unidade): Sistema
    {
        return Sistema::create([
            'nome' => $nome,
            'slug' => \Illuminate\Support\Str::slug($nome),
            'unidade_cobranca' => $unidade,
            'ativo' => true,
        ]);
    }

    private function clientes(Sistema $sistema, Revenda $revenda, int $quantos): void
    {
        for ($i = 0; $i < $quantos; $i++) {
            $cliente = Cliente::create([
                'nome' => $sistema->nome." {$i}",
                'revenda_id' => $revenda->id,
                'ativo' => true,
            ]);
            $cliente->sistemas()->attach($sistema->id, ['ativo' => true]);
        }
    }

    /**
     * Cenário: uma revenda com três sistemas — um metrado, um de valor fixo e
     * um sem tier configurado.
     */
    private function cenario(): array
    {
        $revenda = Revenda::create(['nome' => 'Vetor Tecnologia', 'ativo' => true]);

        $metrado = $this->sistema('AlfaGym', 'academia');
        PrecoAtacado::create([
            'sistema_id' => $metrado->id, 'nome' => 'Metrado',
            'preco_base' => 0, 'unidades_inclusas' => 0, 'valor_excedente_unidade' => 2.50,
            'vigencia_inicio' => now()->subYear()->toDateString(),
        ]);
        $this->clientes($metrado, $revenda, 20);   // 20 × 2,50 = 50,00

        $fixo = $this->sistema('AlfaHome', 'condomínio');
        PrecoAtacado::create([
            'sistema_id' => $fixo->id, 'nome' => 'Plano fixo',
            'preco_base' => 300.00, 'unidades_inclusas' => 100, 'limite_unidades' => 1000,
            'vigencia_inicio' => now()->subYear()->toDateString(),
        ]);
        $this->clientes($fixo, $revenda, 8);       // dentro do incluso = 300,00

        $semTier = $this->sistema('AlfaSchool', 'escola');
        $this->clientes($semTier, $revenda, 6);    // fora do ciclo

        return [$revenda, $metrado, $fixo, $semTier];
    }

    /**
     * @spec:AC-050 A barra do ciclo resume o que será gerado, e o botão diz
     * quantas cobranças serão criadas — não um "gerar" genérico.
     */
    public function test_a_barra_do_ciclo_resume_e_o_botao_declara_quantas_cobrancas(): void
    {
        $this->cenario();

        $resposta = $this->actingAs($this->operador())->get(route('faturamento.index'));
        $resposta->assertOk();

        $ciclo = $resposta->viewData('ciclo');

        $this->assertEqualsWithDelta(350.0, $ciclo['total'], 0.01, '50 do metrado + 300 do fixo; a linha sem tier fica de fora.');
        $this->assertSame(1, $ciclo['revendas']);
        $this->assertSame(3, $ciclo['linhas']);
        $this->assertSame(1, $ciclo['pendencias']);
        $this->assertFalse($ciclo['gerado'], 'Nada foi gerado ainda — o selo precisa dizer que é prévia.');

        // O rótulo do botão carrega a contagem: é o que deixa conferir o que
        // está prestes a acontecer antes de clicar.
        $resposta->assertSee('Gerar 1 cobrança', escape: false);
        $resposta->assertSee('prévia · nada gerado', escape: false);

        // O vencimento declarado é o mesmo que o motor usa (fim da
        // competência + 5 dias) — a tela não pode prometer outra data.
        $mes = \Illuminate\Support\Carbon::createFromFormat('Y-m', $resposta->viewData('competencia'))->startOfMonth();
        $this->assertTrue($ciclo['vencimento']->isSameDay($mes->copy()->endOfMonth()->addDays(5)));
        $resposta->assertSee($ciclo['vencimento']->format('d/m/Y'), escape: false);

        // Trocar a competência recarrega a prévia.
        $outra = now()->subMonths(2)->format('Y-m');
        $resposta = $this->actingAs($this->operador())->get(route('faturamento.index', ['competencia' => $outra]));
        $this->assertSame($outra, $resposta->viewData('competencia'));
    }

    /**
     * @spec:AC-051 Cada linha mostra o cálculo que a originou, e o subtotal é
     * exatamente a soma das linhas que entram no ciclo.
     */
    public function test_cada_linha_mostra_a_conta_e_o_subtotal_e_a_soma_delas(): void
    {
        $this->cenario();

        $resposta = $this->actingAs($this->operador())->get(route('faturamento.index'));
        $resposta->assertOk();

        $painel = $resposta->viewData('preview')->first();
        $linhas = $painel['linhas'];

        // A conta de cada linha, por extenso.
        $gym = $linhas->firstWhere('sistema', 'AlfaGym');
        $this->assertSame('20 × R$ 2,50', $gym['calculo']);
        $this->assertSame('metrado', $gym['tipo_tier']);
        $this->assertEqualsWithDelta(50.0, $gym['valor'], 0.01);

        $home = $linhas->firstWhere('sistema', 'AlfaHome');
        $this->assertSame('fixo · teto 1.000', $home['calculo'], 'Tier fixo com teto precisa declarar o teto.');
        $this->assertSame('fixo', $home['tipo_tier']);
        $this->assertEqualsWithDelta(300.0, $home['valor'], 0.01);

        // O subtotal é a SOMA das linhas que entram — não um campo à parte.
        $somaDasLinhas = $linhas->where('sem_tier', false)->sum('valor');
        $this->assertEqualsWithDelta($somaDasLinhas, $painel['total'], 0.01);
        $this->assertEqualsWithDelta(350.0, $painel['total'], 0.01);

        // E o total do ciclo é a soma dos subtotais.
        $this->assertEqualsWithDelta(
            $resposta->viewData('preview')->sum('total'),
            $resposta->viewData('ciclo')['total'],
            0.01
        );

        // A unidade real aparece ao lado do número.
        $this->assertSame('academia', $gym['unidade_cobranca']);
        $resposta->assertSee('20 × R$ 2,50', escape: false);
        $resposta->assertSee('fixo · teto 1.000', escape: false);
    }

    /**
     * @spec:AC-052 Linha sem tier fica fora do subtotal e aparece explicada,
     * com atalho para configurar o tier.
     */
    public function test_linha_sem_tier_fica_fora_do_subtotal_e_e_explicada(): void
    {
        $this->cenario();

        $resposta = $this->actingAs($this->operador())->get(route('faturamento.index'));
        $resposta->assertOk();

        $painel = $resposta->viewData('preview')->first();
        $school = $painel['linhas']->firstWhere('sistema', 'AlfaSchool');

        // A linha existe, é marcada, e não tem valor inventado.
        $this->assertTrue($school['sem_tier']);
        $this->assertNull($school['valor'], 'Sem tier não há preço — a tela não pode arbitrar um.');
        $this->assertNull($school['calculo']);
        $this->assertSame(6, $school['unidades']);

        // E fica fora do subtotal: 350, não 350 + qualquer coisa.
        $this->assertEqualsWithDelta(350.0, $painel['total'], 0.01);
        $this->assertSame(1, $painel['foraDoTotal']);

        // A tela explica o que ficou de fora, quanto e por quê.
        $pendencias = $resposta->viewData('pendencias');
        $this->assertCount(1, $pendencias);
        $this->assertSame('AlfaSchool', $pendencias->first()['sistema']);
        $this->assertSame(6, $pendencias->first()['unidades']);
        $this->assertSame('Vetor Tecnologia', $pendencias->first()['revenda']);

        $resposta->assertSee('linha fora do faturamento deste ciclo', escape: false);
        $resposta->assertSee('não tem tier de atacado configurado', escape: false);
        $resposta->assertSee('não serão cobradas', escape: false);

        // Com atalho para onde o problema se resolve.
        $resposta->assertSee(route('produtos.index'), escape: false);
        $resposta->assertSee('Definir tier', escape: false);
    }
}
