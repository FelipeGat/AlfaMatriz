<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAbertura;
use App\Models\User;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use App\Services\Suporte\OrcamentoDoGestor;
use App\Services\Suporte\Pedro;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A busca da casa pelo CNPJ (Felipe, 03/11/2026: "por que o Pedro não está encontrando o cliente? PowerFit?").
 * O cliente estava no Gestor, ativo e da INVEST; num pedido de orçamento, os itens ("mega", "tera", que soa
 * como "Terra") puxavam outros cadastros e ele saía dos 10 parecidos, e o CNPJ que o Felipe mandou depois não
 * ajudava, porque a busca era só pelo nome. Agora o CNPJ acha o cliente, e o Pedro diz por que não achou.
 * Nomes e documentos fictícios (os CNPJ só têm o dígito verificador certo).
 */
class PedroBuscaDaCasaPorCnpjTest extends TestCase
{
    use RefreshDatabase;

    private const FELIPE = '5527998845397';

    private const CNPJ = '12.312.312/0001-10';

    /** Como o pedido do Felipe: o nome, e os itens com palavras de outros cadastros. */
    private const PEDIDO = 'Pedro, orçamento para a Force Fit: 4 câmeras full color 2 mega, DVR 8 canais, HD de 1 tera, cabo coaxial, conectores, fonte, rack e obra R$ 850, com NF';

