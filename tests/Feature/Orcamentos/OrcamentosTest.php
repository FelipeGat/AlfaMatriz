<?php

namespace Tests\Feature\Orcamentos;

use App\Models\Documento;
use App\Models\DocumentoOperacao;
use App\Models\DocumentoPasta;
use App\Models\Orcamento;
use App\Models\Perfil;
use App\Models\User;
use App\Services\Orcamentos\Pdf;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Comercial › Orçamentos (docs/specs/orcamentos-vixcard). */
class OrcamentosTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;
    private User $victor;
    private User $rossini;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-19 10:00', 'America/Sao_Paulo'));
        Storage::fake(Pdf::DISCO);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['name' => 'Felipe', 'email' => 'felipehenriquegat@gmail.com']);
        $this->rossini = User::factory()->create(['name' => 'Rossini']);
        $this->rossini->perfis()->syncWithoutDetaching([Perfil::where('slug', 'admin')->value('id')]);
        $this->victor = User::factory()->semPerfil()->create(['name' => 'Victor', 'email' => 'victor@vixcard.com.br']);
        $this->victor->perfis()->attach(Perfil::where('slug', 'vixcard')->value('id'));
        $perfil = Perfil::where('slug', 'orcamentos')->firstOrFail();
        $perfil->users()->syncWithoutDetaching([$this->felipe->id, $this->victor->id]);
        // O Chromium de mentira: grava um PDF mínimo com o HTML dentro, para conferir o que foi impresso.
        $this->app->bind(Pdf::class, fn () => new class extends Pdf {
            protected function imprimir(string $html, string $pdf): void
            {
                file_put_contents($pdf, "%PDF-1.4\n".file_get_contents($html));
            }
        });
    }

    private function dados(array $extra = []): array
    {
        return array_replace([
            'produto' => 'vix-id', 'cliente' => 'Marmoraria Lorenzo', 'contato' => 'Lorenzo', 'cidade' => 'Cariacica · ES', 'validade_dias' => 15,
            'titulo' => 'Controle de acesso', 'destaque' => 'Vix-ID', 'lead' => 'Portaria com RFID.', 'entrada' => 10000, 'parcelas' => 10, 'desconto_avista' => 5,
            'inclui' => "Portal RFID\nTreinamento", 'nao_inclui' => 'Obras civis', 'passos' => "Aprovação\nInstalação", 'aceite' => 1,
            'itens' => [
                ['descricao' => 'Portal RFID UHF', 'quantidade' => 1, 'unidade' => 'un', 'valor_unitario' => 24700, 'cobranca' => 'unico'],
                ['descricao' => 'Etiquetas UHF', 'quantidade' => 300, 'unidade' => 'un', 'valor_unitario' => 15, 'cobranca' => 'unico'],
                ['descricao' => '', 'quantidade' => 1, 'unidade' => 'un', 'valor_unitario' => 999, 'cobranca' => 'unico'],
                ['descricao' => 'Painel Vix-ID', 'quantidade' => 1, 'unidade' => 'mês', 'valor_unitario' => 1399, 'cobranca' => 'mensal'],
            ],
        ], $extra);
    }

    public function test_so_felipe_e_victor_admin_nao(): void
    {
        $this->actingAs($this->felipe)->get(route('orcamentos.index'))->assertOk()->assertSee('Novo orçamento');
        $this->actingAs($this->victor)->get(route('orcamentos.index'))->assertOk();
        $this->actingAs($this->victor)->get(route('vixcard.projetos'))->assertSee(route('orcamentos.index'), false);
        $this->actingAs($this->rossini)->get(route('orcamentos.index'))->assertForbidden();
        $this->actingAs($this->rossini)->get(route('tarefas.index'))->assertDontSee(route('orcamentos.index'), false);
    }

    public function test_cria_numera_totaliza_e_gera_o_pdf_padrao(): void
    {
        $this->actingAs($this->felipe)->get(route('orcamentos.create', ['produto' => 'cartoes-nfc']))->assertOk()
            ->assertSee('NTAG213 personalizado')->assertSee('Salvar e ver o PDF')->assertSee('2 · Itens &amp; valores', false);
        $this->post(route('orcamentos.store'), $this->dados())->assertRedirect();
        $o = Orcamento::sole();
        $this->assertSame([2026, 1, 1, 'rascunho'], [$o->ano, $o->numero, $o->revisao, $o->situacao]);
        $this->assertSame([29200.0, 1399.0, 3], [$o->total_unico, $o->total_mensal, $o->itens()->count()], 'linha sem descrição não entra');
        $this->assertSame('Entrada de R$ 10.000,00 e saldo em 10x de R$ 1.920,00 sem juros, ou à vista com 5% de desconto: R$ 27.740,00. Mensalidade de R$ 1.399,00 a partir da instalação.', $o->condicoes());
        $this->assertSame('001', $o->codigo());

        $this->post(route('orcamentos.store'), $this->dados(['cliente' => 'Jumper Four']));
        $this->assertSame(2, Orcamento::where('cliente', 'Jumper Four')->value('numero'));

        $pdf = $this->get(route('orcamentos.pdf', $o))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $conteudo = $pdf->streamedContent();
        $this->assertStringStartsWith('%PDF', $conteudo);
        $this->assertStringNotContainsString('VixCard ·', $conteudo, 'toda proposta sai como VixSoftHouse');
        foreach (['SoftHouse</b>', 'Marmoraria Lorenzo', 'Orçamento 001', 'R$ 29.200,00', 'Painel Vix-ID', 'Proposta válida até 03/11/2026', 'De acordo', '(27) 3335-4950'] as $trecho) {
            $this->assertStringContainsString($trecho, $conteudo);
        }
        $this->assertSame('orcamentos/2026/PROPOSTA_001_MARMORARIA_LORENZO.pdf', $o->fresh()->pdf_arquivo);
    }

    public function test_previa_usa_o_mesmo_modelo_sem_gravar(): void
    {
        $this->actingAs($this->felipe)->post(route('orcamentos.previa'), $this->dados(['cliente' => 'Padaria Monte Líbano']))
            ->assertOk()->assertSee('Padaria Monte Líbano')->assertSee('Rascunho')->assertSee('R$ 29.200,00');
        $this->assertSame(0, Orcamento::count());
    }

    public function test_enviado_congela_guarda_em_documentos_e_so_se_revisa(): void
    {
        $pasta = DocumentoPasta::create(['user_id' => $this->felipe->id, 'nome' => 'VixCard']);
        $pasta->pessoas()->attach($this->victor->id);
        $this->actingAs($this->felipe)->post(route('orcamentos.store'), $this->dados());
        $o = Orcamento::sole();

        $this->actingAs($this->victor)->put(route('orcamentos.update', $o), $this->dados(['depois' => 'enviar']))->assertRedirect(route('orcamentos.edit', $o));
        $o->refresh();
        $this->assertSame('enviado', $o->situacao);
        $this->assertNotNull($o->enviado_em);
        $op = DocumentoOperacao::sole();
        $this->assertSame(['enviar', 'Novos_Projetos/Orçamentos/Clientes/Marmoraria Lorenzo/PROPOSTA_001_MARMORARIA_LORENZO.pdf', $this->victor->id],
            [$op->tipo, $op->caminho, $op->user_id]);
        Storage::disk(Documento::DISCO)->assertExists($op->arquivo);

        $this->actingAs($this->felipe)->put(route('orcamentos.update', $o), $this->dados(['cliente' => 'Outro']))->assertSessionHasErrors('situacao');
        $this->assertSame('Marmoraria Lorenzo', $o->fresh()->cliente);

        $this->post(route('orcamentos.revisar', $o))->assertRedirect();
        $rev = Orcamento::where('revisao', 2)->sole();
        $this->assertSame([1, 'rascunho', $o->id, 3], [$rev->numero, $rev->situacao, $rev->revisao_de_id, $rev->itens()->count()]);
        $this->assertSame(['substituido', $rev->id], [$o->fresh()->situacao, $o->fresh()->substituido_por_id]);
        $this->assertSame('001.Rev2', $rev->codigo());
        $this->get(route('orcamentos.index'))->assertSee('001.Rev2')->assertSee(route('orcamentos.edit', $rev), false)
            ->assertSee(route('orcamentos.edit', $o), false); // a Rev1 continua acessível pela linha da vigente
        $this->get(route('orcamentos.index', ['situacao' => 'substituido']))->assertSee(route('orcamentos.edit', $o), false);
    }

    public function test_responder_duplicar_e_apagar(): void
    {
        $this->actingAs($this->felipe)->post(route('orcamentos.store'), $this->dados());
        $o = Orcamento::sole();
        $this->post(route('orcamentos.resposta', $o), ['resposta' => 'aprovado'])->assertSessionHasErrors('situacao');
        $this->post(route('orcamentos.enviado', $o))->assertRedirect();
        $this->assertNull(DocumentoOperacao::first(), 'sem a pasta VixCard, só não guarda em Documentos');
        $this->post(route('orcamentos.resposta', $o), ['resposta' => 'aprovado'])->assertSessionHas('status');
        $this->assertSame('aprovado', $o->fresh()->situacao);

        $this->post(route('orcamentos.duplicar', $o))->assertRedirect();
        $copia = Orcamento::where('numero', 2)->sole();
        $this->assertSame(['rascunho', 'Marmoraria Lorenzo (cópia)', 3], [$copia->situacao, $copia->cliente, $copia->itens()->count()]);
        $this->delete(route('orcamentos.destroy', $copia))->assertRedirect(route('orcamentos.index'));
        $this->assertSame(1, Orcamento::count());
        $this->delete(route('orcamentos.destroy', $o))->assertStatus(422);
    }

    public function test_enviar_sem_valor_e_recusado(): void
    {
        $this->actingAs($this->felipe)->post(route('orcamentos.store'), $this->dados(['itens' => [['descricao' => 'Item', 'quantidade' => 1, 'valor_unitario' => 0, 'cobranca' => 'unico']]]));
        $this->post(route('orcamentos.enviado', Orcamento::sole()))->assertSessionHasErrors('itens');
        $this->assertSame('rascunho', Orcamento::sole()->situacao);
    }
}
