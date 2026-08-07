<?php

namespace Tests\Feature\Integracao;

use App\Models\Cliente;
use App\Models\FaturamentoSnapshot;
use App\Models\Revenda;
use App\Models\Sincronizacao;
use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaContador;
use App\Models\SistemaLicenca;
use App\Models\SistemaRevenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelasTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $gym;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->gym = Sistema::factory()->integrado('https://gym.alfasolucoes.cloud')->create([
            'nome' => 'AlfaGym',
            'unidade_cobranca' => 'academia ativa',
            'sincronizado_em' => now()->subMinutes(20),
        ]);
    }

    /**
     * @spec:AC-082 O painel mostra o último retrato mesmo com o sistema fora do
     * ar, e diz há quantas tentativas seguidas ele falha. Uma queda lá não pode
     * deixar quem está aqui sem informação.
     */
    public function test_o_painel_mostra_o_ultimo_retrato_de_um_sistema_fora_do_ar(): void
    {
        $this->gym->update(['falhas_consecutivas' => 3]);
        SistemaCliente::factory()->count(2)->create(['sistema_id' => $this->gym->id]);
        Sincronizacao::factory()->falha()->create(['sistema_id' => $this->gym->id]);

        $resposta = $this->get(route('integracao.index'));

        $resposta->assertOk();
        $resposta->assertSee('fora do ar');
        $resposta->assertSee('3 tentativas seguidas falharam');
        // O retrato continua à vista: é o que sobrou de confiável.
        $resposta->assertSee('Clientes no sistema');
        $resposta->assertSee('Não foi possível falar com o sistema.');
    }

    /**
     * @spec:AC-079 Sistema mal configurado diz QUAL das coisas falta, em vez de
     * "não foi possível" — que mandaria a pessoa adivinhar entre quatro causas.
     */
    public function test_o_painel_diz_o_que_falta_em_cada_sistema_mal_configurado(): void
    {
        Sistema::factory()->create(['nome' => 'AlfaMed', 'base_url' => null, 'token' => null]);
        Sistema::factory()->create(['nome' => 'AlfaHome', 'base_url' => 'https://home.alfa', 'token' => null]);

        $resposta = $this->get(route('integracao.index'));

        $resposta->assertOk();
        $resposta->assertSee('falta o endereço de integração');
        $resposta->assertSee('falta a chave de integração');
    }

    /**
     * @spec:AC-083 Toda tela de integração diz de quando é o dado que está
     * mostrando — e destaca quando ele está velho demais para se confiar.
     */
    public function test_toda_tela_diz_de_quando_e_o_dado(): void
    {
        SistemaCliente::factory()->create(['sistema_id' => $this->gym->id]);

        foreach (['integracao.index', 'integracao.clientes', 'integracao.licencas', 'integracao.contratos'] as $rota) {
            $this->get(route($rota))->assertOk()->assertSee('há ', false);
        }
    }

    /**
     * @spec:AC-083 Retrato velho demais é destacado como crítico: o problema
     * não é o dado ser antigo, é alguém decidir em cima dele achando que é novo.
     */
    public function test_retrato_velho_demais_e_destacado(): void
    {
        $this->gym->update(['sincronizado_em' => now()->subDays(4)]);

        $resposta = $this->get(route('integracao.index'));

        $resposta->assertOk();
        $resposta->assertSee('4 dias');
    }

    /** @spec:AC-095 O painel mostra, por sistema, se a matriz já manda nele. */
    public function test_o_painel_mostra_o_estagio_de_cada_sistema(): void
    {
        $this->get(route('integracao.index'))->assertOk()->assertSee('apenas observando');

        $this->gym->update(['importado_em' => now(), 'cadastro_na_matriz_desde' => now()]);

        $this->get(route('integracao.index'))->assertOk()->assertSee('a matriz manda no cadastro');
    }

    /**
     * @spec:AC-092 Cada pendência aparece pelo MOTIVO, porque cada motivo pede
     * uma ação diferente. Uma lista única de "não vinculados" obrigaria a
     * descobrir isso registro a registro.
     */
    public function test_a_conferencia_separa_as_pendencias_por_motivo(): void
    {
        Cliente::factory()->create(['cpf_cnpj' => '11111111111111']);
        Cliente::factory()->create(['cpf_cnpj' => '22222222222222']);
        Cliente::factory()->create(['cpf_cnpj' => '22222222222222']);

        SistemaCliente::factory()->create(['sistema_id' => $this->gym->id, 'nome' => 'Sem par aqui', 'cpf_cnpj' => '99999999999999']);
        SistemaCliente::factory()->create(['sistema_id' => $this->gym->id, 'nome' => 'Dois candidatos', 'cpf_cnpj' => '22222222222222']);
        SistemaCliente::factory()->semDocumento()->create(['sistema_id' => $this->gym->id, 'nome' => 'Sem documento']);

        $resposta = $this->get(route('integracao.conferencia', ['sistema' => $this->gym->id]));

        $resposta->assertOk();
        $resposta->assertSee('Sem par na matriz');
        $resposta->assertSee('Mais de um candidato');
        $resposta->assertSee('Sem documento na origem');
        $resposta->assertSee('Sem par aqui');
        $resposta->assertSee('Dois candidatos');
    }

    /**
     * @spec:AC-094 O corte é recusado enquanto sobrar pendência, e a recusa vem
     * do serviço — esconder o botão impede o clique distraído, mas não impede
     * um pedido montado à mão, e este é o passo praticamente irreversível.
     */
    public function test_o_corte_e_recusado_enquanto_houver_pendencia(): void
    {
        $this->gym->update(['importado_em' => now()]);
        SistemaCliente::factory()->create(['sistema_id' => $this->gym->id, 'cpf_cnpj' => '99999999999999']);

        $this->post(route('integracao.conferencia.corte', $this->gym))
            ->assertRedirect()
            ->assertSessionHas('erro');

        $this->assertNull($this->gym->refresh()->cadastro_na_matriz_desde, 'A matriz não pode ter virado dona.');
    }

    /** @spec:AC-094 Sem pendência nenhuma, o corte é aplicado e fica registrado. */
    public function test_o_corte_e_aplicado_com_a_conferencia_zerada(): void
    {
        $this->gym->update(['importado_em' => now()]);
        $daMatriz = Cliente::factory()->create(['cpf_cnpj' => '33333333333333']);
        SistemaCliente::factory()->create([
            'sistema_id' => $this->gym->id,
            'cpf_cnpj' => '33333333333333',
            'cliente_id' => $daMatriz->id,
            'vinculo_origem' => 'automatico',
        ]);

        $this->post(route('integracao.conferencia.corte', $this->gym))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNotNull($this->gym->refresh()->cadastro_na_matriz_desde);
    }

    /** @spec:AC-094 Corte sem importação prévia é recusado com motivo próprio. */
    public function test_corte_sem_importacao_e_recusado(): void
    {
        $this->post(route('integracao.conferencia.corte', $this->gym))
            ->assertRedirect()
            ->assertSessionHas('erro');

        $this->assertNull($this->gym->refresh()->cadastro_na_matriz_desde);
    }

    /**
     * @spec:AC-091 A tela de clientes diz a quem cada registro corresponde na
     * matriz — é a coluna que responde a pergunta que motivou a integração.
     */
    public function test_a_tela_de_clientes_mostra_o_vinculo_com_a_matriz(): void
    {
        $daMatriz = Cliente::factory()->create(['nome' => 'Corpo em Movimento LTDA', 'cpf_cnpj' => '44444444444444']);

        SistemaCliente::factory()->create([
            'sistema_id' => $this->gym->id,
            'nome' => 'Academia Corpo em Movimento',
            'cpf_cnpj' => '44444444444444',
            'cliente_id' => $daMatriz->id,
        ]);
        SistemaCliente::factory()->create(['sistema_id' => $this->gym->id, 'nome' => 'Academia Órfã']);

        $resposta = $this->get(route('integracao.clientes'));

        $resposta->assertOk();
        $resposta->assertSee('Academia Corpo em Movimento');
        $resposta->assertSee('Corpo em Movimento LTDA');
        $resposta->assertSee('Academia Órfã');
        $resposta->assertSee('sem vínculo');
    }

    /**
     * @spec:AC-086 Quem sumiu na origem fica fora da lista por padrão, mas
     * continua alcançável: o retrato guarda o histórico, e escondê-lo para
     * sempre seria o mesmo que ter apagado.
     */
    public function test_quem_sumiu_na_origem_fica_fora_por_padrao_mas_alcancavel(): void
    {
        SistemaCliente::factory()->ausenteNaOrigem()->create([
            'sistema_id' => $this->gym->id,
            'nome' => 'Academia Que Sumiu',
        ]);

        $this->get(route('integracao.clientes'))->assertOk()->assertDontSee('Academia Que Sumiu');
        $this->get(route('integracao.clientes', ['ausentes' => 'sim']))->assertOk()->assertSee('Academia Que Sumiu');
    }

    /**
     * @spec:AC-084 A tela de licenças separa por faixa e diz, em cada linha, se
     * bloquear realmente barra o acesso naquele sistema — sem isso a matriz
     * prometeria um efeito que não acontece.
     */
    public function test_a_tela_de_licencas_separa_por_faixa_e_diz_o_efeito_do_bloqueio(): void
    {
        $cliente = SistemaCliente::factory()->create(['sistema_id' => $this->gym->id, 'nome' => 'Academia Ritmo']);

        SistemaLicenca::factory()->vencendoEm(5)->create(['sistema_cliente_id' => $cliente->id]);
        SistemaLicenca::factory()->vencida()->semBloqueioDeAcesso()->create(['sistema_cliente_id' => $cliente->id]);

        $vencendo = $this->get(route('integracao.licencas', ['faixa' => 'vencendo']));
        $vencendo->assertOk();
        $vencendo->assertSee('Academia Ritmo');
        $vencendo->assertSee('barra o acesso');
        $vencendo->assertSee('em 5 dias');

        $vencidas = $this->get(route('integracao.licencas', ['faixa' => 'vencidas']));
        $vencidas->assertOk();
        $vencidas->assertSee('só marca, não barra');
    }

    /**
     * @spec:AC-088 A tela de contratos põe o valor do contrato (da matriz) ao
     * lado do uso (do sistema), e destaca quem está ativo lá dentro sem valor
     * cadastrado aqui — uso que ninguém está cobrando.
     */
    public function test_a_tela_de_contratos_cruza_o_contrato_da_matriz_com_o_uso_do_sistema(): void
    {
        $revenda = Revenda::factory()->create(['nome' => 'Invest Soluções']);

        $comContrato = Cliente::factory()->create([
            'nome' => 'Academia Pagante',
            'revenda_id' => $revenda->id,
            'valor_mensal' => 249.00,
        ]);
        $semContrato = Cliente::factory()->create([
            'nome' => 'Academia Sem Valor',
            'revenda_id' => $revenda->id,
            'valor_mensal' => null,
        ]);

        SistemaCliente::factory()->create([
            'sistema_id' => $this->gym->id, 'nome' => 'Pagante no sistema',
            'cliente_id' => $comContrato->id, 'unidades_ativas' => 1,
        ]);
        SistemaCliente::factory()->create([
            'sistema_id' => $this->gym->id, 'nome' => 'Sem valor no sistema',
            'cliente_id' => $semContrato->id, 'unidades_ativas' => 1,
        ]);

        $resposta = $this->get(route('integracao.contratos'));

        $resposta->assertOk();
        $resposta->assertSee('Invest Soluções');
        $resposta->assertSee('249,00');
        $resposta->assertSee('sem valor contratado');
        $resposta->assertSee('Ativo sem contrato');
    }

    /**
     * @spec:AC-088 A exportação sai com separador e marca de codificação que o
     * Excel em português abre sem estragar acento nem jogar tudo numa coluna.
     */
    public function test_a_exportacao_de_contratos_sai_legivel_no_excel(): void
    {
        $cliente = Cliente::factory()->create(['nome' => 'Academia Pagante', 'valor_mensal' => 249.00]);
        SistemaCliente::factory()->create([
            'sistema_id' => $this->gym->id, 'nome' => 'Pagante', 'cliente_id' => $cliente->id,
        ]);

        $resposta = $this->get(route('integracao.contratos.exportar'));

        $resposta->assertOk();
        $conteudo = $resposta->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $conteudo, 'Sem a marca de codificação, o Excel estraga os acentos.');
        // Campo com espaço sai entre aspas, como manda o formato — o que
        // importa provar é o separador, não a ausência de aspas.
        $this->assertStringContainsString('"Valor mensal contratado";', $conteudo, 'O separador precisa ser ponto e vírgula.');
        $this->assertStringContainsString('Academia Pagante', $conteudo);
        $this->assertStringContainsString('249,00', $conteudo);
    }

    /**
     * @spec:AC-090 A tela de divergências aponta o CASO, não só o total: uma
     * contagem divergente sem o caso não ajuda ninguém a agir.
     */
    public function test_a_tela_de_divergencias_aponta_o_caso_e_nao_so_o_total(): void
    {
        $competencia = now()->format('Y-m');
        $revenda = Revenda::factory()->create(['nome' => 'Invest Soluções']);

        SistemaRevenda::factory()->create([
            'sistema_id' => $this->gym->id,
            'id_externo' => '3',
            'revenda_id' => $revenda->id,
        ]);

        SistemaContador::factory()
            ->comRevenda('3', 16, 'Invest Soluções')
            ->create(['sistema_id' => $this->gym->id, 'competencia' => $competencia]);

        FaturamentoSnapshot::create([
            'competencia' => $competencia,
            'sistema_id' => $this->gym->id,
            'revenda_id' => $revenda->id,
            'clientes_ativos' => 14,
            'valor_unitario' => 17.78,
            'total' => 249.00,
        ]);

        $resposta = $this->get(route('integracao.divergencias', ['competencia' => $competencia]));

        $resposta->assertOk();
        $resposta->assertSee('Unidades contadas x unidades faturadas');
        $resposta->assertSee('Invest Soluções');
        $resposta->assertSee('16');   // o que o AlfaGym diz
        $resposta->assertSee('14');   // o que a Alfa faturou
        $resposta->assertSee('não cobrado');
    }

    /**
     * @spec:AC-090 Sistema que nunca foi lido não vira alarme: acusar tudo como
     * divergente transformaria "ainda não sincronizei" em problema.
     */
    public function test_sistema_nunca_sincronizado_nao_vira_alarme(): void
    {
        $novo = Sistema::factory()->create(['nome' => 'AlfaMed', 'sincronizado_em' => null]);
        $cliente = Cliente::factory()->create();
        $cliente->sistemas()->attach($novo->id, ['ativo' => true, 'ativado_em' => now()]);

        $resposta = $this->get(route('integracao.divergencias'));

        $resposta->assertOk();
        $resposta->assertSee('nada divergindo nesta competência');
    }

    /**
     * @spec:AC-088 Cliente ativo no sistema com o valor mensal em branco na
     * matriz aparece na tela de divergências: está sendo usado e não está sendo
     * cobrado de ninguém.
     */
    public function test_ativo_no_sistema_sem_valor_contratado_aparece_nas_divergencias(): void
    {
        $cliente = Cliente::factory()->create(['nome' => 'Usa e não paga', 'valor_mensal' => null]);
        SistemaCliente::factory()->create([
            'sistema_id' => $this->gym->id,
            'nome' => 'Academia sem valor',
            'cliente_id' => $cliente->id,
        ]);

        $resposta = $this->get(route('integracao.divergencias'));

        $resposta->assertOk();
        $resposta->assertSee('Ativo no sistema, sem valor contratado');
        $resposta->assertSee('Usa e não paga');
    }

    /**
     * @spec:AC-082 O painel oferece testar a conexão, e a recusa do sistema
     * chega à tela com a mensagem legível — é a única forma de descobrir que a
     * chave está errada sem esperar a sincronização falhar de madrugada.
     */
    public function test_testar_conexao_traz_a_recusa_para_a_tela(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(
                ['erro' => ['codigo' => 'nao_autenticado', 'mensagem' => 'Chave recusada.']], 401
            ),
        ]);

        $this->post(route('integracao.testar', $this->gym))
            ->assertRedirect()
            ->assertSessionHas('erro', 'AlfaGym: Chave recusada.');
    }

    /**
     * @spec:AC-083 O retrato de demonstração enche as telas fora de produção,
     * e RECUSA rodar em produção — dado de exemplo no retrato viraria
     * divergência falsa e, pior, cliente inventado na conferência do corte.
     */
    public function test_o_retrato_de_demonstracao_enche_as_telas_e_recusa_producao(): void
    {
        $this->artisan('app:retrato-de-demonstracao', ['--sistema' => $this->gym->slug])
            ->assertSuccessful();

        $this->assertGreaterThan(0, SistemaCliente::doSistema($this->gym)->count());
        $this->assertNotNull($this->gym->refresh()->sincronizado_em);

        $this->get(route('integracao.clientes'))->assertOk()->assertSee('Academia Corpo em Movimento');

        $this->artisan('app:retrato-de-demonstracao', ['--sistema' => $this->gym->slug, '--limpar' => true])
            ->assertSuccessful();

        $this->assertSame(0, SistemaCliente::doSistema($this->gym)->count());

        app()['env'] = 'production';
        $this->artisan('app:retrato-de-demonstracao')
            ->expectsOutputToContain('não roda em produção')
            ->assertFailed();
    }

    /** @spec:AC-078 O grupo Integração aparece no menu de toda tela do painel. */
    public function test_o_menu_ganha_o_grupo_de_integracao(): void
    {
        $resposta = $this->get(route('centro-controle'));

        $resposta->assertOk();
        $resposta->assertSee('Integração');
        $resposta->assertSee(route('integracao.index'), false);
        $resposta->assertSee(route('integracao.divergencias'), false);
    }
}
