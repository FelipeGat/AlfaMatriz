<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operar um sistema (dono, 12/10/2026): "agende o Vitor amanhã o dia todo no
 * cliente Royal Palace". Não é a agenda pessoal, não é "sem IA", e o "sim" da
 * confirmação continua sendo a mesma operação.
 */
class OperacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_pedido_de_agendar_no_gestor_e_operacao_e_a_confirmacao_segue_nela(): void
    {
        config(['central_ia.canal_exclusivo' => false]);
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        $r = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude', 'codex', 'copilot'], 'projetos' => ['gestor'], 'visto_em' => now()]);
        $texto = 'agende no calendário o Vitor amanha o dia todo no cliente: Royal Palace. Para continuação do atendimento referente a eletrica da garagem';
        $s = app(AgentesService::class);

        $e = $s->pedir($u, $texto, ['projeto' => 'gestor', 'agente' => 'auto', 'modo' => 'auto', 'instrucao' => $texto], 'web', 'c-op');
        $this->assertSame('operacao', $e->roteamento['categoria']);
        $this->assertSame('analisar', $e->modo, 'confere e pergunta antes; não pede o código de implementação');
        $this->assertSame('medio', $e->roteamento['nivel']);
        $this->assertSame([], $e->roteamento['fontes'], 'a agenda do Gestor não é a Google Agenda do dono');

        $job = $s->proxima($u, $r);
        $this->assertSame('operacao', $job['categoria']);
        $s->concluir($u, $job['id'], ['status' => 'concluida', 'resposta' => "Achei o atendimento 123.\n<<<CENTRAL-IA\n".json_encode(['resumo' => 'ok', 'duvida' => 'Agenda do Vitor vazia amanhã; agendo das 08:00 às 18:00?', 'opcoes_duvida' => ['Sim, pode fazer', 'Não']])."\nCENTRAL-IA>>>"]);

        $sim = $s->pedir($u, 'x', ['projeto' => 'gestor', 'agente' => $e->agente, 'modo' => 'auto', 'tarefa_id' => $e->tarefa_id,
            'instrucao' => 'Resposta à sua pergunta ("Agenda do Vitor vazia amanhã; agendo das 08:00 às 18:00?"): Sim, pode fazer'], 'web', 'c-sim');
        $this->assertSame('operacao', $sim->roteamento['categoria']);
        $this->assertSame('analisar', $sim->modo);
        $this->assertSame('medio', $sim->roteamento['nivel']);
        $this->assertStringStartsWith('Resposta à pergunta de', $sim->roteamento['motivo']);
        $this->assertSame($e->tarefa_id, $sim->tarefa_id);
    }

    public function test_ler_a_agenda_continua_sem_ia_mas_marcar_nao(): void
    {
        $r = app(\App\Services\CentralIa\Roteador::class);
        $agenda = [['tipo' => 'agenda']];
        $this->assertSame('sem_ia', $r->nivel('quais meus compromissos amanhã?', 'investigacao', 'analisar', $agenda));
        $this->assertNotSame('sem_ia', $r->nivel('marque reunião amanhã na agenda', 'investigacao', 'analisar', $agenda));
    }
}
