<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\Notificacao;
use App\Models\SuporteConversa;
use App\Models\User;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Transferencia;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as Pedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Quando o Pedro diz ao cliente que passou a um colega, a conversa não morre ali (Felipe, 07/11/2026: "o Pedro tem
 * que se comunicar com o Alexandre para cobrar a resposta ao cliente [...] até ele perceber que ele deu seguimento").
 * Sem nome, a conversa fica com o responsável pelos chamados; o cliente sem resposta é cobrado no sino, no Telegram e
 * no WhatsApp do responsável, pela conversa dele com o Pedro, até alguém responder, encerrar ou devolver ao Pedro
 * (docs/specs/suporte-pedro-whatsapp/encerramento.md, FR-F1, FR-F3 e FR-F7). A IA e o Gestor são falsos; os nomes e
 * números, fictícios.
 */
class PedroCobraOResponsavelTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENTE = '5527988887777';

    private const OUTRO_CLIENTE = '5527977776666';

    /** O número da linha do responsável na lista da casa, com a conversa dele com o Pedro (nº 70 no Gestor). */
    private const DO_RESPONSAVEL = '552731093265';

    private User $felipe;

    private User $alexandre;

    private User $rossini;

    /** @var list<array{acao: string, conversa: int, texto: ?string, humano: ?bool}> */
    private array $aoGestor = [];

    /** @var list<string> */
    private array $noTelegram = [];

    /** @var array<int, string> a conversa do Gestor que recusa o envio, e o motivo */
    private array $recusa = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Uma segunda-feira, no horário de atendimento.
        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '1179318548601637', 'token' => null, 'app_secret' => 'segredo-app', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_gestor' => ['url' => 'https://gestor.teste', 'token' => 'segredo-integracao'],
            'services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsavel_chamados' => 'alexandre@teste.local',
            'services.telegram' => ['bot_token' => 'bot-teste', 'chat_id_alertas' => '-100']]);
        Http::fake(function (Pedido $r) {
            if (str_contains($r->url(), 'api.telegram.org')) {
                $this->noTelegram[] = (string) $r['text'];

                return Http::response(['ok' => true]);
            }
            if (! preg_match('#/conversas/(\d+)/(\w+)$#', $r->url(), $m)) {
                return Http::response([], 404);
            }
            $this->aoGestor[] = ['acao' => $m[2], 'conversa' => (int) $m[1], 'texto' => $r['texto'] ?? null, 'humano' => $r['humano'] ?? null];
            if ($m[2] === 'enviar' && isset($this->recusa[(int) $m[1]])) {
                return Http::response(['motivo' => $this->recusa[(int) $m[1]]], 409);
            }

            return Http::response($m[2] === 'enviar' ? ['wamid' => 'wamid.G'.count($this->aoGestor)] : ['ok' => true]);
        });
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['name' => 'Felipe Gat', 'email' => 'felipe@teste.local']);
        $this->alexandre = User::factory()->create(['name' => 'Alexandre Blank', 'email' => 'alexandre@teste.local']);
        $this->rossini = User::factory()->create(['name' => 'Rossini Souza', 'email' => 'rossini@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, '+55 27 3109-3265 | Alexandre | suporte | alexandre@teste.local');
    }

    private function repasse(string $telefone, string $texto, string $id, int $conversaNoGestor = 55, string $nome = 'Otávio'): void
    {
        $valor = ['metadata' => ['phone_number_id' => '1179318548601637'], 'contacts' => [['wa_id' => $telefone, 'profile' => ['name' => $nome]]],
            'messages' => [['from' => $telefone, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);
        $estado = [$telefone => ['conversa_id' => $conversaNoGestor, 'setor' => 'aguardando_menu', 'com_pedro' => true, 'suporte_no_matriz' => false]];
        $this->call('POST', route('suporte.webhook.gestor'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer segredo-integracao',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo-app'), 'HTTP_X_GESTOR_ESTADO' => json_encode($estado)], $corpo)->assertOk();
    }

    private function responde(array $bloco): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->assertOk()->json();
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl', 'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    /** O cliente pede uma pessoa e o Pedro passa a conversa. */
    private function passaAoColega(string $telefone = self::CLIENTE, ?string $atendente = null, int $conversaNoGestor = 55, string $nome = 'Otávio'): SuporteConversa
    {
        $this->repasse($telefone, 'Preciso que alguém veja o alarme da sala', 'wamid.'.$telefone.'.'.now()->timestamp, $conversaNoGestor, $nome);
        $this->responde(array_filter(['resposta' => 'Claro.', 'acao' => 'transferir', 'resumo' => 'Alarme da sala disparando à noite', 'atendente' => $atendente]));

        return SuporteConversa::where('telefone', $telefone)->sole();
    }

    /** O responsável escreveu ao Pedro há pouco e já teve resposta: a janela do WhatsApp dele está aberta. */
    private function responsavelFalouComOPedro(string $quando = '-1 hour'): SuporteConversa
    {
        $c = SuporteConversa::create(['telefone' => self::DO_RESPONSAVEL, 'nome_perfil' => 'Alexandre', 'gestor_conversa_id' => 70, 'gestor_setor' => 'aguardando_menu',
            'situacao' => 'pedro', 'ultima_entrada_em' => now()->modify($quando)]);
        $c->mensagens()->create(['wamid' => 'wamid.r1', 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'Bom dia, Pedro']);
        $c->mensagens()->create(['wamid' => 'wamid.r2', 'direcao' => 'pedro', 'tipo' => 'text', 'texto' => 'Bom dia, Alexandre!', 'status' => 'enviada']);

        return $c;
    }

    /** @return list<array{acao: string, conversa: int, texto: ?string, humano: ?bool}> o que foi enviado na conversa do responsável */
    private function aoResponsavel(): array
    {
        return array_values(array_filter($this->aoGestor, fn ($p) => $p['acao'] === 'enviar' && $p['conversa'] === 70));
    }

    private function ultimaDoPedro(SuporteConversa $c): string
    {
        return (string) $c->mensagens()->reorder()->where('direcao', 'pedro')->orderByDesc('id')->value('texto');
    }

    private function lembrar(): int
    {
        return app(Transferencia::class)->lembrarFila();
    }

    // ---- Com quem a conversa fica

    public function test_sem_nome_a_conversa_fica_com_o_responsavel_e_o_cliente_ouve_o_mesmo_de_antes(): void
    {
        $c = $this->passaAoColega();

        $this->assertSame(['humano', $this->alexandre->id], [$c->situacao, $c->humano_id]);
        $this->assertStringContainsString('Já chamei um colega da equipe, que continua com você por aqui.', $this->ultimaDoPedro($c));
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->alexandre->id)->where('titulo', 'like', 'Na fila do WhatsApp: Otávio%')->count());
        $this->assertSame(0, Notificacao::whereIn('destinatario_id', [$this->felipe->id, $this->rossini->id])->count(), 'só o responsável, e não a equipe toda');
        $this->assertStringContainsString('Para Alexandre Blank', $this->noTelegram[0]);
        $this->assertSame([], $this->aoResponsavel(), 'o WhatsApp é a cobrança, e não o aviso da passagem');
    }

    public function test_nome_que_nao_e_da_equipe_tambem_fica_com_o_responsavel(): void
    {
        $c = $this->passaAoColega(atendente: 'Marta');

        $this->assertSame($this->alexandre->id, $c->humano_id);
        $this->assertStringContainsString('Já avisei a equipe que você deseja falar com Marta', $this->ultimaDoPedro($c));
    }

    public function test_nome_da_equipe_continua_indo_para_essa_pessoa_sem_o_whatsapp_do_responsavel(): void
    {
        $this->responsavelFalouComOPedro();
        $c = $this->passaAoColega(atendente: 'Rossini');
        $this->assertSame($this->rossini->id, $c->humano_id);
        $this->assertStringContainsString('Já chamei o Rossini', $this->ultimaDoPedro($c));

        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(1, $this->lembrar());
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->rossini->id)->where('titulo', 'like', 'Ainda sem resposta%')->count());
        $this->assertSame(0, Notificacao::where('destinatario_id', $this->alexandre->id)->count());
        $this->assertStringContainsString('Para Rossini Souza', end($this->noTelegram));
        $this->assertSame([], $this->aoResponsavel());
    }

    public function test_responsavel_desativado_ou_sem_permissao_deixa_a_conversa_na_fila_geral(): void
    {
        $this->alexandre->update(['ativo' => false]);
        $c = $this->passaAoColega();
        $this->assertNull($c->humano_id);
        $this->assertSame(2, Notificacao::where('titulo', 'like', 'Na fila do WhatsApp%')->count(), 'Felipe e Rossini, a equipe que sobrou');

        $this->alexandre->update(['ativo' => true]);
        $semPermissao = User::factory()->semPerfil()->create(['email' => 'sem-suporte@teste.local']);
        config(['services.suporte_pedro.responsavel_chamados' => $semPermissao->email]);
        $this->assertNull(app(Transferencia::class)->responsavel());
    }

    // ---- A cobrança

    public function test_o_lembrete_chega_agrupado_ao_whatsapp_do_responsavel(): void
    {
        $this->responsavelFalouComOPedro();
        $primeiro = $this->passaAoColega();
        Carbon::setTestNow(now()->addMinutes(2));
        $segundo = $this->passaAoColega(self::OUTRO_CLIENTE, conversaNoGestor: 56, nome: 'Beatriz');
        $this->assertSame(0, $this->lembrar(), 'antes de 15 min, não');

        Carbon::setTestNow(Carbon::parse('2026-11-09 10:16', 'America/Sao_Paulo'));
        $this->assertSame(2, $this->lembrar(), 'venceu o do Otávio, e a Beatriz vai junto');

        $enviados = $this->aoResponsavel();
        $this->assertCount(1, $enviados, 'uma mensagem por rodada, e não uma por cliente');
        $this->assertFalse($enviados[0]['humano'], 'sai como do Pedro: não conta como primeira resposta da equipe no Gestor');
        $this->assertSame(
            sprintf(Transferencia::ESPERANDO, 'Otávio', 'Alarme da sala disparando à noite', '16 min', '10:00 de 10/11', route('suporte.conversas', $primeiro->id))
            ."\n\n".sprintf(Transferencia::ESPERANDO, 'Beatriz', 'Alarme da sala disparando à noite', '14 min', '10:02 de 10/11', route('suporte.conversas', $segundo->id)),
            $enviados[0]['texto']);
        $this->assertSame('pedro', SuporteConversa::where('telefone', self::DO_RESPONSAVEL)->sole()->mensagens()->reorder()->orderByDesc('id')->value('direcao'));
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->alexandre->id)->where('rota', route('suporte.conversas', $primeiro->id))->count(), 'no sino, um por conversa');
        $this->assertStringContainsString('Beatriz', end($this->noTelegram));
        $this->assertStringContainsString('Otávio', end($this->noTelegram), 'no Telegram também, uma mensagem só');

        // No mesmo ritmo do sino: a cada 15 min na primeira hora, depois de hora em hora.
        Carbon::setTestNow(now()->addMinutes(10));
        $this->assertSame(0, $this->lembrar());
        Carbon::setTestNow(now()->addMinutes(6));
        $this->assertSame(2, $this->lembrar());
        $this->assertCount(2, $this->aoResponsavel());
        Carbon::setTestNow(Carbon::parse('2026-11-09 11:20', 'America/Sao_Paulo'));
        $this->assertSame(0, $this->lembrar(), 'passada a primeira hora, de hora em hora');
        Carbon::setTestNow(Carbon::parse('2026-11-09 11:48', 'America/Sao_Paulo'));
        $this->assertSame(2, $this->lembrar());
    }

    public function test_sem_a_janela_do_responsavel_o_whatsapp_nao_sai_e_o_resto_continua(): void
    {
        $responsavel = $this->responsavelFalouComOPedro('-25 hours');
        $this->passaAoColega();
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(1, $this->lembrar());
        $this->assertSame([], $this->aoResponsavel(), 'ele não escreveu ao Pedro nas últimas 24 h');
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->alexandre->id)->where('titulo', 'like', 'Ainda sem resposta%')->count());
        $this->assertCount(2, $this->noTelegram);

        // Com o número dele ligado a outro usuário do Gestor, também não: quem decide é o e-mail, e não o número.
        $responsavel->update(['ultima_entrada_em' => now()->subMinutes(5)]);
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, '+55 27 3109-3265 | Alexandre | suporte | outra-pessoa@teste.local');
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(1, $this->lembrar());
        $this->assertSame([], $this->aoResponsavel());
    }

    public function test_o_gestor_recusar_fica_registrado_e_a_cobranca_continua(): void
    {
        $this->responsavelFalouComOPedro();
        $this->recusa[70] = 'conversa_inexistente';
        $c = $this->passaAoColega();
        Carbon::setTestNow(now()->addMinutes(16));

        $this->assertSame(1, $this->lembrar());
        $falha = SuporteConversa::where('telefone', self::DO_RESPONSAVEL)->sole()->mensagens()->reorder()->orderByDesc('id')->first();
        $this->assertSame(['pedro', 'falhou'], [$falha->direcao, $falha->status]);
        $this->assertNotNull($c->fresh()->fila_desde, 'continua esperando');

        unset($this->recusa[70]);
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(1, $this->lembrar());
        $this->assertCount(2, $this->aoResponsavel());
    }

    public function test_espera_o_pedro_responder_o_que_o_responsavel_mandou(): void
    {
        $responsavel = $this->responsavelFalouComOPedro();
        $responsavel->mensagens()->create(['wamid' => 'wamid.r3', 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'Qual a agenda de hoje?']);
        $this->passaAoColega();
        Carbon::setTestNow(now()->addMinutes(16));

        $this->assertSame(1, $this->lembrar());
        $this->assertSame([], $this->aoResponsavel(), 'as duas mensagens se cruzariam');
    }

    public function test_o_cliente_que_escreve_de_novo_depois_da_resposta_volta_a_ser_cobrado(): void
    {
        $this->responsavelFalouComOPedro();
        $c = $this->passaAoColega();
        Carbon::setTestNow(now()->addMinutes(5));
        $this->actingAs($this->alexandre)->post(route('suporte.responder', $c->id), ['texto' => 'Oi, Otávio, sou o Alexandre. Vou olhar.'])->assertRedirect();
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(0, $this->lembrar(), 'respondido: para');
        $this->assertNull($c->fresh()->fila_desde);

        // Antes, a primeira resposta zerava a cobrança de vez.
        Carbon::setTestNow(Carbon::parse('2026-11-09 11:00', 'America/Sao_Paulo'));
        $this->repasse(self::CLIENTE, 'Continua disparando', 'wamid.de-novo');
        $this->assertSame('Continua disparando', $c->mensagens()->reorder()->orderByDesc('id')->value('texto'), 'com a pessoa, o Pedro não responde');
        Carbon::setTestNow(now()->addMinutes(14));
        $this->assertSame(0, $this->lembrar());
        $this->assertNotNull($c->fresh()->fila_desde, 'já conta como esperando, para a tela');
        Carbon::setTestNow(now()->addMinutes(1));
        $this->assertSame(1, $this->lembrar());
        $this->assertStringContainsString('Cliente esperando resposta: Otávio — escreveu de novo: Continua disparando. Esperando há 15 min.', end($this->aoGestor)['texto']);
    }

    public function test_resposta_que_nao_saiu_nao_conta_como_resposta(): void
    {
        $c = $this->passaAoColega();
        $this->recusa[55] = 'janela_fechada';
        $this->actingAs($this->alexandre)->post(route('suporte.responder', $c->id), ['texto' => 'Oi, Otávio.']);
        $this->assertSame('falhou', $c->mensagens()->reorder()->orderByDesc('id')->value('status'));
        Carbon::setTestNow(now()->addMinutes(16));

        $this->assertSame(1, $this->lembrar(), 'o cliente não recebeu nada: continua esperando');
    }

    public function test_resposta_pelo_celular_e_conversa_assumida_tambem_voltam_a_cobrar(): void
    {
        // Pelo celular (coexistência): a conversa fica com "humano", sem ninguém; o cliente responde e espera.
        $c = SuporteConversa::create(['telefone' => self::CLIENTE, 'nome_perfil' => 'Otávio', 'gestor_conversa_id' => 55, 'situacao' => 'humano',
            'humano_ate' => now()->addHours(20), 'ultima_entrada_em' => now()]);
        $c->mensagens()->create(['wamid' => 'wamid.e1', 'direcao' => 'humano_app', 'tipo' => 'text', 'texto' => 'Bom dia, já vamos ver']);
        $c->mensagens()->create(['wamid' => 'wamid.e2', 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'Obrigado, aguardo o técnico']);
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(1, $this->lembrar());
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->alexandre->id)->where('titulo', 'like', 'Ainda sem resposta: Otávio — escreveu de novo: Obrigado%')->count(),
            'sem ninguém com a conversa, quem é cobrado é o responsável');

        // Assumida pela tela sem responder: o cliente escreve e quem assumiu é cobrado.
        $outra = SuporteConversa::create(['telefone' => self::OUTRO_CLIENTE, 'nome_perfil' => 'Beatriz', 'gestor_conversa_id' => 56, 'situacao' => 'pedro', 'ultima_entrada_em' => now()]);
        $outra->mensagens()->create(['wamid' => 'wamid.b1', 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'Bom dia']);
        $outra->mensagens()->create(['wamid' => 'wamid.b2', 'direcao' => 'pedro', 'tipo' => 'text', 'texto' => 'Bom dia! Em que posso ajudar?', 'status' => 'enviada']);
        $this->actingAs($this->felipe)->post(route('suporte.assumir', $outra->id))->assertRedirect();
        $this->assertSame(0, $this->lembrar(), 'o Pedro respondeu por último: ninguém espera');
        $this->repasse(self::OUTRO_CLIENTE, 'Quero trocar a senha do portão', 'wamid.b3', 56, 'Beatriz');
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(2, $this->lembrar(), 'a Beatriz, e o Otávio de cima, que continua esperando');
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->felipe->id)->where('titulo', 'like', 'Ainda sem resposta: Beatriz%')->count());
        $this->assertSame(0, Notificacao::where('destinatario_id', $this->alexandre->id)->where('titulo', 'like', 'Ainda sem resposta: Beatriz%')->count(), 'é de quem assumiu');
    }

    public function test_para_quando_devolve_ao_pedro_ou_encerra(): void
    {
        $c = $this->passaAoColega();
        $this->actingAs($this->alexandre)->post(route('suporte.devolver', $c->id))->assertRedirect();
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(0, $this->lembrar());

        $c->update(['situacao' => 'humano', 'humano_id' => $this->alexandre->id, 'fila_desde' => now(), 'fila_lembrada_em' => now()]);
        $this->actingAs($this->alexandre)->post(route('suporte.encerrar', $c->id))->assertRedirect();
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(0, $this->lembrar());
    }

    // ---- O horário e o prazo do WhatsApp

    public function test_fora_do_horario_nao_cobra_e_o_prazo_que_acaba_a_noite_tem_o_ultimo_aviso_na_abertura(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 19:00', 'America/Sao_Paulo'));
        $c = $this->passaAoColega();
        $this->assertNotNull($c->fila_desde);

        Carbon::setTestNow(Carbon::parse('2026-11-09 19:30', 'America/Sao_Paulo'));
        $this->assertSame(0, $this->lembrar(), 'fora do horário, não');
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00', 'America/Sao_Paulo'));
        $this->responsavelFalouComOPedro('-5 minutes');
        $this->assertSame(1, $this->lembrar(), 'na abertura');
        $this->assertStringContainsString('Esperando há 13 h. O WhatsApp aceita resposta até as 19:00.', end($this->aoGestor)['texto']);

        // A janela do cliente fecha às 19:00, depois do horário: o último aviso sai na abertura seguinte, e acabou.
        Carbon::setTestNow(Carbon::parse('2026-11-10 19:01', 'America/Sao_Paulo'));
        $this->assertSame(0, $this->lembrar());
        Carbon::setTestNow(Carbon::parse('2026-11-11 08:00', 'America/Sao_Paulo'));
        SuporteConversa::where('telefone', self::DO_RESPONSAVEL)->update(['ultima_entrada_em' => now()->subMinutes(5)]);
        $this->assertSame(1, $this->lembrar());
        $this->assertSame(sprintf(Transferencia::PRAZO_ACABOU, 'Otávio', '19:00 de 10/11', route('suporte.conversas', $c->id)), end($this->aoGestor)['texto']);
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->alexandre->id)->where('titulo', 'like', 'Prazo do WhatsApp acabou: Otávio%')->count());
        $this->assertSame(0, Notificacao::where('destinatario_id', $this->alexandre->id)->where('titulo', 'like', 'Ainda sem resposta%')->count(), 'o último substitui o lembrete');
        $this->assertNull($c->fresh()->fila_desde);
        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(0, $this->lembrar(), 'uma vez só');
    }

    public function test_espera_de_antes_desta_regra_so_sai_da_fila(): void
    {
        $c = SuporteConversa::create(['telefone' => self::CLIENTE, 'nome_perfil' => 'Otávio', 'situacao' => 'humano', 'humano_ate' => now()->subDays(4),
            'ultima_entrada_em' => now()->subDays(5), 'fila_desde' => now()->subDays(5), 'fila_motivo' => 'pediu uma pessoa', 'fila_lembrada_em' => now()->subDays(4)]);
        $c->mensagens()->create(['wamid' => 'wamid.v1', 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'Alguém?']);

        $this->assertSame(0, $this->lembrar());
        $this->assertNull($c->fresh()->fila_desde);
        $this->assertSame(0, Notificacao::count());
        $this->assertSame([], $this->noTelegram);
    }

    public function test_quem_e_da_casa_nao_e_cobrado_quando_volta_a_escrever(): void
    {
        $c = $this->responsavelFalouComOPedro();
        $c->update(['situacao' => 'humano', 'humano_ate' => now()->addHours(20)]);
        $c->mensagens()->create(['wamid' => 'wamid.r4', 'direcao' => 'humano_app', 'tipo' => 'text', 'texto' => 'Oi, aqui é o Felipe pelo celular']);
        $c->mensagens()->create(['wamid' => 'wamid.r5', 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'Certo']);
        Carbon::setTestNow(now()->addMinutes(16));

        $this->assertSame(0, $this->lembrar());
        $this->assertNull($c->fresh()->fila_desde);
    }

    // ---- A tela

    public function test_na_fila_mostra_quem_espera_mesmo_com_o_responsavel_e_vencido_o_prazo_dele(): void
    {
        $c = $this->passaAoColega();
        $c->update(['humano_ate' => now()->subMinute()]);
        $respondida = $this->passaAoColega(self::OUTRO_CLIENTE, conversaNoGestor: 56, nome: 'Beatriz');
        $this->actingAs($this->alexandre)->post(route('suporte.responder', $respondida->id), ['texto' => 'Oi, Beatriz.'])->assertRedirect();
        $this->lembrar();

        $r = $this->actingAs($this->felipe)->get(route('suporte.conversas', ['situacao' => 'fila']))->assertOk();
        $this->assertSame([$c->id], $r->viewData('lista')->pluck('id')->all());
        $this->assertSame(1, $r->viewData('indicadores')['na_fila']);
    }
}
