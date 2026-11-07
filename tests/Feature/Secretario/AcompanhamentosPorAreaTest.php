<?php

namespace Tests\Feature\Secretario;

use App\Models\Notificacao;
use App\Models\SecretarioGestorRetrato;
use App\Models\SuporteConversa;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\Secretario\Areas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * De quem é cada coisa em Acompanhamentos (docs/specs/secretario-acompanhamentos-por-area;
 * Felipe, 07/11/2026): suporte é do Alexandre, sistemas e desenvolvimento do
 * Rossini, comercial e administrativo do Felipe — do Gestor e do Matriz.
 */
class AcompanhamentosPorAreaTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    private User $felipe;

    private User $rossini;

    private User $alexandre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->felipe = $this->dono(telegram: false);
        $this->felipe->update(['name' => 'Felipe Henrique']);
        $this->rossini = $this->dono(telegram: false);
        $this->rossini->update(['name' => 'Rossini Santos']);
        $this->alexandre = $this->dono(telegram: false);
        $this->alexandre->update(['name' => 'Alexandre Blank']);
        config(['services.areas' => ['comercial' => $this->felipe->email, 'administrativo' => $this->felipe->email,
            'suporte' => $this->alexandre->email, 'desenvolvimento' => $this->rossini->email]]);
    }

    private function aviso(User $para, string $titulo, array $extra = []): Notificacao
    {
        return Notificacao::create($extra + ['destinatario_id' => $para->id, 'tipo' => 'lembrete', 'nivel' => 'atencao',
            'icone' => 'bell', 'titulo' => $titulo, 'meta' => null, 'rota' => route('agenda.index')]);
    }

    /** Os avisos que o Felipe recebia misturados, cada um com o dono certo. */
    public function test_cada_aviso_vai_para_a_area_e_a_pessoa_certas(): void
    {
        $f = $this->felipe;
        $dev = Tarefa::factory()->create(['tipo' => 'desenvolvimento', 'responsavel_id' => $this->rossini->id]);
        $bugSemDono = Tarefa::factory()->create(['tipo' => 'bug', 'responsavel_id' => null]);
        $operacional = Tarefa::factory()->create(['tipo' => 'operacional', 'responsavel_id' => $this->alexandre->id]);
        $doAtendimento = Tarefa::factory()->create(['tipo' => 'operacional', 'responsavel_id' => null]);
        SuporteConversa::create(['telefone' => '5527999990001', 'tarefa_id' => $doAtendimento->id]);

        $avisos = collect([
            'sincroniza' => $this->aviso($f, 'AlfaJornada parou de sincronizar', ['tipo' => 'sincronizacao', 'nivel' => 'critico', 'rota' => url('/sistemas/3/edit')]),
            'whatsapp' => $this->aviso($f, 'Ainda sem resposta: cliente — Pedro sem resposta', ['tipo' => 'direcionamento', 'meta' => 'Suporte WhatsApp', 'rota' => url('/suporte/conversas/3')]),
            'chamaram' => $this->aviso($f, 'Chamaram você no WhatsApp: cliente — quer o Felipe', ['tipo' => 'direcionamento', 'meta' => 'Suporte WhatsApp', 'rota' => url('/suporte/conversas/4')]),
            'cobranca' => $this->aviso($f, 'Cobrança T-9: cliente — CRÍTICO', ['tipo' => 'direcionamento', 'meta' => 'Suporte WhatsApp', 'tarefa_id' => $doAtendimento->id, 'rota' => route('tarefas.index')]),
            'licenca' => $this->aviso($f, 'Licença expira em 7 dias: EXPERIENCE', ['rota' => route('clientes.index')]),
            'faturamento' => $this->aviso($f, 'Faturamento gerado', ['tipo' => 'faturamento', 'rota' => route('faturamento.index')]),
            'pergunta' => $this->aviso($f, 'Rossini Santos perguntou em «Leitor facial»', ['tipo' => 'pergunta', 'tarefa_id' => $dev->id, 'rota' => route('tarefas.index')]),
            'triagem' => $this->aviso($f, 'Bug novo na triagem', ['tipo' => 'triagem', 'tarefa_id' => $bugSemDono->id, 'rota' => route('tarefas.index')]),
            'concluiu' => $this->aviso($f, 'Concluída: trocar o cabo', ['tipo' => 'conclusao', 'tarefa_id' => $operacional->id, 'rota' => route('tarefas.index')]),
            'agenda' => $this->aviso($f, 'Reunião em 30 min'),
        ]);

        $de = app(Areas::class)->dosAvisos($avisos->values(), $f);
        $quem = $avisos->map(fn ($n) => [$de[$n->id]['quem'], $de[$n->id]['area']])->all();

        $this->assertSame([$this->rossini->id, 'desenvolvimento'], $quem['sincroniza']);
        $this->assertSame([$this->alexandre->id, 'suporte'], $quem['whatsapp']);
        $this->assertSame([$f->id, 'suporte'], $quem['chamaram'], 'o cliente pediu por ele: é dele');
        $this->assertSame([$this->alexandre->id, 'suporte'], $quem['cobranca'], 'cobrança do atendimento é do suporte, mesmo com tarefa');
        $this->assertSame([$f->id, 'comercial'], $quem['licenca']);
        $this->assertSame([$f->id, 'administrativo'], $quem['faturamento']);
        $this->assertSame([$f->id, 'desenvolvimento'], $quem['pergunta'], 'a pergunta feita a ele é dele, de qualquer área');
        $this->assertSame([$this->rossini->id, 'desenvolvimento'], $quem['triagem'], 'bug sem responsável é do desenvolvimento');
        $this->assertSame([$this->alexandre->id, null], $quem['concluiu'], 'notícia de tarefa é de quem está com ela');
        $this->assertSame([$f->id, null], $quem['agenda']);
    }

    /** A tela: o filtro com os três, a contagem do que está a tratar e o rótulo em cada linha, do Gestor também. */
    public function test_a_tela_separa_para_mim_rossini_e_alexandre(): void
    {
        $f = $this->felipe;
        $this->aviso($f, 'AlfaGym parou de sincronizar', ['tipo' => 'sincronizacao', 'nivel' => 'critico', 'rota' => url('/sistemas/3/edit')]);
        $this->aviso($f, 'AlfaControl parou de sincronizar', ['tipo' => 'sincronizacao', 'nivel' => 'critico', 'rota' => url('/sistemas/3/edit')]);
        $this->aviso($f, 'Na fila do WhatsApp: cliente', ['tipo' => 'direcionamento', 'meta' => 'Suporte WhatsApp', 'rota' => url('/suporte/conversas/5')]);
        $this->aviso($f, 'Licença expira em 7 dias: EXPERIENCE', ['rota' => route('clientes.index')]);
        // Tratada: leva o rótulo, mas não conta no que falta.
        $this->aviso($f, 'AlfaJornada voltou a sincronizar', ['tipo' => 'sincronizacao', 'rota' => url('/sistemas/3/edit')])
            ->forceFill(['lida_em' => now()->subHour()])->save();
        SecretarioGestorRetrato::create(['user_id' => $f->id, 'maquina' => 'wsl', 'lido_em' => now()->subHour(), 'dados' => ['blocos' => [
            'orcamentos_aprovacao' => ['quantidade' => 1, 'itens' => [['texto' => 'Orç. 151 — VD Cariacica', 'data' => now()->subDays(3)->toDateString()]]],
            'cobrancas_vencidas' => ['quantidade' => 1, 'valor' => 900, 'itens' => [['texto' => 'CONDOMÍNIO X — Mensalidade', 'valor' => 900, 'data' => now()->subDay()->toDateString()]]],
        ]]]);

        $html = $this->actingAs($f)->get(route('secretario.acompanhamentos'))->assertOk()->getContent();

        // Para mim: a licença + as duas linhas do Gestor; Rossini: as duas sincronizações a tratar; Alexandre: a fila.
        $this->assertMatchesRegularExpression('/data-quem="eu"[^>]*>\s*Para mim\s*<span[^>]*>3</', $html);
        $this->assertMatchesRegularExpression('/data-quem="u'.$this->rossini->id.'"[^>]*>\s*Rossini\s*<span[^>]*>2</', $html);
        $this->assertMatchesRegularExpression('/data-quem="u'.$this->alexandre->id.'"[^>]*>\s*Alexandre\s*<span[^>]*>1</', $html);
        $this->assertMatchesRegularExpression('/data-quem="todos"[^>]*>\s*Todos\s*<span[^>]*>6</', $html);
        $this->assertLessThan(strpos($html, 'data-quem="u'.$this->alexandre->id.'"'), strpos($html, 'data-quem="u'.$this->rossini->id.'"'),
            'na ordem do pedido: para mim, Rossini, Alexandre');

        $this->assertStringContainsString('Rossini · Desenvolvimento', $html);
        $this->assertStringContainsString('Alexandre · Suporte', $html);
        $this->assertStringContainsString('Você · Comercial', $html, 'licença e orçamento do Gestor');
        $this->assertStringContainsString('Você · Administrativo', $html, 'cobrança do Gestor');
        $this->assertStringContainsString('Sistemas e desenvolvimento: Rossini · Suporte: Alexandre', $html);
    }

    /** Vale para todo mundo, e não só para o Felipe: na tela do Rossini, a licença é do Felipe e a sincronização é dele. */
    public function test_na_tela_de_outra_pessoa_o_dono_e_o_mesmo(): void
    {
        $this->aviso($this->rossini, 'AlfaGym parou de sincronizar', ['tipo' => 'sincronizacao', 'rota' => url('/sistemas/3/edit')]);
        $this->aviso($this->rossini, 'Licença expira em 7 dias: EXPERIENCE', ['rota' => route('clientes.index')]);

        $html = $this->actingAs($this->rossini)->get(route('secretario.acompanhamentos'))->assertOk()->getContent();

        $this->assertStringContainsString('Você · Desenvolvimento', $html);
        $this->assertStringContainsString('Felipe · Comercial', $html);
        $this->assertMatchesRegularExpression('/data-quem="u'.$this->felipe->id.'"[^>]*>\s*Felipe\s*<span[^>]*>1</', $html);
        $this->assertStringNotContainsString('data-quem="u'.$this->alexandre->id.'"', $html, 'sem nada a tratar, sem botão');
    }

    /** Área cuja conta não existe ou foi desativada: a tela diz a área, sem inventar pessoa. */
    public function test_area_sem_conta_fica_sem_dono(): void
    {
        $this->alexandre->update(['ativo' => false]);
        $this->aviso($this->felipe, 'Na fila do WhatsApp: cliente', ['tipo' => 'direcionamento', 'meta' => 'Suporte WhatsApp', 'rota' => url('/suporte/conversas/5')]);

        $html = $this->actingAs($this->felipe)->get(route('secretario.acompanhamentos'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-quem="sem"[^>]*>\s*Sem dono\s*<span[^>]*>1</', $html);
        $this->assertStringContainsString('>Suporte</span>', $html);
        $this->assertStringContainsString('Suporte: sem dono', $html);
    }
}
