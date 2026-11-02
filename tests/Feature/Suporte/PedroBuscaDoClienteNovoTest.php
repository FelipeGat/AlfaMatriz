<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\Cliente;
use App\Models\SuporteConversa;
use App\Models\User;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A busca do cliente novo (Felipe, 02/11/2026). Ele escreveu "Duque de Caxias" do número de teste e o
 * Pedro não achou o "CDC COLEGIO DUQUE DE CAXIAS": a busca exigia o nome inteiro. Agora é a busca larga,
 * com o candidato de nome só parecido confirmado antes; e, pela LGPD, mais de um cliente de nome
 * parecido para um número fora do cadastro pede o CNPJ, sem o Pedro saber nome nem cidade de nenhum
 * ("se for por exemplo fit club, tem duas, uma em Vitória e outra em Cariacica"). Dados fictícios.
 */
class PedroBuscaDoClienteNovoTest extends TestCase
{
    use RefreshDatabase;

    private const DESCONHECIDO = '5527988887777';

    private User $felipe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0']]);
        config(['services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['rossini@teste.local']]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        User::factory()->create(['name' => 'Rossini', 'email' => 'rossini@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);

        $cliente = fn (int $id, string $nome, string $cidade, array $extra = []) => $extra + ['id' => $id, 'nome' => $nome, 'fantasia' => $nome, 'razao' => '',
            'cidade' => $cidade, 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => []];
        $this->runner();
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(), 'atendimentos' => [], 'clientes' => [
            // O caso do Felipe: o nome do cadastro tem um prefixo e uma palavra que a pessoa não diz.
            $cliente(501, 'XPT COLEGIO BARAO DE MAUA', 'SANTA LEOPOLDINA'),
            // Palavra em comum com o de cima, mas não é o nome que a pessoa disse.
            $cliente(502, 'CONDOMINIO RESIDENCIAL MAUA', 'MARATAIZES'),
            $cliente(503, 'ACADEMIA CORPO EM FORMA', 'IBIRACU'),
            // O exemplo das duas Fit Club, com outro nome: mesmo nome, cidades diferentes.
            $cliente(601, 'POWER GYM', 'VITORIA', ['razao' => 'POWER GYM ACADEMIA LTDA', 'documento' => '11222333000181', 'telefones' => ['(27) 99111-2233']]),
            // Como a Fit Club de Cariacica: a cidade e "academia" no nome, que a pessoa não diz.
            $cliente(602, 'ACADEMIA POWER GYM (CARIACICA)', 'CARIACICA', ['documento' => '44.555.666/0001-81']),
            // A Revizza de 23/10: a cidade citada não traz os cadastros "(VILA VELHA)".
            $cliente(40, 'REVIZZA', 'VITORIA'),
            $cliente(41, 'PADARIA PAO DOURADO (VILA VELHA)', 'VILA VELHA'),
            $cliente(42, 'MERCADO SOL (VILA VELHA)', 'VILA VELHA'),
            $cliente(43, 'OTICA BRILHO (VILA VELHA)', 'VILA VELHA'),
            // A pessoa diz o próprio nome e o do condomínio: a cliente de mesmo primeiro nome não entra.
            $cliente(701, 'ZULEICA MORAES', 'SERRA'),
            $cliente(702, 'RESIDENCIAL ATLANTICO', 'GUARAPARI'),
            $cliente(703, 'PADARIA MORAES', 'SERRA'),
            $cliente(704, 'MORAES ENGENHARIA', 'SERRA'),
            $cliente(705, 'OTICA MORAES', 'SERRA'),
        ]])->assertOk();
    }

    private function runner(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
    }

    private function chega(string $texto, string $id, string $de = self::DESCONHECIDO): void
    {
        $valor = ['metadata' => ['phone_number_id' => '111222'], 'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Joaquim']]],
            'messages' => [['from' => $de, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);
        $this->call('POST', route('suporte.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo')], $corpo)->assertOk();
    }

    /** @return array{0: int, 1: string} o pedido e o texto que a IA recebe */
    private function pedido(): array
    {
        $this->runner();
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();

        return [$job['id'], (string) $job['prompt']];
    }

    private function responde(int $id, array $bloco): void
    {
        $this->runner();
        $this->postJson(route('suporte.runner.resposta', $id), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();
    }

    public function test_nome_curto_acha_o_cadastro_com_prefixo_e_a_confirmacao_vem_antes(): void
    {
        $this->chega('boa tarde, sou do Barão de Mauá, o portão da escola não abre', 'wamid.1');
        [$id, $prompt] = $this->pedido();

        $this->assertStringContainsString('gestor:501: XPT COLEGIO BARAO DE MAUA — manutenção e segurança eletrônica (Gestor, INVEST)'.Contexto::NOME_PARECIDO, $prompt);
        $this->assertStringNotContainsString('SANTA LEOPOLDINA', $prompt, 'antes de confirmar, só o nome');
        $this->assertStringNotContainsString('gestor:502', $prompt, '"Mauá" sozinho pesa menos que a metade do Barão de Mauá');
        $this->assertStringNotContainsString('RESIDENCIAL MAUA', $prompt);
        $this->assertStringNotContainsString(Contexto::PEDIR_CNPJ, $prompt, 'um só candidato não pede CNPJ');
        $this->assertStringContainsString('Candidato marcado NOME PARECIDO: pergunte de forma simples se é ele', $prompt);

        $this->responde($id, ['resposta' => 'É o XPT Colégio Barão de Mauá?', 'acao' => 'responder', 'cliente' => null]);
        $this->assertNull(SuporteConversa::sole()->gestor_cliente_id);
        $this->chega('isso', 'wamid.2');
        [$id] = $this->pedido();
        $this->responde($id, ['resposta' => 'E com quem eu falo?', 'acao' => 'responder', 'cliente' => 'gestor:501']);
        $this->assertSame(501, SuporteConversa::sole()->gestor_cliente_id);
    }

    public function test_dois_de_nome_parecido_pedem_o_cnpj_sem_o_pedro_saber_nenhum(): void
    {
        $this->chega('oi, aqui é da Power Gym, a câmera da recepção apagou', 'wamid.1');
        [$id, $prompt] = $this->pedido();

        $this->assertStringContainsString(Contexto::PEDIR_CNPJ, $prompt);
        foreach (['gestor:601', 'gestor:602', 'POWER GYM', 'VITORIA', 'CARIACICA', 'CANDIDATOS'] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, "nada dos dois cadastros: {$dado}");
        }
        // Código de um deles, sem o CNPJ, não vale: não estava entre os oferecidos.
        $this->responde($id, ['resposta' => 'Pode me passar o CNPJ, para eu localizar o cadastro?', 'acao' => 'responder', 'cliente' => 'gestor:601']);
        $this->assertNull(SuporteConversa::sole()->gestor_cliente_id);

        // O CNPJ, escrito com pontuação, acha o de Cariacica, e só ele.
        $this->chega('o CNPJ é 44.555.666/0001-81', 'wamid.2');
        [$id, $prompt] = $this->pedido();
        $this->assertStringContainsString('gestor:602: ACADEMIA POWER GYM (CARIACICA) — manutenção e segurança eletrônica (Gestor, INVEST)'.Contexto::PELO_CNPJ, $prompt);
        foreach (['gestor:601', 'VITORIA', Contexto::PEDIR_CNPJ, Contexto::CNPJ_NAO_CONFERE] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, "só o do CNPJ: {$dado}");
        }
        $this->responde($id, ['resposta' => 'Localizei. E com quem eu falo?', 'acao' => 'responder', 'cliente' => 'gestor:602']);
        $this->assertSame(602, SuporteConversa::sole()->gestor_cliente_id);
    }

    public function test_cnpj_que_nao_bate_nao_confirma_nada_nem_diz_qual_chegou_perto(): void
    {
        $this->chega('sou da power gym, CNPJ 77.888.999/0001-81', 'wamid.1');
        [$id, $prompt] = $this->pedido();

        $this->assertStringContainsString(Contexto::CNPJ_NAO_CONFERE, $prompt);
        foreach (['gestor:601', 'gestor:602', 'POWER GYM', 'VITORIA', 'CARIACICA', Contexto::PEDIR_CNPJ] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, $dado);
        }
        $this->responde($id, ['resposta' => 'Não localizei o cadastro por esse CNPJ, pode conferir?', 'acao' => 'responder', 'cliente' => 'gestor:601']);
        $this->assertNull(SuporteConversa::sole()->gestor_cliente_id);
    }

    public function test_cnpj_com_digito_errado_ou_telefone_nao_contam_como_cnpj(): void
    {
        $this->assertSame(['44555666000181'], Contexto::documentos('cnpj 44.555.666/0001-81, liga no (27) 99111-2233'));
        $this->assertSame([], Contexto::documentos('cnpj 44.555.666/0001-82'), 'dígito errado: pede de novo, e não "não confere"');
        $this->assertSame([], Contexto::documentos('meu celular 27991112233 e o fixo 2733894253'));
        $this->assertSame([], Contexto::documentos('00000000000000'));

        $this->chega('sou da power gym, o CNPJ é 44.555.666/0001-82', 'wamid.1');
        [, $prompt] = $this->pedido();
        $this->assertStringContainsString(Contexto::PEDIR_CNPJ, $prompt);
        $this->assertStringNotContainsString(Contexto::CNPJ_NAO_CONFERE, $prompt);
    }

    public function test_numero_do_cadastro_de_um_deles_nao_pede_cnpj_nem_cita_o_outro(): void
    {
        $this->chega('aqui é da power gym, a câmera apagou', 'wamid.1', '5527991112233');
        [, $prompt] = $this->pedido();

        $this->assertStringContainsString('gestor:601: POWER GYM ACADEMIA LTDA (POWER GYM), VITORIA', $prompt);
        foreach (['gestor:602', 'CARIACICA', Contexto::PEDIR_CNPJ] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, $dado);
        }
    }

    public function test_palavra_solta_nao_poe_nome_de_cliente_na_conversa(): void
    {
        $this->chega('minha academia está com o portão travado', 'wamid.1');
        [, $prompt] = $this->pedido();

        $this->assertStringNotContainsString('gestor:503', $prompt);
        $this->assertStringNotContainsString('CORPO EM FORMA', $prompt);
        $this->assertStringNotContainsString(Contexto::PEDIR_CNPJ, $prompt);
    }

    public function test_busca_do_cliente_novo_pela_raridade_e_pelo_que_foi_dito(): void
    {
        $d = app(DiretorioGestor::class);
        $ids = fn (string $texto) => array_map(fn ($x) => [$x['cliente']['id'], $x['inteiro']], $d->candidatosPorNome($texto));

        $this->assertSame([[501, false]], $ids('sou do Barão de Mauá'));
        $this->assertSame([[501, true]], $ids('colégio barão de mauá'), 'o nome inteiro continua inteiro');
        $this->assertSame([[502, true]], $ids('residencial mauá'));
        $this->assertSame([[40, false]], $ids('sou da revissa em vila velha'), 'a cidade não traz os "(VILA VELHA)"; escrito diferente, confirma antes');
        $this->assertSame([[601, true], [602, false]], $ids('power gym'), 'a de Cariacica entra: "academia" e a cidade do nome quase não pesam');
        $this->assertSame([], $ids('minha academia'), '"academia" é palavra de muitos cadastros: não basta para pôr um nome na conversa');
        $this->assertSame([[702, true]], $ids('sou a Zuleica, do Residencial Atlântico'), 'a Zuleica é a pessoa: o nome dito por inteiro é o do condomínio');
        $this->assertSame([[701, true]], $ids('sou a Zuleica Moraes'));
        $this->assertSame([], $ids('bom dia'));
        // A busca da casa continua a mesma (acesso-interno.md): ela não passa pelas travas do cliente novo.
        $this->assertSame([501, 502], array_column($d->buscarClientes('barão de mauá'), 'id'));
    }

    /** A bateria de 02/11/2026: com poucos cadastros, "academia" não é rara, e a de Cariacica ficava fora da régua. */
    public function test_com_poucos_cadastros_a_de_outra_cidade_continua_nome_parecido(): void
    {
        $d = app(DiretorioGestor::class);
        $d->gravar('wsl', now()->toDateTimeString(), [
            ['id' => 601, 'nome' => 'POWER GYM', 'fantasia' => 'POWER GYM', 'razao' => 'POWER GYM ACADEMIA LTDA', 'cidade' => 'VITORIA', 'empresa' => 'INVEST', 'telefones' => []],
            ['id' => 602, 'nome' => 'ACADEMIA POWER GYM (CARIACICA)', 'fantasia' => 'ACADEMIA POWER GYM (CARIACICA)', 'razao' => '', 'cidade' => 'CARIACICA', 'empresa' => 'INVEST', 'telefones' => []],
        ], []);

        $this->assertSame([601, 602], array_column(array_column($d->candidatosPorNome('oi, aqui é da Power Gym'), 'cliente'), 'id'));
    }

    public function test_dois_clientes_do_matriz_de_mesmo_nome_tambem_pedem_cnpj(): void
    {
        Cliente::create(['nome' => 'Residencial Aurora Boreal', 'cidade' => 'Linhares', 'ativo' => true, 'cpf_cnpj' => '11222333000181']);
        Cliente::create(['nome' => 'Residencial Aurora Boreal', 'cidade' => 'Colatina', 'ativo' => true, 'cpf_cnpj' => '44555666000181']);

        $this->chega('sou do aurora boreal, o sistema não abre', 'wamid.1');
        [$id, $prompt] = $this->pedido();
        $this->assertStringContainsString(Contexto::PEDIR_CNPJ, $prompt);
        foreach (['matriz:', 'Linhares', 'Colatina'] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, $dado);
        }

        $this->responde($id, ['resposta' => 'Pode me passar o CNPJ, para eu localizar o cadastro?', 'acao' => 'responder', 'cliente' => null]);
        $this->chega('11.222.333/0001-81', 'wamid.2');
        [, $prompt] = $this->pedido();
        $this->assertMatchesRegularExpression('/matriz:\d+: Residencial Aurora Boreal — sistemas Alfa \(Matriz\)'.preg_quote(Contexto::PELO_CNPJ, '/').'/u', $prompt);
        $this->assertStringNotContainsString('Colatina', $prompt);
        $this->assertStringNotContainsString('Linhares', $prompt, 'o do CNPJ vai sem a cidade');
    }
}
