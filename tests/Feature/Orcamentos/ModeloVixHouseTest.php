<?php

namespace Tests\Feature\Orcamentos;

use App\Models\Orcamento;
use App\Models\Perfil;
use App\Models\User;
use App\Services\Orcamentos\Historico;
use App\Services\Orcamentos\Modelos;
use App\Services\Orcamentos\Pdf;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** O modelo VixHouse do PDF (docs/specs/orcamentos-vixcard/modelo-vixhouse.md). */
class ModeloVixHouseTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-25 10:00', 'America/Sao_Paulo'));
        Storage::fake(Pdf::DISCO);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['name' => 'Felipe', 'email' => 'felipehenriquegat@gmail.com']);
        Perfil::where('slug', 'orcamentos')->firstOrFail()->users()->syncWithoutDetaching([$this->felipe->id]);
    }

    /** O orçamento da Inove (proposta conceito v4), resumido: dor em tabela, fluxo, planos e implantação em 10x. */
    private function inove(array $extra = []): array
    {
        return array_replace_recursive([
            'produto' => 'gestor', 'cliente' => 'Inove Audiovisual', 'contato' => 'Gustavo', 'cidade' => 'Vila Velha/ES', 'validade_dias' => 15,
            'tipo' => 'Proposta conceito', 'rotulo_3' => 'Sistema', 'valor_3' => 'Gestor de Eventos',
            'titulo' => 'Gestor de Eventos sob medida para a', 'destaque' => 'Inove Audiovisual', 'lead' => 'Prévia visual de todas as telas.',
            'parcelas' => 10, 'entrada' => 0, 'aceite' => 1,
            'dados_cliente' => ['razao_social' => 'INOVE AUDIOVISUAL LTDA ME', 'cnpj' => '17.516.520/0001-54'],
            'inclui' => "Agenda: dia, semana e mês\nOrçamentos: itens e diárias", 'nao_inclui' => 'Emissão de nota fiscal e boletos',
            'passos' => "Aprovação do conceito: a Inove revisa as telas\nEscopo e aceite: escopo e prazo fechados",
            'planos' => "Anual | 350 | mês por CNPJ | Pagamento anual: R$ 4.200,00 por CNPJ ao ano\nMensal | 399 | mês por CNPJ | Pagamento mensal",
            'notas_internas' => 'Resumo para 3 CNPJs ainda a confirmar.',
            'secoes' => [
                'desafio' => ['ativo' => '1', 'titulo' => 'O que a operação da Inove pede', 'texto' => 'Vários eventos ao mesmo tempo.',
                    'itens' => "# Ponto | O que vimos | O que isso pode causar | Como o Gestor de Eventos resolve\nMuitos eventos no mesmo dia | Seis frentes no sábado | Ler o documento inteiro | Agenda por dia, semana e mês"],
                'solucao' => ['ativo' => '1', 'titulo' => 'Um gestor do tamanho da operação', 'passos' => "1. Orçamento: itens, diárias e condições\nAgenda: montagem, evento e desmontagem\nFinanceiro: resultado do evento"],
            ],
            'itens' => [
                ['descricao' => 'Implantação e treinamento', 'quantidade' => 1, 'unidade' => 'serv', 'valor_unitario' => 2900, 'categoria' => 'implantacao'],
                ['descricao' => 'Leitor de etiquetas', 'quantidade' => 1, 'unidade' => 'un', 'valor_unitario' => 0, 'categoria' => 'material', 'opcional' => 1],
            ],
        ], $extra);
    }

    public function test_sistema_nasce_no_vixhouse_e_cartao_no_classico(): void
    {
        $this->assertSame('vixhouse', Modelos::modeloDoPdf('alfacontrol'));
        $this->assertSame('classico', Modelos::modeloDoPdf('cartoes-nfc'));
        $this->actingAs($this->felipe)->get(route('orcamentos.create', ['produto' => 'gestor']))->assertOk()->assertSee('Modelo do PDF')->assertSee('VixHouse · sistemas');

        // Sem a escolha no formulário, vale o do produto.
        $this->post(route('orcamentos.store'), $this->inove())->assertSessionHasNoErrors()->assertRedirect();
        // O cartão NFC saiu do catálogo em 31/10/2026 (produto da VixCard): já não se cria orçamento dele.
        $this->post(route('orcamentos.store'), $this->inove(['produto' => 'cartoes-nfc', 'cliente' => 'Clube']))->assertSessionHasErrors('produto');
        $this->assertSame(['vixhouse'], Orcamento::orderBy('id')->pluck('modelo')->all());

        // O histórico importado foi enviado no clássico; o levantamento pode pedir o VixHouse.
        $base = ['grupo' => 'g', 'ordem_no_grupo' => 1, 'data' => '2026-10-25', 'situacao' => 'rascunho', 'sistema' => 'gestor-vixcard', 'capa' => ['titulo' => 'Gestor'], 'cliente' => ['nome' => 'X']];
        app(Historico::class)->importar([$base + ['arquivo' => 'a.pdf'], ['grupo' => 'h'] + $base + ['arquivo' => 'b.pdf', 'modelo' => 'vixhouse']], sys_get_temp_dir(), $this->felipe);
        $this->assertSame(['classico', 'vixhouse'], Orcamento::whereNotNull('origem_arquivo')->orderBy('origem_arquivo')->pluck('modelo')->all());
    }

    public function test_pdf_vixhouse_traz_dor_fluxo_telas_valores_e_parecer(): void
    {
        $this->actingAs($this->felipe)->post(route('orcamentos.store'), $this->inove() + [
            'imagens_novas' => [UploadedFile::fake()->image('dashboard.jpg', 1600, 900), UploadedFile::fake()->image('agenda.jpg', 1600, 900), UploadedFile::fake()->image('os.jpg', 1600, 900)],
            'imagens_novas_onde' => 'telas',
        ])->assertRedirect();
        $o = Orcamento::with('itens')->sole();
        $this->assertCount(3, $o->imagensDoCorpo());
        $html = app(Pdf::class)->html($o);

        foreach ([
            'vixhouse-wordmark-claro.png', 'Proposta conceito · Rev. 01', 'Inove Audiovisual', 'Gestor de Eventos', // capa, com o sistema da 3ª coluna
            'O que vimos', 'Como o Gestor de Eventos resolve', 'Seis frentes no sábado',                       // a dor em tabela
            'Orçamento</b>', 'itens, diárias e condições',                                                     // fluxo sem o "1." repetido
            'Tela ilustrativa · dados fictícios', 'As telas desta proposta',                                  // telas com selo e índice
            'R$ 350,00', 'por mês por CNPJ', 'Em até 10x de R$ 290,00 sem juros', 'Opcionais · fora do total', 'Sob consulta',
            'Parecer do cliente', 'Plano pretendido', 'INOVE AUDIOVISUAL LTDA ME', 'Diretor Comercial', '(27) 3335-4950',
        ] as $esperado) {
            $this->assertStringContainsString($esperado, $html);
        }
        $this->assertStringNotContainsString('1. Orçamento', $html);
        $this->assertStringNotContainsString('SoftHouse', $html);
        $this->assertStringNotContainsString('Felipe', $html, 'assinatura só como Diretor Comercial');
        $this->assertStringNotContainsString('3 CNPJs ainda a confirmar', $html, 'nota interna nunca vai ao PDF');
        // A capa usa a primeira tela quando não há foto; a página de telas numera e leva o rodapé de dados fictícios.
        $this->assertStringContainsString('nomes, valores e quantidades das telas são fictícios', $html);
    }

    public function test_condicao_so_no_texto_nao_vira_a_vista_e_o_modelo_se_troca_e_se_copia(): void
    {
        $this->actingAs($this->felipe)->post(route('orcamentos.store'), $this->inove(['parcelas' => 1, 'pagamento_texto' => 'Entrada de 30% e saldo em até 6x sem juros.']))->assertRedirect();
        $o = Orcamento::with('itens')->sole();
        $html = app(Pdf::class)->html($o);
        $this->assertStringContainsString('Conforme a condição abaixo', $html);
        $this->assertStringNotContainsString('À vista', $html);

        // Trocar para o clássico no editor muda o desenho; a revisão e a cópia levam o modelo junto.
        $this->put(route('orcamentos.update', $o), $this->inove(['modelo' => 'classico']))->assertRedirect();
        $this->assertStringContainsString('SoftHouse</b>', app(Pdf::class)->html($o->fresh()->load('itens')));
        $this->post(route('orcamentos.revisar', $o))->assertRedirect();
        $this->post(route('orcamentos.duplicar', $o))->assertRedirect();
        $this->assertSame(['classico'], Orcamento::pluck('modelo')->unique()->values()->all());
        // Formulário sem o campo não apaga o modelo.
        $nova = Orcamento::where('revisao', 2)->sole();
        $this->put(route('orcamentos.update', $nova), collect($this->inove())->except('modelo')->all())->assertRedirect();
        $this->assertSame('classico', $nova->fresh()->modelo);
    }
}
