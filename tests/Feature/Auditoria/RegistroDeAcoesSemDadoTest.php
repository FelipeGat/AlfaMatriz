<?php

namespace Tests\Feature\Auditoria;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\CobrancaAnexo;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Os fatos que não mexem em cadastro nenhum.
 *
 * São os que o trait de auditoria jamais veria: o arquivo que sai daqui e passa
 * a existir na máquina de quem baixou, o fechamento do mês, a licença que mora
 * num pivô. Sem gravação explícita, o sistema registraria com perfeição tudo o
 * que se EDITA e nada do que se FAZ.
 */
class RegistroDeAcoesSemDadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['name' => 'Camila']);
    }

    public function test_baixar_anexo_de_receita_deixa_linha(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('anexos/boleto.pdf', 'conteúdo');

        $cobranca = Cobranca::create([
            'descricao' => 'Mensalidade agosto',
            'valor' => 500,
            'status' => 'pendente',
            'data_vencimento' => '2026-08-15',
        ]);

        $anexo = CobrancaAnexo::create([
            'cobranca_id' => $cobranca->id,
            'tipo' => 'boleto',
            'nome_original' => 'boleto-agosto.pdf',
            'nome_arquivo' => 'boleto.pdf',
            'caminho' => 'anexos/boleto.pdf',
            'tamanho' => 8,
        ]);

        $this->actingAs($this->admin())
            ->get(route('cobrancas.anexos.download', $anexo))
            ->assertOk();

        $linha = Auditoria::where('acao', 'baixou')->sole();

        // Boleto traz conta bancária e CNPJ. "Quem levou isto embora, e quando"
        // é pergunta que alguém vai fazer — e a resposta só existe se for
        // gravada no momento.
        $this->assertSame('Camila', $linha->usuario_nome);
        $this->assertSame('cobrancas', $linha->recurso);
        $this->assertSame('boleto-agosto.pdf', $linha->descricao);
        $this->assertSame($cobranca->id, $linha->auditavel_id);
    }

    public function test_anexo_que_sumiu_do_disco_nao_deixa_linha_de_download(): void
    {
        Storage::fake('public');

        $cobranca = Cobranca::create([
            'descricao' => 'Mensalidade agosto',
            'valor' => 500,
            'status' => 'pendente',
            'data_vencimento' => '2026-08-15',
        ]);

        $anexo = CobrancaAnexo::create([
            'cobranca_id' => $cobranca->id,
            'tipo' => 'boleto',
            'nome_original' => 'boleto-agosto.pdf',
            'nome_arquivo' => 'sumido.pdf',
            'caminho' => 'anexos/sumido.pdf',
            'tamanho' => 8,
        ]);

        $this->actingAs($this->admin())
            ->get(route('cobrancas.anexos.download', $anexo))
            ->assertNotFound();

        // Ninguém levou nada: registrar aqui seria acusar alguém de ter em mãos
        // um arquivo que o servidor não entregou.
        $this->assertSame(0, Auditoria::where('acao', 'baixou')->count());
    }

    public function test_gerar_o_faturamento_deixa_a_linha_do_fechamento(): void
    {
        $this->actingAs($this->admin())
            ->post(route('faturamento.gerar'), ['competencia' => '2026-08']);

        $linha = Auditoria::where('acao', 'gerou')->sole();

        // As receitas criadas já deixam linha própria, uma a uma. Esta é a do
        // FECHAMENTO: sem ela, dezenas de cobranças nascidas no mesmo segundo
        // se pareceriam com lançamentos avulsos, e ninguém diria se foram um
        // fechamento ou um engano repetido.
        $this->assertSame('faturamento', $linha->recurso);
        $this->assertStringContainsString('competência 2026-08', $linha->descricao);
    }

    public function test_bloquear_a_licenca_deixa_linha_com_o_antes_e_o_depois(): void
    {
        $gym = Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);

        $cliente = Cliente::create([
            'nome' => 'Condomínio Corpo em Movimento',
            'revenda_id' => $revenda->id,
            'ativo' => true,
        ]);

        $cliente->sistemas()->attach($gym->id, [
            'ativo' => true, 'status_saas' => 'ativo',
            'licenca_status' => 'ativa', 'licenca_id_externo' => '77',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas/77/bloquear' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym',
                'dados' => ['status' => 'bloqueado'],
            ], 200),
        ]);

        $this->actingAs($this->admin())
            ->post(route('clientes.bloquearLicenca', [$cliente, $gym]));

        $linha = Auditoria::where('acao', 'licenca')->sole();

        // A licença mora no pivô `cliente_sistema`, e `syncWithoutDetaching`
        // não dispara evento nenhum do Eloquent. Cortar o acesso de uma
        // academia seria a operação mais consequente do painel e a única sem
        // rastro.
        $this->assertSame('clientes', $linha->recurso);
        $this->assertSame($cliente->id, $linha->auditavel_id);
        $this->assertSame('bloquear', $linha->alteracoes['operação']['para']);
        $this->assertSame('ativo', $linha->alteracoes['status_saas']['de']);
        $this->assertSame('bloqueado', $linha->alteracoes['status_saas']['para']);
    }

    public function test_licenca_recusada_pelo_outro_lado_nao_deixa_linha(): void
    {
        $gym = Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);

        $cliente = Cliente::create([
            'nome' => 'Condomínio Corpo em Movimento',
            'revenda_id' => $revenda->id,
            'ativo' => true,
        ]);

        $cliente->sistemas()->attach($gym->id, [
            'ativo' => true, 'status_saas' => 'ativo',
            'licenca_status' => 'ativa', 'licenca_id_externo' => '77',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas/77/bloquear' => Http::response([], 500),
        ]);

        $this->actingAs($this->admin())
            ->post(route('clientes.bloquearLicenca', [$cliente, $gym]));

        // Operação que estourou não mudou nada — e uma linha para ela diria
        // que mudou.
        $this->assertSame(0, Auditoria::where('acao', 'licenca')->count());
    }
}
