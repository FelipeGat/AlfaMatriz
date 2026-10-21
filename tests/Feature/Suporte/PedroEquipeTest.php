<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Quem da equipe escreve é reconhecido pelo telefone do cadastro de funcionários do Gestor (Felipe, 20/10/2026). */
class PedroEquipeTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'felipe@teste.local']);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(),
            'clientes' => [['id' => 13, 'nome' => 'CONDOMINIO CASTANHEIRAS', 'fantasia' => 'CASTANHEIRAS', 'telefones' => []]],
            'atendimentos' => [
                ['id' => 1, 'numero' => '900', 'cliente_id' => 13, 'descricao' => 'Portão G1 parado', 'status' => 'em_atendimento', 'criado_em' => '2026-10-18 09:00:00', 'funcionarios' => [19]],
                ['id' => 2, 'numero' => '901', 'cliente_id' => 13, 'descricao' => 'Câmera 3 sem imagem', 'status' => 'aberto', 'criado_em' => '2026-10-19 09:00:00', 'funcionarios' => [7, 19]],
                ['id' => 3, 'numero' => '850', 'cliente_id' => 13, 'descricao' => 'Interfone', 'status' => 'concluido', 'criado_em' => '2026-10-01 09:00:00', 'funcionarios' => [19]],
                ['id' => 4, 'numero' => '902', 'cliente_id' => 13, 'descricao' => 'Alarme', 'status' => 'aberto', 'criado_em' => '2026-10-19 10:00:00', 'funcionarios' => [7]],
            ],
            'assuntos' => [],
            'equipe' => [['id' => 19, 'nome' => 'Jonas Técnico', 'telefones' => ['(27) 99999-1234']], ['id' => 13, 'nome' => 'Felipe Henrique Gat', 'telefones' => ['27 9.9884-5397']]],
        ])->assertOk();
    }

    private function prompt(string $telefone): string
    {
        $c = SuporteConversa::create(['telefone' => $telefone, 'ultima_entrada_em' => now()]);
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'oi', 'enviada_em' => now()]);

        return app(\App\Services\Suporte\Contexto::class)->prompt($c->fresh());
    }

    /**
     * 21/10/2026 (Felipe): "ele não sabe que o Alexandre é o nosso, poderia ser qualquer um".
     * O telefone do cadastro de funcionários do Gestor NÃO dá acesso: é tratado como cliente.
     * Só a lista de acesso interno (Suporte › Pedro) libera dado da casa.
     */
    public function test_telefone_de_funcionario_fora_da_lista_e_tratado_como_cliente(): void
    {
        app(\App\Services\Suporte\Conhecimento::class)->gravarAjuste(\App\Services\Suporte\AcessoInterno::AJUSTE, '');
        $p = $this->prompt('5527999991234');
        $this->assertStringNotContainsString('EQUIPE INTERNA', $p);
        $this->assertStringNotContainsString('Jonas Técnico', $p);
        $this->assertStringNotContainsString('nº 900', $p);
        $this->assertStringNotContainsString('nº 902', $p);
        $this->assertStringContainsString('QUEM ESCREVE É SEMPRE UM CLIENTE', $p);
    }

    public function test_da_lista_de_acesso_interno_e_da_casa(): void
    {
        app(\App\Services\Suporte\Conhecimento::class)->gravarAjuste(\App\Services\Suporte\AcessoInterno::AJUSTE, '+55 27 99999-1234 | Jonas | suporte');
        $p = $this->prompt('5527999991234');
        $this->assertStringContainsString('QUEM ESCREVE: Jonas', $p);
        $this->assertStringContainsString('nº 900', $p);
    }
}
