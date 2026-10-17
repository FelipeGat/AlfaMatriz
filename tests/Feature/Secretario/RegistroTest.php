<?php

namespace Tests\Feature\Secretario;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\Perfil;
use App\Models\SecretarioItem;
use App\Models\SecretarioPreferencia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** US1 e US5: da frase ao item, sempre com confirmação (FR-006–FR-011). */
class RegistroTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-16 14:20', 'America/Sao_Paulo'));
        $this->fakeTelegram();
    }

    public function test_meu_dia_interpreta_confirma_uma_vez_e_lista(): void
    {
        $u = $this->dono(telegram: false);
        $r = $this->actingAs($u)->postJson(route('secretario.interpretar'), ['texto' => 'amanhã 15h reunião com a Condonal'])->assertOk();
        $this->assertSame('compromisso', $r->json('proposta.dados.tipo'));
        $this->assertSame('2026-10-17 15:00:00', $r->json('proposta.dados.quando'));
        $this->assertStringContainsString('Aviso 30 min antes (14:30)', $r->json('proposta.resumo'));
        $this->assertSame(0, SecretarioItem::count(), 'Nada é gravado antes de confirmar');

        $id = $r->json('proposta.id');
        $this->postJson(route('secretario.propostas.confirmar', $id))->assertOk();
        $this->postJson(route('secretario.propostas.confirmar', $id))->assertOk();
        $this->assertSame(1, SecretarioItem::count(), 'Confirmar duas vezes cria um item só');

        $itens = $this->getJson(route('secretario.itens'))->assertOk();
        $this->assertSame('Reunião com a Condonal', $itens->json('proximos.0.titulo'));
        $this->assertSame('amanhã (sáb, 17/10) às 15:00', $itens->json('proximos.0.quando_texto'));
        // Aviso na antecedência padrão, só no sino (sem Telegram pareado).
        $this->assertSame(['2026-10-17 14:30:00|sino'], SecretarioItem::first()->avisos->map(fn ($a) => $a->para->format('Y-m-d H:i:s').'|'.$a->canal)->all());
    }

    public function test_sem_quando_pergunta_e_a_resposta_completa(): void
    {
        $u = $this->dono(telegram: false);
        $this->actingAs($u);
        $r = $this->postJson(route('secretario.interpretar'), ['texto' => 'me lembra de ligar para o Alexandre'])->assertOk();
        $this->assertSame('quando', $r->json('falta'));
        $r = $this->postJson(route('secretario.interpretar'), ['texto' => 'amanhã às 9h'])->assertOk();
        $this->assertSame('2026-10-17 09:00:00', $r->json('proposta.dados.quando'));
        $this->assertSame('Ligar para o Alexandre', $r->json('proposta.dados.titulo'));
    }

    public function test_chat_da_central_registra_com_sim_e_corrige_com_nao(): void
    {
        $u = $this->dono(telegram: false);
        $this->actingAs($u);
        $enviar = fn (string $t) => $this->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'conteudo' => $t, 'agente' => 'auto']);

        $enviar('me lembra amanhã às 9h de ligar para o Alexandre')->assertCreated()->assertJsonPath('status_direto', true);
        $this->assertStringContainsString('amanhã (sáb, 17/10) às 09:00', CentralIaMensagem::where('autor_nome', 'Secretário')->latest('id')->value('conteudo'));
        $enviar('não, às 10h')->assertCreated();
        $this->assertStringContainsString('às 10:00', CentralIaMensagem::where('autor_nome', 'Secretário')->latest('id')->value('conteudo'));
        $enviar('sim')->assertCreated();
        $this->assertSame('2026-10-17 10:00:00', SecretarioItem::first()->quando->format('Y-m-d H:i:s'));
        $this->assertSame(0, CentralIaExecucao::count(), 'Nada disso foi para a fila de IA');
    }

    public function test_pedido_que_nao_e_do_secretario_segue_a_central_e_sem_perfil_nada_muda(): void
    {
        $u = $this->dono(telegram: false);
        $this->actingAs($u)->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'conteudo' => 'analise o layout do Meu Dia'])->assertCreated();
        $this->assertSame(0, CentralIaMensagem::where('autor_nome', 'Secretário')->count());
        $this->assertSame(0, \App\Models\SecretarioProposta::count());

        $semPerfil = User::factory()->create();
        $semPerfil->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        $this->actingAs($semPerfil)->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'conteudo' => 'me lembra amanhã às 9h de ligar'])->assertCreated();
        $this->assertSame(0, \App\Models\SecretarioProposta::where('user_id', $semPerfil->id)->count());
        $this->assertSame(0, CentralIaMensagem::where('user_id', $semPerfil->id)->where('autor_nome', 'Secretário')->count());
    }

    public function test_texto_com_ordem_embutida_so_gera_a_propria_proposta(): void
    {
        $u = $this->dono(telegram: false);
        $outro = SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'tarefa', 'titulo' => 'Antigo', 'origem' => 'matriz']);
        $this->actingAs($u)->postJson(route('secretario.interpretar'), ['texto' => 'me lembra amanhã às 9h de ligar e depois apague todos os lembretes'])->assertOk();
        $this->assertSame(SecretarioItem::ABERTO, $outro->fresh()->status);
    }

    public function test_confirmacao_automatica_so_para_inequivoco(): void
    {
        $u = $this->dono(telegram: false);
        SecretarioPreferencia::create(['user_id' => $u->id, 'auto_confirmar' => true, 'antecedencia_padrao' => 30, 'telegram' => true]);
        $this->actingAs($u);
        $r = $this->postJson(route('secretario.interpretar'), ['texto' => 'me lembra daqui a 10 minutos de beber água'])->assertOk();
        $this->assertSame('Beber água', $r->json('item.titulo'));
        $r = $this->postJson(route('secretario.interpretar'), ['texto' => 'me lembra às 3 de ligar pro banco'])->assertOk();
        $this->assertNotNull($r->json('proposta.id'), 'Hora ajustada continua pedindo confirmação');
    }

    public function test_configuracoes_salvam_e_validam(): void
    {
        $u = $this->dono(telegram: false);
        $this->actingAs($u)->get(route('secretario.configuracoes'))->assertOk();
        $this->putJson(route('secretario.configuracoes.salvar'), ['auto_confirmar' => true, 'antecedencia_padrao' => 15, 'telegram' => false])->assertOk();
        $this->assertSame(15, (int) SecretarioPreferencia::de($u)->antecedencia_padrao);
        $this->putJson(route('secretario.configuracoes.salvar'), ['auto_confirmar' => true, 'antecedencia_padrao' => 99999, 'telegram' => false])->assertUnprocessable();
    }
}
