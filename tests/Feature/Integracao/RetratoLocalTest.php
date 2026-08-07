<?php

namespace Tests\Feature\Integracao;

use App\Models\Sincronizacao;
use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaContador;
use App\Models\SistemaFatura;
use App\Models\SistemaLicenca;
use App\Models\SistemaPlano;
use App\Models\SistemaRevenda;
use App\Models\SistemaUsuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetratoLocalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-085 A garantia de não duplicar é ESTRUTURAL: o banco recusa dois
     * registros do mesmo sistema com o mesmo identificador de origem. Se
     * dependesse do serviço conferir antes de gravar, bastaria um caminho novo
     * esquecer a conferência para a duplicata voltar.
     */
    public function test_o_banco_recusa_dois_registros_com_o_mesmo_identificador(): void
    {
        $sistema = Sistema::factory()->create();

        SistemaRevenda::create([
            'sistema_id' => $sistema->id,
            'id_externo' => '3',
            'nome' => 'Invest Soluções',
        ]);

        $this->expectException(QueryException::class);

        SistemaRevenda::create([
            'sistema_id' => $sistema->id,
            'id_externo' => '3',
            'nome' => 'Invest Soluções (duplicada)',
        ]);
    }

    /**
     * @spec:AC-085 O mesmo identificador em sistemas DIFERENTES convive: dois
     * sistemas numerando os clientes a partir de 1 é o caso comum, não a
     * exceção.
     */
    public function test_o_mesmo_identificador_em_sistemas_diferentes_convive(): void
    {
        $gym = Sistema::factory()->create(['nome' => 'AlfaGym']);
        $control = Sistema::factory()->create(['nome' => 'AlfaControl']);

        SistemaCliente::create(['sistema_id' => $gym->id, 'id_externo' => '1', 'nome' => 'Academia A']);
        SistemaCliente::create(['sistema_id' => $control->id, 'id_externo' => '1', 'nome' => 'Condomínio B']);

        $this->assertSame(2, SistemaCliente::count());
    }

    /**
     * @spec:AC-086 Registro que some na origem é MARCADO, nunca apagado.
     * Apagar levaria junto o vínculo com o cliente da matriz e o histórico do
     * que já foi faturado em cima dele.
     */
    public function test_registro_ausente_e_marcado_e_continua_existindo(): void
    {
        $cliente = $this->umClienteDeSistema();

        $cliente->marcarAusenteNaOrigem();

        $this->assertTrue($cliente->refresh()->ausenteNaOrigem());
        $this->assertNotNull($cliente->ausente_em_origem_em);
        $this->assertFalse($cliente->ativo, 'Ausente na origem para de contar como ativo.');
        $this->assertDatabaseHas('sistema_clientes', ['id' => $cliente->id]);
    }

    /**
     * @spec:AC-086 O registro que reaparece volta ao normal: sumir da origem
     * pode ter sido um soluço da API, e a volta não pode exigir intervenção.
     */
    public function test_registro_que_reaparece_volta_ao_normal(): void
    {
        $cliente = $this->umClienteDeSistema();

        $cliente->marcarAusenteNaOrigem();
        $cliente->marcarPresenteNaOrigem();

        $this->assertFalse($cliente->refresh()->ausenteNaOrigem());
        $this->assertNull($cliente->ausente_em_origem_em);
    }

    /**
     * @spec:AC-086 Quem sumiu sai das listagens de presentes sem sair do banco
     * — é o que permite às telas mostrarem só o que existe hoje sem perder o
     * rastro do que existiu.
     */
    public function test_ausentes_saem_das_listagens_de_presentes(): void
    {
        $sistema = Sistema::factory()->create();

        $fica = SistemaCliente::create(['sistema_id' => $sistema->id, 'id_externo' => '1', 'nome' => 'Fica']);
        $some = SistemaCliente::create(['sistema_id' => $sistema->id, 'id_externo' => '2', 'nome' => 'Some']);
        $some->marcarAusenteNaOrigem();

        $presentes = SistemaCliente::doSistema($sistema)->presentes()->pluck('id_externo');

        $this->assertSame(['1'], $presentes->all());
        $this->assertSame(2, SistemaCliente::doSistema($sistema)->count(), 'Nada foi apagado.');
        $this->assertSame('Fica', $fica->nome);
    }

    /**
     * @spec:AC-084 O identificador de licença nunca é nulo: sistema sem
     * entidade de licença própria manda um derivado do cliente. Chave única
     * sobre coluna que aceita nulo tem semântica diferente entre bancos, e é
     * assim que um teste verde esconde duplicata em produção.
     */
    public function test_a_licenca_derivada_de_cliente_tem_identificador_proprio(): void
    {
        $cliente = $this->umClienteDeSistema();

        $licenca = SistemaLicenca::create([
            'sistema_id' => $cliente->sistema_id,
            'sistema_cliente_id' => $cliente->id,
            'id_externo' => 'cliente:'.$cliente->id_externo,
            'status' => 'ativa',
            'fim_em' => now()->addDays(20)->toDateString(),
            'bloqueia_acesso' => false,
        ]);

        $this->assertSame('cliente:128', $licenca->id_externo);

        $this->expectException(QueryException::class);
        SistemaLicenca::create([
            'sistema_id' => $cliente->sistema_id,
            'sistema_cliente_id' => $cliente->id,
            'id_externo' => 'cliente:'.$cliente->id_externo,
        ]);
    }

    /**
     * @spec:AC-084 A licença sabe dizer quanto falta para vencer, calculando
     * na hora. Guardar o número que o sistema mandou faria a tela mentir: um
     * "vence em 3 dias" gravado há uma semana já venceu.
     */
    public function test_a_licenca_calcula_o_vencimento_na_hora_de_mostrar(): void
    {
        $cliente = $this->umClienteDeSistema();

        $futura = SistemaLicenca::create([
            'sistema_id' => $cliente->sistema_id,
            'sistema_cliente_id' => $cliente->id,
            'id_externo' => '91',
            'fim_em' => now()->addDays(10)->toDateString(),
        ]);
        $vencida = SistemaLicenca::create([
            'sistema_id' => $cliente->sistema_id,
            'sistema_cliente_id' => $cliente->id,
            'id_externo' => '92',
            'fim_em' => now()->subDays(3)->toDateString(),
        ]);
        $semFim = SistemaLicenca::create([
            'sistema_id' => $cliente->sistema_id,
            'sistema_cliente_id' => $cliente->id,
            'id_externo' => '93',
            'fim_em' => null,
        ]);

        $this->assertSame(10, $futura->diasParaVencer());
        $this->assertFalse($futura->vencida());

        $this->assertSame(-3, $vencida->diasParaVencer());
        $this->assertTrue($vencida->vencida());

        $this->assertNull($semFim->diasParaVencer(), 'Sem data de fim, não há contagem.');
        $this->assertFalse($semFim->vencida());
    }

    /**
     * @spec:AC-088 A linha derivada é reconhecível: a tela de divergências não
     * pode acusar diferença de valor contra um número que o próprio sistema
     * não considera oficial — falso alarme faz a tela inteira ser ignorada.
     */
    public function test_a_fatura_derivada_e_reconhecivel(): void
    {
        $cliente = $this->umClienteDeSistema();

        $titulo = SistemaFatura::create([
            'sistema_id' => $cliente->sistema_id,
            'sistema_cliente_id' => $cliente->id,
            'id_externo' => 'nf-1',
            'competencia' => '2026-08',
            'valor' => 599.00,
            'origem' => 'titulo',
        ]);
        $derivada = SistemaFatura::create([
            'sistema_id' => $cliente->sistema_id,
            'sistema_cliente_id' => $cliente->id,
            'id_externo' => 'lic-91-2026-08',
            'competencia' => '2026-08',
            'valor' => 599.00,
            'origem' => 'derivado',
        ]);

        $this->assertFalse($titulo->ehDerivada());
        $this->assertTrue($derivada->ehDerivada());
        $this->assertSame(2, SistemaFatura::daCompetencia('2026-08')->count());
    }

    /**
     * @spec:AC-089 A contagem por competência é única por sistema, e a quebra
     * por revenda vem junto — é ela que a tela de divergências usa para
     * comparar sem somar milhares de linhas.
     */
    public function test_o_contador_e_unico_por_sistema_e_competencia(): void
    {
        $sistema = Sistema::factory()->create();

        $contador = SistemaContador::create([
            'sistema_id' => $sistema->id,
            'competencia' => '2026-08',
            'unidade_cobranca' => 'academia ativa',
            'unidades_ativas' => 33,
            'por_revenda' => [
                ['revenda_id_externo' => '3', 'nome' => 'Invest Soluções', 'unidades_ativas' => 18],
                ['revenda_id_externo' => '7', 'nome' => 'Outra', 'unidades_ativas' => 15],
            ],
        ]);

        $this->assertSame(18, $contador->unidadesDaRevenda('3'));
        $this->assertSame(15, $contador->unidadesDaRevenda('7'));
        $this->assertNull($contador->unidadesDaRevenda('99'), 'Revenda que o sistema não conhece não vira zero.');

        $this->expectException(QueryException::class);
        SistemaContador::create(['sistema_id' => $sistema->id, 'competencia' => '2026-08']);
    }

    /**
     * @spec:AC-087 A execução nasce em andamento e sabe distinguir sucesso de
     * entrada parcial. Tratar parcial como sucesso esconde que metade dos
     * dados não chegou.
     */
    public function test_a_execucao_distingue_sucesso_de_entrada_parcial(): void
    {
        $sistema = Sistema::factory()->create();

        $execucao = Sincronizacao::create(['sistema_id' => $sistema->id, 'iniciada_em' => now()]);

        $this->assertSame('em_andamento', $execucao->status);
        $this->assertFalse($execucao->deuCerto());
        $this->assertFalse($execucao->foiParcial());

        $execucao->update(['status' => 'parcial']);
        $this->assertTrue($execucao->foiParcial());
        $this->assertFalse($execucao->deuCerto(), 'Parcial não é sucesso.');

        $execucao->update(['status' => 'sucesso']);
        $this->assertTrue($execucao->deuCerto());
    }

    /**
     * @spec:AC-084 Apagar um sistema leva junto todo o retrato dele — não
     * ficam órfãos apontando para um sistema que não existe mais.
     */
    public function test_apagar_o_sistema_leva_o_retrato_junto(): void
    {
        $cliente = $this->umClienteDeSistema();
        $sistema = $cliente->sistema;

        SistemaUsuario::create([
            'sistema_id' => $sistema->id,
            'sistema_cliente_id' => $cliente->id,
            'id_externo' => '512',
            'nome' => 'Marina Alves',
        ]);
        SistemaPlano::create(['sistema_id' => $sistema->id, 'id_externo' => '2', 'nome' => 'Growth']);
        Sincronizacao::create(['sistema_id' => $sistema->id]);

        $sistema->delete();

        $this->assertSame(0, SistemaCliente::count());
        $this->assertSame(0, SistemaUsuario::count());
        $this->assertSame(0, SistemaPlano::count());
        $this->assertSame(0, Sincronizacao::count());
    }

    private function umClienteDeSistema(): SistemaCliente
    {
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);

        return SistemaCliente::create([
            'sistema_id' => $sistema->id,
            'id_externo' => '128',
            'nome' => 'Academia Corpo em Movimento',
            'cpf_cnpj' => '98765432000155',
            'unidades_ativas' => 1,
        ]);
    }
}
