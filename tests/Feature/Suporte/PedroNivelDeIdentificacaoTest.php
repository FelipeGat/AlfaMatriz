<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\Cliente;
use App\Models\ClienteContrato;
use App\Models\ClienteTelefone;
use App\Models\MonitorAlerta;
use App\Models\Sistema;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAbertura;
use App\Models\SuporteNota;
use App\Models\User;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\Identificacao;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O nível de identificação (Felipe, 31/10/2026: "o Pedro não pode passar informação nenhuma,
 * nem mesmo NF nem boleto, para quem ele não conhece"). Cada nível contra a lista do que é do
 * nível de cima: se um trecho novo do pedido esquecer a regra, é aqui que aparece.
 */
class PedroNivelDeIdentificacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    private Cliente $academia;

    /** O que só o número do cadastro pode ver; nenhum deles aparece abaixo do nível CADASTRO. */
    private const DO_CADASTRO = ['Sistemas ativos', 'Contratos:', 'PLANO-DA-ACADEMIA', 'ALERTA-DA-ACADEMIA', 'MEMORIA-DA-ACADEMIA', 'MEMORIA-DO-CASTANHEIRAS',
        'ATENDIMENTO-DE-OUTRA-CONVERSA', '- Login do portal', 'sindico@castanheiras.com.br', 'Gestor nº 782'];

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

        // O mesmo telefone está no cadastro da academia (Matriz) e no do Castanheiras (Gestor).
        $this->academia = Cliente::create(['nome' => 'Academia Boa Forma', 'ativo' => true]);
        ClienteTelefone::create(['cliente_id' => $this->academia->id, 'telefone' => '(27) 99999-8888', 'principal' => true]);
        $gym = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym', 'natureza' => 'produto']);
        $this->academia->sistemas()->attach($gym->id, ['ativo' => true]);
        ClienteContrato::create(['cliente_id' => $this->academia->id, 'sistema_id' => $gym->id, 'plano' => 'PLANO-DA-ACADEMIA', 'valor_mensal' => 100, 'status' => 'ativo', 'data_inicio' => '2026-01-01']);
        $alerta = ['severity' => 'critical', 'body' => 'x', 'aberto' => true, 'caiu_em' => now(), 'quedas' => 1, 'ultimo_evento_em' => now(), 'ultimo_aviso_em' => now()];
        MonitorAlerta::create($alerta + ['kind' => 'monitor', 'entity' => 'catraca-academia', 'title' => 'ALERTA-DA-ACADEMIA', 'cliente_id' => $this->academia->id]);
        MonitorAlerta::create($alerta + ['kind' => 'hostinger', 'entity' => 'vps-1', 'title' => 'ALERTA-GERAL']);
        SuporteNota::create(['estado' => 'ativa', 'tipo' => 'cliente', 'titulo' => 'Academia', 'cliente_id' => $this->academia->id, 'corpo' => 'MEMORIA-DA-ACADEMIA']);
        SuporteNota::create(['estado' => 'ativa', 'tipo' => 'cliente', 'titulo' => 'Castanheiras', 'gestor_cliente_id' => 13, 'corpo' => 'MEMORIA-DO-CASTANHEIRAS']);
        SuporteNota::create(['estado' => 'ativa', 'tipo' => 'atendimento', 'titulo' => 'Catraca travada', 'cliente_id' => $this->academia->id, 'situacao' => 'em_curso',
            'relato' => 'ATENDIMENTO-DE-OUTRA-CONVERSA']);

        $this->runner();
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(),
            'clientes' => [
                ['id' => 13, 'nome' => 'CONDOMINIO CASTANHEIRAS', 'fantasia' => 'CONDOMINIO CASTANHEIRAS', 'razao' => 'CONDOMINIO RESIDENCIAL PARQUE DAS CASTANHEIRAS', 'cidade' => 'VILA VELHA',
                    'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => ['(27) 3389-4253', '(27) 99999-8888'], 'portal' => ['sindico@castanheiras.com.br'],
                    // Quem se identificou antes ao Pedro (T-479): é registro, não cadastro.
                    'contatos' => [['nome' => 'Joana', 'papel' => 'porteira', 'telefone' => '5527911112222', 'chave' => '2711112222']]],
                ['id' => 20, 'nome' => 'CONDOMINIO SOLAR', 'fantasia' => '', 'razao' => 'CONDOMINIO SOLAR DOS IPES', 'cidade' => 'VILA VELHA', 'empresa_id' => 3, 'empresa' => 'INVEST',
                    'telefones' => ['(27) 3200-1000'], 'contatos' => []],
            ],
            'atendimentos' => [['id' => 889, 'numero' => '782', 'cliente_id' => 13, 'descricao' => 'Portão de saída G1 não fecha', 'status' => 'concluido', 'empresa_id' => 3, 'assunto_id' => 3, 'criado_em' => '2026-10-06 07:56:06']],
            'assuntos' => [['id' => 3, 'nome' => 'PORTÃO / ACESSO', 'tipo' => 'SERVICO', 'categoria' => 'CONTOLE DE ACESSO', 'empresa_id' => 3]],
        ])->assertOk();
    }

    private function runner(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
    }

    private function chega(string $texto, string $id, string $de): void
    {
        $valor = ['metadata' => ['phone_number_id' => '111222'], 'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Fulano']]],
            'messages' => [['from' => $de, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);
        $this->call('POST', route('suporte.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo')], $corpo)->assertOk();
    }

    /** @return array{id: int, prompt: string} */
    private function proximo(): array
    {
        $this->runner();

        return $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->json();
    }

    private function responde(int $pedido, array $bloco): void
    {
        $this->runner();
        $this->postJson(route('suporte.runner.resposta', $pedido), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();
    }

    private function conversa(string $telefone, array $dados): SuporteConversa
    {
        return SuporteConversa::create(['telefone' => $telefone, 'ultima_entrada_em' => now()] + $dados);
    }

    private function nivel(SuporteConversa $c): string
    {
        return app(Identificacao::class)->nivel($c->fresh());
    }

    public function test_o_nivel_vem_do_numero_do_cadastro_e_nao_do_que_a_pessoa_diz(): void
    {
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, '+55 27 99884-5397 | Felipe | diretor');
        $this->assertSame(Identificacao::INTERNO, $this->nivel($this->conversa('5527998845397', ['gestor_cliente_id' => 13, 'solicitante_nome' => 'Felipe'])));

        // Do cadastro, mas ainda sem dizer quem é: só a identificação.
        $c = $this->conversa('5527999998888', ['cliente_id' => $this->academia->id]);
        $this->assertSame(Identificacao::DESCONHECIDO, $this->nivel($c));
        $c->update(['solicitante_nome' => 'Maria']);
        $this->assertSame(Identificacao::CADASTRO, $this->nivel($c));
        $this->assertSame(Identificacao::CADASTRO, $this->nivel($this->conversa('552733894253', ['gestor_cliente_id' => 13, 'solicitante_nome' => 'Ana'])));

        // Dizer o nome do cliente não basta.
        $this->assertSame(Identificacao::PELO_NOME, $this->nivel($this->conversa('5527911110001', ['gestor_cliente_id' => 13, 'solicitante_nome' => 'Carlos'])));
        $this->assertSame(Identificacao::PELO_NOME, $this->nivel($this->conversa('5527911110002', ['cliente_id' => $this->academia->id, 'solicitante_nome' => 'Carlos'])));
        // O contato que o próprio Pedro gravou no Gestor (T-479) identifica a pessoa, mas não é cadastro.
        $this->assertSame(Identificacao::PELO_NOME, $this->nivel($this->conversa('5527911112222', ['gestor_cliente_id' => 13, 'solicitante_nome' => 'Joana'])));
    }

    public function test_matriz_e_gestor_ligados_valem_como_um_cadastro_so_e_soltos_como_dois(): void
    {
        // O condomínio do Matriz ligado ao Castanheiras do Gestor: o número está só no cadastro do Matriz, e basta.
        $condominio = Cliente::create(['nome' => 'Condominio Parque das Castanheiras', 'ativo' => true]);
        $condominio->forceFill(['gestor_cliente_id' => 13])->save();
        ClienteTelefone::create(['cliente_id' => $condominio->id, 'telefone' => '(27) 98888-0000', 'principal' => true]);
        $c = $this->conversa('5527988880000', ['cliente_id' => $condominio->id, 'solicitante_nome' => 'Paulo']);
        $this->assertSame(13, $c->fresh()->gestor_cliente_id);
        $this->assertSame(Identificacao::CADASTRO, $this->nivel($c));

        // O síndico profissional do Castanheiras diz falar pelo Solar: sem ligação, o número precisa estar no cadastro dos dois.
        $c->update(['gestor_cliente_id' => 20]);
        $this->assertSame(Identificacao::PELO_NOME, $this->nivel($c));
        $this->assertStringNotContainsString('CONFIRMADO PELO TELEFONE', app(Contexto::class)->prompt($c->fresh()));
    }

    public function test_quem_so_disse_o_nome_nao_recebe_nada_do_cadastro(): void
    {
        $c = $this->conversa('5527911110001', ['cliente_id' => $this->academia->id, 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Carlos']);
        SuporteNota::create(['estado' => 'ativa', 'tipo' => 'atendimento', 'titulo' => 'Portão', 'conversa_id' => $c->id, 'gestor_cliente_id' => 13, 'situacao' => 'em_curso',
            'relato' => 'ATENDIMENTO-DESTA-CONVERSA']);
        $prompt = app(Contexto::class)->prompt($c->fresh());

        foreach (self::DO_CADASTRO as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, "\"{$dado}\" é do cadastro");
        }
        $this->assertStringNotContainsString('CONFIRMADO', $prompt, 'o nome dito não é prova');
        $this->assertStringContainsString('IDENTIFICADO SÓ PELO NOME (Matriz): Academia Boa Forma', $prompt);
        $this->assertStringContainsString('IDENTIFICADO SÓ PELO NOME (Gestor', $prompt);
        $this->assertStringContainsString('POR SEGURANÇA, o número +55 (27) 91111-0001 NÃO é um telefone do cadastro deste cliente', $prompt);
        $this->assertStringContainsString(Contexto::FINANCEIRO, $prompt);
        $this->assertStringContainsString(Contexto::FINANCEIRO_PELO_NOME, $prompt);
        // O que é de todos continua: o alerta geral, o que nasceu nesta conversa e o assunto para abrir o chamado.
        $this->assertStringContainsString('ALERTA-GERAL', $prompt);
        $this->assertStringContainsString('ATENDIMENTO-DESTA-CONVERSA', $prompt);
        $this->assertStringContainsString('- 3: PORTÃO / ACESSO', $prompt);
    }

    public function test_do_telefone_do_cadastro_o_cliente_vem_inteiro(): void
    {
        $c = $this->conversa('5527999998888', ['cliente_id' => $this->academia->id, 'gestor_cliente_id' => 13, 'solicitante_nome' => 'Maria']);
        $prompt = app(Contexto::class)->prompt($c->fresh());

        foreach (self::DO_CADASTRO as $dado) {
            $this->assertStringContainsString($dado, $prompt);
        }
        $this->assertStringContainsString('CONFIRMADO PELO TELEFONE DO CADASTRO (Matriz): Academia Boa Forma', $prompt);
        $this->assertStringContainsString('Sistemas ativos: AlfaGym', $prompt);
        $this->assertStringContainsString('Login do portal (o número que escreve é do cadastro): sindico@castanheiras.com.br', $prompt);
        // Nem do cadastro o documento sai na conversa: a regra do financeiro vale para todos.
        $this->assertStringContainsString(Contexto::FINANCEIRO, $prompt);
        $this->assertStringNotContainsString(Contexto::FINANCEIRO_PELO_NOME, $prompt);
        $this->assertStringNotContainsString('POR SEGURANÇA, o número', $prompt);
    }

    public function test_antes_de_dizer_quem_e_nem_o_numero_do_cadastro_recebe_dados(): void
    {
        $c = $this->conversa('5527999998888', ['cliente_id' => $this->academia->id, 'gestor_cliente_id' => 13]);
        $prompt = app(Contexto::class)->prompt($c->fresh());

        $this->assertStringContainsString('IDENTIFICAÇÃO PRIMEIRO', $prompt);
        $this->assertStringContainsString('CONFIRMADO (Matriz): Academia Boa Forma', $prompt);
        foreach (self::DO_CADASTRO as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, "\"{$dado}\" é do cadastro");
        }
    }

    /** O caso do roteiro de validação: número desconhecido pede a segunda via dizendo ser do Castanheiras. */
    public function test_numero_desconhecido_pede_a_segunda_via_e_nao_recebe_nada_do_cliente(): void
    {
        $this->chega('preciso da segunda via do boleto do condominio parque das castanheiras', 'wamid.1', '5527911110001');
        $pedido = $this->proximo();
        $this->assertStringContainsString('gestor:13', $pedido['prompt'], 'o candidato vem pelo nome');
        $this->assertStringNotContainsString('sindico@castanheiras.com.br', $pedido['prompt']);

        // Ele diz o nome e a IA confirma o condomínio: a conversa volta na hora, agora identificada só pelo nome.
        $this->responde($pedido['id'], ['resposta' => 'Obrigado, Carlos.', 'acao' => 'responder', 'cliente' => 'gestor:13', 'solicitante' => ['nome' => 'Carlos', 'funcao' => 'síndico']]);
        $this->assertSame(Identificacao::PELO_NOME, $this->nivel(SuporteConversa::sole()));
        $prompt = $this->proximo()['prompt'];
        foreach (self::DO_CADASTRO as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, "\"{$dado}\" é do cadastro");
        }
        $this->assertStringContainsString(Contexto::FINANCEIRO_PELO_NOME, $prompt);
        $this->assertStringContainsString('Boleto / segunda via', $prompt, 'o caminho do portal continua: é de todos');
    }

    /** O chamado que já existia é histórico do cliente: quem só disse o nome não ouve o número dele. */
    public function test_chamado_que_ja_existia_nao_e_dito_a_quem_so_disse_o_nome(): void
    {
        config(['services.suporte_pedro.gestor_gravar' => true]);
        $this->conversa('5527911110001', ['gestor_cliente_id' => 13, 'solicitante_nome' => 'Carlos']);
        $this->chega('o portão G1 não fecha', 'wamid.1', '5527911110001');
        $this->responde($this->proximo()['id'], ['resposta' => 'Vou encaminhar para a equipe.', 'acao' => 'abrir_atendimento', 'resumo' => 'Portão G1 não fecha', 'destino' => 'gestor', 'assunto_id' => 3]);
        $a = SuporteGestorAbertura::sole();
        $this->assertSame(['reabrir', '782'], [$a->modo, $a->atendimento_numero], 'o chamado do cliente é reaberto do mesmo jeito');

        $this->runner();
        $this->postJson(route('suporte.runner.aberturas'), ['maquina' => 'wsl'])->assertOk();
        $this->postJson(route('suporte.runner.abertura-feita', $a->id), ['maquina' => 'wsl', 'numero' => '782', 'modo' => 'reabrir'])->assertOk();

        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === 'Registrei as informações que você me passou. A equipe técnica retornará o contato por aqui.');
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), '782'));
    }
}
