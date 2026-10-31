<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\Cliente;
use App\Models\ClienteTelefone;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAbertura;
use App\Models\SuportePedido;
use App\Models\User;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\DiretorioGestor;
use App\Services\Suporte\Horario;
use App\Services\Suporte\Pedro;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O horário do cliente (Felipe, 31/10/2026, 18:23: "24 h para clientes novos perguntando em que pode
 * ajudar; para quem já é cliente, somente das 08:00 às 18:00, e após esse horário informar que estamos
 * fora do horário e o número do plantão"). A regra é do código: o cliente do cadastro, à noite, nem
 * chega à IA. Números fictícios.
 */
class PedroHorarioDoClienteTest extends TestCase
{
    use RefreshDatabase;

    private const CADASTRO = '5527999998888';

    private const NOVO = '5527911110000';

    private const DO_GESTOR = '5527933334444';

    private const PRE_CLIENTE = '5527955556666';

    private User $felipe;

    private const PLANTAO = 'Em caso de urgência ou emergência, ligue para o nosso plantão: (27) 99000-1111.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 21:00', 'America/Sao_Paulo')); // terça, à noite
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['rossini@teste.local']]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        User::factory()->create(['name' => 'Rossini', 'email' => 'rossini@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        app(Conhecimento::class)->gravarAjuste('contatos', "Comercial — (27) 99000-2222\nPlantão — (27) 99000-1111");
        $academia = Cliente::create(['nome' => 'Academia Exemplo', 'ativo' => true]);
        ClienteTelefone::create(['cliente_id' => $academia->id, 'telefone' => '(27) 99999-8888', 'principal' => true]);
        app(DiretorioGestor::class)->gravar('wsl', now()->toDateTimeString(), [
            ['id' => 13, 'nome' => 'CONDOMINIO JARDIM EXEMPLO', 'fantasia' => 'JARDIM EXEMPLO', 'telefones' => ['(27) 93333-4444'], 'empresa' => 'GW'],
            ['id' => -7, 'nome' => 'LOJA FICTICIA', 'fantasia' => 'LOJA FICTICIA', 'telefones' => ['(27) 95555-6666'], 'pre' => true],
        ], []);
    }

    private function chega(string $texto, string $id, string $de): void
    {
        $valor = ['metadata' => ['phone_number_id' => '111222'], 'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Pessoa']]],
            'messages' => [['from' => $de, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);
        $this->call('POST', route('suporte.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo')], $corpo)->assertOk();
    }

    /** @return string o prompt que a IA recebeu */
    private function responde(array $bloco): string
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->assertOk()->json();
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl',
            'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();

        return (string) $job['prompt'];
    }

    /** @return list<string> o que saiu para o WhatsApp, na ordem */
    private function enviados(): array
    {
        return Http::recorded()->map(fn ($par) => (string) ($par[0]['text']['body'] ?? ''))->filter()->values()->all();
    }

    public function test_cliente_do_cadastro_a_noite_recebe_o_aviso_sem_ia_e_o_pedro_retoma_as_8(): void
    {
        $this->chega('o portão parou', 'wamid.1', self::CADASTRO);

        $this->assertSame(0, SuportePedido::count(), 'nem chega à IA');
        $this->assertSame(['Olá! Nosso atendimento é de segunda a sexta, das 08:00 às 18:00, e agora estamos fora do horário. Sua mensagem ficou registrada e retornamos no próximo horário de atendimento. '.self::PLANTAO],
            $this->enviados());

        // Duas mensagens seguidas: nenhum aviso antes de 2 min; o minuto do agendador completa, antes dos 5 do Gestor.
        Carbon::setTestNow(now()->addSeconds(30));
        $this->chega('é urgente?', 'wamid.2', self::CADASTRO);
        $this->assertCount(1, $this->enviados());
        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame(Pedro::FORA_DO_HORARIO_DE_NOVO, $this->enviados()[1]);
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertCount(2, $this->enviados(), 'respondida, não repete');
        $this->assertSame(0, SuportePedido::count());
        $this->assertSame(0, SuporteGestorAbertura::count(), 'nada se abre');

        // Quarta, 08:00: o Pedro retoma sozinho o que ficou da noite (ainda na janela de 24 h).
        Carbon::setTestNow(Carbon::parse('2026-10-21 08:00', 'America/Sao_Paulo'));
        CentralIaRunner::query()->update(['visto_em' => now()]);
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame(1, SuportePedido::where('situacao', 'pendente')->count());
        $prompt = $this->responde(['resposta' => 'Bom dia! Sobre o portão: me diga o seu nome, por favor.', 'acao' => 'responder']);
        $this->assertStringContainsString('fora do horário, e quem escreveu recebeu o aviso automático', $prompt);
    }

    /** 07:59 ainda é noite para o cliente; 08:00 já é horário (o padrão mudou de 07:00 para 08:00). */
    public function test_o_horario_comeca_as_8(): void
    {
        $this->assertFalse(Horario::aberto(Carbon::parse('2026-10-21 07:59', 'America/Sao_Paulo')));
        $this->assertTrue(Horario::aberto(Carbon::parse('2026-10-21 08:00', 'America/Sao_Paulo')));
        $this->assertFalse(Horario::aberto(Carbon::parse('2026-10-21 18:00', 'America/Sao_Paulo')));
        $this->assertFalse(Horario::aberto(Carbon::parse('2026-10-24 10:00', 'America/Sao_Paulo')), 'sábado');
    }

    public function test_no_horario_o_cliente_do_cadastro_vai_a_ia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 08:00', 'America/Sao_Paulo'));
        $this->chega('o portão parou', 'wamid.1', self::CADASTRO);
        $this->assertSame(1, SuportePedido::count());
    }

    public function test_telefone_de_cliente_do_gestor_e_cliente_e_o_pre_cliente_e_o_novo_sao_atendidos(): void
    {
        $this->chega('boa noite', 'wamid.g', self::DO_GESTOR);
        $this->assertSame(0, SuportePedido::count(), 'cliente do Gestor: o aviso');
        $this->assertStringStartsWith('Olá! Nosso atendimento', $this->enviados()[0]);

        $this->chega('boa noite', 'wamid.p', self::PRE_CLIENTE);
        $this->chega('boa noite', 'wamid.n', self::NOVO);
        $this->assertSame(2, SuportePedido::count(), 'pré-cliente do funil e número novo são clientes novos: o Pedro atende');
    }

    /** Quem é da casa nunca é "cliente", nem com o telefone também num cadastro. */
    public function test_quem_e_da_casa_e_atendido_a_noite_mesmo_com_o_telefone_no_cadastro(): void
    {
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, '+55 27 99999-8888 | Diretor Teste | diretor');
        $this->chega('quantos atendimentos abertos?', 'wamid.1', self::CADASTRO);
        $this->assertSame(1, SuportePedido::count());
    }

    /**
     * Recomendação aprovada em 31/10/2026: à noite, quem escreve de um número desconhecido e se confirma
     * de um cliente que já existe ("sou do Jardim Exemplo, o portão parou") é tratado como cliente — o
     * aviso com o plantão, e nada se abre, mesmo que a IA tenha pedido para abrir.
     */
    public function test_desconhecido_que_se_confirma_cliente_a_noite_recebe_o_aviso_e_nada_se_abre(): void
    {
        $this->chega('Sou a Joana, síndica do Jardim Exemplo, o portão parou', 'wamid.1', self::NOVO);
        $this->responde(['resposta' => 'Joana, vou abrir o chamado do portão.', 'acao' => 'abrir_atendimento', 'resumo' => 'portão parado',
            'cliente' => 'gestor:13', 'solicitante' => ['nome' => 'Joana', 'funcao' => 'síndica']]);

        $c = SuporteConversa::sole();
        $this->assertSame(13, (int) $c->gestor_cliente_id, 'o cliente confirmado fica gravado');
        $this->assertSame('Joana', $c->solicitante_nome);
        $this->assertSame('fora_do_horario', SuportePedido::sole()->acao);
        $this->assertSame(0, SuporteGestorAbertura::count(), 'nada se abre');
        $this->assertNotContains('Joana, vou abrir o chamado do portão.', $this->enviados());
        $this->assertStringStartsWith('Olá! Nosso atendimento', $this->enviados()[0]);
    }

    /** O cliente novo da noite pergunta por serviço: o pedido leva a regra do preço e a do horário da equipe. */
    public function test_o_pedido_do_cliente_novo_proibe_preco_e_fala_do_horario_da_equipe(): void
    {
        $this->chega('vocês instalam câmeras? quanto custa?', 'wamid.1', self::NOVO);
        $prompt = $this->responde(['resposta' => 'Olá! Aqui é o Pedro, do suporte.', 'acao' => 'responder']);

        $this->assertStringContainsString('PREÇO: nunca informe preço', $prompt);
        $this->assertStringContainsString('de segunda a sexta, das 08:00 às 18:00', $prompt);
    }

    /** Sem máquina, à noite: o cliente novo ouve "fora do horário", com o plantão. */
    public function test_sem_maquina_a_noite_o_aviso_e_de_fora_do_horario(): void
    {
        $this->chega('olá', 'wamid.1', self::NOVO);
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('suporte:pedro')->assertSuccessful();

        $this->assertSame([Pedro::FORA_DO_AR.' '.self::PLANTAO], $this->enviados());
    }

    /** Sem máquina, quem é da casa ouve que o Pedro está sem as consultas — nada de "fora do horário" nem plantão. */
    public function test_sem_maquina_quem_e_da_casa_ouve_que_esta_sem_as_consultas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00', 'America/Sao_Paulo'));
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, '+55 27 91111-0000 | Diretor Teste | diretor');
        $this->chega('agenda de hoje?', 'wamid.1', self::NOVO);
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('suporte:pedro')->assertSuccessful();

        $this->assertSame([Pedro::SEM_CONSULTAS], $this->enviados());
    }

    /** A IA falhou de madrugada: nada de "em instantes"; o colega retorna no próximo horário. */
    public function test_falha_da_ia_a_noite_nao_promete_colega_em_instantes(): void
    {
        $this->chega('olá, preciso de ajuda', 'wamid.1', self::NOVO);
        SuportePedido::query()->update(['situacao' => 'reservado', 'reservado_em' => now()]);
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('suporte:pedro')->assertSuccessful();

        $this->assertSame([Pedro::FALLBACK_FORA.' '.self::PLANTAO], $this->enviados());
        $this->assertSame('humano', SuporteConversa::sole()->situacao, 'a conversa fica para a equipe');
    }

    /** A IA falhou com quem é da casa: ele consulta o Pedro, não é atendimento — nada vai à fila da equipe. */
    public function test_falha_da_ia_com_quem_e_da_casa_nao_vai_a_fila(): void
    {
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, '+55 27 91111-0000 | Diretor Teste | diretor');
        $this->chega('agenda de amanhã?', 'wamid.1', self::NOVO);
        SuportePedido::query()->update(['situacao' => 'reservado', 'reservado_em' => now()]);
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('suporte:pedro')->assertSuccessful();

        $this->assertSame([Pedro::NAO_CONSEGUI], $this->enviados());
        $this->assertSame('pedro', SuporteConversa::sole()->situacao);
        $this->chega('e agora?', 'wamid.2', self::NOVO);
        $this->assertSame(1, SuportePedido::where('situacao', 'pendente')->count(), 'a próxima mensagem volta ao Pedro');
    }
}
