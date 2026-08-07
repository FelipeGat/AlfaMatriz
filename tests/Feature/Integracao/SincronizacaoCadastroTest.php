<?php

namespace Tests\Feature\Integracao;

use App\Models\Cliente;
use App\Models\Sincronizacao;
use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaContador;
use App\Models\SistemaLicenca;
use App\Models\SistemaPlano;
use App\Models\SistemaRevenda;
use App\Models\SistemaUsuario;
use App\Services\Integracao\ConectorFalso;
use App\Services\Integracao\FabricaDeConector;
use App\Services\Integracao\SincronizacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Amostras;
use Tests\Support\FabricaFalsa;
use Tests\TestCase;

class SincronizacaoCadastroTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $sistema;

    private ConectorFalso $conector;

    private SincronizacaoService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sistema = Sistema::factory()->integrado()->create(['nome' => 'AlfaGym']);
        $this->conector = Amostras::conector();
        $this->app->instance(FabricaDeConector::class, (new FabricaFalsa)->registrar($this->sistema, $this->conector));
        $this->servico = app(SincronizacaoService::class);
    }

    /**
     * @spec:AC-084 Uma sincronização completa traz revendas, clientes, planos,
     * usuários, licenças e a contagem da competência para o retrato local — e
     * liga cada um ao que já entrou antes (cliente à revenda, usuário e licença
     * ao cliente), na ordem que os vínculos exigem.
     */
    public function test_sincronizacao_completa_traz_todo_o_retrato(): void
    {
        $execucao = $this->servico->sincronizar($this->sistema);

        $this->assertSame(2, SistemaRevenda::doSistema($this->sistema)->count());
        $this->assertSame(4, SistemaCliente::doSistema($this->sistema)->count());
        $this->assertSame(3, SistemaPlano::doSistema($this->sistema)->count());
        $this->assertSame(3, SistemaUsuario::doSistema($this->sistema)->count());
        $this->assertSame(4, SistemaLicenca::doSistema($this->sistema)->count());

        $cliente = SistemaCliente::doSistema($this->sistema)->where('id_externo', '128')->first();
        $this->assertSame('98765432000155', $cliente->cpf_cnpj, 'O documento chega normalizado.');
        $this->assertSame(
            SistemaRevenda::doSistema($this->sistema)->where('id_externo', '3')->value('id'),
            $cliente->sistema_revenda_id,
            'Cliente 128 é da revenda 3.'
        );

        $usuario = SistemaUsuario::doSistema($this->sistema)->where('id_externo', '512')->first();
        $this->assertSame($cliente->id, $usuario->sistema_cliente_id);

        $licenca = SistemaLicenca::doSistema($this->sistema)->where('id_externo', '91')->first();
        $this->assertSame($cliente->id, $licenca->sistema_cliente_id);
        $this->assertSame('ativa', $licenca->status);
        $this->assertSame('Growth', $licenca->plano);
        $this->assertTrue($licenca->bloqueia_acesso);

        $contador = SistemaContador::where('sistema_id', $this->sistema->id)->where('competencia', now()->format('Y-m'))->first();
        $this->assertNotNull($contador, 'A contagem da competência atual fica guardada.');
        $this->assertSame('academia ativa', $contador->unidade_cobranca);
        $this->assertSame(2, $contador->unidadesDaRevenda('3'));

        $this->assertTrue($execucao->deuCerto());
        $this->assertSame('sucesso', $execucao->status);
    }

    /**
     * @spec:AC-084 A execução fica registrada com quantos itens vieram, quanto
     * tempo levou e quando terminou — é esse registro que permite saber se a
     * rotina está viva sem adivinhar.
     */
    public function test_a_execucao_fica_registrada_com_contagens_e_duracao(): void
    {
        $execucao = $this->servico->sincronizar($this->sistema);

        $this->assertSame(4, SistemaCliente::count());
        $this->assertGreaterThan(0, $execucao->itens_lidos);
        $this->assertSame(4 + 2 + 3 + 3 + 4, $execucao->itens_criados);
        $this->assertNotNull($execucao->finalizada_em);
        $this->assertGreaterThanOrEqual(0, $execucao->duracao_ms);
        $this->assertNotNull($this->sistema->refresh()->sincronizado_em, 'O selo "atualizado há" é alimentado aqui.');
    }

    /**
     * @spec:AC-085 Sincronizar de novo não duplica nada: a idempotência é
     * estrutural, por (sistema, id na origem), e uma segunda execução só
     * atualiza o que já existia.
     */
    public function test_sincronizar_de_novo_nao_duplica_nada(): void
    {
        $this->servico->sincronizar($this->sistema);

        $segunda = $this->servico->sincronizar($this->sistema);

        $this->assertSame(2, SistemaRevenda::count());
        $this->assertSame(4, SistemaCliente::count());
        $this->assertSame(3, SistemaPlano::count());
        $this->assertSame(3, SistemaUsuario::count());
        $this->assertSame(4, SistemaLicenca::count());

        $this->assertSame(0, $segunda->itens_criados, 'Nada a criar na segunda vez.');
        $this->assertSame(4 + 2 + 3 + 3 + 4, $segunda->itens_atualizados);
        $this->assertSame(0, $segunda->itens_ausentes);
    }

    /**
     * @spec:AC-085 O que mudou na origem chega ao retrato: nome, situação e
     * unidades são sobrescritos pelo que o sistema diz hoje.
     */
    public function test_o_que_mudou_na_origem_atualiza_o_retrato(): void
    {
        $this->servico->sincronizar($this->sistema);

        $clientes = Amostras::comAlteracao(Amostras::ler('v1', 'clientes'), '128', [
            'nome' => 'Academia Corpo em Movimento 2',
            'unidades_ativas' => 3,
        ]);
        $this->conector->com('clientes', $clientes);

        $this->servico->sincronizar($this->sistema);

        $cliente = SistemaCliente::doSistema($this->sistema)->where('id_externo', '128')->first();
        $this->assertSame('Academia Corpo em Movimento 2', $cliente->nome);
        $this->assertSame(3, $cliente->unidades_ativas);
    }

    /**
     * @spec:AC-084 Sistema mal configurado NÃO lança exceção: grava a execução
     * com o motivo e devolve — a tela mostra o que falta, não um rastro de
     * pilha. E nada do retrato é tocado.
     */
    public function test_sistema_mal_configurado_grava_o_motivo_e_retorna(): void
    {
        $semChave = Sistema::factory()->create(['base_url' => 'https://gym.alfasolucoes.cloud', 'token' => null]);

        $execucao = $this->servico->sincronizar($semChave);

        $this->assertSame('falha', $execucao->status);
        $this->assertSame('sem_chave', $execucao->erro_codigo);
        $this->assertNotNull($execucao->erro_mensagem);
        $this->assertNotNull($execucao->finalizada_em);
        $this->assertSame(0, SistemaCliente::count(), 'Nada pode ter entrado no retrato.');
    }

    /**
     * @spec:AC-084 Sincronizar um escopo isolado lê SÓ ele: a varredura leve
     * de hora em hora não pode custar uma leitura completa de tudo.
     */
    public function test_sincronizar_um_escopo_isolado_so_le_ele(): void
    {
        $this->servico->sincronizar($this->sistema, 'clientes');

        $this->assertSame(1, $this->conector->vezesQueChamou('clientes'));
        $this->assertFalse($this->conector->chamou('planos'));
        $this->assertFalse($this->conector->chamou('licencas'));
        $this->assertFalse($this->conector->chamou('contadores'));

        $this->assertSame(4, SistemaCliente::count());
        $this->assertSame(0, SistemaLicenca::count(), 'Escopo isolado não traz o que não foi pedido.');
    }

    /**
     * @spec:AC-085 Vínculo feito à mão sobrevive à varredura: o registro que
     * alguém escolheu na conferência continua apontando para o mesmo cliente da
     * matriz depois de cada sincronização.
     */
    public function test_o_vinculo_manual_sobrevive_a_varredura(): void
    {
        $daMatriz = Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);

        $this->servico->sincronizar($this->sistema);

        $registro = SistemaCliente::doSistema($this->sistema)->where('id_externo', '128')->first();
        $registro->forceFill(['cliente_id' => $daMatriz->id, 'vinculo_origem' => 'manual'])->save();

        $this->servico->sincronizar($this->sistema);

        $registro->refresh();
        $this->assertSame($daMatriz->id, $registro->cliente_id);
        $this->assertSame('manual', $registro->vinculo_origem);
    }

    /** @spec:AC-084 O comando e a tela pedem escopos; um escopo que não existe recusa antes de gravar qualquer coisa. */
    public function test_escopo_desconhecido_recusa_sem_gravar_execucao(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->servico->sincronizar($this->sistema, 'faturas');
    }

    /** @spec:AC-084 O que a varredura fez fica no registro, para conferir sem adivinhar. */
    public function test_rodar_a_varredura_de_novo_nao_infla_falhas_consecutivas(): void
    {
        $this->servico->sincronizar($this->sistema);
        $this->servico->sincronizar($this->sistema);

        $this->assertSame(0, $this->sistema->refresh()->falhas_consecutivas);
        $this->assertSame(2, Sincronizacao::count());
    }
}
