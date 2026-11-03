<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAgenda;
use App\Models\User;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\AgendaDoTecnico;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use App\Services\Suporte\OrcamentoDoGestor;
use App\Services\Suporte\Pedro;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Os defeitos da programação da semana mandada ao Pedro em 02/11/2026 (pedro-dia-do-tecnico,
 * bugfix): a prévia dizia 20 visitas e só 15 foram conferidas e gravadas, sem aviso; a mensagem de
 * 1.507 caracteres chegou cortada em 1.200; o Pedro pediu o e-mail que o Gestor já sabia pelo
 * celular; as visitas saíam no nome da conta do Pedro; e o aviso já respondido voltou na prévia
 * seguinte. Dados fictícios, na forma da conversa real.
 */
class PedroAgendaCorrecoesTest extends TestCase
{
    use RefreshDatabase;

    private User $conta;

    private const FELIPE = '5527998845397';

    private const WANDA = '5527999990002';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 20:19', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'pedro@teste.local', 'services.suporte_pedro.reserva_segundos' => 120]);
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid('', true)]]]));
        (new PerfilPermissaoSeeder)->run();
        $this->conta = User::factory()->create(['email' => 'pedro@teste.local']);
        CentralIaRunner::create(['user_id' => $this->conta->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        // Sem a 4ª coluna: o usuário do Gestor vem do cadastro de funcionário com o mesmo celular.
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, "+55 27 99884-5397 | Felipe | diretor\n+55 27 99999-0002 | Wanda | gestor");
        $this->retrato();
    }

    private function retrato(array $equipeExtra = []): void
    {
        $tecnicos = [41 => 'Bruno Lima', 42 => 'Caio Rocha', 43 => 'Davi Melo', 44 => 'Edu Paiva', 45 => 'Fábio Reis', 46 => 'Gil Souza'];
        app(DiretorioGestor::class)->gravar('wsl', '2026-11-02 20:10:00', [
            ['id' => 701, 'nome' => 'Condomínio Vale Verde', 'fantasia' => 'Condomínio Vale Verde', 'cidade' => 'Cariacica', 'telefones' => []],
            ['id' => 702, 'nome' => 'Mercado Bom Preço', 'fantasia' => 'Mercado Bom Preço', 'cidade' => 'Vila Velha', 'telefones' => []],
            ['id' => 703, 'nome' => 'Residencial Aurora', 'fantasia' => 'Residencial Aurora', 'cidade' => 'Serra', 'telefones' => []],
            ['id' => 704, 'nome' => 'Clínica Sol', 'fantasia' => 'Clínica Sol', 'cidade' => 'Serra', 'telefones' => []],
            ['id' => 705, 'nome' => 'Loja Norte', 'fantasia' => 'Loja Norte', 'cidade' => 'Vitória', 'telefones' => []],
        ], [
            ['id' => 1901, 'numero' => '901', 'cliente_id' => 701, 'descricao' => 'Troca de local das cancelas', 'status' => 'em_atendimento', 'criado_em' => '2026-10-20 10:00:00'],
            ['id' => 1902, 'numero' => '902', 'cliente_id' => 702, 'descricao' => 'Cobertura do estacionamento', 'status' => 'em_atendimento', 'criado_em' => '2026-10-01 10:00:00'],
            ['id' => 1903, 'numero' => '903', 'cliente_id' => 703, 'descricao' => 'Reinstalação do interfone', 'status' => 'aberto', 'criado_em' => '2026-10-28 10:00:00'],
            ['id' => 1904, 'numero' => '904', 'cliente_id' => 704, 'descricao' => 'Manutenção de alarme e CFTV', 'status' => 'aberto', 'criado_em' => '2026-10-15 10:00:00'],
            ['id' => 1905, 'numero' => '905', 'cliente_id' => 705, 'descricao' => 'Manutenção conforme contrato', 'status' => 'aberto', 'criado_em' => '2026-09-15 10:00:00'],
            ['id' => 1906, 'numero' => '906', 'cliente_id' => 703, 'descricao' => 'Câmera da portaria', 'status' => 'aberto', 'criado_em' => '2026-10-30 10:00:00'],
        ], [], [
            ...collect($tecnicos)->map(fn ($nome, $id) => ['id' => $id, 'nome' => $nome, 'telefones' => []])->values()->all(),
            ['id' => 13, 'nome' => 'Felipe Henrique', 'telefones' => ['(27) 99884-5397'], 'usuario' => 'felipe@gestor.teste'],
            // A Wanda é da equipe, mas o celular dela não está no cadastro: não há como saber quem ela é no Gestor.
            ['id' => 7, 'nome' => 'Wanda Prado', 'telefones' => [], 'usuario' => 'wanda@gestor.teste'],
            ...$equipeExtra,
        ], [], [
            // O Fábio já tem visita na terça, antes da programação, e sem choque.
            ['id' => 5001, 'atendimento_id' => 1906, 'tecnico_id' => 45, 'inicio' => '2026-11-03 09:00', 'fim' => '2026-11-03 10:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
        ], []);
    }

    /** A programação da semana: 10 lugares, 20 visitas (uma por técnico). */
    private static function semana(): array
    {
        $dia = ['2026-11-03' => [['901', [41, 42]], ['902', [43, 44, 46]], ['903', [45], '10:00']],
            '2026-11-04' => [['904', [41, 45]], ['902', [43, 44, 46]], ['905', [42]]],
            '2026-11-05' => [['904', [45]], ['902', [43, 44, 46]]],
            '2026-11-06' => [['904', [45]], ['902', [43, 44, 46]]]];
        $paradas = [];
        foreach ($dia as $data => $lugares) {
            foreach ($lugares as $l) {
                foreach ($l[1] as $tecnico) {
                    $paradas[] = ['tipo' => 'criar_visita', 'atendimento' => $l[0], 'tecnico_id' => $tecnico, 'data' => $data, 'hora_inicio' => $l[2] ?? '08:00', 'hora_fim' => '18:00', 'observacao' => 'continuidade'];
                }
            }
        }

        return $paradas;
    }

    private function conversa(string $telefone, string $texto): SuporteConversa
    {
        $c = SuporteConversa::firstOrCreate(['telefone' => $telefone], ['ultima_entrada_em' => now()]);
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => $texto, 'enviada_em' => now()]);

        return $c->fresh();
    }

    private function responder(SuporteConversa $c, array $bloco): string
    {
        $p = app(Pedro::class)->enfileirar($c->id);
        $p->update(['situacao' => 'reservado']);
        app(Pedro::class)->concluir($this->conta, $p->id, '<<<PEDRO '.json_encode($bloco, JSON_UNESCAPED_UNICODE).' PEDRO>>>', null);

        return (string) $c->mensagens()->reorder()->where('direcao', 'pedro')->orderByDesc('id')->value('texto');
    }

    private function previa(SuporteConversa $c, array $paradas, string $intro = 'Fechado: Aurora no 903 e Clínica Sol no 904.'): string
    {
        return $this->responder($c, ['resposta' => $intro, 'acao' => 'previa_agenda', 'agenda' => $paradas]);
    }

    private function confirmar(SuporteConversa $c): string
    {
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'Pode', 'enviada_em' => now()]);

        return $this->responder($c->fresh(), ['resposta' => 'Lançando!', 'acao' => 'confirmar_agenda']);
    }

    /** A mensagem de 1.507 caracteres chegou ao Pedro cortada em 1.200 com "…", e ele pediu a sexta de novo. */
    public function test_a_mensagem_longa_chega_inteira_ao_pedro(): void
    {
        $longa = 'Pedro, registre a semana. '.str_repeat('*TERÇA* Bruno / Caio - Vale Verde (continuidade do atendimento de sexta deles). ', 18)
            .'*SEXTA* Fábio - Clínica Sol (analisar atendimento do cliente, já existe um de cerca elétrica)';
        $this->assertGreaterThan(1500, mb_strlen($longa));

        $p = app(Contexto::class)->prompt($this->conversa(self::FELIPE, $longa));

        $this->assertStringContainsString('*SEXTA* Fábio - Clínica Sol (analisar atendimento do cliente, já existe um de cerca elétrica)', $p);
        // Até o que o WhatsApp entrega numa mensagem; acima disso (não acontece), o corte é dito.
        $c = $this->conversa(self::FELIPE, str_repeat('x', Contexto::MENSAGEM_INTEIRA + 50));
        $this->assertStringContainsString('… [cortada aqui]', app(Contexto::class)->prompt($c));
    }

    /** A prévia é do sistema, com as 20 visitas, os técnicos juntos e o total; a IA só fala o que entendeu. */
    public function test_previa_da_semana_inteira_montada_pelo_sistema(): void
    {
        $c = $this->conversa(self::FELIPE, 'Castanheiras é o 903 e o Mestre é o 904');
        $msg = $this->previa($c, self::semana());

        $this->assertStringStartsWith("Fechado: Aurora no 903 e Clínica Sol no 904.\n\nPrévia da agenda (as visitas entram agendadas; o técnico finaliza pelo app):\n\n*Terça 03/11*\n"
            ."• 08:00 às 18:00 — Condomínio Vale Verde — nº 901 — visita nova — Bruno e Caio\n"
            ."• 08:00 às 18:00 — Mercado Bom Preço — nº 902 — visita nova — Davi, Edu e Gil\n"
            ."• 10:00 às 18:00 — Residencial Aurora — nº 903 — visita nova — Fábio\n\n*Quarta 04/11*\n", $msg);
        $this->assertStringContainsString("*Sexta 06/11*\n• 08:00 às 18:00 — Clínica Sol — nº 904 — visita nova — Fábio\n• 08:00 às 18:00 — Mercado Bom Preço — nº 902 — visita nova — Davi, Edu e Gil", $msg);
        $this->assertStringContainsString("Total: 20 visitas novas.\n\nPara você saber:\n• Fábio já tem visita em 03/11 das 09:00 às 10:00 (nº 906), que não está nesta programação: ela fica como está.\n\nPosso lançar?", $msg);
        $this->assertCount(20, $c->fresh()->previa_agenda['paradas'], 'nenhuma parada fica de fora');
    }

    /** O "sim" põe as 20 na fila, com quem pediu como autor; o resultado traz o total que o Gestor confirmou e nomeia a falha. */
    public function test_o_sim_lanca_as_20_e_o_resultado_soma_o_que_o_gestor_confirmou(): void
    {
        $c = $this->conversa(self::FELIPE, 'segue a semana');
        $this->previa($c, self::semana());
        $this->assertSame('Enviei 20 mudanças da agenda para o Gestor; o resultado chega em seguida.', $this->confirmar($c));
        $this->assertSame(20, SuporteGestorAgenda::count());

        Sanctum::actingAs($this->conta, ['central-ia-runner']);
        $ids = [];
        while ($lote = $this->postJson(route('suporte.runner.agenda'), ['maquina' => 'wsl'])->assertOk()->json('mudancas')) {
            $this->assertSame(['felipe@gestor.teste'], array_values(array_unique(array_column($lote, 'autor_email'))), 'o autor é quem pediu, e não a conta do Pedro');
            $ids = [...$ids, ...array_column($lote, 'id')];
        }
        $this->assertCount(20, $ids);
        foreach ($ids as $i => $id) {
            $ultimo = $i === count($ids) - 1;
            $this->postJson(route('suporte.runner.agenda-feita', $id), ['maquina' => 'wsl'] + ($ultimo
                ? ['ok' => false, 'erro' => 'Técnico já tem agenda no dia 06/11/2026 entre 08:00 e 18:00.'] : ['ok' => true, 'visita_id' => 6000 + $i, 'numero' => SuporteGestorAgenda::find($id)->atendimento]))->assertOk();
        }

        $resultado = (string) $c->mensagens()->reorder()->where('direcao', 'pedro')->orderByDesc('id')->value('texto');
        $this->assertStringStartsWith("Agenda no Gestor: 19 de 20 gravadas; 1 não gravou.\n\n❌ Não gravaram:\n"
            ."• Sexta 06/11, Gil (nº 902) — visita nova: Técnico já tem agenda no dia 06/11/2026 entre 08:00 e 18:00.\n\n✅ Gravadas:\n*Terça 03/11*\n"
            .'• 08:00 às 18:00 — Condomínio Vale Verde — nº 901 — visita nova — Bruno e Caio', $resultado);
        $this->assertStringEndsWith("*Sexta 06/11*\n• 08:00 às 18:00 — Clínica Sol — nº 904 — visita nova — Fábio\n• 08:00 às 18:00 — Mercado Bom Preço — nº 902 — visita nova — Davi e Edu", $resultado, 'o Gil, que falhou, não aparece como gravado');
        Http::assertSentCount(3);
    }

    /** Acima do que se lança de uma vez, a prévia recusa dizendo — nunca corta calada. */
    public function test_acima_do_limite_recusa_dizendo_e_nada_vai_a_fila(): void
    {
        $paradas = [...self::semana(), ...self::semana(), ...array_slice(self::semana(), 0, AgendaDoTecnico::LIMITE - 39)];
        $c = $this->conversa(self::FELIPE, 'segue o mês');
        $msg = $this->previa($c, $paradas, 'Entendi.');

        $this->assertSame('Entendi.'."\n\n".'A programação tem '.count($paradas).' mudanças na agenda, e eu lanço até '.AgendaDoTecnico::LIMITE.' de cada vez. '
            .'Mande em partes (por exemplo, terça e quarta; depois quinta e sexta), e eu monto a prévia de cada uma.', $msg);
        $this->assertStringStartsWith('Ainda não lancei: a programação passou de', $this->confirmar($c));
        $this->assertSame(0, SuporteGestorAgenda::count());
    }

    /** A máquina grava 5 por volta: as últimas de uma semana esperam mais que o prazo com ela trabalhando, e não podem ser dadas como falha. */
    public function test_lote_em_andamento_nao_vence_e_lote_parado_vence(): void
    {
        $c = $this->conversa(self::FELIPE, 'segue a semana');
        $this->previa($c, self::semana());
        $this->confirmar($c);
        Sanctum::actingAs($this->conta, ['central-ia-runner']);

        Carbon::setTestNow(now()->addSeconds(100));
        foreach ($this->postJson(route('suporte.runner.agenda'), ['maquina' => 'wsl'])->json('mudancas') as $m) {
            $this->postJson(route('suporte.runner.agenda-feita', $m['id']), ['maquina' => 'wsl', 'ok' => true, 'visita_id' => 7000 + $m['id'], 'numero' => $m['atendimento']])->assertOk();
        }
        Carbon::setTestNow(now()->addSeconds(50));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame(['feito' => 5, 'pendente' => 15], SuporteGestorAgenda::pluck('situacao')->countBy()->sortKeys()->all(), 'a máquina está no meio do lote');

        Carbon::setTestNow(now()->addMinutes(5));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame(['falhou' => 15, 'feito' => 5], SuporteGestorAgenda::pluck('situacao')->countBy()->sortKeys()->all(), 'parou de andar: falha, e quem pediu fica sabendo');
        Http::assertSent(fn ($r) => str_starts_with((string) ($r['text']['body'] ?? ''), 'Agenda no Gestor: 5 de 20 gravadas; 15 não gravaram.'));
    }

    /** O aviso que já saiu não volta: o Felipe respondeu "sim, continua", e a prévia seguinte perguntou de novo. */
    public function test_o_aviso_ja_dado_nao_volta_na_previa_seguinte(): void
    {
        $c = $this->conversa(self::FELIPE, 'segue a semana');
        $aviso = 'Fábio já tem visita em 03/11 das 09:00 às 10:00 (nº 906), que não está nesta programação: ela fica como está.';
        $this->assertStringContainsString($aviso, $this->previa($c, self::semana()));

        $c = $this->conversa(self::FELIPE, 'Sim, o Fábio continua com a agenda das 09 às 10. Tira a Loja Norte.');
        $segunda = $this->previa($c, array_values(array_filter(self::semana(), fn ($p) => $p['atendimento'] !== '905')), 'Tirei a Loja Norte.');

        $this->assertStringNotContainsString('Fábio já tem visita', $segunda);
        $this->assertStringNotContainsString('Para você saber', $segunda);
        $this->assertStringEndsWith("Total: 19 visitas novas.\n\nPosso lançar?", $segunda);
        $this->assertContains($aviso, $c->fresh()->previa_agenda['avisos'], 'o aviso continua guardado na prévia');
    }

    /** O pedido à IA não manda listar as paradas: a lista, o total e o "Posso lançar?" são do sistema. */
    public function test_o_pedido_manda_todas_as_paradas_e_nao_listar_no_texto(): void
    {
        $p = app(Contexto::class)->prompt($this->conversa(self::FELIPE, 'lança a semana'));

        $this->assertStringContainsString('com TODAS as paradas, sem resumir nem cortar — UMA parada por técnico', $p);
        $this->assertStringContainsString('em "resposta" NÃO liste as paradas, NÃO conte as visitas e NÃO escreva "Posso lançar?"', $p);
        $this->assertStringContainsString('NUNCA de novo o que ele já respondeu nesta conversa', $p);
        $this->assertStringNotContainsString('termine com "Posso lançar?"', $p);
    }

    /** O Gestor já ligava o celular do Felipe ao funcionário e ao usuário: o Pedro não pede e-mail. */
    public function test_o_usuario_do_gestor_vem_do_cadastro_de_funcionario_e_a_4a_coluna_vale_quando_preenchida(): void
    {
        $acesso = app(AcessoInterno::class)->deQuem(self::FELIPE);
        $this->assertSame(['felipe@gestor.teste', 'cadastro'], [$acesso['gestor_email'], $acesso['gestor_email_de']]);
        $p = app(Contexto::class)->prompt($this->conversa(self::FELIPE, 'faz um orçamento para a Loja Norte'));
        $this->assertStringContainsString('|previa_orcamento|', $p, 'diretor ligado pelo cadastro cria orçamento');
        $this->assertStringNotContainsString(OrcamentoDoGestor::SEM_VINCULO, $p);
        $this->assertStringNotContainsString('felipe@gestor.teste', $p, 'o e-mail do Gestor não vai à IA');

        // Preenchida, a 4ª coluna vale mais que o cadastro.
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, "+55 27 99884-5397 | Felipe | diretor | outro@gestor.teste\n+55 27 99999-0002 | Wanda | gestor");
        $this->assertSame(['outro@gestor.teste', 'lista'], array_values(array_intersect_key(app(AcessoInterno::class)->deQuem(self::FELIPE), array_flip(['gestor_email', 'gestor_email_de']))));
    }

    /** Sem saber quem pede, a visita sairia no nome de outra pessoa: a prévia diz o que falta e o "sim" não lança. */
    public function test_quem_nao_esta_ligado_ao_gestor_nao_lanca_em_nome_de_outro(): void
    {
        $this->assertNull(app(AcessoInterno::class)->deQuem(self::WANDA)['gestor_email']);
        $c = $this->conversa(self::WANDA, 'lança o Bruno no 901 amanhã');
        $msg = $this->previa($c, [self::semana()[0]], 'Certo.');

        $this->assertStringContainsString("Antes de lançar, preciso acertar:\n• ".AgendaDoTecnico::SEM_AUTOR, $msg);
        $this->assertStringNotContainsString('Posso lançar?', $msg);
        $this->assertStringContainsString(AgendaDoTecnico::SEM_AUTOR, $this->confirmar($c));
        $this->assertSame(0, SuporteGestorAgenda::count());

        // Com o celular no cadastro de funcionário, ela passa a lançar em nome dela.
        $this->retrato([['id' => 8, 'nome' => 'Wanda Prado', 'telefones' => ['27 99999-0002'], 'usuario' => 'wanda@gestor.teste']]);
        $this->assertSame('wanda@gestor.teste', app(AcessoInterno::class)->deQuem(self::WANDA)['gestor_email']);
    }

    /** Dois funcionários com o mesmo celular não são ninguém; e-mail estranho no cadastro não derruba o retrato. */
    public function test_vinculo_ambiguo_ou_estranho_nao_liga_ninguem(): void
    {
        $this->retrato([['id' => 99, 'nome' => 'Felipe Duplicado', 'telefones' => ['27998845397'], 'usuario' => 'dup@gestor.teste']]);
        $this->assertNull(app(AcessoInterno::class)->deQuem(self::FELIPE)['gestor_email']);

        Sanctum::actingAs($this->conta, ['central-ia-runner']);
        $this->postJson(route('suporte.runner.gestor'), ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(), 'clientes' => [], 'atendimentos' => [],
            'equipe' => [['id' => 13, 'nome' => 'Felipe Henrique', 'telefones' => ['27998845397'], 'usuario' => 'não é e-mail'],
                ['id' => 41, 'nome' => 'Bruno Lima', 'telefones' => ['27911112222'], 'usuario' => 'bruno@gestor.teste']]])->assertOk();
        $this->assertNull(app(DiretorioGestor::class)->daEquipe(self::FELIPE)['usuario']);
        $this->assertSame('bruno@gestor.teste', app(DiretorioGestor::class)->daEquipe('5527911112222')['usuario'], 'o campo novo da leitura chega ao Matriz');
    }
}
