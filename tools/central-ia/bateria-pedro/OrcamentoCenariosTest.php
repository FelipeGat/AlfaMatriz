<?php

namespace Tests\Bateria;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorOrcamento;
use App\Models\User;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use App\Services\Suporte\Pedro;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A bateria do orçamento pelo Pedro (docs/specs/pedro-orcamento): os 7 cenários de 02/11/2026 e os dos
 * ajustes do dono (sessão 2), montados pelo Contexto de verdade, para a IA de verdade responder; a
 * resposta dela volta pelo Pedro::concluir de verdade, e o que sai na conversa é o que se compara.
 * Fica fora das suítes do phpunit.xml: só roda chamado pelo caminho, com BATERIA_FASE e a pasta
 * /bateria montada (pedidos.json na fase "pedidos"; respostas.json → envios.json na fase "envios").
 *
 * O áudio do cenário 8 é a transcrição de verdade, pelo Whisper da máquina, de um pedido falado
 * sintetizado (BATERIA_AUDIO). Nenhum nome, telefone ou valor aqui é de cliente real.
 */
class OrcamentoCenariosTest extends TestCase
{
    use RefreshDatabase;

    private const FELIPE = '5527998845397';

    private const SUPORTE = '552731093265';

    private User $felipe;

    private const PREVIA = ['cliente' => 'gestor:501', 'empresa_id' => 3, 'sem_nf' => false, 'atendimento' => '812', 'descricao' => 'Manutenção do motor do portão',
        'itens' => [['nome' => 'Manutenção do motor', 'tipo' => 'servico', 'quantidade' => 1, 'valor_unitario' => 450],
            ['nome' => 'Controle 433 MHz', 'tipo' => 'produto', 'quantidade' => 3, 'valor_unitario' => 80]]];

    private const PEDIDO = 'Pedro, faz um orçamento para o Mar Azul pela Invest, com nota, conforme o atendimento 812: manutenção R$ 450 e 3 controles a R$ 80.';

    private const GRAVADO = ['ok' => true, 'orcamento_id' => 991, 'numero' => '045/2026', 'empresa' => 'INVEST', 'valor_total' => 793.5, 'imposto_nf_percentual' => 15,
        'link' => 'https://gestor.exemplo/orcamentos/991', 'pdf' => 'https://gestor.exemplo/orcamentos/991/imprimir'];

