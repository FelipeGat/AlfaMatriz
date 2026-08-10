<?php

namespace Tests\Feature\ClientesViaRevenda;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use App\Services\SincronizadorSistemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Clientes via revenda: o cliente final só é vendido por revenda (não existe
 * venda direta), os clientes moram dentro da tela de Revendas, e o admin da
 * Matriz libera a licença do cliente que a revenda solicitou no AlfaGym.
 */
class ClientesViaRevendaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create();
    }

    private function revenda(): Revenda
    {
        return Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);
    }

    private function sistemaAlfaGym(): Sistema
    {
        return Sistema::create([
            'nome' => 'AlfaGym', 'slug' => 'alfagym',
            'unidade_cobranca' => 'academia ativa', 'ativo' => true,
            'base_url' => 'https://gym.alfasolucoes.cloud', 'token' => 'chave-de-teste',
        ]);
    }

    private function clientePendente(Revenda $revenda, Sistema $sistema): Cliente
    {
        $cliente = Cliente::create([
            'nome' => 'Academia Corpo em Movimento', 'revenda_id' => $revenda->id, 'ativo' => true,
            'tipo_cliente' => 'CONTRATO', 'valor_mensal' => 540.00, 'dia_vencimento' => 15,
        ]);
        $cliente->sistemas()->attach($sistema->id, ['ativo' => true, 'status_saas' => 'pendente']);

        return $cliente;
    }

    /**
     * @spec:AC-072 O cadastro de cliente exige uma revenda — não existe mais
     * "venda direta da Alfa".
     */
    public function test_cadastro_de_cliente_exige_revenda(): void
    {
        $this->actingAs($this->admin())
            ->post(route('clientes.store'), [
                'tipo_pessoa' => 'PJ', 'razao_social' => 'Academia Central LTDA',
                'nome_fantasia' => 'Academia Central', 'cpf_cnpj' => '11.222.333/0001-44',
                'tipo_cliente' => 'CONTRATO', 'valor_mensal' => 540, 'dia_vencimento' => 15,
            ])
            ->assertSessionHasErrors('revenda_id');

        $this->assertDatabaseMissing('clientes', ['razao_social' => 'Academia Central LTDA']);
    }

    /**
     * @spec:AC-073 A listagem de clientes não oferece mais o recorte "venda
     * direta" — todo cliente da lista pertence a alguma revenda.
     */
    public function test_listagem_nao_oferece_recorte_venda_direta(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $revenda = $this->revenda();
        $this->clientePendente($revenda, $sistema);

        $resposta = $this->actingAs($this->admin())->get(route('clientes.index'));

        $resposta->assertOk();
        $resposta->assertDontSee('Venda direta', escape: false);
        $resposta->assertDontSee('value="direta"', escape: false);

        // O filtro de revenda continua filtrando por revenda.
        $resposta = $this->actingAs($this->admin())->get(route('clientes.index', ['revenda' => $revenda->id]));
        $resposta->assertOk();
        $this->assertSame('Academia Corpo em Movimento', $resposta->viewData('clientes')->first()->nome);
    }

    /**
     * @spec:AC-074 Todo cliente sincronizado do AlfaGym chega vinculado à sua
     * revenda (via revenda_id_externo).
     */
    public function test_sync_vincula_cliente_a_revenda(): void
    {
        $sistema = $this->sistemaAlfaGym();

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/revendas*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [
                    ['id_externo' => '1', 'nome' => 'Invest Soluções', 'cnpj' => null,
                        'email' => null, 'telefone' => null, 'ativo' => true, 'clientes_ativos' => 1],
                ],
            ]),
            '*gym.alfasolucoes.cloud/api/matriz/v1/clientes*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [
                    ['id_externo' => '128', 'nome' => 'Academia Corpo em Movimento',
                        'cpf_cnpj' => '98.765.432/0001-55', 'email' => null, 'telefone' => null,
                        'cidade' => 'Bauru', 'uf' => 'SP', 'ativo' => true, 'status' => 'pendente',
                        'revenda_id_externo' => '1', 'unidades_ativas' => 1],
                ],
            ]),
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [],
            ]),
        ]);

        $servico = new SincronizadorSistemaService($sistema);
        $resultado = $servico->sincronizar();

        $this->assertTrue($resultado['ok']);

        $cliente = Cliente::porOrigemExterna($sistema, '128');
        $this->assertNotNull($cliente);
        $this->assertSame(Revenda::porOrigemExterna($sistema, '1')->id, $cliente->revenda_id);
    }

    /**
     * @spec:AC-075 A tela de Revendas mostra a aba "Clientes", com a lista de
     * clientes da revenda selecionada.
     */
    public function test_tela_de_revendas_mostra_aba_clientes(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $revenda = $this->revenda();
        $this->clientePendente($revenda, $sistema);

        $resposta = $this->actingAs($this->admin())->get(route('revendas.index', ['aba' => 'clientes']));

        $resposta->assertOk();
        $resposta->assertSee('Clientes', escape: false);
        $this->assertSame('Academia Corpo em Movimento', $resposta->viewData('clientesView')['clientes']->first()->nome);
    }

    /**
     * @spec:AC-076 Usuário de revenda, na aba de clientes, vê só a própria
     * revenda — a lista de revendas do filtro tem apenas a dele.
     */
    public function test_usuario_de_revenda_so_ve_a_propria_revenda_na_aba(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $alpha = $this->revenda();
        $beta = Revenda::create(['nome' => 'Beta Rev', 'ativo' => true]);

        $clienteAlpha = $this->clientePendente($alpha, $sistema);
        $clienteBeta = Cliente::create(['nome' => 'Cliente Beta', 'revenda_id' => $beta->id, 'ativo' => true]);

        $usuario = User::factory()->create(['revenda_id' => $alpha->id]);

        $resposta = $this->actingAs($usuario)->get(route('revendas.index', ['aba' => 'clientes']));

        $resposta->assertOk();
        $nomes = $resposta->viewData('clientesView')['clientes']->pluck('nome')->all();
        $this->assertSame(['Academia Corpo em Movimento'], $nomes);

        $idsFiltro = $resposta->viewData('clientesView')['revendas']->pluck('id')->all();
        $this->assertSame([$alpha->id], $idsFiltro);
    }

    /**
     * @spec:AC-077 Cliente pendente de licença aparece com a ação de liberar.
     */
    public function test_cliente_pendente_aparece_com_acao_de_liberar(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $revenda = $this->revenda();
        $this->clientePendente($revenda, $sistema);

        $resposta = $this->actingAs($this->admin())->get(route('clientes.index'));

        $resposta->assertOk();
        $resposta->assertSee('Liberar licença', escape: false);
    }

    /**
     * @spec:AC-078 Liberar licença envia tipo, valor e observação ao AlfaGym
     * e grava o retorno no vínculo do cliente.
     */
    public function test_liberar_licenca_envia_tipo_valor_e_obs_ao_gym(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $revenda = $this->revenda();
        $cliente = $this->clientePendente($revenda, $sistema);
        $cliente->ancorarEm($sistema, '128');

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [
                    'id_externo' => '91', 'status' => 'ativa', 'plano' => 'Growth',
                    'tipo' => 'mensal', 'inicio_em' => '2026-08-08', 'fim_em' => '2026-09-08',
                    'bloqueia_acesso' => false,
                ],
            ]),
        ]);

        $resposta = $this->actingAs($this->admin())->post(route('clientes.liberarLicenca', $cliente), [
            'tipo' => 'mensal', 'valor' => 540.00, 'obs' => 'Contrato 2026',
        ]);

        $resposta->assertRedirect();
        $resposta->assertSessionHas('status');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/matriz/v1/licencas')
                && $request['cliente_id_externo'] === '128'
                && $request['tipo'] === 'mensal'
                && (float) $request['valor'] === 540.0
                && $request['obs'] === 'Contrato 2026';
        });

        $vinculo = $cliente->sistemas()->where('sistemas.id', $sistema->id)->first();
        $this->assertSame('ativa', $vinculo->pivot->licenca_status);
        $this->assertSame('2026-09-08', $vinculo->pivot->licenca_fim_em);
    }

    /**
     * @spec:AC-079 Liberar licença não conta como avulso: o cliente permanece
     * vinculado à revenda.
     */
    public function test_liberar_licenca_mantem_cliente_na_revenda(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $revenda = $this->revenda();
        $cliente = $this->clientePendente($revenda, $sistema);
        $cliente->ancorarEm($sistema, '128');

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [
                    'id_externo' => '91', 'status' => 'ativa', 'plano' => 'Growth',
                    'tipo' => 'mensal', 'inicio_em' => '2026-08-08', 'fim_em' => '2026-09-08',
                    'bloqueia_acesso' => false,
                ],
            ]),
        ]);

        $this->actingAs($this->admin())->post(route('clientes.liberarLicenca', $cliente), [
            'tipo' => 'anual', 'valor' => 5400.00, 'obs' => 'Contrato anual',
        ]);

        $cliente->refresh();
        $this->assertSame($revenda->id, $cliente->revenda_id, 'O cliente não vira avulso ao liberar a licença.');
        $this->assertFalse($cliente->isDireto(), 'O cliente continua sendo da revenda, nunca venda direta.');
    }

    /**
     * @spec:AC-080 A recusa do AlfaGym aparece como erro sem gravar nada.
     */
    public function test_recusa_do_gym_aparece_como_erro_sem_gravar(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $revenda = $this->revenda();
        $cliente = $this->clientePendente($revenda, $sistema);
        $cliente->ancorarEm($sistema, '128');

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym',
                'erro' => ['codigo' => 'JA_ATIVA', 'mensagem' => 'O cliente já possui licença ativa.'],
            ], 422),
        ]);

        $resposta = $this->actingAs($this->admin())->post(route('clientes.liberarLicenca', $cliente), [
            'tipo' => 'mensal', 'valor' => 540.00, 'obs' => null,
        ]);

        $resposta->assertRedirect();
        $resposta->assertSessionHasErrors('licenca');

        $vinculo = $cliente->sistemas()->where('sistemas.id', $sistema->id)->first();
        $this->assertSame('pendente', $vinculo->pivot->status_saas, 'A recusa não altera o vínculo.');
        $this->assertNull($vinculo->pivot->licenca_status);
    }

    private function clienteComLicenca(Revenda $revenda, Sistema $sistema, string $status = 'ativa'): Cliente
    {
        $cliente = Cliente::create([
            'nome' => 'Academia Corpo em Movimento', 'revenda_id' => $revenda->id, 'ativo' => true,
            'tipo_cliente' => 'CONTRATO', 'valor_mensal' => 540.00, 'dia_vencimento' => 15,
        ]);
        $cliente->sistemas()->attach($sistema->id, [
            'ativo' => true,
            'status_saas' => $status,
            'licenca_id_externo' => '91',
            'licenca_status' => $status,
            'bloqueia_acesso' => $status === 'bloqueado' ? 1 : 0,
            'licenca_fim_em' => '2026-09-08',
        ]);
        $cliente->ancorarEm($sistema, '128');

        return $cliente;
    }

    /**
     * Cliente com licença ativa mostra as ações de licenciamento sempre
     * (Renovar e Suspender), não só quando pendente.
     */
    public function test_cliente_com_licenca_mostra_acoes_sempre_visiveis(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $revenda = $this->revenda();
        $this->clienteComLicenca($revenda, $sistema);

        $resposta = $this->actingAs($this->admin())->get(route('clientes.index'));

        $resposta->assertOk();
        $resposta->assertSee('Renovar', escape: false);
        $resposta->assertSee('Suspender', escape: false);
        $resposta->assertDontSee('>Liberar<', escape: false);
    }

    /**
     * Renovar licença chama POST /licencas/{id}/renovar no gym e grava o
     * retorno no vínculo do cliente.
     */
    public function test_renovar_licenca_chama_o_gym_e_grava_retorno(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $revenda = $this->revenda();
        $cliente = $this->clienteComLicenca($revenda, $sistema);

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas/91/renovar' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [
                    'id_externo' => '91', 'status' => 'ativa', 'plano' => 'Growth',
                    'tipo' => 'anual', 'inicio_em' => '2026-08-08', 'fim_em' => '2027-08-08',
                    'bloqueia_acesso' => false,
                ],
            ]),
        ]);

        $resposta = $this->actingAs($this->admin())->post(route('clientes.renovarLicenca', $cliente), [
            'tipo' => 'anual', 'valor' => 5400.00, 'obs' => 'Renovação anual',
        ]);

        $resposta->assertRedirect();
        $resposta->assertSessionHas('status');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/matriz/v1/licencas/91/renovar')
                && $request['tipo'] === 'anual'
                && (float) $request['valor'] === 5400.0
                && $request['obs'] === 'Renovação anual';
        });

        $vinculo = $cliente->sistemas()->where('sistemas.id', $sistema->id)->first();
        $this->assertSame('2027-08-08', $vinculo->pivot->licenca_fim_em);
        $this->assertSame('ativa', $vinculo->pivot->licenca_status);
    }

    /**
     * Bloquear licença chama POST /licencas/{id}/bloquear no gym e grava o
     * novo status do cliente no vínculo.
     */
    public function test_bloquear_licenca_chama_o_gym_e_grava_status(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $revenda = $this->revenda();
        $cliente = $this->clienteComLicenca($revenda, $sistema);

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas/91/bloquear' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [
                    'cliente_id_externo' => '128', 'status' => 'bloqueado',
                ],
            ]),
        ]);

        $resposta = $this->actingAs($this->admin())->post(route('clientes.bloquearLicenca', $cliente));

        $resposta->assertRedirect();
        $resposta->assertSessionHas('status');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/matriz/v1/licencas/91/bloquear'));

        $vinculo = $cliente->sistemas()->where('sistemas.id', $sistema->id)->first();
        $this->assertSame('bloqueado', $vinculo->pivot->status_saas);
        $this->assertSame(1, (int) $vinculo->pivot->bloqueia_acesso);
    }

    /**
     * Desbloquear licença chama POST /licencas/{id}/desbloquear no gym e grava
     * o novo status do cliente no vínculo.
     */
    public function test_desbloquear_licenca_chama_o_gym_e_grava_status(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $revenda = $this->revenda();
        $cliente = $this->clienteComLicenca($revenda, $sistema, 'bloqueado');

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas/91/desbloquear' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => [
                    'cliente_id_externo' => '128', 'status' => 'ativo',
                ],
            ]),
        ]);

        $resposta = $this->actingAs($this->admin())->post(route('clientes.desbloquearLicenca', $cliente));

        $resposta->assertRedirect();
        $resposta->assertSessionHas('status');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/matriz/v1/licencas/91/desbloquear'));

        $vinculo = $cliente->sistemas()->where('sistemas.id', $sistema->id)->first();
        $this->assertSame('ativo', $vinculo->pivot->status_saas);
        $this->assertSame(0, (int) $vinculo->pivot->bloqueia_acesso);
    }

    /**
     * Recusa do gym ao renovar aparece como erro sem alterar o vínculo.
     */
    public function test_recusa_ao_renovar_aparece_como_erro_sem_gravar(): void
    {
        $sistema = $this->sistemaAlfaGym();
        $revenda = $this->revenda();
        $cliente = $this->clienteComLicenca($revenda, $sistema);

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas/91/renovar' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym',
                'erro' => ['codigo' => 'PLANO_INVALIDO', 'mensagem' => 'Plano não encontrado para renovação.'],
            ], 422),
        ]);

        $resposta = $this->actingAs($this->admin())->post(route('clientes.renovarLicenca', $cliente), [
            'tipo' => 'anual', 'valor' => 5400.00, 'obs' => null,
        ]);

        $resposta->assertRedirect();
        $resposta->assertSessionHasErrors('licenca');

        $vinculo = $cliente->sistemas()->where('sistemas.id', $sistema->id)->first();
        $this->assertSame('ativa', $vinculo->pivot->status_saas, 'A recusa não altera o vínculo.');
        $this->assertSame('2026-09-08', $vinculo->pivot->licenca_fim_em, 'O fim da licença continua o mesmo.');
    }
}
