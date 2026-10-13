<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chats Business, fase 2: o chat de assunto nasce de conversas do VS Code. O
 * servidor só conhece o id que a máquina listou; o runner lê a última semana e
 * o agente devolve a memória — o mapa do trabalho primeiro.
 */
class SementeTest extends TestCase
{
    use RefreshDatabase;

    private const SESSAO = 'aaaaaaaa-1111-2222-3333-444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]);
    }

    private function comCentral(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));

        return $u;
    }

    /** A batida da máquina com uma conversa da raiz ~/projetos, com nome. */
    private function batida(User $u): void
    {
        Sanctum::actingAs($u, ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.batida'), ['maquina' => 'wsl', 'agentes' => ['claude', 'codex'], 'projetos' => ['gestor'],
            'sessoes' => [
                ['agente' => 'claude', 'id' => self::SESSAO, 'projeto' => '', 'cwd' => '/home/x/projetos', 'ultima_atividade' => now()->subHour()->toIso8601String(),
                    'mensagens' => 40, 'titulo' => 'Proposta Comercial VixCard', 'origem' => 'wsl'],
                ['agente' => 'claude', 'id' => 'bbbbbbbb-1111', 'projeto' => 'alheio', 'cwd' => '/x', 'ultima_atividade' => now()->toIso8601String(), 'mensagens' => 1],
            ]])->assertOk();
    }

    public function test_conversa_da_raiz_aparece_com_o_nome_e_projeto_alheio_nao(): void
    {
        $u = $this->comCentral();
        $this->batida($u);
        $sessoes = CentralIaRunner::where('user_id', $u->id)->first()->sessoes;
        $this->assertCount(1, $sessoes);
        $this->assertSame(['', 'Proposta Comercial VixCard', 'wsl'], [$sessoes[0]['projeto'], $sessoes[0]['titulo'], $sessoes[0]['origem']]);
    }

    public function test_semear_cria_o_assunto_e_o_pedido_economico_com_a_conversa_pelo_id(): void
    {
        $u = $this->comCentral();
        $this->batida($u);

        $r = $this->actingAs($u)->postJson(route('central-ia.assuntos.semear'), ['titulo' => 'Comercial VixCard',
            'sessoes' => [['agente' => 'claude', 'id' => self::SESSAO]]])->assertCreated();
        $assunto = CentralIaTarefa::find($r->json('id'));
        $this->assertSame(CentralIaTarefa::ASSUNTO, $assunto->tipo);

        $e = CentralIaExecucao::find($r->json('execucao_id'));
        $this->assertSame(['semear', 'leve', 'analisar'], [$e->roteamento['finalidade'], $e->roteamento['nivel'], $e->modo]);
        $this->assertStringContainsString('MAPA DO TRABALHO', $e->instrucao);

        $job = app(AgentesService::class)->proxima($u, CentralIaRunner::where('user_id', $u->id)->first());
        $this->assertSame([['agente' => 'claude', 'id' => self::SESSAO, 'titulo' => 'Proposta Comercial VixCard', 'origem' => 'wsl']], $job['semear']);
        $this->assertArrayNotHasKey('cwd', $job['semear'][0], 'o servidor nunca manda caminho');

        // O agente devolve a memória; o runner diz quanto leu.
        Sanctum::actingAs($u, ['central-ia-runner']);
        $bloco = ['resumo' => 'Memória montada', 'mapa' => [
            ['texto' => 'Propostas da VixCard', 'caminho' => '~/OneDrive/Propostas/VixCard', 'etapa' => 'cliente X aguardando aprovação'],
            ['texto' => 'Token da API', 'caminho' => 'senha=SuperSecreta123456'],
        ], 'jeito_de_fazer' => ['Primeira versão sem valores'], 'decisoes' => ['Proposta em PDF'],
            // Mapa junto com pendências: em 13/10/2026 o mapa sobrescrevia o
            // ajudante das listas e a conclusão caía com 500.
            'pendencias' => ['Enviar a proposta do cliente X'], 'proximos_passos' => ['Cobrar retorno'], 'riscos' => []];
        $this->postJson(route('central-ia.runner.concluir', $e->id), ['maquina' => 'wsl', 'status' => 'concluida',
            'resposta' => "Pronto.\n<<<CENTRAL-IA\n".json_encode($bloco)."\nCENTRAL-IA>>>",
            'semente' => [['agente' => 'claude', 'id' => self::SESSAO, 'incluidos' => 31, 'fora' => 0, 'caracteres' => 94000]]])->assertOk();

        $d = $assunto->fresh()->dossie;
        $this->assertSame('~/OneDrive/Propostas/VixCard', $d['mapa'][0]['caminho']);
        $this->assertSame('cliente X aguardando aprovação', $d['mapa'][0]['etapa']);
        $this->assertStringNotContainsString('SuperSecreta123456', json_encode($d), 'segredo não entra na memória');
        $this->assertSame('Primeira versão sem valores', $d['jeito_de_fazer'][0]['texto']);
        $this->assertSame(['Enviar a proposta do cliente X'], $d['pendencias']);
        $this->assertSame([31, 'Proposta Comercial VixCard'], [$d['sementes'][0]['turnos'], $d['sementes'][0]['titulo']]);
        $this->assertSame(CentralIaTarefa::ABERTA, $assunto->fresh()->status, 'o assunto continua aberto');

        // E o próximo pedido no assunto já recebe o mapa.
        $p = app(AgentesService::class)->pedir($u, 'x', ['projeto' => '', 'tarefa_id' => $assunto->id, 'agente' => 'codex', 'modo' => 'analisar', 'instrucao' => 'onde estão as propostas?'], 'web', 'p2');
        $job = app(AgentesService::class)->proxima($u, CentralIaRunner::where('user_id', $u->id)->first());
        $this->assertSame($p->id, $job['id']);
        $this->assertStringContainsString('~/OneDrive/Propostas/VixCard', $job['contexto']);
        $this->assertStringContainsString('etapa: cliente X aguardando aprovação', $job['contexto']);
    }

    public function test_mapa_atualiza_pelo_caminho_em_vez_de_duplicar(): void
    {
        $u = $this->comCentral();
        $this->batida($u);
        $id = $this->actingAs($u)->postJson(route('central-ia.assuntos.criar'), ['titulo' => 'Obras'])->json('id');
        foreach (['fundação', 'alvenaria'] as $i => $etapa) {
            $e = app(AgentesService::class)->pedir($u, 'x', ['projeto' => '', 'tarefa_id' => $id, 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => "etapa {$i}"], 'web', "m{$i}");
            app(AgentesService::class)->proxima($u, CentralIaRunner::where('user_id', $u->id)->first());
            app(AgentesService::class)->concluir($u, $e->id, ['status' => 'concluida', 'resposta' => "ok\n<<<CENTRAL-IA\n".json_encode(['resumo' => 'ok',
                'mapa' => [['texto' => 'Planilha da obra', 'caminho' => '~/OneDrive/Obras/X.xlsx', 'etapa' => $etapa]]])."\nCENTRAL-IA>>>"]);
        }
        $mapa = CentralIaTarefa::find($id)->dossie['mapa'];
        $this->assertCount(1, $mapa);
        $this->assertSame('alvenaria', $mapa[0]['etapa']);
    }

    public function test_conversa_que_a_maquina_nao_listou_ou_de_outra_conta_e_recusada_sem_deixar_assunto(): void
    {
        $u = $this->comCentral();
        $this->batida($u);
        $outro = $this->comCentral();
        $this->batida($outro);

        $this->actingAs($u)->postJson(route('central-ia.assuntos.semear'), ['titulo' => 'x', 'sessoes' => [['agente' => 'claude', 'id' => 'nao-listada-123']]])
            ->assertUnprocessable();
        // A mesma sessão existe na máquina do outro, não na dele: recusa.
        CentralIaRunner::where('user_id', $u->id)->update(['sessoes' => []]);
        $this->actingAs($u)->postJson(route('central-ia.assuntos.semear'), ['titulo' => 'x', 'sessoes' => [['agente' => 'claude', 'id' => self::SESSAO]]])
            ->assertUnprocessable();
        $this->assertSame(0, CentralIaTarefa::where('user_id', $u->id)->count(), 'nenhum assunto vazio para trás');
    }
}
