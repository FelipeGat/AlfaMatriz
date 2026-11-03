<?php

namespace Tests\Bateria;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAgenda;
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
use Tests\TestCase;

/**
 * A bateria da programação da semana (pedro-dia-do-tecnico, bugfix de 02/11/2026): a conversa do
 * Felipe daquela noite, na mesma forma — a mensagem de mais de 1.200 caracteres, as dúvidas de
 * atendimento, o aviso respondido, o "pode" e o orçamento sem o e-mail na lista —, com clientes,
 * técnicos e números fictícios. Montada pelo Contexto de verdade para a IA de verdade responder; a
 * resposta volta pelo Pedro::concluir de verdade. Fora das suítes: BATERIA_FASE=pedidos|envios e a
 * pasta /bateria montada, como a OrcamentoCenariosTest.
 */
class AgendaCenariosTest extends TestCase
{
    use RefreshDatabase;

    private const FELIPE = '5527998845397';

    private User $conta;

    private const LONGA = "Pedro, preciso que registre esses atendimentos, analise corretamente e se tiver dúvidas me pergunte para não fazer errado\n\n"
        ."*TERÇA: dia todo das 08:00 as 18:00*\nBruno / Caio - Vale Verde (continuidade no atendimento de sexta deles)\nDavi / Edu / Gil. - Bom Preço (continuidade no atendimento de sexta deles)\nFábio - Aurora. (das 10:00 as 18:00)\n\n"
        ."*QUARTA: dia todo das 08:00 as 18:00*\nBruno / Fábio - Clínica Sol (analisar atendimento do cliente, já existe um atendimento para instalação de cerca elétrica)\nDavi / Edu / Gil. - Bom Preço (continuidade no atendimento de sexta deles)\n"
        ."Caio - Loja Norte ( atendimento de quarta contrato, o mesmo que ele foi atender na semana passada, somente adicionar a agenda nesse)\n\n"
        ."*QUINTA: dia todo das 08:00 as 18:00*\nFábio - Clínica Sol (analisar atendimento do cliente, já existe um atendimento para instalação de cerca elétrica)\nDavi / Edu / Gil. - Bom Preço (continuidade no atendimento de sexta deles)\n"
        ."Caio / Bruno -  Auto Center Sul (orçamento novo criar o orçamento para manutenção corretiva ar condicionado e depois agendar os dois)\n\n"
        ."*SEXTA: dia todo das 08:00 as 18:00*\nFábio - Clínica Sol (analisar atendimento do cliente, já existe um atendimento para instalação de cerca elétrica)\nDavi / Edu / Gil. - Bom Preço (continuidade no atendimento de sexta deles)\n"
        ."Caio / Bruno -  Concessionária Leste (orçamento novo criar o orçamento para manutenção corretiva ar condicionado e depois agendar os dois)";

    private const DUVIDA = 'Vale Verde e Bom Preço têm mais de um atendimento aberto (Vale Verde: nº 901 e 911; Bom Preço: nº 902 e 912). Em qual deles lanço as visitas? E a Aurora tem o 903 e o 913.';

    private const RESPOSTAS = 'Vale Verde é o 901, das cancelas. Bom Preço é o 902, da cobertura. Aurora é o 903. A Clínica Sol é o 904 e a Loja Norte é o 905. A Auto Center e a Concessionária ficam para depois.';

