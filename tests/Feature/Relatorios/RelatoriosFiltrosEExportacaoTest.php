<?php

namespace Tests\Feature\Relatorios;

use App\Models\Cobranca;
use App\Models\Lead;
use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os filtros dos Relatórios cobrem os eixos que cada seção mostra, o recorte
 * ativo vira pílula nomeada, e exportar (CSV/PDF) leva o MESMO recorte da
 * tela — atrás de `relatorios,imprimir`, como todo download financeiro.
 */
class RelatoriosFiltrosEExportacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_filtro_de_vendedor_recorta_a_secao_comercial_e_vira_pilula(): void
    {
        $vera = User::factory()->create(['name' => 'Vera Vendedora']);
        $otto = User::factory()->create(['name' => 'Otto Vendedor']);

        foreach ([$vera, $otto] as $vendedor) {
            Lead::create([
                'nome' => 'Fechado de '.$vendedor->name, 'estagio' => 'cliente_ativo',
                'tipo_interesse' => 'saas', 'valor_estimado' => 500.00,
                'estagio_atualizado_em' => now(), 'vendedor_id' => $vendedor->id,
            ]);
        }

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'comercial', 'vendedor' => $vera->id]));

        $resposta->assertOk();
        $this->assertSame(1, $resposta->viewData('fechadosQtd'));
        $resposta->assertSee('Vendedor · Vera Vendedora');
    }

    public function test_o_filtro_de_sistema_recorta_as_conclusoes_do_desenvolvimento(): void
    {
        $gym = Sistema::create(['nome' => 'AlfaGym', 'slug' => 'alfagym', 'unidade_cobranca' => 'academia ativa', 'ativo' => true]);
        $control = Sistema::create(['nome' => 'AlfaControl', 'slug' => 'alfacontrol', 'unidade_cobranca' => 'empresa ativa', 'ativo' => true]);

        foreach ([$gym, $control] as $sistema) {
            $tarefa = Tarefa::factory()->create(['status' => 'concluida', 'sistema_id' => $sistema->id]);
            TarefaEvento::create([
                'tarefa_id' => $tarefa->id,
                'de_status' => 'pronta_producao', 'para_status' => 'concluida',
                'entrou_em' => now(),
            ]);
        }

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'desenvolvimento', 'sistema' => $gym->id]));

        $resposta->assertOk();
        $this->assertSame(1, $resposta->viewData('concluidasQtd'));
        $resposta->assertSee('Sistema · AlfaGym');
    }

    /**
     * O recorte financeiro vale para os títulos — e a tela DIZ que o caixa
     * não responde a ele, em vez de deixar os cards parecerem filtrados.
     */
    public function test_o_filtro_de_tipo_recorta_o_a_receber_e_avisa_que_o_caixa_nao_responde(): void
    {
        Cobranca::create([
            'descricao' => 'Locação', 'valor' => 100.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'status' => 'pendente', 'tipo' => 'locacao_sistema',
        ]);
        Cobranca::create([
            'descricao' => 'Direta', 'valor' => 200.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'status' => 'pendente', 'tipo' => 'direta',
        ]);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'financeiro', 'tipo_receita' => 'direta']));

        $resposta->assertOk();
        $this->assertEqualsWithDelta(200.0, (float) $resposta->viewData('aReceber')->total, 0.01);
        $resposta->assertSee('respondem só à competência');
        $resposta->assertSee('Tipo · Direta');
    }

    public function test_o_filtro_de_perfil_recorta_as_contas_da_secao_sistema(): void
    {
        User::factory()->create();           // admin
        User::factory()->membro()->create(); // membro

        $perfilAdmin = Perfil::where('slug', 'admin')->first();

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'sistema', 'perfil' => $perfilAdmin->id]));

        $resposta->assertOk();
        // Os dois admins (o observado e o que abriu a tela); o membro fica fora.
        $this->assertSame(2, $resposta->viewData('usuariosAtivos'));
        $resposta->assertSee('Perfil · Administrador');
    }

    /** Valor fora da lista volta como vazio — a tela corrige, não quebra. */
    public function test_filtro_malformado_e_ignorado_em_silencio(): void
    {
        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'comercial', 'vendedor' => 'abc', 'origem' => 'Telepatia']));

        $resposta->assertOk();
        $this->assertSame(['vendedor' => '', 'origem' => '', 'sistema' => ''], $resposta->viewData('filtros'));
    }

    public function test_o_csv_sai_com_os_indicadores_e_o_recorte_anunciado(): void
    {
        $vera = User::factory()->create(['name' => 'Vera Vendedora']);
        Lead::create([
            'nome' => 'Fechado', 'estagio' => 'cliente_ativo', 'tipo_interesse' => 'saas',
            'valor_estimado' => 500.00, 'estagio_atualizado_em' => now(), 'vendedor_id' => $vera->id,
        ]);
        Lead::create([
            'nome' => 'De outra pessoa', 'estagio' => 'cliente_ativo', 'tipo_interesse' => 'saas',
            'valor_estimado' => 900.00, 'estagio_atualizado_em' => now(),
        ]);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.exportar', ['secao' => 'comercial', 'formato' => 'csv', 'vendedor' => $vera->id]));

        $resposta->assertOk();
        $resposta->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $conteudo = $resposta->streamedContent();
        $this->assertStringContainsString('Relatório Comercial', $conteudo);
        $this->assertStringContainsString('Recorte;"Vendedor · Vera Vendedora"', $conteudo);
        // O recorte da tela é o do arquivo: só o fechado da Vera.
        $this->assertStringContainsString('"Fechados na competência";1', $conteudo);
    }

    /**
     * O caminho do arquivo passa pela PRÉVIA: o painel oferece Exportar e
     * Imprimir, os dois abrem o documento no navegador, e é lá que se escolhe
     * o formato — vendo exatamente o que vai sair.
     */
    public function test_exportar_e_imprimir_abrem_a_previa_do_documento(): void
    {
        $this->actingAs(User::factory()->create());

        $painel = $this->get(route('relatorios.index'));
        $painel->assertOk();
        $painel->assertSee('Exportar');
        $painel->assertSee('Imprimir');

        $previa = $this->get(route('relatorios.previa', ['secao' => 'comercial']));
        $previa->assertOk();
        $previa->assertSee('Relatório Comercial');
        $previa->assertSee('Prévia do arquivo');
        $previa->assertSee('Baixar CSV');
        $previa->assertSee('Baixar PDF');
        $previa->assertSee('Imprimir');
        // Sem `?imprimir`, a prévia só mostra — não dispara a impressão
        // sozinha (o `window.print()` do BOTÃO continua lá, à espera).
        $previa->assertDontSee("window.addEventListener('load'", escape: false);
    }

    public function test_o_botao_imprimir_abre_a_previa_ja_imprimindo(): void
    {
        $this->actingAs(User::factory()->create());

        $resposta = $this->get(route('relatorios.previa', ['secao' => 'financeiro', 'imprimir' => 1]));

        $resposta->assertOk();
        $resposta->assertSee("window.addEventListener('load', () => window.print())", escape: false);
    }

    /** A prévia carrega o recorte da URL, como os arquivos que ela oferece. */
    public function test_a_previa_leva_o_recorte_junto(): void
    {
        $vera = User::factory()->create(['name' => 'Vera Vendedora']);
        Lead::create([
            'nome' => 'Fechado', 'estagio' => 'cliente_ativo', 'tipo_interesse' => 'saas',
            'valor_estimado' => 500.00, 'estagio_atualizado_em' => now(), 'vendedor_id' => $vera->id,
        ]);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.previa', ['secao' => 'comercial', 'vendedor' => $vera->id]));

        $resposta->assertOk();
        $resposta->assertSee('Vendedor · Vera Vendedora');
    }

    public function test_o_pdf_sai_como_pdf_de_verdade(): void
    {
        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.exportar', ['secao' => 'financeiro', 'formato' => 'pdf']));

        $resposta->assertOk();
        $resposta->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $resposta->getContent());
    }

    /**
     * Exportar é mais que ler: quem tem `relatorios` só com `ler` abre a tela
     * mas não leva o arquivo — e o botão nem aparece para ela.
     */
    public function test_exportar_exige_imprimir(): void
    {
        $perfil = Perfil::create(['slug' => 'so-le-relatorios', 'nome' => 'Só lê relatórios']);
        $permissao = Permissao::firstOrCreate(['recurso' => 'relatorios'], ['descricao' => 'Relatórios']);
        $perfil->permissoes()->syncWithoutDetaching([
            $permissao->id => ['ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => false, 'excluir' => false],
        ]);
        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->attach($perfil->id);

        $this->actingAs($usuario);

        $tela = $this->get(route('relatorios.index'));
        $tela->assertOk();
        $tela->assertDontSee('Exportar');
        $tela->assertDontSee('Imprimir');

        $this->get(route('relatorios.exportar', ['secao' => 'comercial', 'formato' => 'csv']))
            ->assertForbidden();
        // A prévia é o próprio documento — fica atrás da mesma porta.
        $this->get(route('relatorios.previa', ['secao' => 'comercial']))
            ->assertForbidden();
    }
}
