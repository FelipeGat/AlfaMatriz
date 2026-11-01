<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use App\Models\SuportePedido;
use App\Models\SuporteRoteiro;
use App\Models\User;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A 2ª rodada da comunicação do Pedro (docs/specs/pedro-comunicacao, FR-016 a FR-022; Felipe,
 * 31/10/2026, 20:19): período, prazo, comercial curto, preço barrado na entrega, só o que ele faz,
 * e nada deduzido. Na bateria de 31/10, a agenda da semana que vem terminou nos atendimentos sem data,
 * o cliente ouviu "até 3 horas", e o suporte ouviu que o 1203 não tinha visita (tinha). Dados fictícios.
 */
class PedroComunicacaoSegundaRodadaTest extends TestCase
{
    use RefreshDatabase;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00', 'America/Sao_Paulo')); // segunda
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'dono@teste.local', 'services.suporte_pedro.editores' => ['dono@teste.local'], 'services.suporte_pedro.gestor_gravar' => false]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->dono = User::factory()->create(['email' => 'dono@teste.local']);
        CentralIaRunner::create(['user_id' => $this->dono->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, "+55 27 91111-0001 | Carlos | diretor\n+55 27 91111-0003 | Diego | suporte");
        $this->retratoDoGestor('agendado');
    }

    private function runner(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->dono, ['central-ia-runner']);
    }

    private function retratoDoGestor(string $situacaoDaPrimeira): void
    {
        $this->runner();
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->subMinutes(8)->toIso8601String(),
            'clientes' => [['id' => 13, 'nome' => 'CONDOMINIO JARDIM EXEMPLO', 'fantasia' => 'JARDIM EXEMPLO', 'telefones' => ['(27) 92222-0001'], 'empresa' => 'GW']],
            'atendimentos' => [
                ['id' => 1, 'numero' => '1201', 'cliente_id' => 13, 'descricao' => 'Portão', 'status' => 'aberto', 'criado_em' => '2026-10-28 09:00:00', 'funcionarios' => [31]],
                ['id' => 2, 'numero' => '1202', 'cliente_id' => 13, 'descricao' => 'Câmera', 'status' => 'aguardando_peca', 'criado_em' => '2026-10-21 09:00:00', 'funcionarios' => [31]],
            ],
            'equipe' => [['id' => 31, 'nome' => 'Lucas Prado', 'telefones' => []]],
            'visitas' => [
                ['id' => 1, 'atendimento_id' => 1, 'tecnico_id' => 31, 'inicio' => '2026-11-04 08:00', 'fim' => '2026-11-04 10:00', 'situacao' => $situacaoDaPrimeira, 'observacoes' => '', 'adicionais' => []],
                ['id' => 2, 'atendimento_id' => 1, 'tecnico_id' => 31, 'inicio' => '2026-11-10 08:00', 'fim' => '2026-11-10 09:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
                ['id' => 3, 'atendimento_id' => 2, 'tecnico_id' => 31, 'inicio' => '2026-10-30 08:00', 'fim' => '2026-10-30 09:00', 'situacao' => 'finalizado', 'observacoes' => '', 'adicionais' => []],
            ],
        ])->assertOk();
    }

    private function prompt(string $telefone, array $mensagens, array $conversa = []): string
    {
        SuporteConversa::query()->delete();
        $c = SuporteConversa::create(['telefone' => $telefone, 'ultima_entrada_em' => now()] + $conversa);
        foreach ($mensagens as $i => [$direcao, $texto]) {
            $c->mensagens()->create(['wamid' => 'wamid.'.uniqid().$i, 'direcao' => $direcao, 'tipo' => 'text', 'texto' => $texto, 'enviada_em' => now()]);
        }

        return app(Contexto::class)->prompt($c->fresh());
    }

    /** FR-016 a FR-019, FR-021 e FR-022: cada regra no pedido de quem ela serve, e só nele. */
    public function test_as_regras_novas_vao_nos_pedidos_certos(): void
    {
        $casa = $this->prompt('5527911110001', [['entrada', 'agenda de amanhã']]);
        $desconhecido = $this->prompt('5527933330002', [['entrada', 'vocês fazem câmeras?']]);
        $cliente = $this->prompt('5527922220001', [['entrada', 'o portão parou']], ['gestor_cliente_id' => 13, 'solicitante_nome' => 'Marta', 'identificada_em' => now()]);

        $this->assertStringContainsString(Contexto::PERIODO, $casa);
        $this->assertStringContainsString('## O QUE VOCÊ FAZ POR ELE', $casa);
        $this->assertStringNotContainsString(Contexto::PRAZO, $casa);
        foreach ([$desconhecido, $cliente] as $p) {
            $this->assertStringContainsString(Contexto::PRAZO, $p);
            $this->assertStringContainsString(Contexto::COMERCIAL_CURTO, $p);
            $this->assertStringNotContainsString(Contexto::PERIODO, $p);
            $this->assertStringNotContainsString('## O QUE VOCÊ FAZ POR ELE', $p);
        }
        $this->assertStringContainsString(Contexto::ACOES_DO_CLIENTE, $cliente);
        // Nada deduzido (FR-021), nos três pedidos, porque está na regra comum.
        foreach ([$casa, $desconhecido, $cliente] as $p) {
            $this->assertStringContainsString('Não deduza', $p);
        }
        $this->assertStringNotContainsString('quem está com ele e o próximo passo;', $this->prompt('5527911110003', [['entrada', 'e o 1201?']]));
    }

    /** FR-018: o prazo do roteiro fica para a equipe; a mensagem que prometia "até 3 horas" mudou. */
    public function test_o_cliente_nao_recebe_o_prazo_do_roteiro(): void
    {
        $roteiro = SuporteRoteiro::where('titulo', 'Portão parado')->sole();
        $this->assertSame(3, $roteiro->prazo_horas, 'o prazo continua valendo para a equipe');
        $this->assertStringNotContainsString('3 horas', $roteiro->mensagem);

        $p = $this->prompt('5527922220001', [['entrada', 'o portão parou']], ['gestor_cliente_id' => 13, 'solicitante_nome' => 'Marta', 'identificada_em' => now()]);
        $this->assertStringContainsString('Portão parado', $p);
        $this->assertStringNotContainsString('prazo de até', $p);
        $this->assertStringNotContainsString('até 3 horas', $p);
    }

    /** FR-019: só o que o perfil faz; o resto vai como "não faz". */
    public function test_o_que_o_pedro_faz_depende_do_perfil(): void
    {
        $diretor = AcessoInterno::oQueFaz(['perfil' => 'diretor']);
        $suporte = AcessoInterno::oQueFaz(['perfil' => 'suporte']);

        $this->assertStringContainsString('lançar, corrigir ou cancelar visitas', $diretor);
        $this->assertStringContainsString('consultar o financeiro', $diretor);
        $this->assertStringContainsString('resumo do dia', $diretor);
        $this->assertStringNotContainsString('lançar, corrigir ou cancelar visitas', $suporte);
        $this->assertStringNotContainsString('consultar o financeiro', $suporte);
        $this->assertStringContainsString('registrar atendimento', $suporte);
        foreach ([$diretor, $suporte] as $bloco) {
            $this->assertMatchesRegularExpression('/Não faz \(não ofereça\):.*ver andamentos.*mandar boleto/u', $bloco);
        }
    }

    /** FR-021: o atendimento leva a próxima visita marcada; a cancelada, a finalizada e a passada não contam. */
    public function test_o_atendimento_leva_a_proxima_visita(): void
    {
        $p = $this->prompt('5527911110003', [['entrada', 'e o 1201?']]);
        $this->assertStringContainsString('etapa: aberto — aberto em 28/10 — com Lucas — próxima visita: 04/11 08:00 (Lucas) — Portão', $p);
        $this->assertMatchesRegularExpression('/nº 1202 — [^\n]*com Lucas — Câmera/u', $p, 'a visita de 30/10 já passou');

        foreach (['cancelado', 'finalizado'] as $situacao) {
            $this->retratoDoGestor($situacao);
            $p = $this->prompt('5527911110003', [['entrada', 'e o 1201?']]);
            $this->assertStringContainsString('próxima visita: 10/11 08:00 (Lucas)', $p, $situacao);
        }
    }

    /** FR-020: o valor em dinheiro sai da resposta ao cliente; a frase vira o aviso do comercial. */
    public function test_sem_preco_para_cliente(): void
    {
        [$texto, $barrou] = Contexto::semPrecoParaCliente('Fazemos o monitoramento com câmeras IP. Um projeto assim fica em torno de R$ 2.900 por mês! Quer que eu passe ao comercial?');
        $this->assertTrue($barrou);
        $this->assertSame('Fazemos o monitoramento com câmeras IP. Quer que eu passe ao comercial? '.Contexto::SEM_VALOR, $texto);

        [$texto] = Contexto::semPrecoParaCliente('A instalação sai por 18 mil reais.');
        $this->assertSame(Contexto::SEM_VALOR, $texto);

        [$texto] = Contexto::semPrecoParaCliente("Opções:\n• Câmeras — R$ 1.200,00\n• Alarme\nQual combina mais?");
        $this->assertSame("Opções:\n• Alarme\nQual combina mais? ".Contexto::SEM_VALOR, $texto);

        [$texto, $barrou] = Contexto::semPrecoParaCliente('Atendemos 40 apartamentos sem problema. São 16 câmeras no caso que citei.');
        $this->assertFalse($barrou, 'número que não é dinheiro fica');
        $this->assertSame('Atendemos 40 apartamentos sem problema. São 16 câmeras no caso que citei.', $texto);
    }

    /** FR-020 na entrega: cliente não recebe o valor; quem é da casa recebe o financeiro como sempre. */
    public function test_a_trava_vale_na_entrega_so_para_quem_nao_e_da_casa(): void
    {
        $this->responder('5527933330005', 'quanto custa mais ou menos?', 'Fazemos o monitoramento de câmeras. Um caso parecido ficou em R$ 2.900/mês. O que você precisa monitorar?');
        $saida = SuporteMensagem::where('direcao', 'pedro')->latest('id')->value('texto');
        $this->assertStringNotContainsString('R$', $saida);
        $this->assertStringContainsString('O que você precisa monitorar? '.Contexto::SEM_VALOR, $saida);
        $this->assertSame('valor em dinheiro retirado da resposta ao cliente', SuportePedido::latest('id')->value('erro'));

        $this->responder('5527911110001', 'quanto temos de cobrança vencida?', 'São 24 cobranças vencidas, somando R$ 31.480,50.');
        $this->assertSame('São 24 cobranças vencidas, somando R$ 31.480,50.', SuporteMensagem::where('direcao', 'pedro')->latest('id')->value('texto'));
    }

    private function responder(string $de, string $texto, string $resposta): void
    {
        $valor = ['metadata' => ['phone_number_id' => '111222'], 'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Fulano']]],
            'messages' => [['from' => $de, 'id' => 'wamid.in'.uniqid(), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);
        $this->call('POST', route('suporte.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo')], $corpo)->assertOk();
        $this->runner();
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl',
            'texto' => "<<<PEDRO\n".json_encode(['resposta' => $resposta, 'acao' => 'responder', 'resumo' => 'teste'], JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();
    }
}