    public function test_bateria_do_orcamento(): void
    {
        $fase = (string) getenv('BATERIA_FASE');
        if (! in_array($fase, ['pedidos', 'envios'], true) || ! is_dir('/bateria')) {
            $this->markTestSkipped('BATERIA_FASE=pedidos|envios, com a pasta /bateria montada');
        }
        $respostas = $fase === 'envios' ? collect(json_decode((string) file_get_contents('/bateria/respostas.json'), true))->keyBy('n') : null;
        $saida = [];
        $this->semear();
        foreach ($this->cenarios() as $n => $s) {
            // Cada cenário começa do zero: mesma hora, nenhuma conversa, nada na fila.
            Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'America/Sao_Paulo'));
            SuporteGestorOrcamento::query()->delete();
            \App\Models\SuportePedido::query()->delete();
            SuporteConversa::query()->delete();
            $c = $this->conversa($s['telefone']);
            $antes = 0;
            foreach ($s['turnos'] as [$tipo, $valor]) {
                $antes = $this->passo($c, $tipo, $valor);
            }
            if ($fase === 'pedidos') {
                $saida[] = ['n' => $n + 1, 'nome' => $s['nome'], 'prompt' => app(Contexto::class)->prompt($c->fresh())];

                continue;
            }
            $bruto = (string) ($respostas[$n + 1]['bruto'] ?? '');
            $ultimo = $this->pedroResponde($c, $bruto);
            $mensagens = $this->novas($c, $antes);
            foreach ($s['depois'] ?? [] as [$tipo, $valor]) {
                $this->passo($c, $tipo, $valor);
            }
            $saida[] = ['n' => $n + 1, 'nome' => $s['nome'], 'acao' => $respostas[$n + 1]['bloco']['acao'] ?? null, 'pedido_acao' => $ultimo,
                'mensagens' => $this->novas($c, $antes), 'logo_depois' => $mensagens, 'fila' => SuporteGestorOrcamento::count(),
                'fila_situacao' => SuporteGestorOrcamento::orderBy('id')->pluck('situacao')->all()];
        }
        file_put_contents('/bateria/'.$fase.'.json', json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->assertCount(count($this->cenarios()), $saida);
    }

    /** @return list<array{nome: string, telefone: string, turnos: list<array{0: string, 1: mixed}>, depois?: list<array{0: string, 1: mixed}>}> */
    private function cenarios(): array
    {
        $audio = (string) (getenv('BATERIA_AUDIO') ?: 'Pedro faz um orçamento para o residencial Marazul pela Invest. É a manutenção do motor do portão do atendimento 812. O valor é R$1,150 já com a nota.');
        $comPrevia = [['in', self::PEDIDO], ['ia', self::PREVIA]];

        return [
            // Os 7 de 02/11/2026, com as mesmas mensagens.
            ['nome' => 'Completo, texto', 'telefone' => self::FELIPE, 'turnos' => [['in', 'Pedro, faz um orçamento para o Mar Azul pela Invest, com nota, de manutenção no motor do portão, conforme o atendimento 812. Manutenção R$ 450 e 3 controles a R$ 80 cada.']]],
            ['nome' => 'Pega o atendimento, sem dados', 'telefone' => self::FELIPE, 'turnos' => [['in', 'Pedro, pega aquele atendimento do Mar Azul sobre o portão e monta um orçamento para mim.']]],
            ['nome' => 'Sem valor (Padaria Sol tem o 813, de câmera, em aberto)', 'telefone' => self::FELIPE, 'turnos' => [['in', 'Pedro, preciso de um orçamento de 3 controles de portão para a Padaria Sol, pela Delta, sem nota.']]],
            ['nome' => 'Valor já com nota', 'telefone' => self::FELIPE, 'turnos' => [['in', 'Faz um orçamento para o Mar Azul pela Invest de manutenção do portão, fica 1.150 já com a nota.']]],
            ['nome' => 'Corrige depois da prévia', 'telefone' => self::FELIPE, 'turnos' => [...$comPrevia, ['in', 'muda o controle para 90']]],
            ['nome' => 'Sim depois da prévia, e o Gestor grava', 'telefone' => self::FELIPE, 'turnos' => [...$comPrevia, ['in', 'pode criar']],
                'depois' => [['maquina', self::GRAVADO]]],
            ['nome' => 'Suporte pede orçamento', 'telefone' => self::SUPORTE, 'turnos' => [['in', 'Pedro, cria um orçamento de 2 câmeras para a Padaria Sol pela Delta, sem nota, 300 cada.']]],
            // Os ajustes do dono (sessão 2).
            ['nome' => 'Áudio transcrito pelo Whisper da máquina', 'telefone' => self::FELIPE, 'turnos' => [['audio', $audio]]],
            ['nome' => 'Corrige empresa e nota', 'telefone' => self::FELIPE, 'turnos' => [...$comPrevia, ['in', 'na verdade é pela Delta e sem nota']]],
            ['nome' => 'Corrige quantidade e acrescenta item', 'telefone' => self::FELIPE, 'turnos' => [...$comPrevia, ['in', 'são 4 controles, e põe também uma visita técnica de 150']]],
            ['nome' => 'Não é de nenhum atendimento', 'telefone' => self::FELIPE, 'turnos' => [
                ['in', 'Pedro, orçamento para o Mar Azul pela Invest, com nota: troca do motor do portão, 1.800.'],
                ['ia', ['cliente' => 'gestor:501', 'empresa_id' => 3, 'sem_nf' => false, 'atendimento' => null, 'descricao' => 'Troca do motor do portão',
                    'itens' => [['nome' => 'Troca do motor', 'tipo' => 'servico', 'quantidade' => 1, 'valor_unitario' => 1800]]]],
                ['in', 'não é do 812, é outro serviço']]],
            ['nome' => 'Câmeras para cliente com atendimento de portão em aberto', 'telefone' => self::FELIPE, 'turnos' => [['in', 'Pedro, orçamento de 4 câmeras para o Mar Azul pela Invest, com nota, 300 cada.']]],
            ['nome' => 'Total com nota de um item com quantidade', 'telefone' => self::FELIPE, 'turnos' => [['in', 'Orçamento para o Mar Azul pela Invest: 2 instalações de motor de portão, 2.300 no total, já com a nota. Sem vínculo com atendimento.']]],
            ['nome' => 'Tentar de novo depois de a ponte não responder', 'telefone' => self::FELIPE, 'turnos' => [...$comPrevia, ['in', 'sim'], ['ia', 'confirmar'],
                ['maquina', ['ok' => false, 'erro' => 'a ponte não devolveu resposta — conferir no Gestor antes de repetir']], ['in', 'tenta de novo']],
                'depois' => [['maquina', self::GRAVADO + ['ja_estava' => true]]]],
        ];
    }

    /** Um passo da conversa; devolve o id da última mensagem antes da resposta que se quer ver. */
    private function passo(SuporteConversa $c, string $tipo, mixed $valor): int
    {
        Carbon::setTestNow(now()->addMinute());
        match ($tipo) {
            'in' => $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => $valor, 'enviada_em' => now()]),
            'audio' => $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'audio', 'texto' => null, 'transcricao' => $valor, 'enviada_em' => now()]),
            'ia' => $this->pedroResponde($c, '<<<PEDRO '.json_encode($valor === 'confirmar' ? ['resposta' => 'Criando.', 'acao' => 'confirmar_orcamento']
                : ['resposta' => 'Organizei o orçamento.', 'acao' => 'previa_orcamento', 'orcamento' => $valor], JSON_UNESCAPED_UNICODE).' PEDRO>>>'),
            'maquina' => $this->maquina($valor),
        };

        return (int) $c->mensagens()->reorder()->max('id');
    }

    private function pedroResponde(SuporteConversa $c, string $texto): ?string
    {
        $p = app(Pedro::class)->enfileirar($c->id);
        $p->update(['situacao' => 'reservado']);
        app(Pedro::class)->concluir($this->felipe, $p->id, $texto, null);

        return $p->fresh()->acao;
    }

    /** A máquina reserva o orçamento da fila e devolve o resultado do Gestor. */
    private function maquina(array $resultado): void
    {
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $o = $this->postJson(route('suporte.runner.orcamentos'), ['maquina' => 'wsl'])->assertOk()->json('orcamentos.0');
        $this->postJson(route('suporte.runner.orcamento-feito', $o['id']), ['maquina' => 'wsl'] + $resultado)->assertOk();
    }

    /** @return list<string> */
    private function novas(SuporteConversa $c, int $depoisDe): array
    {
        return $c->mensagens()->reorder()->where('direcao', 'pedro')->where('id', '>', $depoisDe)->orderBy('id')->pluck('texto')->all();
    }

    private function conversa(string $telefone): SuporteConversa
    {
        return SuporteConversa::create(['telefone' => $telefone, 'ultima_entrada_em' => now()]);
    }

    /** O mesmo cenário dos testes de feature e da bateria de 02/11 (PedroOrcamentoTest antes da sessão 2). */
    private function semear(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'felipe@teste.local']);
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid('', true)]]]));
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, "+55 27 99884-5397 | Felipe | diretor | felipe@gestor.local\n+55 27 99999-0001 | Wallas | diretor\n+55 27 3109-3265 | Alexandre | suporte");
        app(DiretorioGestor::class)->gravar('wsl', '2026-11-03 09:55:00', [
            ['id' => 501, 'nome' => 'Residencial Mar Azul', 'fantasia' => 'Residencial Mar Azul', 'razao' => '', 'cidade' => 'Vila Velha', 'empresa' => 'INVEST', 'empresa_id' => 3, 'telefones' => []],
            ['id' => 503, 'nome' => 'Padaria Sol', 'fantasia' => 'Padaria Sol', 'razao' => '', 'cidade' => 'Serra', 'empresa' => 'DELTA', 'empresa_id' => 1, 'telefones' => []],
            ['id' => -900, 'nome' => 'Clínica Horizonte', 'fantasia' => 'Clínica Horizonte', 'razao' => '', 'cidade' => 'Vitória', 'telefones' => [], 'pre' => true],
        ], [
            ['id' => 1001, 'numero' => '812', 'cliente_id' => 501, 'descricao' => 'Motor do portão travando na abertura', 'status' => 'aberto', 'criado_em' => '2026-10-30 09:00:00'],
            ['id' => 1002, 'numero' => '813', 'cliente_id' => 503, 'descricao' => 'Câmera da entrada sem imagem', 'status' => 'em_atendimento', 'criado_em' => '2026-10-31 09:00:00'],
            ['id' => 1003, 'numero' => '700', 'cliente_id' => 501, 'descricao' => 'Troca de lâmpadas', 'status' => 'concluido', 'criado_em' => '2026-09-01 09:00:00'],
        ], [], [], [], [], [], [['id' => 1, 'nome' => 'DELTA', 'imposto_nf' => 17.0], ['id' => 2, 'nome' => 'GW', 'imposto_nf' => 15.0], ['id' => 3, 'nome' => 'INVEST', 'imposto_nf' => 15.0]]);
    }
}
