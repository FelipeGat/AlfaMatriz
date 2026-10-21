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

    public function test_tecnico_da_equipe_ve_os_atendimentos_abertos_em_nome_dele(): void
    {
        $p = $this->prompt('5527999991234');
        $this->assertStringContainsString('## QUEM ESCREVE — EQUIPE INTERNA', $p);
        $this->assertStringContainsString('Jonas Técnico, da equipe', $p);
        $this->assertStringContainsString('ATENDIMENTOS ABERTOS EM NOME DELE (2)', $p);
        $this->assertStringContainsString('nº 900 — CASTANHEIRAS — etapa: em atendimento', $p);
        $this->assertStringContainsString('nº 901', $p);
        $this->assertStringNotContainsString('nº 850', $p, 'concluído não entra');
        $this->assertStringNotContainsString('nº 902', $p, 'de outro técnico não entra');
    }

    public function test_felipe_sem_atendimentos_e_reconhecido_mesmo_com_o_nono_digito_diferente(): void
    {
        // Sem a lista de acesso interno (que já traz o número do Felipe como diretor): aqui vale o funcionário.
        app(\App\Services\Suporte\Conhecimento::class)->gravarAjuste(\App\Services\Suporte\AcessoInterno::AJUSTE, '');
        $p = $this->prompt('552798845397');
        $this->assertStringContainsString('Felipe Henrique Gat, da equipe', $p);
        $this->assertStringContainsString('ATENDIMENTOS ABERTOS EM NOME DELE (0)', $p);
    }

    public function test_cliente_comum_nao_ganha_a_secao_da_equipe(): void
    {
        $this->assertStringNotContainsString('EQUIPE INTERNA', $this->prompt('5527988887777'));
    }
}
