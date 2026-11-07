<?php

namespace Tests\Feature\Secretario;

use App\Models\Notificacao;
use App\Models\Revenda;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As notificações tratadas uma a uma em Acompanhamentos
 * (docs/specs/secretario-notificacoes-em-acompanhamentos; Felipe, 07/11/2026).
 */
class NotificacoesEmAcompanhamentosTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    private function aviso(User $u, string $titulo, array $extra = []): Notificacao
    {
        return Notificacao::create($extra + ['destinatario_id' => $u->id, 'tipo' => 'lembrete', 'nivel' => 'marca',
            'icone' => 'bell', 'titulo' => $titulo, 'meta' => 'Teste', 'rota' => route('agenda.index')]);
    }

    /** Só a seção de notificações: o painel do sino, na mesma página, repete as 12 últimas. */
    private function secao(string $html): string
    {
        $inicio = strpos($html, '<section id="notificacoes"');
        $this->assertNotFalse($inicio, 'a seção de notificações está na tela');

        return substr($html, $inicio, strpos($html, '</section>', $inicio) - $inicio);
    }

    /** FR-001, FR-006, SC-001: todas as não lidas (o sino mostra 12), críticas primeiro, nenhuma alheia. */
    public function test_lista_todas_as_nao_lidas_da_conta_com_as_criticas_primeiro(): void
    {
        $u = $this->dono(telegram: false);
        foreach (range(1, 25) as $i) {
            $this->aviso($u, sprintf('Aviso %02d', $i));
        }
        $this->aviso($u, 'Cliente sem resposta há 16 h', ['nivel' => 'critico']);
        $this->aviso($this->dono(telegram: false), 'Aviso de outra conta');

        $secao = $this->secao($this->actingAs($u)->get(route('secretario.acompanhamentos'))->assertOk()->getContent());

        // A mais antiga também: era a que o sino não mostrava.
        $this->assertStringContainsString('Aviso 01', $secao);
        $this->assertStringContainsString('Aviso 25', $secao);
        $this->assertStringNotContainsString('Aviso de outra conta', $secao);
        $this->assertLessThan(strpos($secao, 'Aviso 25'), strpos($secao, 'Cliente sem resposta há 16 h'), 'a crítica vem antes');
        $this->assertSame(26, substr_count($secao, '>Tratado</button>'));
    }

    /** FR-002, FR-003: Tratado, Desfazer e a aba das tratadas de 7 dias; aviso alheio é 404. */
    public function test_tratar_desfazer_e_tratadas_dos_ultimos_sete_dias(): void
    {
        $u = $this->dono(telegram: false);
        $aviso = $this->aviso($u, 'Licença expira em 7 dias');
        $this->aviso($u, 'Tratada anteontem')->forceFill(['lida_em' => now()->subDays(2)])->save();
        $this->aviso($u, 'Tratada há oito dias')->forceFill(['lida_em' => now()->subDays(8)])->save();
        $this->actingAs($u);

        $this->postJson(route('notificacoes.lida', $aviso->id))->assertOk()->assertJson(['nao_lidas' => 0]);
        $this->assertNotNull($aviso->fresh()->lida_em);
        $this->postJson(route('notificacoes.nao-lida', $aviso->id))->assertOk()->assertJson(['nao_lidas' => 1]);
        $this->assertNull($aviso->fresh()->lida_em);

        $secao = $this->secao($this->get(route('secretario.acompanhamentos'))->getContent());
        $this->assertStringContainsString('Tratada anteontem', $secao);
        $this->assertStringContainsString('Voltar a tratar', $secao);
        $this->assertStringNotContainsString('Tratada há oito dias', $secao);

        $outro = $this->dono(telegram: false);
        $this->actingAs($outro)->postJson(route('notificacoes.nao-lida', $aviso->id))->assertNotFound();
        $this->actingAs($outro)->postJson(route('notificacoes.lida', $aviso->id))->assertNotFound();
    }

    /** FR-004: do sino, o aviso chega destacado e continua a tratar; o de outra conta não aparece. */
    public function test_o_aviso_do_sino_chega_destacado_e_continua_a_tratar(): void
    {
        $u = $this->dono(telegram: false);
        $aviso = $this->aviso($u, 'Erro voltou no log');
        $alheio = $this->aviso($this->dono(telegram: false), 'Erro de outra conta');

        $secao = $this->secao($this->actingAs($u)->get(route('secretario.acompanhamentos', ['notificacao' => $aviso->id]))->assertOk()->getContent());
        $this->assertStringContainsString('Do sino', $secao);
        $this->assertSame(1, substr_count($secao, 'id="notificacao-'.$aviso->id.'"'), 'uma linha só: a âncora não se duplica');
        $this->assertNull($aviso->fresh()->lida_em);

        $secao = $this->secao($this->get(route('secretario.acompanhamentos', ['notificacao' => $alheio->id]))->assertOk()->getContent());
        $this->assertStringNotContainsString('Do sino', $secao);
        $this->assertStringNotContainsString('Erro de outra conta', $secao);
    }

    /** FR-005, SC-002: Abrir vai à tarefa, no endereço atual; sem rota, não há Abrir. */
    public function test_abrir_leva_a_tarefa_no_endereco_atual(): void
    {
        $u = $this->dono(telegram: false);
        $tarefa = Tarefa::factory()->create();
        $daTarefa = $this->aviso($u, 'Rossini perguntou', ['tarefa_id' => $tarefa->id, 'rota' => 'https://alfamatriz.tail0939dd.ts.net/tarefas?esperando=1']);
        $doSistema = $this->aviso($u, 'AlfaGym voltou a sincronizar', ['rota' => 'https://alfamatriz.tail0939dd.ts.net/sistemas/1/edit']);
        $semRota = $this->aviso($u, 'Aviso sem destino', ['rota' => null]);

        $this->assertSame(url('/tarefas').'?esperando=1&tarefa='.$tarefa->id, $daTarefa->destino());
        $this->assertSame(url('/sistemas/1/edit'), $doSistema->destino());
        $this->assertNull($semRota->destino());

        $this->actingAs($u)->get(route('notificacoes.abrir', $daTarefa->id))->assertRedirect($daTarefa->destino());
        $this->assertNotNull($daTarefa->fresh()->lida_em);

        $secao = $this->secao($this->get(route('secretario.acompanhamentos'))->getContent());
        $linha = substr($secao, strpos($secao, 'id="notificacao-'.$semRota->id.'"'));
        $linha = substr($linha, 0, strpos($linha, '</li>'));
        $this->assertStringNotContainsString('>Abrir</a>', $linha);
    }

    /** FR-004: quem tem Acompanhamentos é levado para lá; a revenda continua indo direto ao destino. */
    public function test_o_sino_leva_a_acompanhamentos_so_quem_tem_a_tela(): void
    {
        $u = $this->dono(telegram: false);
        $aviso = $this->aviso($u, 'Para acompanhar (4)');

        $html = $this->actingAs($u)->get(route('secretario.meu-dia'))->assertOk()->getContent();
        $this->assertStringContainsString(route('secretario.acompanhamentos', ['notificacao' => $aviso->id]).'#notificacoes', $html);
        $this->assertStringContainsString('Tratar uma a uma em Acompanhamentos', $html);

        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $daRevenda = User::factory()->create(['revenda_id' => $revenda->id]);
        $doRevendedor = $this->aviso($daRevenda, 'Licença da revenda');

        $html = $this->actingAs($daRevenda)->get(route('clientes.index'))->assertOk()->getContent();
        $this->assertStringContainsString(route('notificacoes.abrir', $doRevendedor->id), $html);
        $this->assertStringNotContainsString('Tratar uma a uma em Acompanhamentos', $html);
    }
}