    public function test_bateria_da_agenda(): void
    {
        $fase = (string) getenv('BATERIA_FASE');
        if (! in_array($fase, ['pedidos', 'envios'], true) || ! is_dir('/bateria')) {
            $this->markTestSkipped('BATERIA_FASE=pedidos|envios, com a pasta /bateria montada');
        }
        $this->assertGreaterThan(1200, mb_strlen(self::LONGA), 'maior que o teto antigo, como a de 02/11');
        $respostas = $fase === 'envios' ? collect(json_decode((string) file_get_contents('/bateria/respostas.json'), true))->keyBy('n') : null;
        $saida = [];
        $this->semear();
        foreach ($this->cenarios() as $n => $s) {
            Carbon::setTestNow(Carbon::parse('2026-11-02 20:19', 'America/Sao_Paulo'));
            SuporteGestorAgenda::query()->delete();
            \App\Models\SuportePedido::query()->delete();
            SuporteConversa::query()->delete();
            $c = SuporteConversa::create(['telefone' => self::FELIPE, 'ultima_entrada_em' => now()]);
            $antes = 0;
            foreach ($s['turnos'] as [$tipo, $valor]) {
                $antes = $this->passo($c, $tipo, $valor);
            }
            if ($fase === 'pedidos') {
                $saida[] = ['n' => $n + 1, 'nome' => $s['nome'], 'prompt' => app(Contexto::class)->prompt($c->fresh())];

                continue;
            }
            $bloco = $respostas[$n + 1]['bloco'] ?? null;
            $this->pedroResponde($c, (string) ($respostas[$n + 1]['bruto'] ?? ''));
            $saida[] = ['n' => $n + 1, 'nome' => $s['nome'], 'acao' => $bloco['acao'] ?? null, 'paradas' => is_array($bloco['agenda'] ?? null) ? count($bloco['agenda']) : null,
                'texto_da_ia' => $bloco['resposta'] ?? null, 'mensagens' => $c->mensagens()->reorder()->where('direcao', 'pedro')->where('id', '>', $antes)->orderBy('id')->pluck('texto')->all(),
                'fila' => SuporteGestorAgenda::count(), 'autores' => SuporteGestorAgenda::distinct()->pluck('autor_email')->all()];
        }
        file_put_contents('/bateria/'.$fase.'.json', json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->assertCount(count($this->cenarios()), $saida);
    }

    /** @return list<array{nome: string, turnos: list<array{0: string, 1: mixed}>}> */
    private function cenarios(): array
    {
        $respondeu = [['in', self::LONGA], ['pedro', self::DUVIDA], ['in', self::RESPOSTAS]];
        $comPrevia = [...$respondeu, ['ia', self::semana()]];

        return [
            ['nome' => 'A programação da semana, com mais de 1.200 caracteres', 'turnos' => [['in', self::LONGA]]],
            ['nome' => 'Respondeu os atendimentos: a prévia', 'turnos' => $respondeu],
            ['nome' => 'Respondeu o aviso do Fábio e tirou a Loja Norte', 'turnos' => [...$comPrevia, ['in', 'Sim, o Fábio continua com a agenda das 09 às 10. Pode tirar a Loja Norte.']]],
            ['nome' => '"Pode" depois da prévia', 'turnos' => [...$comPrevia, ['in', 'Pode']]],
            ['nome' => 'Orçamento sem o e-mail na lista (ligado pelo celular do cadastro)', 'turnos' => [['in', 'Pedro, cria o orçamento da Auto Center Sul pela GW, sem nota: manutenção corretiva do ar-condicionado, 2 visitas de 350 cada.']]],
        ];
    }

    /** As 20 visitas da programação, uma por técnico, como a IA deve mandar. */
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

    private function passo(SuporteConversa $c, string $tipo, mixed $valor): int
    {
        Carbon::setTestNow(now()->addMinute());
        match ($tipo) {
            'in' => $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => $valor, 'enviada_em' => now()]),
            'pedro' => $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'pedro', 'tipo' => 'text', 'texto' => $valor, 'enviada_em' => now(), 'status' => 'delivered']),
            'ia' => $this->pedroResponde($c, '<<<PEDRO '.json_encode(['resposta' => 'Fechado: Vale Verde no 901, Bom Preço no 902, Aurora no 903, Clínica Sol no 904 e Loja Norte no 905.',
                'acao' => 'previa_agenda', 'agenda' => $valor], JSON_UNESCAPED_UNICODE).' PEDRO>>>'),
        };

        return (int) $c->mensagens()->reorder()->max('id');
    }

    private function pedroResponde(SuporteConversa $c, string $texto): void
    {
        $p = app(Pedro::class)->enfileirar($c->id);
        $p->update(['situacao' => 'reservado']);
        app(Pedro::class)->concluir($this->conta, $p->id, $texto, null);
    }

    private function semear(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 20:19', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'pedro@teste.local']);
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid('', true)]]]));
        (new PerfilPermissaoSeeder)->run();
        $this->conta = User::factory()->create(['email' => 'pedro@teste.local']);
        CentralIaRunner::create(['user_id' => $this->conta->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        // Sem a 4ª coluna, como a linha do Felipe em produção: o usuário vem do cadastro de funcionário.
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, '+55 27 99884-5397 | Felipe | diretor');
        $cl = fn ($id, $nome, $cidade, $empresa = 'GW', $empresaId = 2) => ['id' => $id, 'nome' => $nome, 'fantasia' => $nome, 'razao' => '', 'cidade' => $cidade, 'empresa' => $empresa, 'empresa_id' => $empresaId, 'telefones' => []];
        $at = fn ($id, $numero, $cliente, $descricao, $criado, $status = 'em_atendimento') => ['id' => $id, 'numero' => $numero, 'cliente_id' => $cliente, 'descricao' => $descricao, 'status' => $status, 'criado_em' => $criado];
        $v = fn ($id, $atendimento, $tecnico, $inicio, $fim, $situacao = 'agendado') => ['id' => $id, 'atendimento_id' => $atendimento, 'tecnico_id' => $tecnico, 'inicio' => $inicio, 'fim' => $fim, 'situacao' => $situacao, 'observacoes' => '', 'adicionais' => []];
        app(DiretorioGestor::class)->gravar('wsl', '2026-11-02 20:10:00', [
            $cl(701, 'Condomínio Vale Verde', 'Cariacica'), $cl(702, 'Mercado Bom Preço', 'Vila Velha'), $cl(703, 'Residencial Aurora', 'Serra', 'INVEST', 3),
            $cl(704, 'Clínica Sol', 'Serra'), $cl(705, 'Loja Norte', 'Vitória'), $cl(706, 'Auto Center Sul', 'Cachoeiro de Itapemirim'), $cl(707, 'Concessionária Leste', 'Serra'),
        ], [
            $at(1901, '901', 701, 'Troca de local das cancelas (desinstalação e reinstalação)', '2026-10-20 10:00:00'),
            $at(1911, '911', 701, 'Câmeras da guarita sem imagem', '2026-10-25 10:00:00', 'aberto'),
            $at(1902, '902', 702, 'Cobertura do estacionamento: estrutura e telhas', '2026-10-01 10:00:00'),
            $at(1912, '912', 702, 'Revisão do quadro elétrico', '2026-10-27 10:00:00', 'aberto'),
            $at(1903, '903', 703, 'Reinstalação do interfone e reposicionamento da câmera', '2026-10-28 10:00:00', 'aberto'),
            $at(1913, '913', 703, 'Implantação do AlfaControl', '2026-10-29 10:00:00', 'aberto'),
            $at(1904, '904', 704, 'Manutenção de alarme e CFTV', '2026-10-15 10:00:00', 'aberto'),
            $at(1905, '905', 705, 'Manutenção conforme contrato', '2026-09-15 10:00:00', 'aberto'),
        ], [], [
            ['id' => 41, 'nome' => 'Bruno Lima', 'telefones' => []], ['id' => 42, 'nome' => 'Caio Rocha', 'telefones' => []], ['id' => 43, 'nome' => 'Davi Melo', 'telefones' => []],
            ['id' => 44, 'nome' => 'Edu Paiva', 'telefones' => []], ['id' => 45, 'nome' => 'Fábio Reis', 'telefones' => []], ['id' => 46, 'nome' => 'Gil Souza', 'telefones' => []],
            ['id' => 13, 'nome' => 'Felipe Henrique', 'telefones' => ['(27) 99884-5397'], 'usuario' => 'felipe@gestor.teste'],
        ], [], [
            // A semana passada: a "sexta deles" e a quarta da Loja Norte.
            $v(5101, 1901, 41, '2026-10-30 08:00', '2026-10-30 18:00', 'finalizado'), $v(5102, 1901, 42, '2026-10-30 08:00', '2026-10-30 18:00', 'finalizado'),
            $v(5103, 1902, 43, '2026-10-30 08:00', '2026-10-30 18:00', 'finalizado'), $v(5104, 1902, 44, '2026-10-30 08:00', '2026-10-30 18:00', 'finalizado'),
            $v(5105, 1902, 46, '2026-10-30 08:00', '2026-10-30 18:00', 'finalizado'), $v(5106, 1905, 42, '2026-10-28 08:00', '2026-10-28 12:00', 'finalizado'),
            // O Fábio já tem a terça cedo, sem choque com a Aurora.
            $v(5001, 1913, 45, '2026-11-03 09:00', '2026-11-03 10:00'),
        ], [], [['id' => 1, 'nome' => 'DELTA', 'imposto_nf' => 17.0], ['id' => 2, 'nome' => 'GW', 'imposto_nf' => 15.0], ['id' => 3, 'nome' => 'INVEST', 'imposto_nf' => 15.0]]);
    }
}
