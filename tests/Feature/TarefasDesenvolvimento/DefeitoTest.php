<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\CriarTarefa;
use App\Mcp\Tools\EditarTarefa;
use App\Mcp\Tools\Referencias;
use App\Mcp\Tools\VerTarefa;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O tipo Defeito e o relato que vem com ele (tarefa #204).
 *
 * As #185 e #191 chegaram só com o sintoma — sem aluno, sem data, uma sem
 * academia. O Defeito anda pelo fluxo do desenvolvimento, mas nasce com quem
 * e quando, pela tela e pelo agente, com a mesma frase de recusa.
 */
class DefeitoTest extends TestCase
{
    use RefreshDatabase;

    public function test_defeito_anda_pelos_portoes_como_o_desenvolvimento(): void
    {
        $defeito = Tarefa::factory()->create(['tipo' => 'defeito', 'status' => 'em_desenvolvimento']);

        $this->assertTrue($defeito->passaPelosPortoes());
        $this->assertSame(['em_revisao', 'backlog', 'cancelada'], FluxoTarefaService::transicoesDe($defeito));

        // Sem atalho de Em andamento para Concluída, como o desenvolvimento.
        try {
            (new FluxoTarefaService)->mover($defeito, 'concluida');
            $this->fail('Esperava recusa: defeito não fecha sem passar pelos portões.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Transição inválida', $e->getMessage());
        }

        // E o portão do staging vale para ele: sem veredito aprovado, não sobe.
        $noStaging = Tarefa::factory()->create(['tipo' => 'defeito', 'status' => 'em_staging']);

        try {
            (new FluxoTarefaService)->mover($noStaging, 'em_producao', ['versao_producao' => 'v1.2.3']);
            $this->fail('Esperava recusa: defeito não sobe sem validar o staging.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('validar o staging', $e->getMessage());
        }

        // Encerrar segue sendo de quem faz triagem.
        $membro = User::factory()->membro()->create();
        $this->assertNotNull($noStaging->motivoParaNaoConcluir($membro));
    }

    public function test_a_tela_exige_quem_e_quando_para_abrir_um_defeito(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Treino não aparece no app',
            'tipo' => 'defeito',
        ])->assertSessionHasErrors([
            'defeito_quem' => 'Defeito precisa dizer quem foi afetado: o cliente, o aluno ou a academia.',
            'defeito_quando' => 'Defeito precisa dizer quando aconteceu: o dia e a hora.',
        ]);

        $this->assertSame(0, Tarefa::count());

        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Treino não aparece no app',
            'tipo' => 'defeito',
            'defeito_quem' => '  Aluno João Silva · Academia Centro  ',
            'defeito_quando' => '2026-09-30T14:20',
            'defeito_esperado' => 'Ver o treino de quarta',
            'defeito_ocorrido' => 'Lista vazia',
        ])->assertSessionHasNoErrors();

        $tarefa = Tarefa::sole();

        $this->assertSame('defeito', $tarefa->tipo);
        $this->assertSame('Aluno João Silva · Academia Centro', $tarefa->defeito_quem);
        $this->assertSame('2026-09-30 14:20', $tarefa->defeito_quando->format('Y-m-d H:i'));
        $this->assertSame('Ver o treino de quarta', $tarefa->defeito_esperado);
        $this->assertSame('Lista vazia', $tarefa->defeito_ocorrido);
    }

    public function test_o_clique_duplo_nao_cria_dois_defeitos(): void
    {
        $usuario = User::factory()->create();
        $envio = [
            'titulo' => 'Check-in recusado',
            'tipo' => 'defeito',
            'defeito_quem' => 'Academia Norte',
            'defeito_quando' => '2026-09-30T08:05',
        ];

        $this->actingAs($usuario)->post(route('tarefas.store'), $envio);
        $this->actingAs($usuario)->post(route('tarefas.store'), $envio);

        $this->assertSame(1, Tarefa::count());
    }

    public function test_outros_tipos_nao_gravam_o_relato_escondido(): void
    {
        $usuario = User::factory()->create();

        // O formulário carrega os campos escondidos: quem preencheu e voltou o
        // tipo para Desenvolvimento não pediu para gravar relato.
        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Tela nova de planos',
            'tipo' => 'desenvolvimento',
            'defeito_quem' => 'Ninguém',
            'defeito_quando' => '2026-09-30T08:05',
        ])->assertSessionHasNoErrors();

        $tarefa = Tarefa::sole();

        $this->assertNull($tarefa->defeito_quem);
        $this->assertNull($tarefa->defeito_quando);
    }

    public function test_virar_defeito_na_edicao_tambem_exige_o_relato(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['tipo' => 'desenvolvimento', 'titulo' => 'Pagamento duplicado']);

        $this->actingAs($usuario)->put(route('tarefas.update', $tarefa), [
            'titulo' => 'Pagamento duplicado',
            'tipo' => 'defeito',
        ])->assertSessionHasErrors(['defeito_quem', 'defeito_quando']);

        $this->assertSame('desenvolvimento', $tarefa->fresh()->tipo);
    }

    public function test_o_formulario_traz_o_modelo_e_o_card_se_anuncia(): void
    {
        $usuario = User::factory()->create();
        $defeito = Tarefa::factory()->create([
            'tipo' => 'defeito', 'status' => 'backlog', 'responsavel_id' => User::factory(),
            'defeito_quem' => 'Aluno Pedro', 'defeito_quando' => '2026-09-29 19:40:00',
        ]);
        $desenvolvimento = Tarefa::factory()->create([
            'tipo' => 'desenvolvimento', 'status' => 'backlog', 'responsavel_id' => User::factory(),
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O modelo está no "nova tarefa", com os quatro campos e o lembrete do print.
        $this->assertStringContainsString('data-relato-defeito', $html);
        foreach (['defeito_quem', 'defeito_quando', 'defeito_esperado', 'defeito_ocorrido'] as $campo) {
            $this->assertStringContainsString('name="'.$campo.'"', $html);
        }
        $this->assertStringContainsString('Print da tela', $html);
        $this->assertStringContainsString('<option value="defeito"', $html);

        $this->assertStringContainsString('>Defeito</span>', $this->trechoDoCard($html, $defeito->id));
        $this->assertStringNotContainsString('>Defeito</span>', $this->trechoDoCard($html, $desenvolvimento->id));

        // A edição devolve o relato gravado nos campos.
        $this->actingAs($usuario)->get(route('tarefas.modal', $defeito))
            ->assertOk()
            ->assertSee('value="Aluno Pedro"', false)
            ->assertSee('value="2026-09-29T19:40"', false);
    }

    public function test_o_agente_abre_defeito_so_com_quem_e_quando(): void
    {
        $admin = User::factory()->create();
        Sistema::factory()->create(['nome' => 'AlfaGym']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(CriarTarefa::class, ['titulo' => 'Treino sumiu', 'tipo' => 'defeito', 'sistema' => 'AlfaGym'])
            ->assertHasErrors()
            ->assertSee('Defeito precisa dizer quem foi afetado');

        $this->assertSame(0, Tarefa::count());

        // Relato sem o tipo é engano de quem ditou, e não um relato a descartar.
        AlfaMatrizServer::actingAs($admin)
            ->tool(CriarTarefa::class, ['titulo' => 'Treino sumiu', 'quem' => 'Aluno João'])
            ->assertHasErrors()
            ->assertSee('Passe tipo "defeito"');

        AlfaMatrizServer::actingAs($admin)
            ->tool(CriarTarefa::class, [
                'titulo' => 'Treino sumiu', 'tipo' => 'defeito', 'sistema' => 'AlfaGym',
                'quem' => 'Aluno João · Academia Centro', 'quando' => '2026-09-30 14:20',
                'ocorrido' => 'Lista de treinos vazia',
            ])
            ->assertOk()
            ->assertSee('criada');

        $tarefa = Tarefa::sole();
        $this->assertSame('defeito', $tarefa->tipo);
        $this->assertSame('Aluno João · Academia Centro', $tarefa->defeito_quem);
        $this->assertSame('2026-09-30 14:20', $tarefa->defeito_quando->format('Y-m-d H:i'));

        AlfaMatrizServer::actingAs($admin)
            ->tool(VerTarefa::class, ['tarefa' => '#'.$tarefa->id])
            ->assertOk()
            ->assertSee('Tipo: Defeito')
            ->assertSee('Quem: Aluno João · Academia Centro')
            ->assertSee('Quando: 30/09/2026 14:20')
            ->assertSee('Ocorrido: Lista de treinos vazia');
    }

    public function test_o_agente_edita_o_relato_e_nao_vira_defeito_sem_ele(): void
    {
        $admin = User::factory()->create();
        $tarefa = Tarefa::factory()->create(['tipo' => 'desenvolvimento']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(EditarTarefa::class, ['tarefa' => '#'.$tarefa->id, 'tipo' => 'defeito'])
            ->assertHasErrors()
            ->assertSee('Defeito precisa dizer quando aconteceu');

        $this->assertSame('desenvolvimento', $tarefa->fresh()->tipo);

        AlfaMatrizServer::actingAs($admin)
            ->tool(EditarTarefa::class, [
                'tarefa' => '#'.$tarefa->id, 'tipo' => 'defeito',
                'quem' => 'Academia Sul', 'quando' => '2026-09-28 10:00',
            ])
            ->assertOk();

        // Parcial: o que já está gravado vale, e só o que veio muda.
        AlfaMatrizServer::actingAs($admin)
            ->tool(EditarTarefa::class, ['tarefa' => '#'.$tarefa->id, 'esperado' => 'Entrar no app'])
            ->assertOk();

        $tarefa->refresh();
        $this->assertSame('defeito', $tarefa->tipo);
        $this->assertSame('Academia Sul', $tarefa->defeito_quem);
        $this->assertSame('Entrar no app', $tarefa->defeito_esperado);
    }

    public function test_referencias_explica_o_tipo_defeito(): void
    {
        AlfaMatrizServer::actingAs(User::factory()->create())
            ->tool(Referencias::class, [])
            ->assertOk()
            ->assertSee('defeito = Defeito')
            ->assertSee('Defeito segue o fluxo do desenvolvimento e exige o relato');
    }

    public function test_os_relatorios_contam_defeitos_por_sistema(): void
    {
        $gym = Sistema::factory()->create(['nome' => 'AlfaGym']);
        $control = Sistema::factory()->create(['nome' => 'AlfaControl']);

        $original = Tarefa::factory()->create(['tipo' => 'defeito', 'sistema_id' => $gym->id]);
        Tarefa::factory()->create(['tipo' => 'defeito', 'sistema_id' => $gym->id]);
        Tarefa::factory()->create(['tipo' => 'defeito', 'sistema_id' => $control->id]);
        // Fora da conta: não é defeito, o defeito de outro mês e o mesmo
        // defeito pedido de novo (#205) — a reclamação já contou na original.
        Tarefa::factory()->create(['tipo' => 'desenvolvimento', 'sistema_id' => $control->id]);
        Tarefa::factory()->create(['tipo' => 'defeito', 'sistema_id' => $control->id])
            ->forceFill(['created_at' => now()->subMonths(2)])->save();
        Tarefa::factory()->create(['tipo' => 'defeito', 'sistema_id' => $gym->id, 'status' => 'cancelada'])
            ->forceFill(['duplicada_de_id' => $original->id])->save();

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('relatorios.index', ['secao' => 'desenvolvimento']))
            ->assertOk()
            ->assertSee('Defeitos por sistema');

        $ranking = collect($resposta->viewData('rankingDefeitos')['itens'])->pluck('valor', 'nome')->all();

        $this->assertSame(['AlfaGym' => 2.0, 'AlfaControl' => 1.0], $ranking);
    }

    private function trechoDoCard(string $html, int $tarefaId): string
    {
        $inicio = strpos($html, 'data-tarefa="'.$tarefaId.'"');
        $this->assertNotFalse($inicio, "A tarefa {$tarefaId} não apareceu no quadro.");

        return substr($html, $inicio, strpos($html, '</article>', $inicio) - $inicio);
    }
}
