<?php

namespace Tests\Feature\Suporte;

use App\Models\SuporteConversa;
use App\Models\SuporteRoteiro;
use App\Models\User;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** O Pedro estagiário (docs/specs/suporte-pedro-whatsapp/estagiario.md): conhecimento do grupo, diagnóstico guiado, equipe. */
class PedroEstagiarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'America/Sao_Paulo'));
    }

    private function prompt(string $telefone = '5527988887777', string $texto = 'oi'): string
    {
        $c = SuporteConversa::create(['telefone' => $telefone, 'ultima_entrada_em' => now()]);
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => $texto, 'enviada_em' => now()]);

        return app(Contexto::class)->prompt($c->fresh());
    }

    public function test_o_conhecimento_do_grupo_vai_ao_pedro(): void
    {
        $p = $this->prompt();
        foreach (['GW Soluções', 'Delta Soluções', 'Invest Soluções', 'https://solucoesgrupo.com/', 'https://invest.solucoesgrupo.com/',
            'Financeiro — (27) 99652-6457', 'Comercial — (27) 99527-3269', 'Plantão — (27) 99244-6889', 'https://gestor.alfa.solucoesgrupo.com/login'] as $esperado) {
            $this->assertStringContainsString($esperado, $p);
        }
        $this->assertStringContainsString('DIAGNÓSTICO GUIADO', $p);
        $this->assertStringContainsString('SEGURANÇA (acima de qualquer roteiro)', $p);
        $this->assertStringContainsString('Catraca desligada ou sem comunicação', $p);
        $this->assertStringContainsString('Ar-condicionado não liga', $p);
        // O POP de procedimentos básicos (20/10/2026) e o padrão de pedir foto ou vídeo.
        foreach (['Impressora não imprime', 'Computador não liga', 'Computador lento ou travando', 'Mouse ou teclado não funciona',
            'Monitor sem imagem', 'Catraca não reconhece face ou digital', 'Nobreak apitando ou desligado'] as $roteiro) {
            $this->assertStringContainsString($roteiro, $p);
        }
        $this->assertStringContainsString('FOTO OU VÍDEO É O PADRÃO', $p);
    }

    public function test_o_pop_basico_nao_duplica_ao_rodar_de_novo(): void
    {
        $antes = SuporteRoteiro::count();
        (require base_path('database/migrations/2026_10_20_170000_suporte_pop_basico.php'))->up();
        $this->assertSame($antes, SuporteRoteiro::count());
    }

    public function test_os_roteiros_seguros_nao_mandam_abrir_equipamento(): void
    {
        foreach (SuporteRoteiro::all() as $r) {
            // Frases de proibição ("Não oriente abrir o aparelho") são justamente o que se quer.
            $instrucoes = preg_replace('/[^.\n]*\b(não|nunca)\b[^.\n]*/iu', '', (string) $r->orientacao);
            $this->assertDoesNotMatchRegularExpression('/\b(abra|abrir) (o|a) (quadro|equipamento|aparelho|placa)\b/iu', $instrucoes, $r->titulo);
        }
        $this->assertGreaterThanOrEqual(12, SuporteRoteiro::where('ativo', true)->count());
    }

    public function test_a_migracao_nao_passa_por_cima_do_que_a_equipe_editou(): void
    {
        $editor = User::factory()->create();
        SuporteRoteiro::where('titulo', 'Catraca desligada ou sem comunicação')->update(['orientacao' => 'Versão do Rossini', 'atualizado_por' => $editor->id]);
        \Illuminate\Support\Facades\DB::table('suporte_ajustes')->where('chave', 'contatos')->update(['valor' => 'Contatos editados']);

        (require base_path('database/migrations/2026_10_20_150000_suporte_estagiario.php'))->up();

        $this->assertSame('Versão do Rossini', SuporteRoteiro::where('titulo', 'Catraca desligada ou sem comunicação')->value('orientacao'));
        $this->assertSame('Contatos editados', \Illuminate\Support\Facades\DB::table('suporte_ajustes')->where('chave', 'contatos')->value('valor'));
        $this->assertSame(1, SuporteRoteiro::where('titulo', 'Catraca desligada ou sem comunicação')->count(), 'não duplica');
    }

    public function test_a_equipe_ve_os_atendimentos_do_cliente_citado(): void
    {
        // Sem a lista de acesso interno (que já traz o número do Felipe como diretor): aqui vale o funcionário.
        app(\App\Services\Suporte\Conhecimento::class)->gravarAjuste(\App\Services\Suporte\AcessoInterno::AJUSTE, '');
        app(DiretorioGestor::class)->gravar('wsl', now()->toDateTimeString(),
            [['id' => 13, 'nome' => 'CONDOMINIO CASTANHEIRAS', 'fantasia' => 'CASTANHEIRAS', 'razao' => 'CONDOMINIO RESIDENCIAL PARQUE DAS CASTANHEIRAS', 'cidade' => 'VILA VELHA', 'telefones' => []]],
            [['id' => 1, 'numero' => '900', 'cliente_id' => 13, 'descricao' => 'Portão G1 parado', 'status' => 'em_atendimento', 'criado_em' => '2026-10-18 09:00:00', 'funcionarios' => [19]]],
            [], [['id' => 13, 'nome' => 'Felipe Henrique Gat', 'telefones' => ['27 9.9884-5397']]]);

        $p = $this->prompt('5527998845397', 'Dúvidas sobre o castanheiras');
        $this->assertStringContainsString('CLIENTE CITADO: CONDOMINIO RESIDENCIAL PARQUE DAS CASTANHEIRAS (CASTANHEIRAS)', $p);
        $this->assertStringContainsString('nº 900 — etapa: em atendimento', $p);
    }

    /** Felipe, 20/10/2026: o Pedro pesquisa no Showroom comercial do Gestor o que o cliente perguntou. */
    public function test_o_pedro_pesquisa_no_showroom_so_o_que_o_cliente_perguntou(): void
    {
        app(DiretorioGestor::class)->gravar('wsl', now()->toDateTimeString(), [], [], [], [], [
            ['categoria' => 'Produtos', 'titulo' => 'Controle de acesso e portaria', 'descricao' => 'Facial, biometria e portaria remota', 'texto' => 'Catracas e leitores faciais integrados ao AlfaControl.', 'url' => '', 'video' => ''],
            ['categoria' => 'Produtos', 'titulo' => 'Climatização e ar-condicionado', 'descricao' => 'Split, VRF e câmaras frias', 'texto' => 'Projeto, instalação e PMOC.', 'url' => '', 'video' => ''],
            ['categoria' => 'Demos & Links', 'titulo' => 'Site — Invest Digital', 'descricao' => 'Segurança eletrônica e TI', 'texto' => '', 'url' => 'https://invest.solucoesgrupo.com/', 'video' => ''],
        ]);

        $p = $this->prompt('5527911112222', 'Vocês fazem controle de acesso com facial para condomínio?');
        $this->assertStringContainsString('## SHOWROOM COMERCIAL', $p);
        $this->assertStringContainsString('Controle de acesso e portaria (Produtos): Facial, biometria e portaria remota', $p);
        $this->assertStringContainsString('não prometa preço', $p);
        $this->assertStringNotContainsString('Climatização e ar-condicionado', $p, 'só o que combina');

        $this->assertStringNotContainsString('## SHOWROOM COMERCIAL', $this->prompt('5527933334444', 'oi, bom dia'), 'conversa sem assunto não leva o showroom');
    }
}
