<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\User;
use App\Services\Suporte\Contexto;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O cliente que também ficou no funil (Felipe, 07/11/2026). O inquilino novo de uma sala escreveu de um número
 * fora do cadastro, disse por inteiro o nome do condomínio, igual à razão social do cliente, e pediu a senha do
 * alarme. O Pedro pediu o CNPJ, porque o mesmo condomínio estava também como pré-cliente, sem CNPJ e nunca
 * convertido, e a busca viu "dois de nome parecido"; o CNPJ que a pessoa mandou não conferia, e a conversa foi
 * para um colega sem nada registrado. O pré-cliente que é o próprio cliente não é outro cadastro; o de outro
 * CNPJ ou de outra cidade continua sendo, e o Pedro pede o CNPJ, como antes. Dados fictícios.
 */
class PedroClienteTambemNoFunilTest extends TestCase
{
    use RefreshDatabase;

    private const DESCONHECIDO = '5527988886666';

    private User $felipe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-06 14:35', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0']]);
        config(['services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['rossini@teste.local']]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        User::factory()->create(['name' => 'Rossini', 'email' => 'rossini@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);

        $cliente = fn (int $id, string $nome, string $cidade, array $extra = []) => $extra + ['id' => $id, 'nome' => $nome, 'fantasia' => $nome, 'razao' => '',
            'cidade' => $cidade, 'documento' => '', 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => []];
        // O pré-cliente vem como o retrato o manda: id negativo, marcado "pre", com a empresa e a cidade do funil (em branco, se não preencheram).
        $pre = fn (int $id, string $fantasia, string $razao, string $cidade, string $documento = '') => ['id' => $id, 'nome' => $fantasia, 'fantasia' => $fantasia,
            'razao' => $razao, 'cidade' => $cidade, 'documento' => $documento, 'empresa_id' => 1, 'empresa' => 'DELTA', 'telefones' => [], 'portal' => [], 'pre' => true];
        $this->runner();
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(), 'atendimentos' => [], 'clientes' => [
            // O caso de 06/11: o mesmo condomínio no cadastro e no funil, o do funil sem CNPJ e sem cidade.
            $cliente(801, 'CENTRO EMPRESARIAL ATALAIA', 'SERRA', ['razao' => 'CONDOMINIO CENTRO EMPRESARIAL ATALAIA DE SERRA', 'documento' => '11.222.333/0001-81']),
            $pre(-901, 'Centro Empresarial Atalaia SR', 'CONDOMINIO CENTRO EMPRESARIAL ATALAIA DE SERRA', ''),
            // Mesmo nome, outro CNPJ: são duas empresas, e o Pedro pede o CNPJ.
            $cliente(802, 'ACADEMIA VIDA PLENA', 'SERRA', ['documento' => '44555666000181']),
            $pre(-902, 'Academia Vida Plena', 'Vida Plena Fitness LTDA', 'Serra', '88.999.000/0001-10'),
            // Mesmo nome, sem CNPJ no funil, outra cidade: pode ser outra unidade, e o Pedro pede o CNPJ.
            $cliente(803, 'ESTUDIO BAILE LIVRE', 'VITORIA', ['documento' => '55666777000190']),
            $pre(-903, 'Estudio Baile Livre', '', 'Guarapari'),
            // Mesmo CNPJ, nome do funil um pouco diferente: é o mesmo, pelo CNPJ.
            $cliente(804, 'PADARIA TRIGO NOBRE', 'CARIACICA', ['documento' => '66777888000102']),
            $pre(-904, 'Padaria Trigo Nobre Express', '', 'Cariacica', '66.777.888/0001-02'),
            // Mesmo CNPJ, e só o nome do funil dito por inteiro: o do cadastro continua só parecido.
            $cliente(806, 'EMPORIO BOM GRAO SERRA', 'SERRA', ['documento' => '22333444000105']),
            $pre(-906, 'Empório Bom Grão', '', 'Serra', '22.333.444/0001-05'),
            // Sem CNPJ no funil e com outro nome: pode ser outra empresa, e o Pedro pede o CNPJ.
            $cliente(807, 'FORCA TOTAL ACADEMIA', 'SERRA', ['documento' => '33444555000107']),
            $pre(-907, 'Forca Total Crossfit', '', ''),
            // Pré-cliente sem cliente de mesmo nome continua sendo achado.
            $pre(-905, 'Clinica Sorriso Bonito', 'Clinica Sorriso Bonito LTDA', 'Viana'),
        ]])->assertOk();
    }

    private function runner(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
    }

    private function chega(string $texto, string $id): void
    {
        $valor = ['metadata' => ['phone_number_id' => '111222'], 'contacts' => [['wa_id' => self::DESCONHECIDO, 'profile' => ['name' => 'Joaquim']]],
            'messages' => [['from' => self::DESCONHECIDO, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
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

    public function test_nome_inteiro_do_cliente_que_tambem_esta_no_funil_acha_o_cliente_sem_pedir_cnpj(): void
    {
        $this->chega('Boa tarde! Me chamo Joaquim.', 'wamid.1');
        [$id] = $this->pedido();
        $this->responde($id, ['resposta' => 'Boa tarde, Joaquim! Do que você precisa, e de qual empresa ou condomínio?', 'acao' => 'responder', 'cliente' => null]);

        $this->chega('Preciso me cadastrar no Condomínio Centro Empresarial Atalaia de Serra, sala 110, recém aluguei esta sala e agora sou o inquilino. '
            .'Precisa fazer a senha no sistema de alarme.', 'wamid.2');
        [$id, $prompt] = $this->pedido();

        $this->assertStringContainsString('gestor:801: CONDOMINIO CENTRO EMPRESARIAL ATALAIA DE SERRA (CENTRO EMPRESARIAL ATALAIA), SERRA — manutenção e segurança eletrônica (Gestor, INVEST)'."\n", $prompt,
            'o nome dito por inteiro é o cliente, sem NOME PARECIDO');
        foreach (['gestor:-901', Contexto::PEDIR_CNPJ, Contexto::CNPJ_NAO_CONFERE] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, "o pré-cliente é o próprio cliente: {$dado}");
        }
        // No ensaio, com o cliente reconhecido, a IA passava a conversa a um colega em vez de seguir com o pedido.
        $this->assertStringContainsString(Contexto::IDENTIFICOU_RESPONDE, $prompt);

        // Como no dia: um CNPJ que não é o do cadastro, mandado assim mesmo, não desfaz o cliente dito por inteiro.
        $this->responde($id, ['resposta' => 'Entendi, a senha do alarme da sala 110.', 'acao' => 'responder', 'cliente' => null]);
        $this->chega('77.888.999/0001-81', 'wamid.3');
        [$id, $prompt] = $this->pedido();
        $this->assertStringContainsString('gestor:801: CONDOMINIO CENTRO EMPRESARIAL ATALAIA DE SERRA', $prompt);
        foreach (['gestor:-901', Contexto::PEDIR_CNPJ, Contexto::CNPJ_NAO_CONFERE] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, $dado);
        }

        $this->responde($id, ['resposta' => 'Certo, Joaquim. Vou registrar o pedido da senha do alarme da sala 110.', 'acao' => 'responder', 'cliente' => 'gestor:801',
            'solicitante' => ['nome' => 'Joaquim', 'funcao' => 'inquilino da sala 110']]);
        $this->assertSame(801, SuporteConversa::sole()->gestor_cliente_id);

        // Identificado, a conversa volta na hora ao Pedro, agora com o problema: é lá que o chamado se abre.
        [, $prompt] = $this->pedido();
        $this->assertStringContainsString('IDENTIFICADO SÓ PELO NOME (Gestor, manutenção e segurança eletrônica): CONDOMINIO CENTRO EMPRESARIAL ATALAIA DE SERRA', $prompt);
        $this->assertStringNotContainsString(Contexto::IDENTIFICOU_RESPONDE, $prompt, 'a regra é só do pedido de identificação');
    }

    public function test_o_mesmo_cnpj_no_funil_e_o_mesmo_cliente_mesmo_com_outro_nome(): void
    {
        $this->chega('oi, sou da Padaria Trigo Nobre, o alarme disparou', 'wamid.1');
        [, $prompt] = $this->pedido();

        $this->assertMatchesRegularExpression('/gestor:804: [^\n]*PADARIA TRIGO NOBRE[^\n]*CARIACICA — manutenção e segurança eletrônica \(Gestor, INVEST\)\n/u', $prompt,
            'dito por inteiro, sem NOME PARECIDO');
        foreach (['gestor:-904', 'Express', Contexto::PEDIR_CNPJ] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, $dado);
        }
    }

    public function test_so_o_nome_do_funil_dito_por_inteiro_o_cliente_continua_so_parecido(): void
    {
        $this->chega('oi, sou do Empório Bom Grão, o interfone parou', 'wamid.1');
        [, $prompt] = $this->pedido();

        $this->assertStringContainsString('gestor:806: EMPORIO BOM GRAO SERRA — manutenção e segurança eletrônica (Gestor, INVEST)'.Contexto::NOME_PARECIDO, $prompt,
            'o nome do funil não confirma sozinho o do cadastro: o Pedro pergunta se é ele');
        foreach (['gestor:-906', Contexto::PEDIR_CNPJ] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, $dado);
        }
    }

    public function test_mesmo_nome_com_outro_cnpj_no_funil_continua_pedindo_o_cnpj(): void
    {
        $this->chega('oi, aqui é da Academia Vida Plena, a catraca parou', 'wamid.1');
        [, $prompt] = $this->pedido();

        $this->assertStringContainsString(Contexto::PEDIR_CNPJ, $prompt);
        foreach (['gestor:802', 'gestor:-902', 'VIDA PLENA', 'Fitness LTDA', 'CANDIDATOS'] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, "nada dos dois cadastros: {$dado}");
        }
    }

    public function test_mesmo_nome_sem_cnpj_no_funil_em_outra_cidade_continua_pedindo_o_cnpj(): void
    {
        $this->chega('boa tarde, sou do Estúdio Baile Livre, a câmera da sala apagou', 'wamid.1');
        [, $prompt] = $this->pedido();

        $this->assertStringContainsString(Contexto::PEDIR_CNPJ, $prompt);
        foreach (['gestor:803', 'gestor:-903', 'VITORIA', 'Guarapari', 'CANDIDATOS'] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, "nada dos dois cadastros: {$dado}");
        }
    }

    public function test_outro_nome_sem_cnpj_no_funil_continua_pedindo_o_cnpj(): void
    {
        $this->chega('bom dia, sou da Força Total, o portão não abre', 'wamid.1');
        [, $prompt] = $this->pedido();

        $this->assertStringContainsString(Contexto::PEDIR_CNPJ, $prompt);
        foreach (['gestor:807', 'gestor:-907', 'FORCA TOTAL', 'Crossfit', 'CANDIDATOS'] as $dado) {
            $this->assertStringNotContainsString($dado, $prompt, "nada dos dois cadastros: {$dado}");
        }
    }

    public function test_pre_cliente_sem_cliente_de_mesmo_nome_continua_sendo_achado(): void
    {
        $this->chega('boa tarde, sou da Clínica Sorriso Bonito, queria um orçamento de câmeras', 'wamid.1');
        [, $prompt] = $this->pedido();

        $this->assertStringContainsString('gestor:-905: Clinica Sorriso Bonito LTDA (Clinica Sorriso Bonito), Viana', $prompt);
        $this->assertStringNotContainsString(Contexto::PEDIR_CNPJ, $prompt);
    }
}
