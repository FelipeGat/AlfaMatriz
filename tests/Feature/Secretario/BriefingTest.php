<?php

namespace Tests\Feature\Secretario;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\Perfil;
use App\Models\SecretarioBriefing;
use App\Models\SecretarioItem;
use App\Models\SecretarioPreferencia;
use App\Models\User;
use App\Services\CentralIa\TelegramCanal;
use App\Services\Secretario\MontadorDeBriefing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Etapa 3: o briefing diário (docs/specs/secretario-briefing). Sexta 16/10/2026. */
class BriefingTest extends TestCase
{
    use GoogleNosTestes, RefreshDatabase, SecretarioNosTestes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]);
        $this->configurarGoogle();
        $this->relogio('2026-10-16 06:50');
    }

    private function relogio(string $quando): void
    {
        Carbon::setTestNow(Carbon::parse($quando, 'America/Sao_Paulo'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($quando, 'America/Sao_Paulo'));
    }

    private function cenario(): User
    {
        $u = $this->dono();
        $this->evento('vixcard@group.calendar.google.com', 'g1', 'Reunião comercial VixCard', '2026-10-16T08:30:00-03:00', '2026-10-16T09:30:00-03:00');
        $this->evento('felipe@gmail.com', 'g2', 'Cliente RFID', '2026-10-16T10:00:00-03:00', '2026-10-16T11:00:00-03:00');
        $this->evento('felipe@gmail.com', 'g3', 'Revisão de propostas', '2026-10-16T14:30:00-03:00', '2026-10-16T15:30:00-03:00');
        $this->fakeGoogle();
        $this->conectarGoogle($u);
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'tarefa', 'titulo' => 'Enviar proposta Condonal', 'quando' => '2026-10-14 00:00:00', 'so_data' => true, 'origem' => 'matriz']);
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'lembrete', 'titulo' => 'Ligar para o Alexandre', 'quando' => '2026-10-16 16:00:00', 'origem' => 'matriz']);
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'tarefa', 'titulo' => 'Falar com o Rossini', 'origem' => 'matriz']);
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'tarefa', 'titulo' => 'Mandar o contrato', 'quando' => '2026-10-16 00:00:00', 'so_data' => true, 'origem' => 'matriz']);

        return $u;
    }

    public function test_criterio_de_aceite_3_sai_no_horario_uma_vez_no_telegram_e_no_matriz(): void
    {
        $u = $this->cenario();
        $this->artisan('secretario:briefing')->assertSuccessful();
        $this->assertSame(0, SecretarioBriefing::count(), 'Antes das 07:00, nada');

        $this->relogio('2026-10-16 07:00');
        $this->artisan('secretario:briefing')->assertSuccessful();
        $this->relogio('2026-10-16 07:01');
        $this->artisan('secretario:briefing')->assertSuccessful();

        $b = SecretarioBriefing::sole();
        $this->assertSame('entregue', $b->telegram);
        $enviados = collect($this->enviados())->pluck('text')->filter(fn ($t) => str_contains($t, 'BOM DIA'));
        $this->assertCount(1, $enviados);
        $texto = $enviados->first();
        foreach ([
            '☀️ BOM DIA — hoje (sex, 16/10)',
            '08:30–09:30 Reunião comercial VixCard (Google · VixCard)',
            '10:00–11:00 Cliente RFID (Google · Financeiro Grupo)',
            "⚠ Atrasados (1)\n• Enviar proposta Condonal (desde qua, 14/10)",
            '16:00 Ligar para o Alexandre (Secretário · Lembrete)',
            "✅ Para hoje (sem hora marcada)\n• Mandar o contrato",
            "📌 Pendências sem data (1)\n• Falar com o Rossini",
            '💡 Livre 11:00–14:30: bom momento para "Enviar proposta Condonal".',
            'Fontes: Google (lido às 06:50), Agenda do Matriz, Secretário.',
            'Ainda não entram: e-mail, reuniões, Gestor.',
        ] as $esperado) {
            $this->assertStringContainsString($esperado, $texto);
        }
        $this->assertSame($texto, $b->texto);
        $this->assertSame(1, substr_count($texto, 'Ligar para o Alexandre'), 'Sem repetição entre seções');
    }

    public function test_dias_e_horario_configurados_e_desligado(): void
    {
        $u = $this->cenario();
        // Sábado fora do padrão (seg–sex).
        $this->relogio('2026-10-17 07:30');
        $this->artisan('secretario:briefing');
        $this->assertSame(0, SecretarioBriefing::count());

        SecretarioPreferencia::create(['user_id' => $u->id, 'briefing' => true, 'briefing_hora' => '06:30', 'briefing_dias' => [1, 2, 3, 4, 5, 6]]);
        $this->relogio('2026-10-18 06:30'); // domingo
        $this->artisan('secretario:briefing');
        $this->relogio('2026-10-24 06:29'); // sábado, antes da hora
        $this->artisan('secretario:briefing');
        $this->assertSame(0, SecretarioBriefing::count());
        $this->relogio('2026-10-24 06:30');
        $this->artisan('secretario:briefing');
        $this->assertSame(1, SecretarioBriefing::count());

        SecretarioPreferencia::where('user_id', $u->id)->update(['briefing' => false]);
        $this->relogio('2026-10-26 07:00');
        $this->artisan('secretario:briefing');
        $this->assertSame(1, SecretarioBriefing::count());
    }

    public function test_parada_entrega_ate_o_meio_dia_e_depois_nao(): void
    {
        $this->cenario();
        $this->relogio('2026-10-16 07:20');
        $this->artisan('secretario:briefing');
        $this->assertSame(1, SecretarioBriefing::count());

        $this->relogio('2026-10-19 12:05'); // segunda, servidor parado a manhã toda
        $this->artisan('secretario:briefing');
        $this->assertSame(1, SecretarioBriefing::count());
    }

    public function test_telegram_fora_tenta_de_novo_e_fica_no_matriz(): void
    {
        $u = $this->dono();
        $this->fakeGoogle(['api.telegram.org/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect')]);
        $this->relogio('2026-10-16 07:00');
        $this->artisan('secretario:briefing');
        $b = SecretarioBriefing::sole();
        $this->assertSame('pendente', $b->telegram);
        foreach (range(1, 35) as $m) {
            $this->relogio('2026-10-16 07:'.str_pad((string) $m, 2, '0', STR_PAD_LEFT));
            $this->artisan('secretario:briefing');
        }
        $this->assertSame('falhou', $b->fresh()->telegram);
        $this->assertSame(1, SecretarioBriefing::count());
        $this->actingAs($u)->getJson(route('secretario.itens'))->assertJsonPath('briefing.gerado_em', '07:00');
    }

    public function test_sem_google_diz_a_limitacao_e_nao_afirma_dia_livre(): void
    {
        $this->fakeTelegram();
        $u = $this->dono(telegram: false);
        $texto = app(MontadorDeBriefing::class)->montar($u, CarbonImmutable::now())['texto'];
        $this->assertStringContainsString('Nada nas fontes que consegui ler.', $texto);
        $this->assertStringContainsString('Google Agenda não está conectado', $texto);
        $this->assertStringNotContainsString('Nada na agenda de hoje', $texto);
        $this->assertStringNotContainsString('💡', $texto, 'Sem Google em dia, o livre não é confiável');
    }

    public function test_prazo_do_dia_nao_repete_na_agenda_e_rodape_so_cita_fonte_lida(): void
    {
        $this->fakeTelegram();
        $u = User::factory()->create(); // sem admin: sem a Agenda do time
        $u->perfis()->sync(Perfil::whereIn('slug', ['central_ia', 'secretario'])->pluck('id'));
        $this->assertFalse($u->canPermissao('agenda', 'ler'));
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'tarefa', 'titulo' => 'Mandar o contrato', 'quando' => '2026-10-16 00:00:00', 'so_data' => true, 'origem' => 'matriz']);
        $texto = app(MontadorDeBriefing::class)->montar($u, CarbonImmutable::now())['texto'];
        $this->assertSame(1, substr_count($texto, 'Mandar o contrato'));
        $this->assertStringContainsString('Fontes: Secretário.', $texto);
        $this->assertStringNotContainsString('Agenda do Matriz', $texto);
    }

    public function test_dia_vazio_com_google_em_dia_e_limite_de_linhas(): void
    {
        $this->fakeGoogle();
        $u = $this->dono(telegram: false);
        $this->conectarGoogle($u);
        $vazio = app(MontadorDeBriefing::class)->montar($u, CarbonImmutable::now())['texto'];
        $this->assertStringContainsString('Nada na agenda de hoje.', $vazio);

        foreach (range(1, 12) as $n) {
            SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'tarefa', 'titulo' => "Pendência {$n}", 'origem' => 'matriz']);
        }
        $cheio = app(MontadorDeBriefing::class)->montar($u, CarbonImmutable::now())['texto'];
        $this->assertStringContainsString('📌 Pendências sem data (12)', $cheio);
        $this->assertStringContainsString('+4 no Meu Dia', $cheio);
        $this->assertStringNotContainsString('Pendência 9', $cheio);
        $this->assertLessThanOrEqual(MontadorDeBriefing::MAX_CARACTERES, mb_strlen($cheio));
    }

    public function test_resume_meu_dia_pelo_telegram_sem_ia(): void
    {
        $u = $this->cenario();
        $this->relogio('2026-10-16 15:00');
        app(TelegramCanal::class)->receber($this->mensagem(70, 'Matriz, resume meu dia'), $this->botNoTeste($u));
        $texto = collect($this->enviados())->last()['text'];
        $this->assertStringContainsString('📋 SEU DIA — hoje (sex, 16/10) (às 15:00)', $texto);
        $this->assertStringContainsString('10:00–11:00 Cliente RFID (Google · Financeiro Grupo) (já passou)', $texto);
        $this->assertStringContainsString('💡 Livre 15:30–18:00', $texto);
        $this->assertSame(0, CentralIaExecucao::count());
        $this->assertSame(SecretarioBriefing::PEDIDO, SecretarioBriefing::sole()->origem);
    }

    public function test_sem_perfil_a_frase_segue_a_central_e_outra_conta_nao_ve(): void
    {
        $this->fakeTelegram();
        $u = $this->cenario();
        $this->relogio('2026-10-16 07:00');
        $this->artisan('secretario:briefing');

        $semPerfil = User::factory()->create();
        $semPerfil->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        $this->actingAs($semPerfil)->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'conteudo' => 'resume meu dia'])->assertCreated();
        $this->assertSame(0, CentralIaMensagem::where('user_id', $semPerfil->id)->where('autor_nome', 'Secretário')->count());

        $outro = $this->admin();
        $outro->perfis()->syncWithoutDetaching([Perfil::where('slug', 'secretario')->value('id')]);
        $this->actingAs($outro)->getJson(route('secretario.itens'))->assertOk()->assertJsonPath('briefing', null);
    }

    public function test_texto_do_briefing_nao_vai_para_o_log_e_configuracoes_validam(): void
    {
        Log::spy();
        $u = $this->cenario();
        $this->relogio('2026-10-16 07:00');
        $this->artisan('secretario:briefing');
        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($m) => str_contains((string) $m, 'Condonal'))]);
        Log::shouldNotHaveReceived('warning', [\Mockery::any(), \Mockery::on(fn ($c) => str_contains(json_encode($c), 'Condonal'))]);

        $this->actingAs($u)->putJson(route('secretario.configuracoes.salvar'), ['auto_confirmar' => false, 'antecedencia_padrao' => 30, 'telegram' => true,
            'briefing' => true, 'briefing_hora' => '06:45', 'briefing_dias' => [6, 1, 2]])->assertOk();
        $p = SecretarioPreferencia::de($u);
        $this->assertSame(['06:45', [1, 2, 6]], [$p->briefing_hora, $p->briefing_dias]);
        $this->putJson(route('secretario.configuracoes.salvar'), ['auto_confirmar' => false, 'antecedencia_padrao' => 30, 'telegram' => true,
            'briefing_hora' => '25:00'])->assertUnprocessable();
        $this->get(route('secretario.configuracoes'))->assertOk()->assertSee('Briefing diário');
    }
}
