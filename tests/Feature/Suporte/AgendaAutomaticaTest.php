<?php

namespace Tests\Feature\Suporte;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use App\Services\Suporte\AgendaAutomatica;
use App\Services\Suporte\CanalWhatsApp;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A aba Suporte › Agenda automática (docs/specs/pedro-agenda-automatica, Fase 2; quickstart, cenário 8). O Gestor é
 * falsificado no formato de contracts/integracao-agenda.md; o que ele faz de verdade é provado pelo teste de contrato
 * de lá (AgendaAutomaticaPortasTest). Números fictícios, só pelo final.
 */
class AgendaAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    private const GESTOR = 'https://gestor.teste/api/integracao/matriz/agenda/';

    private User $felipe;

    /**
     * O que o Gestor falso responde, por porta (padrão do `Str::is` depois de `agenda/`). Um falso só, registrado no
     * setUp, lendo daqui: o `Http::fake` empilha, e o primeiro que casa ganha — trocar a resposta no meio do teste é
     * trocar este array.
     */
    private array $portas = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'America/Sao_Paulo'));
        config(['services.suporte_gestor' => ['url' => 'https://gestor.teste', 'token' => 'segredo-integracao'],
            'services.suporte_pedro.conta' => 'Felipe@Teste.local']);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            foreach ($this->portas as $padrao => $resposta) {
                if (Str::is(self::GESTOR.$padrao, $r->url())) {
                    return $resposta;
                }
            }

            return null;
        });
    }

    private function destinatario(array $mais = []): array
    {
        return $mais + ['id' => 3, 'funcionario_id' => 7, 'nome' => 'Cristiano', 'papel' => 'tecnico', 'ativo' => true, 'final' => '0001',
            'pendencia' => null, 'consentimento_em' => '2026-11-03T09:00:00-03:00', 'consentimento_por' => 'felipe@teste.local', 'parou_em' => null];
    }

    private function estado(array $mais = []): array
    {
        return $mais + [
            'configuracao' => ['modo' => 'desligada', 'manha' => '06:30', 'programacao' => '16:30', 'relatorio' => '17:00', 'silencio_inicio' => '20:00'],
            'envio_real' => false,
            'modelos' => array_map(fn ($n) => ['nome' => $n, 'conferido' => false, 'aprovado' => false, 'categoria' => null, 'variaveis_ok' => false],
                ['agenda_programacao', 'agenda_mudanca', 'agenda_relatorio', 'agenda_alerta']),
            'verificacoes' => [['tipo' => 'manha', 'dia' => '2026-11-03', 'modo' => 'desligada', 'concluida_em' => '2026-11-03T07:18:05-03:00', 'resumo' => null, 'erro' => null]],
            'destinatarios' => [],
        ];
    }

    private function aviso(array $mais = []): array
    {
        return $mais + ['id' => 11, 'nome' => 'Cristiano', 'papel' => 'tecnico', 'tipo' => 'programacao', 'dia_agenda' => '2026-11-04', 'modo' => 'ensaio',
            'situacao' => 'ensaio', 'pendencia' => null, 'canal' => null, 'modelo' => null, 'final' => '0001', 'tentativas' => 0, 'erro_codigo' => null,
            'erro' => null, 'texto' => "Boa tarde, Cristiano. Sua agenda de amanhã:\n08:00 às 12:00 — Cliente Fictício (nº 900)", 'criado_em' => '2026-11-03T10:00:00-03:00',
            'enviado_em' => null];
    }

    /** As sete portas no formato do contrato. */
    private function gestor(array $estado = [], array $avisos = []): void
    {
        $this->portas = [
            'estado' => Http::response($this->estado($estado)),
            'funcionarios' => Http::response(['funcionarios' => [
                ['id' => 7, 'nome' => 'Cristiano', 'is_tecnico' => true, 'final' => '0001', 'problema' => null],
                ['id' => 8, 'nome' => 'Técnico Sem Celular', 'is_tecnico' => true, 'final' => null, 'problema' => 'sem_numero'],
            ]]),
            'avisos*' => Http::response(['avisos' => $avisos, 'pagina' => 1, 'tem_mais' => false]),
            'destinatarios' => Http::response(['destinatario' => $this->destinatario()]),
            'destinatarios/*' => fn (Request $r) => Http::response(['destinatario' => $this->destinatario(['ativo' => $r['ativo'] ?? true])]),
            'configuracao' => fn (Request $r) => Http::response(['configuracao' => array_intersect_key($r->data(), array_flip(['modo', 'manha', 'programacao', 'relatorio', 'silencio_inicio'])),
                'envio_real' => false]),
            'previa' => Http::response(['avisos' => [$this->aviso()]]),
        ];
    }

    private function soLeitura(): User
    {
        $u = User::factory()->semPerfil()->create(['email' => 'leitor@teste.local']);
        $perfil = Perfil::create(['nome' => 'Leitor do suporte', 'slug' => 'leitor-suporte']);
        $perfil->permissoes()->attach(Permissao::where('recurso', 'suporte')->value('id'), ['ler' => true]);
        $u->perfis()->attach($perfil->id);

        return $u;
    }

    /** O suporte para editar, num perfil próprio: quem decide é a lista de administradores, e não o perfil de admin. */
    private function comEdicao(string $email): User
    {
        $u = User::factory()->semPerfil()->create(['email' => $email]);
        $perfil = Perfil::create(['nome' => 'Edita o suporte', 'slug' => 'edita-suporte']);
        $perfil->permissoes()->attach(Permissao::where('recurso', 'suporte')->value('id'), ['ler' => true, 'editar' => true]);
        $u->perfis()->attach($perfil->id);

        return $u;
    }

    private function postsAoGestor(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST')->count();
    }

    /** Quickstart, cenário 8, inteiro (tarefa 2.4.1). */
    public function test_cenario_8_administracao_no_matriz(): void
    {
        $this->gestor();

        // 1. A conta dona abre a aba; o Gestor devolve estado e funcionários.
        $this->actingAs($this->felipe)->get(route('suporte.agenda-automatica'))->assertOk()
            ->assertSee('Agenda automática')->assertSee('Técnico Sem Celular · técnico · sem número')
            ->assertSee('Cristiano · técnico · …0001')->assertSee('não conferido')->assertSee('Prévia agora')->assertSee('Salvar no Gestor');
        Http::assertSent(fn (Request $r) => $r->url() === self::GESTOR.'estado' && $r->hasHeader('Authorization', 'Bearer segredo-integracao'));

        // 2. Inclui o Cristiano como técnico com consentimento: vai com o e-mail da conta dona.
        $this->from(route('suporte.agenda-automatica'))->post(route('suporte.agenda-automatica.incluir'), ['funcionario_id' => 7, 'papel' => 'tecnico', 'consentimento' => '1'])
            ->assertRedirect(route('suporte.agenda-automatica'))->assertSessionHas('status', 'Cristiano está na lista de técnicos.');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::GESTOR.'destinatarios'
            && $r->data() === ['funcionario_id' => 7, 'papel' => 'tecnico', 'consentimento' => true, 'autor' => 'felipe@teste.local']);
        $this->gestor(['destinatarios' => [$this->destinatario()]]);
        $this->get(route('suporte.agenda-automatica'))->assertOk()->assertSeeInOrder(['Cristiano', 'Técnico', '…0001', 'recebe']);

        // 3. Outra conta com suporte,ler vê, sem os botões; e o POST dá 403 sem chegar ao Gestor.
        $leitor = $this->soLeitura();
        $antes = $this->postsAoGestor();
        $this->actingAs($leitor)->get(route('suporte.agenda-automatica'))->assertOk()
            ->assertSee('Cristiano')->assertSee('só os administradores do Pedro alteram')
            ->assertDontSee('Prévia agora')->assertDontSee('Salvar no Gestor')->assertDontSee('Registrar consentimento')->assertDontSee('Desativar');
        $this->post(route('suporte.agenda-automatica.incluir'), ['funcionario_id' => 7, 'papel' => 'tecnico'])->assertForbidden();
        $this->post(route('suporte.agenda-automatica.mudar', 3), ['acao' => 'desativar'])->assertForbidden();
        $this->post(route('suporte.agenda-automatica.configurar'), ['modo' => 'ligada'])->assertForbidden();
        $this->post(route('suporte.agenda-automatica.previa'), ['tipo' => 'programacao', 'dia' => 'amanha'])->assertForbidden();
        $this->assertSame($antes, $this->postsAoGestor(), 'quem só vê não chega ao Gestor');

        // 4. Gestor fora do ar: a aba diz que ele não respondeu, sem erro 500.
        $this->portas = ['*' => Http::failedConnection()];
        $this->actingAs($this->felipe)->get(route('suporte.agenda-automatica'))->assertOk()->assertSee(AgendaAutomatica::SEM_RESPOSTA);
    }

    /** Quem pode editar o suporte, mas não administra o Pedro, também só vê (Decision 16). */
    public function test_conta_que_edita_o_suporte_mas_nao_administra_o_pedro_recebe_403(): void
    {
        $this->gestor(['destinatarios' => [$this->destinatario()]]);
        $outro = User::factory()->create(['email' => 'outro@teste.local']);
        $this->assertTrue($outro->canPermissao('suporte', 'editar'));
        $this->actingAs($outro)->get(route('suporte.agenda-automatica'))->assertOk()->assertDontSee('Prévia agora')->assertDontSee('Incluir');
        $this->post(route('suporte.agenda-automatica.incluir'), ['funcionario_id' => 7, 'papel' => 'tecnico'])->assertForbidden();
        $this->post(route('suporte.agenda-automatica.mudar', 3), ['acao' => 'consentimento'])->assertForbidden();
        $this->post(route('suporte.agenda-automatica.configurar'), ['modo' => 'ensaio'])->assertForbidden();
        $this->post(route('suporte.agenda-automatica.previa'), ['tipo' => 'programacao', 'dia' => 'hoje'])->assertForbidden();
        $this->assertSame(0, $this->postsAoGestor());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/funcionarios'));
    }

    /** Felipe, 06/11/2026: o Alexandre opera o Pedro. Tudo vai ao Gestor com o e-mail dele, e ligar continua pedindo a confirmação. */
    public function test_administrador_da_lista_muda_em_nome_proprio(): void
    {
        config(['services.suporte_pedro.administradores' => ['Alexandre@Teste.local']]);
        $this->gestor(['destinatarios' => [$this->destinatario()]]);
        $this->actingAs($this->comEdicao('alexandre@teste.local'))->from(route('suporte.agenda-automatica'));

        $this->get(route('suporte.agenda-automatica'))->assertOk()->assertSee('Salvar no Gestor')->assertSee('Prévia agora')
            ->assertSee('Técnico Sem Celular · técnico · sem número')->assertDontSee('alteram');

        $autor = ['autor' => 'alexandre@teste.local'];
        $this->post(route('suporte.agenda-automatica.incluir'), ['funcionario_id' => 7, 'papel' => 'tecnico', 'consentimento' => '1'])->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $r) => $r->url() === self::GESTOR.'destinatarios' && $r->data() === ['funcionario_id' => 7, 'papel' => 'tecnico', 'consentimento' => true] + $autor);
        $this->post(route('suporte.agenda-automatica.mudar', 3), ['acao' => 'desativar'])->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $r) => $r->url() === self::GESTOR.'destinatarios/3' && $r->data() === ['ativo' => false] + $autor);

        $horarios = ['manha' => '06:30', 'programacao' => '16:30', 'relatorio' => '17:00', 'silencio_inicio' => '20:00'];
        $antes = $this->postsAoGestor();
        $this->post(route('suporte.agenda-automatica.configurar'), $horarios + ['modo' => 'ligada', 'modo_atual' => 'desligada'])->assertSessionHasErrors('confirmar_ligada');
        $this->assertSame($antes, $this->postsAoGestor(), 'ligar sem a confirmação não chega ao Gestor, para ninguém');
        $this->post(route('suporte.agenda-automatica.configurar'), $horarios + ['modo' => 'ensaio', 'modo_atual' => 'desligada'])->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $r) => $r->url() === self::GESTOR.'configuracao' && $r->data() === ['modo' => 'ensaio'] + $horarios + $autor);

        $this->post(route('suporte.agenda-automatica.previa'), ['tipo' => 'programacao', 'dia' => 'amanha'])->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $r) => $r->url() === self::GESTOR.'previa' && $r->data() === ['tipo' => 'programacao', 'dia' => '2026-11-04'] + $autor);
    }

    /** A lista não dispensa a permissão da rota: sem `suporte,editar`, quem está nela não chega ao Gestor. */
    public function test_estar_na_lista_sem_editar_o_suporte_nao_chega_ao_gestor(): void
    {
        config(['services.suporte_pedro.administradores' => ['leitor@teste.local']]);
        $this->gestor();
        $this->actingAs($this->soLeitura());
        $this->post(route('suporte.agenda-automatica.incluir'), ['funcionario_id' => 7, 'papel' => 'tecnico'])->assertForbidden();
        $this->post(route('suporte.agenda-automatica.configurar'), ['modo' => 'ligada'])->assertForbidden();
        $this->assertSame(0, $this->postsAoGestor());
    }

    /** A conta dona administra mesmo fora da lista: um `.env` errado não tranca o Pedro para ela. */
    public function test_conta_dona_administra_mesmo_fora_da_lista(): void
    {
        config(['services.suporte_pedro.administradores' => []]);
        $this->gestor();
        $this->actingAs($this->felipe)->get(route('suporte.agenda-automatica'))->assertOk()->assertSee('Salvar no Gestor');
        $this->post(route('suporte.agenda-automatica.previa'), ['tipo' => 'programacao', 'dia' => 'hoje'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->postsAoGestor());
    }

    public function test_sem_permissao_de_suporte_nao_abre(): void
    {
        $this->gestor();
        $this->actingAs(User::factory()->semPerfil()->create())->get(route('suporte.agenda-automatica'))->assertForbidden();
        Http::assertNothingSent();
    }

    /** Mudanças da conta dona: só o que muda, com o autor; ligar pede a confirmação marcada. */
    public function test_conta_dona_muda_destinatario_configuracao_e_roda_a_previa(): void
    {
        $this->gestor(['destinatarios' => [$this->destinatario()]]);
        $this->actingAs($this->felipe)->from(route('suporte.agenda-automatica'));

        $this->post(route('suporte.agenda-automatica.mudar', 3), ['acao' => 'desativar'])->assertSessionHas('status', 'Cristiano: desativado; o histórico continua.');
        Http::assertSent(fn (Request $r) => $r->url() === self::GESTOR.'destinatarios/3' && $r->data() === ['ativo' => false, 'autor' => 'felipe@teste.local']);
        $this->post(route('suporte.agenda-automatica.mudar', 3), ['acao' => 'consentimento']);
        Http::assertSent(fn (Request $r) => $r->url() === self::GESTOR.'destinatarios/3' && $r->data() === ['consentimento' => true, 'autor' => 'felipe@teste.local']);

        $horarios = ['manha' => '06:30', 'programacao' => '16:30', 'relatorio' => '17:00', 'silencio_inicio' => '20:00'];
        $antes = $this->postsAoGestor();
        $this->post(route('suporte.agenda-automatica.configurar'), $horarios + ['modo' => 'ligada', 'modo_atual' => 'desligada'])
            ->assertSessionHasErrors(['confirmar_ligada' => 'Para ligar, marque que as mensagens passam a sair de verdade.']);
        $this->assertSame($antes, $this->postsAoGestor(), 'ligar sem a confirmação não chega ao Gestor');
        $this->post(route('suporte.agenda-automatica.configurar'), $horarios + ['modo' => 'ensaio', 'modo_atual' => 'desligada'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Configuração salva no Gestor: modo ensaio, sem envio de verdade.');
        Http::assertSent(fn (Request $r) => $r->url() === self::GESTOR.'configuracao' && $r->data() === ['modo' => 'ensaio'] + $horarios + ['autor' => 'felipe@teste.local']);
        $this->post(route('suporte.agenda-automatica.configurar'), $horarios + ['modo' => 'ligada', 'modo_atual' => 'desligada', 'confirmar_ligada' => '1'])->assertSessionHasNoErrors();
        $this->post(route('suporte.agenda-automatica.configurar'), $horarios + ['modo' => 'ligada', 'modo_atual' => 'ligada'])->assertSessionHasNoErrors();
        $this->post(route('suporte.agenda-automatica.configurar'), ['modo' => 'ensaio', 'manha' => '6h'] + $horarios)->assertSessionHasErrors('manha');

        $this->post(route('suporte.agenda-automatica.previa'), ['tipo' => 'programacao', 'dia' => 'amanha'])
            ->assertRedirect(route('suporte.agenda-automatica', ['modo' => 'ensaio', 'tipo' => 'programacao', 'dia' => '2026-11-04']))
            ->assertSessionHas('status', 'Prévia feita: 1 aviso de ensaio para 04/11. Nada foi enviado.');
        Http::assertSent(fn (Request $r) => $r->url() === self::GESTOR.'previa' && $r->data() === ['tipo' => 'programacao', 'dia' => '2026-11-04', 'autor' => 'felipe@teste.local']);
    }

    /** A supervisão: o texto exato de cada aviso, filtros que seguem ao Gestor e só o final do número. */
    public function test_lista_de_envios_mostra_o_texto_exato_e_so_o_final(): void
    {
        $this->gestor(
            ['destinatarios' => [$this->destinatario(['numero' => '5527990000001'])]],
            [$this->aviso(['numero' => '5527990000001']), $this->aviso(['id' => 12, 'modo' => 'real', 'situacao' => 'falha', 'tentativas' => 3, 'erro_codigo' => '131026', 'erro' => 'Message undeliverable'])],
        );
        $this->actingAs($this->felipe)->get(route('suporte.agenda-automatica', ['modo' => 'ensaio', 'tipo' => 'programacao', 'dia' => '2026-11-04', 'situacao' => 'nao-existe']))
            ->assertOk()->assertSee('08:00 às 12:00 — Cliente Fictício (nº 900)')->assertSee('Erro 131026: Message undeliverable')->assertSee('3 tentativas')
            ->assertDontSee('5527990000001')->assertDontSee('99000-0001');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::GESTOR.'avisos')
            && $r->data() === ['dia' => '2026-11-04', 'tipo' => 'programacao', 'modo' => 'ensaio', 'pagina' => 1]);

        // Chave fora do contrato não passa do cliente: a tela nunca tem o número para mostrar.
        $agenda = app(AgendaAutomatica::class);
        $this->assertArrayNotHasKey('numero', $agenda->estado()['destinatarios'][0]);
        $this->assertArrayNotHasKey('numero', $agenda->avisos([], 1)['avisos'][0]);
    }

    /** Tarefa 2.1.4: as recusas do Gestor viram a frase da tela; porta inexistente e Gestor caído, "não respondeu". */
    public function test_recusas_do_gestor_viram_a_frase_da_tela(): void
    {
        $agenda = app(AgendaAutomatica::class);
        $casos = [
            [Http::response(['motivo' => 'credencial'], 401), CanalWhatsApp::motivoDoGestor('credencial')],
            [Http::response('', 404), AgendaAutomatica::SEM_RESPOSTA],
            [Http::response(['motivo' => 'destinatario_inexistente'], 404), 'O Gestor não achou esse destinatário (a lista mudou?).'],
            [Http::response(['motivo' => 'horarios_invalidos'], 422), AgendaAutomatica::motivo('horarios_invalidos')],
            [Http::response(['message' => 'x', 'errors' => ['autor' => ['O campo autor é obrigatório.']]], 422), 'O Gestor recusou os dados: O campo autor é obrigatório.'],
            [Http::response(['motivo' => 'tipo_indisponivel'], 422), 'A prévia do relatório ainda não existe no Gestor.'],
            [Http::response('erro', 500), AgendaAutomatica::SEM_RESPOSTA],
            [Http::failedConnection(), AgendaAutomatica::SEM_RESPOSTA],
            [Http::response('não é json', 200), AgendaAutomatica::SEM_RESPOSTA],
        ];
        foreach ($casos as $i => [$resposta, $frase]) {
            $this->portas = ['*' => $resposta];
            try {
                $agenda->configurar(['modo' => 'ensaio'], 'felipe@teste.local');
                $this->fail("caso {$i}: deveria ter recusado");
            } catch (ValidationException $e) {
                $this->assertSame($frase, $e->validator->errors()->first('gestor'), "caso {$i}");
            }
        }

        config(['services.suporte_gestor.token' => null]);
        $chamadas = Http::recorded()->count();
        try {
            $agenda->estado();
            $this->fail('sem a credencial não há o que pedir');
        } catch (ValidationException $e) {
            $this->assertSame('A integração com o Gestor não está configurada aqui.', $e->validator->errors()->first('gestor'));
        }
        $this->assertSame($chamadas, Http::recorded()->count(), 'nada sai sem a credencial');
    }

    /** Recusa numa mudança volta à aba com a frase, e não com erro 500. */
    public function test_recusa_na_mudanca_volta_a_aba_com_a_frase(): void
    {
        $this->gestor();
        $this->portas['previa'] = Http::response(['motivo' => 'tipo_indisponivel'], 422);
        $this->actingAs($this->felipe)->from(route('suporte.agenda-automatica'))
            ->post(route('suporte.agenda-automatica.previa'), ['tipo' => 'relatorio', 'dia' => 'amanha'])
            ->assertRedirect(route('suporte.agenda-automatica'))->assertSessionHasErrors(['gestor' => 'A prévia do relatório ainda não existe no Gestor.']);
    }

    /** Tarefa 2.1.3: o que o Gestor não mandou aparece como "sem dado", e não como "sem número" ou "sem pendência". */
    public function test_chave_ausente_na_resposta_vira_sem_dado(): void
    {
        $semFinal = $this->destinatario();
        unset($semFinal['final'], $semFinal['pendencia']);
        $estado = $this->estado(['destinatarios' => [$semFinal]]);
        unset($estado['modelos']);
        $this->portas = [
            'estado' => Http::response($estado),
            'funcionarios' => Http::response(['outra' => []]),
            'avisos*' => Http::response(['avisos' => [], 'pagina' => 1, 'tem_mais' => false]),
        ];

        $lido = app(AgendaAutomatica::class)->estado();
        $this->assertNull($lido['modelos']);
        $this->assertSame(['final', 'pendencia'], $lido['destinatarios'][0]['faltando']);
        $this->assertNull(app(AgendaAutomatica::class)->funcionarios());

        $this->actingAs($this->felipe)->get(route('suporte.agenda-automatica'))->assertOk()
            ->assertSeeInOrder(['Cristiano', 'sem dado', 'sem dado'])->assertDontSee('recebe</span>', false)
            ->assertSee('Sem dado: o Gestor não mandou a lista de funcionários.');
    }
}