    private User $felipe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-03 17:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.editores' => ['felipe@teste.local'],
            'services.suporte_pedro.empresas' => ['INVEST', 'GW', 'DELTA']]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, '+55 27 99884-5397 | Felipe | diretor | felipe@teste.local');

        $c = fn (int $id, string $nome, array $extra = []) => $extra + ['id' => $id, 'nome' => $nome, 'fantasia' => $nome, 'razao' => '', 'cidade' => 'SERRA',
            'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => []];
        $p = fn (int $id, string $nome) => $c(-$id, $nome, ['pre' => true]);
        app(DiretorioGestor::class)->gravar('wsl', now()->toDateTimeString(), [
            $c(801, 'FORCE-FIT ACADEMIA', ['razao' => 'FULANA DE TAL FORCE-FIT ACADEMIA LTDA', 'cidade' => 'GUARAPARI', 'documento' => self::CNPJ]),
            // Os outros "Force" do funil, como os dez "Power" da produção: o nome dito vale pouco entre eles.
            $p(4342, 'Academia Force Fit LTDA'), $p(5951, 'Force Fit Ddd'), $p(6153, 'Force Fit'), $p(5555, 'Forcefit Training'),
            $p(4323, 'Force Box'), $p(5653, 'Force Training'),
            // Uma palavra dos itens em cada um, rara: cada uma pesa mais que o nome dito.
            $p(1, 'Mega Esportes'), $p(2, 'Terra Nova Educacao'), $p(3, 'Color Center'), $p(4, 'Coaxial Telecom'), $p(5, 'Rack Store'),
            $p(6, 'Fonte Limpa'), $p(7, 'Canais Digitais'), $p(8, 'Conectores Brasil'), $p(9, 'Full Time Fitness'), $p(10, 'Obra Prima'),
            $p(11, 'Cabo Frio Pescados'),
            // De empresa que o Pedro não atende.
            $c(900, 'SISTEMA EXEMPLO', ['empresa_id' => 4, 'empresa' => 'ALFA', 'documento' => '55566677000183']),
        ], [['id' => 784, 'numero' => '710', 'cliente_id' => 801, 'descricao' => 'Câmeras da recepção', 'status' => 'em_atendimento', 'criado_em' => '2026-09-08 11:27:00', 'funcionarios' => []]],
            [['id' => 7, 'nome' => 'Manutenção de CFTV', 'tipo' => 'SERVICO', 'categoria' => 'CFTV', 'empresa_id' => 3]]);
    }

    private function citados(string $texto): array
    {
        return app(AcessoInterno::class)->clientesCitados($texto);
    }

    private function bloco(string $texto): string
    {
        return app(AcessoInterno::class)->blocoParaRegistrar($texto);
    }

    public function test_o_cliente_que_sai_dos_parecidos_volta_pelo_cnpj(): void
    {
        // A causa: pelo nome, ele não fica entre os 10, e o Pedro dizia que não estava na lista.
        $this->assertArrayNotHasKey('gestor:801', $this->citados(self::PEDIDO));
        $semCnpj = $this->bloco(self::PEDIDO);
        $this->assertStringContainsString('Esta lista traz só os 10 mais parecidos com o nome, e há outros: se o cliente que ele citou não está aqui, diga que ele não ficou entre os parecidos e peça o CNPJ', $semCnpj);
        $this->assertStringContainsString('Nunca diga que o cliente não existe no Gestor nem que está fora da lista', $semCnpj);

        foreach ([self::PEDIDO.' O CNPJ é '.self::CNPJ, self::PEDIDO.' cnpj 12312312000110'] as $texto) {
            $citados = $this->citados($texto);
            $this->assertSame('gestor:801', array_key_first($citados), 'o achado pelo CNPJ vem primeiro');
            $this->assertCount(11, $citados, 'fora do limite dos 10 parecidos, e sem tirar nenhum');
            $this->assertStringContainsString('- gestor:801: [CLIENTE] FORCE-FIT ACADEMIA — FULANA DE TAL FORCE-FIT ACADEMIA LTDA — cidade: GUARAPARI (empresa INVEST) — ACHADO PELO CNPJ QUE ELE INFORMOU', $this->bloco($texto));
        }
        // E pelo pedido inteiro, como a IA recebe: o atendimento 710 e o cliente dele, no mesmo pedido.
        $prompt = app(Contexto::class)->prompt($this->conversa(self::PEDIDO, 'é a Force Fit de Guarapari, CNPJ '.self::CNPJ));
        $this->assertStringContainsString('ACHADO PELO CNPJ QUE ELE INFORMOU', $prompt);
        $this->assertStringContainsString('nº 710 — FORCE-FIT ACADEMIA (INVEST)', $prompt);
        $this->assertStringContainsString('diga o motivo que a lista der e peça o CNPJ', $prompt);
    }

    public function test_o_pedro_diz_por_que_nao_achou(): void
    {
        $naoTem = $this->bloco('orçamento para o cliente de CNPJ 32.132.132/0001-63');
        $this->assertStringContainsString('- O CNPJ 32.132.132/0001-63 que ele informou não está no cadastro de nenhum cliente ou pré-cliente que o Pedro atende no Gestor: diga isso ("não achei esse CNPJ no Gestor")', $naoTem);
        $this->assertStringContainsString('Nenhum combina com o nome: diga que não achou o cliente pelo nome e peça o CNPJ', $naoTem);
        $this->assertStringNotContainsString('ACHADO PELO', $naoTem);

        $deOutra = $this->bloco('abre um atendimento para o CNPJ 55.566.677/0001-83');
        $this->assertStringContainsString('- O CNPJ 55.566.677/0001-83 que ele informou é de SISTEMA EXEMPLO, cliente da ALFA, que o suporte do Pedro não atende (só INVEST, GW, DELTA): diga isso, e não registre nem monte orçamento para ele.', $deOutra);
        $this->assertArrayNotHasKey('gestor:900', $this->citados('abre um atendimento para o CNPJ 55.566.677/0001-83'), 'o CNPJ não passa por cima das empresas atendidas');

        $trocado = $this->bloco('Force Fit, CNPJ 12.312.312/0001-11');
        $this->assertStringContainsString('- O número 12.312.312/0001-11 que ele escreveu tem a forma de um CNPJ, mas o dígito verificador não confere', $trocado);
        $this->assertStringNotContainsString('ACHADO PELO', $trocado);
    }

    public function test_celular_nao_vira_cpf_que_nao_confere(): void
    {
        // 27 99900-0035 escrito junto passa no dígito do CPF: sem a forma de CPF, não é motivo de nada.
        $this->assertStringNotContainsString('CPF', $this->bloco('Force Fit, me liga no 27999000035'));
        $this->assertStringContainsString('- O CPF 987.654.321-00 que ele informou não está no cadastro', $this->bloco('orçamento para o CPF 987.654.321-00'));
        $this->assertStringContainsString('- O CPF 987.654.321-00 que ele informou não está no cadastro', $this->bloco('orçamento, cpf 98765432100'));
    }

    public function test_registro_e_orcamento_aceitam_o_cliente_achado_pelo_cnpj(): void
    {
        $abrir = fn (SuporteConversa $c) => $this->concluir($c, '<<<PEDRO {"resposta": "Certo, Felipe.", "acao": "abrir_atendimento", "resumo": "Câmeras da recepção",'
            .' "cliente": "gestor:801", "modo": "novo", "empresa_id": 3, "assunto_id": 7, "titulo": "Visita técnica", "agendar": false} PEDRO>>>');

        // Sem o CNPJ, a recusa pede o CNPJ, e não só o nome.
        $sem = $abrir($this->conversa(self::PEDIDO));
        $this->assertSame(0, SuporteGestorAbertura::count());
        $this->assertStringContainsString('(Não registrei: não consegui confirmar o cliente no Gestor. Me diga o CNPJ dele ou o nome como está cadastrado.)', $sem);

        $abrir($this->conversa(self::PEDIDO, 'o CNPJ é '.self::CNPJ));
        $this->assertSame(801, SuporteGestorAbertura::sole()->gestor_cliente_id);

        $orcamento = ['cliente' => 'gestor:801', 'empresa_id' => 3, 'sem_nf' => false, 'descricao' => 'CFTV', 'itens' => [['nome' => 'DVR', 'tipo' => 'material', 'quantidade' => 1, 'valor' => 850]]];
        [$problemas] = app(OrcamentoDoGestor::class)->conferir($orcamento, $this->citados(self::PEDIDO));
        $this->assertContains('o cliente não foi confirmado no Gestor: diga o CNPJ dele ou o nome como está cadastrado (não crio cliente nem pré-cliente).', $problemas);
        [$problemas] = app(OrcamentoDoGestor::class)->conferir($orcamento, $this->citados(self::PEDIDO.' '.self::CNPJ));
        $this->assertEmpty(array_filter($problemas, fn ($p) => str_contains($p, 'cliente não foi confirmado')));
    }

    private function conversa(string ...$textos): SuporteConversa
    {
        // Uma conversa por número: a de antes sai, como num pedido novo.
        SuporteConversa::where('telefone', self::FELIPE)->get()->each->delete();
        $c = SuporteConversa::create(['telefone' => self::FELIPE, 'ultima_entrada_em' => now()]);
        foreach ($textos as $i => $t) {
            $c->mensagens()->create(['wamid' => 'wamid.'.uniqid('', true), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => $t, 'enviada_em' => now()->subMinutes(count($textos) - $i)]);
        }

        return $c->fresh();
    }

    /** A resposta da IA concluída pelo Pedro; devolve o que foi para o WhatsApp. */
    private function concluir(SuporteConversa $c, string $resposta): string
    {
        $pedido = app(Pedro::class)->enfileirar($c->id);
        $pedido->update(['situacao' => 'reservado']);
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        app(Pedro::class)->concluir($this->felipe, $pedido->id, $resposta, null);

        return (string) $c->mensagens()->reorder()->where('direcao', 'pedro')->orderByDesc('id')->value('texto');
    }
}
