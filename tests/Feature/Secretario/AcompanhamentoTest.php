<?php

namespace Tests\Feature\Secretario;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\Notificacao;
use App\Models\Perfil;
use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioAcompanhamentoEnvio;
use App\Models\SecretarioEmail;
use App\Models\SecretarioEmailConta;
use App\Models\SecretarioGestorRetrato;
use App\Models\SecretarioGoogleAgenda;
use App\Models\SecretarioGoogleEvento;
use App\Models\SecretarioItem;
use App\Models\SecretarioPreferencia;
use App\Models\SecretarioReuniao;
use App\Models\SecretarioReuniaoProposta;
use App\Models\User;
use App\Services\Secretario\Acompanhamento\Envio;
use App\Services\Secretario\Acompanhamento\Motor;
use App\Services\Secretario\Acompanhamento\Regras;
use App\Services\Secretario\MontadorDeBriefing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Etapa 7 (docs/specs/secretario-acompanhamento). Quarta, 21/10/2026, 10:00. */
class AcompanhamentoTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]);
        $this->agora('2026-10-21 10:00');
        $this->fakeTelegram();
    }

    private function agora(string $quando): CarbonImmutable
    {
        Carbon::setTestNow(Carbon::parse($quando, 'America/Sao_Paulo'));
        CarbonImmutable::setTestNow($t = CarbonImmutable::parse($quando, 'America/Sao_Paulo'));

        return $t;
    }

    private function item(User $u, array $dados): SecretarioItem
    {
        return SecretarioItem::create($dados + ['user_id' => $u->id, 'tipo' => 'tarefa', 'origem' => 'matriz']);
    }

    private function email(User $u, string $assunto, string $recebido, int $uid): SecretarioEmail
    {
        $conta = SecretarioEmailConta::firstOrCreate(['user_id' => $u->id, 'rotulo' => 'VixCard'], ['host' => 'imap.vix.local', 'porta' => 993, 'seguranca' => 'ssl',
            'usuario' => 'f@vix.local', 'senha' => 'x', 'pasta_entrada' => 'INBOX', 'pasta_enviados' => 'Sent']);

        return SecretarioEmail::create(['user_id' => $u->id, 'conta_id' => $conta->id, 'pasta' => 'INBOX', 'uidvalidity' => 1, 'uid' => $uid,
            'de_nome' => 'Compras RFID', 'de_email' => 'compras@rfid.com.br', 'assunto' => $assunto, 'recebido_em' => $recebido, 'pede_retorno' => true]);
    }

    /** As sete situações da missão, menos a reunião do Google (desligada por padrão). */
    private function semear(User $u): void
    {
        $this->item($u, ['titulo' => 'Ligar para o Alexandre', 'quando' => '2026-10-21 07:00:00']);
        $this->item($u, ['titulo' => 'Tarefa vencida há pouco', 'quando' => '2026-10-21 09:30:00']); // menos de 2 h: ainda não
        $this->email($u, 'Dúvida sobre a instalação', '2026-10-19 10:00:00', 1);
        $this->email($u, 'Orçamento da demonstração', '2026-10-17 09:00:00', 2);
        $r = SecretarioReuniao::create(['user_id' => $u->id, 'titulo' => 'Demonstração RFID', 'origem' => 'upload', 'status' => SecretarioReuniao::PRONTA,
            'mime' => 'audio/wav', 'tamanho' => 1, 'sha256' => str_repeat('a', 64), 'analisada_em' => '2026-10-20 08:00:00']);
        SecretarioReuniaoProposta::create(['reuniao_id' => $r->id, 'user_id' => $u->id, 'categoria' => 'tarefa', 'texto' => 'Separar tags', 'classificacao' => 'compromisso',
            'evidencias' => [1], 'status' => 'pendente', 'prioridade' => 'normal']);
        $this->item($u, ['tipo' => 'compromisso', 'titulo' => 'Visita RFID', 'quando' => '2026-10-22 10:00:00']);
        $this->item($u, ['tipo' => 'compromisso', 'titulo' => 'Call Condonal', 'quando' => '2026-10-22 10:30:00']);
        $this->item($u, ['titulo' => 'Enviar proposta Medecinal', 'quando' => '2026-10-22 00:00:00', 'so_data' => true, 'origem' => 'reuniao']);
        SecretarioGestorRetrato::create(['user_id' => $u->id, 'maquina' => 'wsl', 'lido_em' => now(), 'dados' => ['blocos' => [
            'orcamentos_aprovacao' => ['quantidade' => 104, 'itens' => [['texto' => 'Orç. 151 — VD Cariacica', 'data' => '2026-10-01'], ['texto' => 'Orç. 300 — novo', 'data' => '2026-10-20']]],
        ]]]);
    }

    private function chaves(User $u): array
    {
        return SecretarioAcompanhamento::de($u)->where('situacao', 'ativo')->orderBy('regra')->pluck('regra')->all();
    }

    public function test_motor_acha_as_situacoes_uma_vez_e_resolve_sozinho(): void
    {
        $u = $this->dono(telegram: false);
        $this->semear($u);
        $outro = $this->dono(telegram: false, chat: 999);
        $this->semear($outro);
        $motor = app(Motor::class);

        $motor->rodar($u, CarbonImmutable::now());
        $motor->rodar($u, CarbonImmutable::now());
        $esperado = ['cliente_sem_resposta', 'conflito', 'entrega_proxima', 'proposta_parada', 'proposta_parada', 'revisao_de_reuniao', 'tarefa_vencida'];
        $this->assertSame($esperado, $this->chaves($u), 'Duas rodadas, uma linha por situação');
        $this->assertSame(7, SecretarioAcompanhamento::count(), 'A outra conta só depois de rodar a dela');

        $textos = SecretarioAcompanhamento::de($u)->pluck('texto')->implode("\n");
        foreach (['Tarefa vencida desde hoje (qua, 21/10) às 07:00: Ligar para o Alexandre', 'Sem resposta há 2 dias: Compras RFID — Dúvida sobre a instalação',
            'Proposta sem retorno há 4 dias: Compras RFID — Orçamento da demonstração', 'Gestor: orçamento aguardando aprovação há mais de 7 dias — o mais antigo lido é Orç. 151 — VD Cariacica, de 01/10 (1 dos lidos nessa situação)',
            'Reunião “Demonstração RFID”', '1 tarefa(s) esperando sua revisão', 'Conflito amanhã (qui, 22/10) às 10:30: “Visita RFID” × “Call Condonal”',
            'Entrega amanhã (qui, 22/10): Enviar proposta Medecinal'] as $t) {
            $this->assertStringContainsString($t, $textos);
        }
        $this->assertStringNotContainsString('Tarefa vencida há pouco', $textos);

        // Concluiu a tarefa: resolvido, sem ninguém tocar no acompanhamento.
        SecretarioItem::where('titulo', 'Ligar para o Alexandre')->update(['status' => SecretarioItem::CONCLUIDO]);
        $motor->rodar($u, CarbonImmutable::now());
        $this->assertSame('resolvido', SecretarioAcompanhamento::where('regra', 'tarefa_vencida')->sole()->situacao);

        // Gestor velho: não lido não é resolvido.
        SecretarioGestorRetrato::query()->update(['lido_em' => now()->subHours(5)]);
        $motor->rodar($u, CarbonImmutable::now());
        $this->assertSame('ativo', SecretarioAcompanhamento::where('chave', 'proposta_parada:gestor')->sole()->situacao);

        // Regra desligada encerra os seus.
        $c = Regras::padroes();
        $c['regras']['conflito']['ligada'] = false;
        SecretarioPreferencia::updateOrCreate(['user_id' => $u->id], ['acompanhamento' => $c]);
        $motor->rodar($u, CarbonImmutable::now());
        $this->assertNotContains('conflito', $this->chaves($u));

        // Situação que volta depois de resolvida é nova.
        SecretarioItem::where('titulo', 'Ligar para o Alexandre')->update(['status' => SecretarioItem::ABERTO]);
        $motor->rodar($u, CarbonImmutable::now());
        $this->assertSame(2, SecretarioAcompanhamento::where('regra', 'tarefa_vencida')->count());
        $this->assertSame(1, SecretarioAcompanhamento::where('regra', 'tarefa_vencida')->where('situacao', 'ativo')->count());
    }

    public function test_reuniao_do_google_so_quando_ligada_e_sem_duplicar_o_item(): void
    {
        $u = $this->dono(telegram: false);
        $agenda = SecretarioGoogleAgenda::create(['user_id' => $u->id, 'google_id' => 'vix', 'nome' => 'VixCard', 'primaria' => true, 'incluida' => true]);
        SecretarioGoogleEvento::create(['user_id' => $u->id, 'agenda_id' => $agenda->id, 'google_id' => 'e1', 'titulo' => 'Call com a Condonal',
            'inicio' => '2026-10-21 10:20:00', 'fim' => '2026-10-21 11:00:00', 'link' => 'https://www.google.com/calendar/event?eid=e1']);
        SecretarioGoogleEvento::create(['user_id' => $u->id, 'agenda_id' => $agenda->id, 'google_id' => 'e2', 'titulo' => 'Visita RFID',
            'inicio' => '2026-10-21 10:15:00', 'fim' => '2026-10-21 11:00:00']);
        $this->item($u, ['tipo' => 'compromisso', 'titulo' => 'Visita RFID', 'quando' => '2026-10-21 10:15:00']);
        $motor = app(Motor::class);

        $motor->rodar($u, CarbonImmutable::now());
        $this->assertSame(0, SecretarioAcompanhamento::where('regra', 'reuniao_proxima')->count(), 'Desligada por padrão');

        $c = Regras::padroes();
        $c['regras']['reuniao_proxima']['ligada'] = true;
        SecretarioPreferencia::updateOrCreate(['user_id' => $u->id], ['acompanhamento' => $c]);
        $motor->rodar($u, CarbonImmutable::now());
        $a = SecretarioAcompanhamento::where('regra', 'reuniao_proxima')->sole();
        $this->assertSame('Começa às 10:20 (em 20 min): Call com a Condonal', $a->texto);
        $this->assertSame('alta', $a->prioridade);
    }

    public function test_politica_alta_na_hora_normal_no_intervalo_silencio_teto_e_repeticao(): void
    {
        $u = $this->dono();
        $motor = app(Motor::class);
        $envio = app(Envio::class);
        $rodada = function () use ($u, $motor, $envio) {
            $motor->rodar($u, CarbonImmutable::now());

            return $envio->preparar($u, CarbonImmutable::now());
        };
        $this->email($u, 'Dúvida sobre a instalação', '2026-10-19 10:00:00', 1);

        // Normal, sem resumo anterior: sai.
        $this->assertSame(1, $rodada());
        $this->assertSame(1, Notificacao::where('destinatario_id', $u->id)->where('tipo', 'acompanhamento')->count());
        $this->assertSame(['sino', 'telegram'], SecretarioAcompanhamentoEnvio::orderBy('id')->pluck('canal')->all());

        // Situação nova normal 1 h depois: espera o intervalo de 3 h.
        $this->agora('2026-10-21 11:00');
        $this->item($u, ['titulo' => 'Ligar para o Alexandre', 'quando' => '2026-10-21 08:00:00']);
        $this->assertSame(0, $rodada());
        // Alta (conflito) fura o intervalo e leva a normal junto; o e-mail já avisado não repete.
        $this->item($u, ['tipo' => 'compromisso', 'titulo' => 'Visita', 'quando' => '2026-10-22 10:00:00']);
        $this->item($u, ['tipo' => 'compromisso', 'titulo' => 'Call', 'quando' => '2026-10-22 10:30:00']);
        $this->assertSame(2, $rodada());
        $this->assertTrue(Notificacao::where('tipo', 'acompanhamento')->latest('id')->value('sonora'), 'Alta toca');

        // Silêncio (20:00–07:00) e fim de semana: nada sai.
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'compromisso', 'titulo' => 'Outra', 'quando' => '2026-10-22 15:00:00', 'origem' => 'matriz']);
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'compromisso', 'titulo' => 'Mais uma', 'quando' => '2026-10-22 15:30:00', 'origem' => 'matriz']);
        $this->agora('2026-10-21 22:00');
        $this->assertSame(0, $rodada());
        $this->agora('2026-10-22 07:05');
        $this->assertSame(1, $rodada(), 'Sai no fim do silêncio');

        // Repetição: depois de 24 h volta, até 3 vezes.
        $email = SecretarioAcompanhamento::where('regra', 'cliente_sem_resposta')->sole();
        $this->assertSame(1, $email->avisos);
        foreach (['2026-10-22 10:01', '2026-10-23 10:02', '2026-10-24 10:03', '2026-10-26 10:04'] as $quando) {
            $this->agora($quando);
            $rodada();
        }
        $this->assertSame(3, $email->fresh()->avisos, 'Limite de repetições');

        // Teto do dia.
        $c = Regras::padroes();
        $c['teto_dia'] = 1;
        SecretarioPreferencia::updateOrCreate(['user_id' => $u->id], ['acompanhamento' => $c]);
        $this->agora('2026-10-26 18:00');
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'compromisso', 'titulo' => 'X', 'quando' => '2026-10-27 15:00:00', 'origem' => 'matriz']);
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'compromisso', 'titulo' => 'Y', 'quando' => '2026-10-27 15:30:00', 'origem' => 'matriz']);
        $this->assertSame(0, $rodada(), 'Teto alcançado');

        // Baixa nunca sai.
        $c['teto_dia'] = 4;
        $c['regras']['conflito']['prioridade'] = 'baixa';
        SecretarioPreferencia::updateOrCreate(['user_id' => $u->id], ['acompanhamento' => $c]);
        $this->agora('2026-10-27 08:00');
        $motor->rodar($u, CarbonImmutable::now());
        $this->assertTrue(SecretarioAcompanhamento::where('regra', 'conflito')->where('situacao', 'ativo')->get()->every(fn ($a) => $a->prioridade === 'baixa'));
        $this->assertFalse(app(\App\Services\Secretario\Acompanhamento\Politica::class)->resumo($u, CarbonImmutable::now())->contains('regra', 'conflito'));
    }

    public function test_telegram_resumo_com_botao_retentativa_e_toque_unico(): void
    {
        $u = $this->dono();
        $this->email($u, 'Dúvida sobre a instalação', '2026-10-19 10:00:00', 1);
        $this->email($u, 'Contrato', '2026-10-19 11:00:00', 2);
        app(Motor::class)->rodar($u, CarbonImmutable::now());
        app(Envio::class)->preparar($u, CarbonImmutable::now());

        // Um falso novo: o Http::fake empilha, e o primeiro que casa vence.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.telegram.org/*/sendMessage' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 5]], 429)]);
        app(Envio::class)->entregarPendentes();
        $tg = SecretarioAcompanhamentoEnvio::where('canal', 'telegram')->sole();
        $this->assertSame('pendente', $tg->status);
        $this->assertNotNull($tg->proxima_tentativa_em);

        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->fakeTelegram();
        $this->agora('2026-10-21 10:02');
        $this->assertSame(1, app(Envio::class)->entregarPendentes());
        $this->assertSame(0, app(Envio::class)->entregarPendentes(), 'Nunca duas vezes');
        $msg = $this->enviados()[0];
        $this->assertStringContainsString('🔔 Para acompanhar (2)', $msg['text']);
        $this->assertStringContainsString('🟡 Sem resposta há 2 dias: Compras RFID — Dúvida sobre a instalação', $msg['text']);
        $this->assertSame('s:ac:'.$tg->id, $msg['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        // Toque: adia os do resumo; o mesmo toque de novo não faz nada a mais.
        $toque = $this->toque('cb-1', 's:ac:'.$tg->id);
        app(\App\Services\CentralIa\TelegramCanal::class)->receber($toque, $this->botNoTeste($u));
        $this->assertSame(2, SecretarioAcompanhamento::whereNotNull('adiado_ate')->count());
        SecretarioAcompanhamento::query()->update(['adiado_ate' => null]);
        app(\App\Services\CentralIa\TelegramCanal::class)->receber($toque, $this->botNoTeste($u));
        $this->assertSame(0, SecretarioAcompanhamento::whereNotNull('adiado_ate')->count(), 'Toque repetido');

        // Toque de outra conta no envio dela: recusado.
        $outro = $this->dono(chat: 999);
        app(\App\Services\CentralIa\TelegramCanal::class)->receber($this->toque('cb-2', 's:ac:'.$tg->id, 999), $this->botNoTeste($outro));
        $this->assertSame(0, SecretarioAcompanhamento::whereNotNull('adiado_ate')->count());
    }

    public function test_tela_regras_chat_meu_dia_briefing_e_isolamento(): void
    {
        $u = $this->dono(telegram: false);
        $this->semear($u);
        app(Motor::class)->rodar($u, CarbonImmutable::now());
        $this->actingAs($u);

        $this->get(route('secretario.acompanhamentos'))->assertOk()
            // A Central (desenho de 17/10/2026): uma tabela só, vencidos e alta primeiro.
            ->assertSeeInOrder(['Tarefa vencida desde', 'Conflito amanhã'])
            ->assertDontSee('Salvar regras'); // as regras moram em Configurações desde a Etapa 8
        $this->get(route('secretario.meu-dia'))->assertOk()->assertSee('Acompanhamentos')->assertSee('Conflito amanhã');

        // Ações.
        $tarefa = SecretarioAcompanhamento::where('regra', 'tarefa_vencida')->sole();
        $this->post(route('secretario.acompanhamentos.resolver', $tarefa->id))->assertRedirect();
        $this->assertSame('dispensado', $tarefa->fresh()->situacao);
        app(Motor::class)->rodar($u, CarbonImmutable::now());
        $this->assertSame('dispensado', $tarefa->fresh()->situacao, 'Resolvido não volta enquanto vale');
        $conflito = SecretarioAcompanhamento::where('regra', 'conflito')->sole();
        $this->post(route('secretario.acompanhamentos.adiar', $conflito->id))->assertRedirect();
        $this->assertSame('2026-10-22 10:00', $conflito->fresh()->adiado_ate->format('Y-m-d H:i'));

        // Regras: inválido recusado; válido salvo.
        $form = Regras::padroes();
        $form['regras']['tarefa_vencida']['valor'] = 500;
        $this->from(route('secretario.acompanhamentos'))->put(route('secretario.acompanhamentos.regras'), $form)->assertSessionHasErrors('regras.tarefa_vencida.valor');
        $form['regras']['tarefa_vencida']['valor'] = 6;
        $form['regras']['reuniao_proxima']['ligada'] = '0';
        $form['fim_de_semana'] = '0';
        $this->put(route('secretario.acompanhamentos.regras'), $form)->assertRedirect(route('secretario.configuracoes').'#avisos');
        $c = Regras::de($u);
        $this->assertSame([6, false, false], [$c['regras']['tarefa_vencida']['valor'], $c['regras']['reuniao_proxima']['ligada'], $c['fim_de_semana']]);

        // Chat, sem IA.
        $this->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'conteudo' => 'o que preciso acompanhar?'])->assertCreated();
        $r = CentralIaMensagem::where('autor_nome', 'Secretário')->latest('id')->value('conteudo');
        $this->assertStringContainsString('🔔 Para acompanhar (6):', $r);
        $this->assertStringContainsString('🔴 Conflito amanhã', $r);
        $this->assertSame(0, CentralIaExecucao::count());

        // Briefing: as comerciais destacadas, sem repetir o e-mail.
        $texto = app(MontadorDeBriefing::class)->montar($u, CarbonImmutable::now())['texto'];
        $this->assertStringContainsString('🔔 Acompanhar (4)', $texto);
        $this->assertSame(1, substr_count($texto, 'Dúvida sobre a instalação'));

        // Outra conta, admin incluído: 404 nas ações e tela vazia.
        $admin = $this->admin();
        $admin->perfis()->syncWithoutDetaching([Perfil::where('slug', 'secretario')->value('id')]);
        $this->actingAs($admin)->post(route('secretario.acompanhamentos.resolver', $conflito->id))->assertNotFound();
        $this->post(route('secretario.acompanhamentos.adiar', $conflito->id))->assertNotFound();
        $this->get(route('secretario.acompanhamentos'))->assertOk()->assertSee('Nada pedindo acompanhamento agora.')->assertDontSee('Visita RFID');
    }

    /** Desde 17/10/2026: o Secretário vale para os perfis de pessoas, e cada conta tem o seu. */
    public function test_secretario_para_os_perfis_de_pessoas_mas_nao_revenda_nem_exibicao(): void
    {
        (new \Database\Seeders\PerfilPermissaoSeeder)->run(); // Membro e Exibição nascem no seeder
        $com = fn (string $slug) => tap(User::factory()->create(), fn ($u) => $u->perfis()->sync([Perfil::where('slug', $slug)->value('id')]));
        $membro = $com('membro');
        $admin = $com('admin');
        $exibicao = $com('exibicao');
        $revenda = $com('revenda');

        $contas = \App\Services\Secretario\Contas::comSecretario()->pluck('id')->all();
        $this->assertContains($membro->id, $contas);
        $this->assertContains($admin->id, $contas);
        $this->assertNotContains($exibicao->id, $contas);
        $this->assertNotContains($revenda->id, $contas);

        // Cada um vê só o seu, administrador incluído.
        $this->item($membro, ['titulo' => 'Tarefa do membro', 'quando' => '2026-10-21 07:00:00']);
        app(Motor::class)->rodar($membro, CarbonImmutable::now());
        $this->actingAs($membro)->get(route('secretario.acompanhamentos'))->assertOk()->assertSee('Tarefa do membro');
        $this->actingAs($admin)->get(route('secretario.acompanhamentos'))->assertOk()->assertDontSee('Tarefa do membro');
        $this->actingAs($exibicao)->get(route('secretario.meu-dia'))->assertForbidden();
    }
}
