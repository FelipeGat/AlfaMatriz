<?php

namespace Tests\Feature\Orcamentos;

use App\Models\Lead;
use App\Models\Orcamento;
use App\Models\Perfil;
use App\Models\Sistema;
use App\Models\User;
use App\Services\Orcamentos\Historico;
use App\Services\Orcamentos\Pdf;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Orçamentos, etapa 2 (docs/specs/orcamentos-vixcard/padrao-e-historico.md). */
class PadraoEHistoricoTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;
    private Lead $direct;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'America/Sao_Paulo'));
        Storage::fake(Pdf::DISCO);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['name' => 'Felipe', 'email' => 'felipehenriquegat@gmail.com']);
        Perfil::where('slug', 'orcamentos')->firstOrFail()->users()->syncWithoutDetaching([$this->felipe->id]);
        $vixId = Sistema::factory()->create(['nome' => 'Vix-ID', 'slug' => 'vix-id', 'natureza' => 'vixcard', 'categoria' => null, 'unidade_cobranca' => null]);
        $this->direct = Lead::create(['nome' => 'Direct Comércio Internacional Ltda. (Lourenço Roldi)', 'cpf_cnpj' => '27.458.553/0001-49', 'telefone' => '(27) 99229-4882',
            'email' => 'lourenco@direct-vix.com.br', 'sistema_id' => $vixId->id, 'tipo_interesse' => 'saas', 'estagio' => 'proposta', 'vendedor_id' => $this->felipe->id]);
    }

    private function proposta(array $extra = []): array
    {
        return array_replace_recursive([
            'arquivo' => 'Lorenzo/PROPOSTA_VIXID_MARMORARIA.pdf', 'lead' => 'Direct Comércio Internacional Ltda. (Lourenço Roldi)', 'sistema' => 'vix-id',
            'grupo' => 'lorenzo', 'ordem_no_grupo' => 1, 'situacao' => 'substituido', 'data' => '2026-09-29',
            'capa' => ['tipo' => 'Proposta comercial', 'revisao' => 1, 'titulo' => 'Controle de saída', 'destaque' => 'Vix-ID', 'subtitulo' => 'Portal RFID no portão.', 'rotulo_3' => 'Sistema', 'valor_3' => 'VIX-ID Patrimônio'],
            'cliente' => ['nome' => 'Marmoraria', 'contato' => 'Lorenzo', 'cidade_uf' => 'Serra/ES', 'cnpj' => null],
            'natureza' => 'venda', 'objeto' => ['Fornecimento do portal', 'Implantação'],
            'secoes' => ['desafio' => ['titulo' => 'O desafio', 'texto' => 'Peças saem sem registro.', 'citacao' => 'Perdemos peças', 'numeros' => [['valor' => '30%', 'rotulo' => 'de perdas']], 'itens' => ['Sem controle']],
                'perguntas' => [['pergunta' => 'Precisa de internet?', 'resposta' => 'Sim, para sincronizar.']],
                'contracapa' => ['frase' => 'Obrigado.', 'mensagem' => 'Lourenço, seguimos à disposição.'],
                'extras' => [['titulo' => 'Gestão Têxtil', 'texto' => 'Como foi enviado.'], ['titulo' => 'Resumo', 'texto' => "| DESCRIÇÃO | VALOR |\n|---|---|\n| Implantação | R$ 32.700,00 |"]]],
            'itens' => [['descricao' => 'Portal RFID', 'quantidade' => 1, 'unidade' => 'un', 'valor_unitario' => 29800, 'cobranca' => 'unico'],
                ['descricao' => 'Mensalidade', 'quantidade' => 1, 'unidade' => 'mês', 'valor_unitario' => 1399, 'cobranca' => 'mensal']],
            'planos' => [['nome' => 'Anual', 'valor' => 350, 'periodo' => 'mês', 'observacao' => null]],
            'inclui' => ['Treinamento'], 'nao_inclui' => ['Obra civil'],
            'pagamento' => ['entrada' => null, 'parcelas' => null, 'desconto_avista' => null, 'texto' => '50% na aprovação e 50% na entrega.'],
            'validade_dias' => null, 'aceite' => true, 'observacoes' => 'v1 superada pela Rev. 03.',
        ], $extra);
    }

    public function test_importa_o_historico_encadeado_com_o_original_e_sem_duplicar(): void
    {
        $pasta = sys_get_temp_dir().'/historico-'.uniqid();
        mkdir("{$pasta}/Lorenzo", 0777, true);
        file_put_contents("{$pasta}/Lorenzo/PROPOSTA_VIXID_MARMORARIA.pdf", "%PDF-1.4 v1");
        file_put_contents("{$pasta}/Lorenzo/PROPOSTA_VIXID_LORENZO.pdf", "%PDF-1.4 rev03");
        $propostas = [$this->proposta(),
            $this->proposta(['arquivo' => 'Lorenzo/PROPOSTA_VIXID_LORENZO.pdf', 'ordem_no_grupo' => 2, 'situacao' => 'enviado', 'data' => '2026-10-13',
                'itens' => [['descricao' => 'Portal RFID', 'quantidade' => 1, 'unidade' => 'un', 'valor_unitario' => 32700, 'cobranca' => 'unico']]])];

        app(Historico::class)->importar($propostas, $pasta, $this->felipe);
        app(Historico::class)->importar($propostas, $pasta, $this->felipe); // de novo: não duplica

        $this->assertSame(2, Orcamento::count());
        [$v1, $rev] = [Orcamento::where('revisao', 1)->sole(), Orcamento::where('revisao', 2)->sole()];
        $this->assertSame([$v1->numero, 'substituido', $rev->id], [$rev->numero, $v1->situacao, $v1->substituido_por_id]);
        $this->assertSame(['enviado', $v1->id, $this->direct->id, 32700.0], [$rev->situacao, $rev->revisao_de_id, $rev->lead_id, $rev->total_unico]);
        $this->assertNull($rev->validade_dias, 'o original não tinha validade');
        $this->assertSame('v1 superada pela Rev. 03.', $v1->notas_internas);

        $this->assertSame('%PDF-1.4 rev03', Storage::disk(Pdf::DISCO)->get($rev->pdf_original));
    }

    public function test_pdf_no_padrao_traz_capa_secoes_e_esconde_as_notas_internas(): void
    {
        app(Historico::class)->importar([$this->proposta()], sys_get_temp_dir(), $this->felipe);
        $html = app(Pdf::class)->html(Orcamento::with('itens')->sole());

        foreach (['Orçamento 001', 'Preparado para', 'Clientes que atendemos', 'CEDTEC', 'VIX-ID Patrimônio', 'Cliente e objeto', 'Fornecimento do portal', 'O desafio', '“Perdemos peças”',
            '30%', 'Precisa de internet?', 'Gestão Têxtil', 'Como foi enviado.', 'Lourenço, seguimos à disposição.', '50% na aprovação e 50% na entrega.', 'Anual'] as $esperado) {
            $this->assertStringContainsString($esperado, $html);
        }
        $this->assertStringNotContainsString('v1 superada', $html, 'nota interna nunca vai ao PDF');
        $this->assertStringContainsString('<th>DESCRIÇÃO</th>', $html, 'tabela de seção livre vira tabela');
        $this->assertStringNotContainsString('|---|', $html);
        $this->assertSame('Controle de saída', \App\Services\Orcamentos\Historico::semDestaque('Controle de saída Vix-ID', 'Vix-ID'));
        $this->assertStringNotContainsString('Proposta válida até', $html, 'sem validade, sem a frase');
    }

    public function test_lista_por_cliente_e_editor_com_pre_cliente(): void
    {
        app(Historico::class)->importar([$this->proposta(['situacao' => 'enviado'])], sys_get_temp_dir(), $this->felipe);
        $this->actingAs($this->felipe)->get(route('orcamentos.index'))->assertOk()
            ->assertSee('Direct Comércio Internacional Ltda. (Lourenço Roldi)')->assertSee('Vix-ID')->assertSee('Abrir no Funil de Vendas')->assertDontSee('orcamentos/1/original');

        // Novo orçamento a partir do pré-cliente: o editor já traz o que ele tem.
        $this->get(route('orcamentos.create', ['lead' => $this->direct->id]))->assertOk()->assertSee('27.458.553', false)->assertSee('Pré-cliente (Funil de Vendas)');

        // A prévia monta a seção ligada enquanto se digita.
        $this->post(route('orcamentos.previa'), ['produto' => 'vix-id', 'cliente' => 'Direct', 'titulo' => 'Portal', 'parcelas' => 1, 'lead_id' => $this->direct->id,
            'dados_cliente' => ['cnpj' => '27.458.553/0001-49'], 'secoes' => ['solucao' => ['ativo' => '1', 'titulo' => 'Como resolvemos', 'texto' => 'Antenas no portão.']]])
            ->assertOk()->assertSee('Como resolvemos')->assertSee('Antenas no portão.')->assertSee('27.458.553/0001-49');
        // Desligada, não sai.
        $this->post(route('orcamentos.previa'), ['produto' => 'vix-id', 'cliente' => 'Direct', 'titulo' => 'Portal', 'parcelas' => 1,
            'secoes' => ['solucao' => ['ativo' => '0', 'titulo' => 'Como resolvemos', 'texto' => 'Antenas no portão.']]])
            ->assertOk()->assertDontSee('Como resolvemos');
    }

    /** Faixas, opcionais e "sob consulta" saem com o preço, fora do total (20/10/2026). */
    public function test_opcao_fica_fora_do_total(): void
    {
        $this->actingAs($this->felipe)->post(route('orcamentos.store'), ['produto' => 'vixlink', 'cliente' => 'Clube', 'titulo' => 'Cartão', 'parcelas' => 1, 'validade_dias' => 15,
            'itens' => [['descricao' => 'Implantação', 'quantidade' => 1, 'unidade' => 'un', 'valor_unitario' => 290, 'cobranca' => 'unico'],
                ['descricao' => 'Cartões — 1.000 unidades', 'quantidade' => 1000, 'unidade' => 'un', 'valor_unitario' => 14.9, 'cobranca' => 'opcao'],
                ['descricao' => 'Leitor USB', 'quantidade' => 2, 'unidade' => 'un', 'valor_unitario' => 0, 'cobranca' => 'opcao']]])->assertRedirect();
        $o = Orcamento::with('itens')->sole();
        $this->assertSame([290.0, 0.0], [$o->total_unico, $o->total_mensal]);
        $html = app(Pdf::class)->html($o);
        $this->assertStringContainsString('Opcionais e faixas (fora do total)', $html);
        $this->assertStringContainsString('sob consulta', $html);
        $this->assertStringContainsString('R$ 14.900,00', $html);
    }

    /** Material, implantação e recorrente separados, cada um com subtotal (20/10/2026). */
    public function test_material_implantacao_e_recorrente_separados(): void
    {
        $this->actingAs($this->felipe)->post(route('orcamentos.store'), ['produto' => 'vix-id', 'cliente' => 'Direct', 'titulo' => 'Portal', 'parcelas' => 1, 'validade_dias' => 15,
            'itens' => [['descricao' => 'Coletor C72', 'quantidade' => 1, 'unidade' => 'un', 'valor_unitario' => 6500, 'categoria' => 'material'],
                ['descricao' => 'Etiquetas', 'quantidade' => 1000, 'unidade' => 'un', 'valor_unitario' => 0.59, 'categoria' => 'material'],
                ['descricao' => 'Implantação e treinamento', 'quantidade' => 1, 'unidade' => 'serv', 'valor_unitario' => 4900, 'categoria' => 'implantacao'],
                ['descricao' => 'Mensalidade Vix-ID', 'quantidade' => 1, 'unidade' => 'mês', 'valor_unitario' => 790, 'categoria' => 'recorrente'],
                ['descricao' => 'Portal RFID', 'quantidade' => 1, 'unidade' => 'un', 'valor_unitario' => 11500, 'categoria' => 'material', 'opcional' => 1]]])->assertRedirect();
        $o = Orcamento::with('itens')->sole();
        $this->assertSame([7090.0, 4900.0, 11990.0, 790.0], [$o->total_material, $o->total_implantacao, $o->total_unico, $o->total_mensal]);
        $html = app(Pdf::class)->html($o);
        foreach (['Material e equipamentos', 'Implantação e serviços', 'Recorrente', 'Subtotal material e equipamentos', 'R$ 7.090,00', 'R$ 4.900,00', 'R$ 790,00/mês', 'R$ 11.990,00'] as $esperado) {
            $this->assertStringContainsString($esperado, $html);
        }
        // Formulário antigo (só a cobrança) ainda funciona, com a categoria provável.
        $this->assertSame('implantacao', \App\Models\OrcamentoItem::categoriaProvavel('Implantação do sistema'));
        $this->assertSame('recorrente', \App\Models\OrcamentoItem::categoriaProvavel('Licença', 'mensal'));
        $this->assertSame('material', \App\Models\OrcamentoItem::categoriaProvavel('Leitor de mão Chainway C72'));
    }

    /** A capa elaborada: foto do orçamento (ou a padrão), clientes atendidos e emissor dos ajustes. */
    public function test_capa_com_foto_ajustes_e_revisao_de_rascunho(): void
    {
        $this->actingAs($this->felipe)->post(route('orcamentos.store'), ['produto' => 'vix-id', 'cliente' => 'Direct', 'titulo' => 'Portal', 'parcelas' => 1, 'validade_dias' => 15,
            'capa' => \Illuminate\Http\UploadedFile::fake()->image('capa.jpg', 1200, 1700)])->assertRedirect();
        $o = Orcamento::sole();
        Storage::disk(Pdf::DISCO)->assertExists($o->capa_imagem);
        $this->assertStringContainsString('file://', app(Pdf::class)->html($o), 'no PDF a foto vem do disco');
        $this->get(route('orcamentos.capa', $o))->assertOk();

        // Ajustes: o emissor e os clientes saem na proposta; vazio não sai.
        $this->post(route('orcamentos.ajustes.salvar'), ['emissor_nome' => 'VixSoftHouse', 'emissor_cnpj' => '12.345.678/0001-90', 'clientes' => "## Saúde\nHospital Teste"])->assertRedirect();
        $html = app(Pdf::class)->html($o->fresh());
        $this->assertStringContainsString('CNPJ 12.345.678/0001-90', $html);
        $this->assertStringContainsString('Hospital Teste', $html);
        $this->assertStringNotContainsString('CEDTEC', $html);
        $this->get(route('orcamentos.ajustes'))->assertOk()->assertSee('Clientes que atendemos');

        // Rascunho também se revisa, sem perder a versão anterior: 001 → 001.Rev2.
        $this->post(route('orcamentos.revisar', $o))->assertRedirect();
        $this->assertSame(['substituido', '001.Rev2'], [$o->fresh()->situacao, Orcamento::where('revisao', 2)->sole()->codigo()]);
    }

    /** O painel (20/10/2026): indicadores do período sobre as vigentes, e a negociação entre o envio e a resposta. */
    public function test_painel_com_indicadores_e_negociacao(): void
    {
        $pasta = sys_get_temp_dir();
        app(Historico::class)->importar([$this->proposta(), $this->proposta(['arquivo' => 'Lorenzo/PROPOSTA_VIXID_LORENZO.pdf', 'ordem_no_grupo' => 2, 'situacao' => 'enviado', 'data' => '2026-10-13'])], $pasta, $this->felipe);
        $rev = Orcamento::where('revisao', 2)->sole();
        $this->actingAs($this->felipe)->post(route('orcamentos.resposta', $rev), ['resposta' => 'negociacao'])->assertRedirect();
        $this->assertSame('negociacao', $rev->fresh()->situacao);

        $tela = $this->get(route('orcamentos.index', ['periodo' => '30']))->assertOk()->assertSee('Total no período')->assertSee('Evolução dos orçamentos')->assertSee('Top clientes');
        $p = $tela->viewData('painel');
        $this->assertSame(1, $p['total']['qtd'], 'a revisão substituída não conta duas vezes');
        $this->assertSame(1, $p['negociacao']['qtd']);
        $this->assertSame(29800.0, $p['negociacao']['valor']);

        $this->post(route('orcamentos.resposta', $rev), ['resposta' => 'aprovado'])->assertRedirect();
        $this->assertSame(1, $this->get(route('orcamentos.index'))->viewData('painel')['aprovado']['qtd']);
    }
}
