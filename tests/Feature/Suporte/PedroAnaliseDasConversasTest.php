<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\Cliente;
use App\Models\Notificacao;
use App\Models\SuporteAvaliacao;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAbertura;
use App\Models\SuportePedido;
use App\Models\User;
use App\Services\Suporte\Atendimento;
use App\Services\Suporte\CanalWhatsApp;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use App\Services\Suporte\Encerramento;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as Pedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O que a análise das conversas de clientes mostrou (Felipe, 06/11/2026: "analise os erros ou dificuldades do
 * Pedro para podermos ser assertivos com o cliente"), antes de todos os contatos passarem pelo 4042-4157. Cada
 * teste repete o que aconteceu nas conversas lidas, com a IA e o Gestor falsos e os dados fictícios (os CNPJ só têm o
 * dígito verificador certo).
 */
class PedroAnaliseDasConversasTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFONE = '5527988887777';

    /** O CNPJ do colégio, que não se parece com nenhum nome de que a conversa fala. */
    private const CNPJ_DO_COLEGIO = '11.222.333/0001-81';

    private const CNPJ_DE_NINGUEM = '12.312.312/0001-10';

    private const CNPJ_DA_OUTRA_EMPRESA = '55566677000183';

    private const CPF = '529.982.247-25';

    private const CATRACAS = 'As duas catracas estão com problemas. Ao passar o cartão o visor continua com a mensagem "Apresente o cartão" e não libera a entrada.';

    private User $felipe;

    private User $alexandre;

    /** @var list<array{acao: string, conversa: int}> */
    private array $pedidosAoGestor = [];

    private ?int $statusDoSuporte = null;

    private bool $gestorForaDoAr = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '1179318548601637', 'token' => null, 'app_secret' => 'segredo-app', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_gestor' => ['url' => 'https://gestor.teste', 'token' => 'segredo-integracao'],
            'services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['alexandre@teste.local'],
            'services.suporte_pedro.responsavel_chamados' => 'alexandre@teste.local', 'services.suporte_pedro.empresas' => ['INVEST', 'GW', 'DELTA']]);
        Http::fake(function (Pedido $r) {
            if (! preg_match('#/conversas/(\d+)/(\w+)$#', $r->url(), $m)) {
                return Http::response([], 404);
            }
            if ($m[2] === 'suporte' && $this->gestorForaDoAr) {
                throw new ConnectionException('o Gestor não respondeu');
            }
            $this->pedidosAoGestor[] = ['acao' => $m[2], 'conversa' => (int) $m[1]];
            if ($m[2] === 'suporte' && $this->statusDoSuporte) {
                return Http::response(['motivo' => 'conversa_com_setor'], $this->statusDoSuporte);
            }

            return Http::response($m[2] === 'enviar' ? ['wamid' => 'wamid.G'.count($this->pedidosAoGestor), 'mensagem_id' => 1] : ['ok' => true]);
        });
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        $this->alexandre = User::factory()->create(['name' => 'Alexandre Blank', 'email' => 'alexandre@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        $this->retrato();
    }

    /** @param array<int, array>|null $equipe */
    private function retrato(?array $equipe = null, array $atendimentos = []): void
    {
        $c = fn (int $id, string $nome, array $extra = []) => $extra + ['id' => $id, 'nome' => $nome, 'fantasia' => $nome, 'razao' => '', 'cidade' => 'SERRA', 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => []];
        $atendimento = fn (string $numero, string $status, string $descricao, string $criado = '2026-11-03 09:00:00') => ['id' => (int) $numero, 'numero' => $numero, 'cliente_id' => 9, 'descricao' => $descricao, 'status' => $status,
            'empresa_id' => 3, 'assunto_id' => 3, 'criado_em' => $criado, 'funcionarios' => []];
        app(DiretorioGestor::class)->gravar('wsl', now()->toDateTimeString(), [
            $c(9, 'CDC COLEGIO EXEMPLO', ['documento' => self::CNPJ_DO_COLEGIO]),
            // Os dois de nome parecido com o que a pessoa diz, que nada têm a ver com o CNPJ dela.
            $c(601, 'FORCE GYM', ['documento' => '44.555.666/0001-81']),
            $c(602, 'ACADEMIA FORCE GYM (SERRA)'),
            // De nome com as palavras do problema: o que trouxe estes ao colégio em 03/11.
            $c(610, 'ZK CARIACICA (CATRACA)'),
            $c(611, 'KARTAN ACADEMIA'),
            $c(612, 'LIBERA MODAS'),
            // A cidade é comum e pesa pouco, como na produção: é por isso que "catraca" decidia o nome acima.
            ...array_map(fn ($i) => $c(640 + $i, "LOJA {$i} (CARIACICA)"), range(1, 14)),
            // As palavras do registro da escolha no menu.
            $c(630, 'SUPORTE MENU'),
            // "Bom dia as…" soa como "dias".
            $c(631, 'G DIAS'),
            // Pessoa física: o CPF dela não diz o nome a quem só digitou o número.
            $c(620, 'JOANA EXEMPLO DA SILVA', ['documento' => self::CPF]),
            // De empresa que o Pedro não atende.
            $c(900, 'SISTEMA EXEMPLO', ['empresa_id' => 4, 'empresa' => 'ALFA', 'documento' => self::CNPJ_DA_OUTRA_EMPRESA]),
        ], $atendimentos ?: [
            $atendimento('837', 'em_atendimento', "Assunto: PORTÃO / ACESSO\n\nPortão da garagem não fecha\n\n(Aberto pelo Pedro, suporte no WhatsApp — Marta, +55 (27) 98888-7777.)"),
            $atendimento('782', 'concluido', "Assunto: PORTÃO / ACESSO\n\nCâmera da entrada sem imagem", '2026-10-20 09:00:00'),
        ], [['id' => 3, 'nome' => 'PORTÃO / ACESSO', 'tipo' => 'SERVICO', 'categoria' => 'CONTROLE DE ACESSO', 'empresa_id' => 3]],
            $equipe ?? [['id' => 19, 'nome' => 'Alexandre Blank Lopes', 'telefones' => ['(27) 3109-3265'], 'usuario' => 'Alexandre@Teste.local'], ['id' => 20, 'nome' => 'Outro Técnico', 'telefones' => ['(27) 99999-0000'], 'usuario' => 'outro@teste.local']]);
    }

    private function repasse(string $texto, string $id, array $estado): void
    {
        $valor = ['metadata' => ['phone_number_id' => '1179318548601637'], 'contacts' => [['wa_id' => self::TELEFONE, 'profile' => ['name' => 'Joaquim']]],
            'messages' => [['from' => self::TELEFONE, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);
        $this->call('POST', route('suporte.webhook.gestor'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer segredo-integracao',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo-app'), 'HTTP_X_GESTOR_ESTADO' => json_encode($estado)], $corpo)->assertOk();
    }

    private function estado(string $setor = 'aguardando_menu', bool $comPedro = true, int $conversa = 55): array
    {
        return [self::TELEFONE => ['conversa_id' => $conversa, 'setor' => $setor, 'com_pedro' => $comPedro, 'suporte_no_matriz' => $setor === 'suporte']];
    }

    /** @return string o prompt que a IA recebeu */
    private function responde(array $bloco): string
    {
        $job = $this->proximo();
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();

        return (string) $job['prompt'];
    }

    private function proximo(): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);

        return $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->assertOk()->json();
    }

    /** O que a máquina faz com a abertura: reserva e devolve o número. @return array o que a máquina recebeu */
    private function maquinaGrava(SuporteGestorAbertura $a, string $numero, ?string $modo = null): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $lista = $this->postJson(route('suporte.runner.aberturas'), ['maquina' => 'wsl'])->assertOk()->json('aberturas');
        $this->postJson(route('suporte.runner.abertura-feita', $a->id), ['maquina' => 'wsl', 'numero' => $numero, 'modo' => $modo ?? $a->modo])->assertOk();

        return $lista[0];
    }

    /** Já se identificou, como cliente nº 9 e de número fora do cadastro. */
    private function identificada(array $extra = []): SuporteConversa
    {
        return SuporteConversa::create($extra + ['telefone' => self::TELEFONE, 'gestor_cliente_id' => 9, 'gestor_conversa_id' => 55, 'gestor_setor' => 'aguardando_menu', 'solicitante_nome' => 'Joaquim', 'ultima_entrada_em' => now()]);
    }

    private function chamado(string $resumo, string $id): SuporteGestorAbertura
    {
        $this->repasse($resumo, $id, $this->estado(SuporteConversa::sole()->gestor_setor ?? 'aguardando_menu'));
        $this->responde(['resposta' => 'Vou encaminhar para a equipe técnica.', 'acao' => 'abrir_atendimento', 'resumo' => $resumo, 'assunto_id' => 3]);

        return SuporteGestorAbertura::orderByDesc('id')->firstOrFail();
    }

    /** @return list<string> */
    private function doPedro(): array
    {
        return SuporteConversa::sole()->mensagens()->reorder()->where('direcao', 'pedro')->orderBy('id')->pluck('texto')->all();
    }

    private function acoes(): array
    {
        return array_column($this->pedidosAoGestor, 'acao');
    }

    // ---- 1. O CNPJ certo acha o cliente

    public function test_cnpj_certo_acha_o_cliente_mesmo_com_outros_de_nome_parecido(): void
    {
        $this->repasse('Somos da Force Gym. CNPJ '.self::CNPJ_DO_COLEGIO, 'wamid.1', $this->estado());
        $prompt = $this->responde(['resposta' => 'Localizei o cadastro. Qual é o seu nome?', 'acao' => 'responder', 'cliente' => 'gestor:9']);

        $this->assertStringContainsString('gestor:9: CDC COLEGIO EXEMPLO', $prompt);
        $this->assertStringContainsString(Contexto::PELO_CNPJ, $prompt);
        foreach (['gestor:601', 'gestor:602', 'FORCE GYM', Contexto::CNPJ_NAO_CONFERE, Contexto::PEDIR_CNPJ] as $fora) {
            $this->assertStringNotContainsString($fora, $prompt);
        }
        $this->assertSame(9, (int) SuporteConversa::sole()->gestor_cliente_id, 'o cadastro achado pelo CNPJ pode ser confirmado');
    }

    public function test_cnpj_certo_acha_o_cliente_sem_nome_nenhum_na_conversa(): void
    {
        $this->repasse('As catracas pararam. CNPJ '.self::CNPJ_DO_COLEGIO, 'wamid.1', $this->estado());
        $prompt = $this->responde(['resposta' => 'Certo.', 'acao' => 'responder']);

        $this->assertStringContainsString('gestor:9: CDC COLEGIO EXEMPLO', $prompt);
        $this->assertStringContainsString(Contexto::PELO_CNPJ, $prompt);
    }

    public function test_cnpj_de_cliente_do_matriz_tambem_acha(): void
    {
        $cliente = Cliente::create(['nome' => 'Escola Matriz Exemplo', 'cpf_cnpj' => '44.555.666/0001-81', 'ativo' => true]);
        // O mesmo CNPJ é de um cliente do Gestor também: os dois caminhos valem, como quando o CNPJ desempatava.
        $this->repasse('Nosso CNPJ é 44555666000181', 'wamid.1', $this->estado());
        $prompt = $this->responde(['resposta' => 'Certo.', 'acao' => 'responder']);

        $this->assertStringContainsString('matriz:'.$cliente->id.': Escola Matriz Exemplo', $prompt);
        $this->assertStringContainsString('gestor:601', $prompt);
    }

    public function test_cnpj_que_nao_e_de_ninguem_continua_nao_conferindo(): void
    {
        $this->repasse('Somos da Force Gym. CNPJ '.self::CNPJ_DE_NINGUEM, 'wamid.1', $this->estado());
        $prompt = $this->responde(['resposta' => 'Não localizei.', 'acao' => 'responder', 'cliente' => 'gestor:601']);

        $this->assertStringContainsString(Contexto::CNPJ_NAO_CONFERE, $prompt);
        $this->assertStringNotContainsString('gestor:601', $prompt);
        $this->assertNull(SuporteConversa::sole()->gestor_cliente_id);
    }

    public function test_cnpj_de_empresa_que_o_pedro_nao_atende_nao_acha(): void
    {
        $this->repasse('CNPJ '.self::CNPJ_DA_OUTRA_EMPRESA, 'wamid.1', $this->estado());
        $prompt = $this->responde(['resposta' => 'Certo.', 'acao' => 'responder']);

        $this->assertStringNotContainsString('SISTEMA EXEMPLO', $prompt);
    }

    /** A decisão do Felipe é sobre o CNPJ: o CPF é de uma pessoa, e não diz de quem é a quem só digitou o número. */
    public function test_cpf_nao_procura_em_todos_os_clientes(): void
    {
        $this->repasse('Meu CPF é '.self::CPF, 'wamid.1', $this->estado());
        $prompt = $this->responde(['resposta' => 'Qual é o nome da empresa?', 'acao' => 'responder']);

        $this->assertStringNotContainsString('JOANA', $prompt);
        $this->assertStringNotContainsString('gestor:620', $prompt);
    }

    public function test_as_palavras_do_problema_nao_trazem_cliente_nem_pedem_o_cnpj(): void
    {
        $this->repasse(self::CATRACAS, 'wamid.1', $this->estado());
        $prompt = $this->responde(['resposta' => 'Qual é o nome da empresa?', 'acao' => 'responder']);

        foreach (['ZK CARIACICA', 'KARTAN', 'LIBERA MODAS', Contexto::PEDIR_CNPJ] as $fora) {
            $this->assertStringNotContainsString($fora, $prompt);
        }
    }

    public function test_bom_dia_nao_traz_o_cadastro_de_nome_parecido(): void
    {
        $this->repasse('Bom dia as catracas não liberam', 'wamid.1', $this->estado());
        $prompt = $this->responde(['resposta' => 'Qual é o nome da empresa?', 'acao' => 'responder']);

        $this->assertStringNotContainsString('gestor:631', $prompt);
    }

    public function test_o_registro_da_escolha_no_menu_nao_traz_cliente(): void
    {
        $this->repasse('3', 'wamid.1', $this->estado('suporte'));
        $prompt = $this->responde(['resposta' => 'Em que posso ajudar?', 'acao' => 'responder']);

        $this->assertStringNotContainsString('gestor:630', $prompt);
    }

    // ---- 2. Agradecer não é dar por resolvido

    public function test_agradecer_com_o_chamado_em_aberto_nao_pede_nota(): void
    {
        $this->identificada(['gestor_atendimento' => '837']);
        $this->repasse('Obrigado! Ficamos no aguardo.', 'wamid.1', $this->estado());
        $prompt = $this->responde(['resposta' => 'Por nada! O atendimento segue com a equipe técnica.', 'acao' => 'encerrar']);

        $c = SuporteConversa::sole();
        $this->assertSame('pedro', $c->situacao);
        $this->assertSame(0, SuporteAvaliacao::count());
        $this->assertSame(['Por nada! O atendimento segue com a equipe técnica.'], $this->doPedro());
        $this->assertSame('responder', SuportePedido::sole()->acao);
        $this->assertStringContainsString('Agradecer não é encerrar', $prompt);
    }

    public function test_abertura_ainda_na_fila_tambem_segura_a_nota(): void
    {
        $this->identificada();
        $this->chamado('Portão da garagem não fecha', 'wamid.1');
        $this->repasse('Obrigado!', 'wamid.2', $this->estado('suporte'));
        $this->responde(['resposta' => 'Por nada.', 'acao' => 'encerrar']);

        $this->assertSame(0, SuporteAvaliacao::count());
    }

    public function test_chamado_que_acabou_de_nascer_e_ainda_nao_esta_no_retrato_segura_a_nota(): void
    {
        $this->identificada();
        $this->maquinaGrava($this->chamado('A cancela está com ruído', 'wamid.1'), '901');
        $this->repasse('Obrigado!', 'wamid.2', $this->estado());
        $this->responde(['resposta' => 'Por nada.', 'acao' => 'encerrar']);
        $this->assertSame(0, SuporteAvaliacao::count());
    }

    public function test_chamado_concluido_ou_antigo_fora_do_retrato_nao_segura_a_nota(): void
    {
        foreach (['782' => 'concluído', '100' => 'fora do retrato, sem abertura recente'] as $numero => $porque) {
            SuporteConversa::query()->delete();
            SuportePedido::query()->delete();
            SuporteAvaliacao::query()->delete();
            $this->identificada(['gestor_atendimento' => (string) $numero]);
            $this->repasse('Resolveu, obrigado!', 'wamid.'.$numero, $this->estado());
            $this->responde(['resposta' => 'Que bom! Até mais.', 'acao' => 'encerrar']);

            $this->assertSame('avaliacao', SuporteConversa::sole()->situacao, $porque);
            $this->assertSame(1, SuporteAvaliacao::count(), $porque);
        }
    }

    // ---- 3. O menu não sai no meio do atendimento

    public function test_passar_a_uma_pessoa_avisa_o_gestor(): void
    {
        $this->identificada();
        $this->repasse('Pode chamar um colega, por gentileza', 'wamid.1', $this->estado());
        $this->responde(['resposta' => 'Claro.', 'acao' => 'transferir', 'resumo' => 'Pediu uma pessoa']);

        $this->assertSame(['suporte', 'enviar'], $this->acoes());
        $this->assertSame('suporte', SuporteConversa::sole()->gestor_setor);
        $this->assertSame('humano', SuporteConversa::sole()->situacao);
    }

    /** O de 06/11: alguém respondeu pela tela às 08:27, o cliente às 08:41, e às 08:47 saiu o menu. */
    public function test_quem_responde_pela_tela_nao_deixa_o_gestor_mandar_o_menu(): void
    {
        $c = $this->identificada(['nome_perfil' => 'Joaquim']);
        $this->actingAs($this->alexandre)->post(route('suporte.responder', $c->id), ['texto' => 'Olá, bom dia.'])->assertRedirect();
        $this->pedidosAoGestor = [];

        $this->repasse('Positivo, está funcionando.', 'wamid.1', $this->estado());

        $this->assertSame(['suporte'], $this->acoes());
        $this->assertSame(0, SuportePedido::count(), 'a conversa continua com a pessoa, sem a IA');
        $this->assertSame('humano', SuporteConversa::sole()->situacao);
    }

    /** O de 04/11: passada a uma pessoa às 10:02, às 07:30 o Gestor abriu conversa nova (6 h parada), com o Pedro. */
    public function test_conversa_nova_no_gestor_com_uma_pessoa_aqui_tambem_e_marcada(): void
    {
        $this->identificada(['gestor_setor' => 'suporte', 'situacao' => 'humano', 'humano_ate' => now()->addHours(3)]);

        $this->repasse('As catracas continuam paradas', 'wamid.1', $this->estado('aguardando_menu', true, 66));

        $this->assertSame([['acao' => 'suporte', 'conversa' => 66]], $this->pedidosAoGestor);
        $this->assertSame('suporte', SuporteConversa::sole()->gestor_setor);
    }

    public function test_conversa_com_o_pedro_nao_e_marcada_e_a_marcada_nao_se_repete(): void
    {
        $this->identificada();
        $this->repasse('Bom dia', 'wamid.1', $this->estado());
        $this->assertSame([], $this->acoes());

        SuporteConversa::sole()->update(['situacao' => 'humano', 'humano_ate' => now()->addHours(3), 'gestor_setor' => 'suporte']);
        $this->repasse('Alguém aí?', 'wamid.2', $this->estado('suporte'));
        $this->assertSame([], $this->acoes());
    }

    public function test_gestor_recusar_ou_cair_nao_atrapalha_a_resposta_ao_cliente(): void
    {
        $this->identificada();
        $this->statusDoSuporte = 409;
        $this->repasse('Quero falar com alguém', 'wamid.1', $this->estado());
        $this->responde(['resposta' => 'Claro.', 'acao' => 'transferir', 'resumo' => 'Pediu uma pessoa']);
        $this->assertSame('aguardando_menu', SuporteConversa::sole()->gestor_setor, 'recusou: fica para a próxima mensagem tentar de novo');
        $this->assertStringContainsString('Claro.', $this->doPedro()[0]);

        // A próxima mensagem do cliente tenta de novo, agora com o Gestor fora do ar.
        $this->statusDoSuporte = null;
        $this->gestorForaDoAr = true;
        $this->repasse('Alô?', 'wamid.2', $this->estado());
        $this->assertSame('humano', SuporteConversa::sole()->situacao);
    }

    /** O de 06/11: o menu saiu, o cliente escolheu 3, e a IA, que não vê o menu, agradeceu pela avaliação. */
    public function test_a_escolha_no_menu_nao_e_nota_nem_para_o_sistema_nem_para_a_ia(): void
    {
        $c = $this->identificada();
        app(Encerramento::class)->encerrar($c, null);
        $this->assertSame('avaliacao', $c->fresh()->situacao);

        $this->repasse('3', 'wamid.1', $this->estado('suporte'));

        $this->assertNull(SuporteAvaliacao::sole()->nota);
        $this->assertSame('pedro', SuporteConversa::sole()->situacao);
        $this->assertNotContains(Encerramento::OBRIGADO, $this->doPedro());
        $prompt = $this->responde(['resposta' => 'Em que posso ajudar?', 'acao' => 'responder']);
        $this->assertStringContainsString('CLIENTE: '.CanalWhatsApp::ESCOLHEU_SUPORTE_NO_MENU, $prompt);
        $this->assertStringNotContainsString('CLIENTE: 3', $prompt);
    }

    public function test_a_nota_de_verdade_continua_sendo_nota(): void
    {
        $c = $this->identificada(['gestor_setor' => 'suporte']);
        app(Encerramento::class)->encerrar($c, null);

        $this->repasse('3', 'wamid.1', $this->estado('suporte'));

        $this->assertSame(3, SuporteAvaliacao::sole()->nota);
        $this->assertContains(Encerramento::OBRIGADO, $this->doPedro());
    }

    public function test_quem_escolhe_o_menu_por_escrito_nao_perde_o_que_escreveu(): void
    {
        $this->repasse('preciso de suporte técnico, a catraca parou', 'wamid.1', $this->estado('suporte'));

        $this->assertSame('preciso de suporte técnico, a catraca parou', SuporteConversa::sole()->mensagens()->reorder()->where('direcao', 'entrada')->value('texto'));
    }

    // ---- 4. A promessa tem dono

    public function test_chamado_novo_nasce_com_o_responsavel_e_a_maquina_recebe_o_funcionario(): void
    {
        $this->identificada();
        $a = $this->chamado('A cancela está com ruído', 'wamid.1');

        $this->assertSame(['novo', 19], [$a->modo, (int) $a->funcionario_id]);
        $this->assertSame(19, $this->maquinaGrava($a, '901')['funcionario_id']);
        $this->assertSame('901', SuporteConversa::sole()->gestor_atendimento);
    }

    public function test_sem_o_responsavel_na_equipe_o_chamado_nasce_assim_mesmo(): void
    {
        $this->retrato([['id' => 20, 'nome' => 'Outro Técnico', 'telefones' => ['(27) 99999-0000'], 'usuario' => 'outro@teste.local'], ['id' => 19, 'nome' => 'Alexandre Blank Lopes', 'telefones' => ['(27) 3109-3265']]]);
        $this->identificada();
        $a = $this->chamado('A cancela está com ruído', 'wamid.1');

        $this->assertSame('novo', $a->modo);
        $this->assertNull($a->funcionario_id);
    }

    public function test_o_chamado_que_ja_existe_fica_com_quem_esta(): void
    {
        $this->identificada();
        $a = $this->chamado('O portão da garagem continua sem fechar', 'wamid.1');

        $this->assertSame(['vincular', '837'], [$a->modo, $a->atendimento_numero]);
        $this->assertNull($a->funcionario_id);
    }

    public function test_o_pedro_nao_diz_mais_que_o_caso_tem_prioridade(): void
    {
        $this->identificada();
        $this->repasse('Em quanto tempo vocês vêm?', 'wamid.1', $this->estado());
        $prompt = $this->responde(['resposta' => 'A equipe confirma o horário por aqui.', 'acao' => 'responder']);

        $this->assertStringNotContainsString('vai com prioridade', $prompt);
        $this->assertStringContainsString('Não diga que o caso tem prioridade', $prompt);
        $this->assertStringContainsString('Mesmo problema, e não só a mesma categoria', $prompt);
    }

    /** O de 03/11: a mesma frase cinco vezes em dois minutos, enquanto o cliente reclamava da demora. */
    public function test_a_confirmacao_do_chamado_nao_se_repete_na_mesma_conversa(): void
    {
        $this->identificada(['gestor_setor' => 'suporte']);
        $frase = 'Registrei as informações que você me passou. A equipe técnica retornará o contato por aqui.';

        $this->maquinaGrava($this->chamado('O portão da garagem continua sem fechar', 'wamid.1'), '837');
        $this->assertSame(1, collect($this->doPedro())->filter(fn ($t) => $t === $frase)->count());
        $this->maquinaGrava($this->chamado('Portão ainda parado, não posso ficar assim', 'wamid.2'), '837');
        $this->maquinaGrava($this->chamado('Portão segue sem resposta', 'wamid.3'), '837');

        $this->assertSame(1, collect($this->doPedro())->filter(fn ($t) => $t === $frase)->count());
        $this->assertSame(3, Notificacao::where('destinatario_id', $this->alexandre->id)->where('titulo', 'like', 'Gestor nº 837%')->count(), 'a equipe continua sabendo de cada informação nova');

        // Passado o tempo, é outra conversa, e o cliente ouve de novo.
        Carbon::setTestNow(now()->addHours(3));
        SuporteConversa::sole()->update(['ultima_entrada_em' => now()]);
        $this->maquinaGrava($this->chamado('O portão voltou a travar', 'wamid.4'), '837');
        $this->assertSame(2, collect($this->doPedro())->filter(fn ($t) => $t === $frase)->count());
    }

    public function test_reabrir_avisa_mesmo_logo_depois_de_vincular(): void
    {
        $this->identificada(['gestor_setor' => 'suporte']);
        $this->maquinaGrava($this->chamado('O portão da garagem continua sem fechar', 'wamid.1'), '837');
        // Foi concluído nesse meio-tempo, e a máquina reabriu: a pessoa precisa saber.
        $this->maquinaGrava($this->chamado('O portão da garagem voltou a travar', 'wamid.2'), '837', 'reabrir');

        $this->assertSame(2, collect($this->doPedro())->filter(fn ($t) => str_starts_with($t, 'Registrei'))->count());
    }

    // ---- 5. O pedido vai para o chamado dele

    public function test_a_mesma_categoria_nao_junta_pedidos_diferentes(): void
    {
        // O de 05/11: as tags foram parar no chamado do portão, do mesmo assunto.
        $this->assertSame(['modo' => 'novo', 'numero' => null], app(Atendimento::class)->modoNoGestor(9, 3, null, 'Morador pede duas tags azuis'));
    }

    public function test_o_mesmo_problema_continua_indo_para_o_chamado_dele(): void
    {
        $at = app(Atendimento::class);

        $this->assertSame(['modo' => 'vincular', 'numero' => '837'], $at->modoNoGestor(9, 3, null, 'O portão da garagem continua travado'));
        $this->assertSame(['modo' => 'vincular', 'numero' => '837'], $at->modoNoGestor(9, 3, null, 'Os portões não fecham'), 'o plural é a mesma palavra');
    }

    public function test_o_que_nao_identifica_o_problema_nao_vincula(): void
    {
        $at = app(Atendimento::class);

        $this->assertSame('novo', $at->modoNoGestor(9, 3, null, 'A Marta pede uma tag')['modo'], 'o nome da assinatura do chamado não conta');
        $this->assertSame('novo', $at->modoNoGestor(9, 3, null, 'Problema de acesso, não abre, continua parado')['modo'], 'nem a categoria e o sintoma');
        $this->assertSame('novo', $at->modoNoGestor(9, 3)['modo'], 'sem o pedido não dá para dizer que é o mesmo');
    }

    public function test_o_mesmo_lugar_nao_e_o_mesmo_problema(): void
    {
        $this->assertSame('novo', app(Atendimento::class)->modoNoGestor(9, 3, null, 'Interfone da garagem mudo')['modo']);
    }

    public function test_a_categoria_escrita_na_descricao_nao_conta_como_o_problema(): void
    {
        $this->retrato(null, [['id' => 500, 'numero' => '500', 'cliente_id' => 9, 'descricao' => "Assunto: PORTÃO / ACESSO\n\nCâmera da entrada sem imagem", 'status' => 'aberto', 'empresa_id' => 3, 'assunto_id' => 3, 'criado_em' => '2026-11-03 09:00:00', 'funcionarios' => []]]);

        $this->assertSame('novo', app(Atendimento::class)->modoNoGestor(9, 3, null, 'O portão parou')['modo']);
    }

    public function test_a_garantia_tambem_e_do_mesmo_problema(): void
    {
        $at = app(Atendimento::class);

        $this->assertSame(['modo' => 'reabrir', 'numero' => '782'], $at->modoNoGestor(9, 3, null, 'A câmera da entrada voltou a ficar sem imagem'));
        $this->assertSame(['modo' => 'novo', 'numero' => null], $at->modoNoGestor(9, 3, null, 'Morador pede duas tags azuis'));
    }

    public function test_o_numero_citado_continua_valendo(): void
    {
        $this->assertSame(['modo' => 'vincular', 'numero' => '837'], app(Atendimento::class)->modoNoGestor(9, 3, '837', 'Morador pede duas tags azuis'));
    }

    public function test_a_conversa_de_agora_continua_o_chamado_dela(): void
    {
        $at = app(Atendimento::class);

        $this->assertSame(['modo' => 'vincular', 'numero' => '837'], $at->modoNoGestor(9, 3, null, 'Marta, apartamento 101', '837'), 'sem palavra em comum, é a mesma conversa');
        $this->assertSame(['modo' => 'vincular', 'numero' => '901'], $at->modoNoGestor(9, 3, null, 'Marta, apartamento 101', '901'), 'acabou de nascer, fora do retrato');
        $this->assertSame(['modo' => 'novo', 'numero' => null], $at->modoNoGestor(9, 3, null, 'Morador pede duas tags azuis', '782'), 'o da conversa já foi concluído');
    }

    public function test_a_segunda_mensagem_nao_abre_um_segundo_chamado(): void
    {
        $this->identificada(['gestor_setor' => 'suporte']);
        $this->maquinaGrava($this->chamado('Morador pede duas tags azuis', 'wamid.1'), '901');
        $a = $this->chamado('Marta, apartamento 101', 'wamid.2');

        $this->assertSame(['vincular', '901'], [$a->modo, $a->atendimento_numero]);
    }
}
