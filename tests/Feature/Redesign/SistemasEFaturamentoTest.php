<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SistemasEFaturamentoTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
    }

    /**
     * @spec:AC-042 O card "Quem revende" mostra só as cinco maiores e resume o
     * resto numa linha. É o que impede a tela de esticar sem fim quando o
     * sistema tem dezenas de revendas.
     */
    public function test_quem_revende_nao_cresce_com_dezenas_de_revendas(): void
    {
        $sistema = $this->criarSistema('AlfaGym');

        // 12 revendas com quantidades decrescentes de clientes.
        foreach (range(1, 12) as $i) {
            $revenda = Revenda::create(['nome' => "Revenda {$i}", 'ativo' => true]);

            foreach (range(1, 13 - $i) as $c) {
                $this->vincular($this->criarCliente("R{$i} C{$c}", $revenda->id), $sistema);
            }
        }

        $resposta = $this->actingAs($this->usuario)->get(route('sistemas.index'));
        $resposta->assertOk();

        $detalhe = $resposta->viewData('detalhe');

        $this->assertCount(5, $detalhe['top_revendas'], 'O card mostra no máximo cinco revendas.');
        $this->assertSame(7, $detalhe['outras_revendas'], 'As demais viram um contador.');
        $this->assertGreaterThan(0, $detalhe['clientes_em_outras']);

        // A maior aparece; a décima não.
        $html = $resposta->getContent();
        $this->assertStringContainsString('Revenda 1', $html);
        $this->assertStringNotContainsString('Revenda 10', $html);
        $this->assertStringContainsString('outra(s) revenda(s)', $html);
    }

    /**
     * @spec:AC-042 A seleção do catálogo vai pela query string, para o link
     * poder ser compartilhado e sobreviver ao recarregar.
     */
    public function test_selecao_do_catalogo_vem_da_url(): void
    {
        $primeiro = $this->criarSistema('AlfaControl');
        $segundo = $this->criarSistema('AlfaMed');

        // Sem parâmetro, seleciona o primeiro da lista.
        $padrao = $this->actingAs($this->usuario)->get(route('sistemas.index'));
        $this->assertSame($primeiro->id, $padrao->viewData('selecionado')->id);

        // Com parâmetro, respeita a escolha.
        $escolhido = $this->actingAs($this->usuario)->get(route('sistemas.index', ['sistema' => $segundo->id]));
        $this->assertSame($segundo->id, $escolhido->viewData('selecionado')->id);

        // Parâmetro inválido cai no primeiro em vez de quebrar a tela.
        $invalido = $this->actingAs($this->usuario)->get(route('sistemas.index', ['sistema' => 99999]));
        $invalido->assertOk();
        $this->assertSame($primeiro->id, $invalido->viewData('selecionado')->id);
    }

    /**
     * @spec:AC-042 Sem revendas pendentes, o botão de gerar faturamento fica
     * inerte em vez de sumir — ação que desaparece deixa a pessoa procurando.
     */
    public function test_botao_de_faturamento_fica_inerte_sem_pendencia(): void
    {
        $html = $this->actingAs($this->usuario)->get(route('faturamento.index'))->getContent();

        $this->assertStringContainsString('Nada a gerar', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('cursor-default bg-raised text-mute', $html);
    }

    /**
     * @spec:AC-042 Com revenda a faturar, o botão fica ativo e diz quantas —
     * o número vem da mesma prévia mostrada na tabela.
     */
    public function test_botao_diz_quantas_revendas_serao_faturadas(): void
    {
        $sistema = $this->criarSistema('AlfaHome');
        PrecoAtacado::create([
            'sistema_id' => $sistema->id, 'nome' => 'Faixa 1', 'preco_base' => 200,
            'unidades_inclusas' => 10, 'ordem' => 1, 'vigencia_inicio' => now()->subMonth(),
        ]);

        $revenda = Revenda::create(['nome' => 'Invest', 'ativo' => true]);
        $this->vincular($this->criarCliente('Cliente 1', $revenda->id), $sistema);

        $resposta = $this->actingAs($this->usuario)->get(route('faturamento.index'));
        $resposta->assertOk();

        $html = $resposta->getContent();
        $this->assertStringContainsString('Gerar faturamento (1)', $html);
        $this->assertStringContainsString('Pendente', $html);
    }

    // ------------------------------------------------------------- apoio

    private function criarSistema(string $nome): Sistema
    {
        return Sistema::create([
            'nome' => $nome,
            'slug' => Str()->slug($nome),
            'categoria' => 'saas',
            'unidade_cobranca' => 'cliente',
            'ativo' => true,
        ]);
    }

    private function criarCliente(string $nome, int $revendaId): Cliente
    {
        return Cliente::create(['nome' => $nome, 'revenda_id' => $revendaId, 'ativo' => true]);
    }

    private function vincular(Cliente $cliente, Sistema $sistema): void
    {
        DB::table('cliente_sistema')->insert([
            'cliente_id' => $cliente->id,
            'sistema_id' => $sistema->id,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
