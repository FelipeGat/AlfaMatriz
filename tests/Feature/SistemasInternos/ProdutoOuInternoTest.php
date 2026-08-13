<?php

namespace Tests\Feature\SistemasInternos;

use App\Models\Cliente;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\IndicadoresService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tabela `sistemas` guarda o que a Alfa VENDE e o que a Alfa USA.
 *
 * O teste que importa não é "a coluna existe" — é que a separação vale nos dois
 * sentidos, e que ela não vaza: o interno tem de sumir de toda tela que fala de
 * dinheiro E aparecer em toda tela que fala de trabalho. Um filtro esquecido num
 * dos onze lugares põe um sistema de MRR zero no meio de uma comparação de
 * receita, e o ticket médio da casa inteira cai sem nada ter mudado de preço.
 */
class ProdutoOuInternoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create();
    }

    /** Um produto de verdade, com cliente ativo e tier — para haver receita a proteger. */
    private function produtoComReceita(): Sistema
    {
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym', 'unidade_cobranca' => 'academia ativa']);
        $revenda = Revenda::create(['nome' => 'Invest', 'cnpj' => '12345678000199', 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'Academia Central', 'revenda_id' => $revenda->id, 'ativo' => true]);

        $cliente->sistemas()->attach($sistema->id, ['ativo' => true]);

        PrecoAtacado::create([
            'sistema_id' => $sistema->id,
            'revenda_id' => null,
            'nome' => 'Único',
            'preco_base' => 500,
            'unidades_inclusas' => 100,
            'limite_unidades' => null,
            'ordem' => 1,
            'vigencia_inicio' => now()->subYear()->toDateString(),
        ]);

        return $sistema;
    }

    public function test_o_catalogo_comercial_nao_conta_o_sistema_interno(): void
    {
        $this->produtoComReceita();
        Sistema::factory()->interno()->create(['nome' => 'AlfaMatriz']);

        // O card "Sistemas ativos" é o mesmo número em três telas. Contando o
        // interno, ele passa a anunciar um catálogo maior do que o que a lista
        // logo abaixo mostra — a tela contradiz a si mesma.
        $this->assertSame(1, app(IndicadoresService::class)->sistemasAtivos());

        $ranking = app(IndicadoresService::class)->rankingSistemas();

        $this->assertCount(1, $ranking);
        $this->assertSame('AlfaGym', $ranking->first()['sistema']->nome);
    }

    public function test_a_tela_de_produtos_lista_so_produto(): void
    {
        $this->produtoComReceita();
        Sistema::factory()->interno()->create(['nome' => 'AlfaMatriz']);

        $resposta = $this->actingAs($this->admin())->get(route('produtos.index'));

        $resposta->assertOk();
        $this->assertSame(1, $resposta->viewData('contagens')['sistemas']);
        $this->assertSame(1, $resposta->viewData('contagens')['internos']);
        $this->assertSame(
            ['AlfaGym'],
            collect($resposta->viewData('produtos')->items())->pluck('sistema.nome')->all()
        );
    }

    public function test_a_aba_de_internos_lista_so_interno(): void
    {
        $this->produtoComReceita();
        Sistema::factory()->interno()->create(['nome' => 'AlfaMatriz']);

        $resposta = $this->actingAs($this->admin())->get(route('produtos.index', ['aba' => 'internos']));

        $resposta->assertOk();
        $this->assertSame('internos', $resposta->viewData('aba'));
        $this->assertSame(['AlfaMatriz'], $resposta->viewData('internos')->pluck('nome')->all());
    }

    /**
     * A aba de internos conta o trabalho ABERTO, e só ele.
     *
     * É a única métrica que um sistema interno tem. Contando também o que
     * fechou, o número viraria histórico acumulado — nunca desceria, e deixaria
     * de responder "o que está pendente aqui", que é a pergunta que a coluna faz.
     */
    public function test_a_contagem_de_tarefas_do_interno_ignora_as_encerradas(): void
    {
        $interno = Sistema::factory()->interno()->create(['nome' => 'AlfaMatriz']);
        $autor = $this->admin();

        Tarefa::factory()->count(2)->create([
            'sistema_id' => $interno->id, 'status' => 'em_desenvolvimento', 'criado_por_id' => $autor->id,
        ]);
        Tarefa::factory()->create([
            'sistema_id' => $interno->id, 'status' => 'concluida', 'criado_por_id' => $autor->id,
        ]);
        Tarefa::factory()->create([
            'sistema_id' => $interno->id, 'status' => 'cancelada', 'criado_por_id' => $autor->id,
        ]);

        $resposta = $this->actingAs($autor)->get(route('produtos.index', ['aba' => 'internos']));

        $this->assertSame(2, $resposta->viewData('internos')->first()->tarefas_count);
    }

    /**
     * O quadro de tarefas é a única lista que enxerga as duas famílias — e é a
     * razão de a distinção existir. Antes disto, a tarefa da própria Matriz
     * nascia sem sistema e sumia do filtro e da raia.
     */
    public function test_o_quadro_de_tarefas_oferece_produto_e_interno(): void
    {
        $this->produtoComReceita();
        Sistema::factory()->interno()->create(['nome' => 'AlfaMatriz']);

        $resposta = $this->actingAs($this->admin())->get(route('tarefas.index'));

        $resposta->assertOk();
        $this->assertEqualsCanonicalizing(
            ['AlfaGym', 'AlfaMatriz'],
            $resposta->viewData('sistemas')->pluck('nome')->all()
        );
    }

    /** Produto primeiro: a ordem da lista alimenta a ordem dos grupos do select. */
    public function test_o_produto_vem_antes_do_interno_na_lista_do_quadro(): void
    {
        Sistema::factory()->interno()->create(['nome' => 'AAA interno']);
        Sistema::factory()->create(['nome' => 'ZZZ produto']);

        $resposta = $this->actingAs($this->admin())->get(route('tarefas.index'));

        $this->assertSame(
            ['ZZZ produto', 'AAA interno'],
            $resposta->viewData('sistemas')->pluck('nome')->all()
        );
    }

    public function test_a_tarefa_pode_apontar_para_um_sistema_interno(): void
    {
        $interno = Sistema::factory()->interno()->create(['nome' => 'AlfaMatriz']);

        $this->actingAs($this->admin())
            ->post(route('tarefas.store'), [
                'titulo' => 'Trocar o certificado do painel',
                'tipo' => 'operacional',
                'sistema_id' => $interno->id,
            ])
            ->assertRedirect();

        $this->assertSame($interno->id, Tarefa::sole()->sistema_id);
    }

    /**
     * As listas comerciais que oferecem sistema para VINCULAR não podem
     * oferecer interno: um cliente vinculado à Matriz entraria no fechamento
     * cobrando por um sistema que não se vende.
     */
    public function test_os_selects_comerciais_nao_oferecem_interno(): void
    {
        $this->produtoComReceita();
        Sistema::factory()->interno()->create(['nome' => 'AlfaMatriz']);

        $admin = $this->admin();

        foreach (['clientes.index', 'leads.index', 'cobrancas.create'] as $rota) {
            $sistemas = $this->actingAs($admin)->get(route($rota))->viewData('sistemas');

            $this->assertSame(
                ['AlfaGym'],
                $sistemas->pluck('nome')->all(),
                "A tela {$rota} está oferecendo sistema interno para vínculo comercial."
            );
        }
    }

    public function test_o_faturamento_nao_enxerga_o_interno(): void
    {
        $this->produtoComReceita();

        // O interno recebe cliente à força — é o cenário que o vazamento
        // produziria, e sem ele o `whereHas('clientes')` do faturamento já
        // esconderia o defeito por acidente.
        $interno = Sistema::factory()->interno()->create(['nome' => 'Infra e Deploy']);
        Cliente::first()->sistemas()->attach($interno->id, ['ativo' => true]);

        $resposta = $this->actingAs($this->admin())->get(route('faturamento.index'));

        $resposta->assertOk();

        // A asserção é sobre as LINHAS do ciclo, e não sobre o HTML: procurar o
        // nome na página inteira pega também o que está no menu e no rodapé, e
        // o teste passaria ou falharia por motivo alheio ao faturamento.
        $this->assertSame(
            ['AlfaGym'],
            collect($resposta->viewData('preview'))->flatMap(fn ($p) => $p['linhas']->pluck('sistema'))->unique()->values()->all()
        );
    }
}
