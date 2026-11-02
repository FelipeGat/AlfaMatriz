<?php

namespace Tests\Bateria;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
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
 * O lado do Matriz da ponta a ponta do orçamento (docs/specs/pedro-orcamento, tarefa 5.8). A conversa é
 * refeita do zero em cada fase, na mesma hora, então a chave da prévia é a mesma nas quatro:
 *
 *   prompt1    o áudio transcrito (BATERIA_AUDIO) → o pedido à IA (e2e-prompt1.json)
 *   prompt2    + a resposta da IA (e2e-ia1.txt) pelo Pedro::concluir, + "sim" → o pedido à IA (e2e-prompt2.json)
 *   fila       + a resposta da IA (e2e-ia2.txt) → a máquina reserva → e2e-fila.json (o que a máquina recebe)
 *   resultado  + o que a máquina devolveu do Gestor (e2e-resultado.json) → as mensagens (e2e-mensagens.json)
 *
 * Os ids de cliente, empresa e atendimento são os do banco de testes do Gestor (gestor.json, do
 * gestor_e2e.php preparar). Fica fora das suítes: só roda chamado pelo caminho, com /bateria montada.
 */
class OrcamentoPontaAPontaTest extends TestCase
{
    use RefreshDatabase;

    private const FELIPE = '5527998845397';

    public function test_ponta_a_ponta(): void
    {
        $fase = (string) getenv('BATERIA_FASE');
        if (! in_array($fase, ['prompt1', 'prompt2', 'fila', 'resultado'], true) || ! is_file('/bateria/gestor.json')) {
            $this->markTestSkipped('BATERIA_FASE=prompt1|prompt2|fila|resultado, com /bateria/gestor.json');
        }
        $g = json_decode((string) file_get_contents('/bateria/gestor.json'), true);
        $felipe = $this->semear($g);
        $c = SuporteConversa::create(['telefone' => self::FELIPE, 'ultima_entrada_em' => now()]);
        $c->mensagens()->create(['wamid' => 'wamid.e2e1', 'direcao' => 'entrada', 'tipo' => 'audio', 'texto' => null, 'transcricao' => (string) getenv('BATERIA_AUDIO'), 'enviada_em' => now()]);
        if ($fase === 'prompt1') {
            file_put_contents('/bateria/e2e-prompt1.json', json_encode([['n' => 1, 'nome' => 'áudio', 'prompt' => app(Contexto::class)->prompt($c->fresh())]], JSON_UNESCAPED_UNICODE));
            $this->assertTrue(true);

            return;
        }
        Carbon::setTestNow(now()->addMinute());
        $this->pedro($felipe, $c, (string) file_get_contents('/bateria/e2e-ia1.txt'));
        Carbon::setTestNow(now()->addMinute());
        $c->mensagens()->create(['wamid' => 'wamid.e2e2', 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'pode criar', 'enviada_em' => now()]);
        if ($fase === 'prompt2') {
            file_put_contents('/bateria/e2e-prompt2.json', json_encode([['n' => 1, 'nome' => 'sim', 'prompt' => app(Contexto::class)->prompt($c->fresh())]], JSON_UNESCAPED_UNICODE));
            $this->assertTrue(true);

            return;
        }
        $this->pedro($felipe, $c, (string) file_get_contents('/bateria/e2e-ia2.txt'));
        Carbon::setTestNow(now()->addSeconds(20));
        Sanctum::actingAs($felipe, ['central-ia-runner']);
        $fila = $this->postJson(route('suporte.runner.orcamentos'), ['maquina' => 'wsl'])->assertOk()->json('orcamentos');
        if ($fase === 'fila') {
            file_put_contents('/bateria/e2e-fila.json', json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->assertCount(1, $fila);

            return;
        }
        $resultado = json_decode((string) file_get_contents('/bateria/e2e-resultado.json'), true);
        $this->postJson(route('suporte.runner.orcamento-feito', $fila[0]['id']), $resultado)->assertOk();
        file_put_contents('/bateria/e2e-mensagens.json', json_encode($c->mensagens()->reorder()->orderBy('id')->get()
            ->map(fn ($m) => ['de' => $m->direcao === 'entrada' ? 'Felipe' : 'Pedro', 'texto' => $m->direcao === 'entrada' ? ($m->texto ?? '[áudio] '.$m->transcricao) : $m->texto])->all(),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->assertTrue(true);
    }

    private function pedro(User $felipe, SuporteConversa $c, string $bruto): void
    {
        $p = app(Pedro::class)->enfileirar($c->id);
        $p->update(['situacao' => 'reservado']);
        app(Pedro::class)->concluir($felipe, $p->id, $bruto, null);
    }

    private function semear(array $g): User
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'felipe@teste.local']);
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid('', true)]]]));
        (new PerfilPermissaoSeeder)->run();
        $felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        CentralIaRunner::create(['user_id' => $felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, '+55 27 99884-5397 | Felipe | diretor | '.$g['dono']);
        app(DiretorioGestor::class)->gravar('wsl', '2026-11-03 09:55:00', [
            ['id' => $g['mar_azul'], 'nome' => 'Residencial Mar Azul', 'fantasia' => 'Residencial Mar Azul', 'razao' => '', 'cidade' => 'Vila Velha', 'empresa' => $g['empresa']['nome'], 'empresa_id' => $g['empresa']['id'], 'telefones' => []],
            ['id' => $g['padaria'], 'nome' => 'Padaria Sol', 'fantasia' => 'Padaria Sol', 'razao' => '', 'cidade' => 'Serra', 'empresa' => $g['empresa']['nome'], 'empresa_id' => $g['empresa']['id'], 'telefones' => []],
        ], [
            ['id' => $g['at812'], 'numero' => '812', 'cliente_id' => $g['mar_azul'], 'descricao' => 'Motor do portão travando na abertura', 'status' => 'aberto', 'criado_em' => '2026-11-02 09:00:00'],
            ['id' => $g['at812'] + 1, 'numero' => '813', 'cliente_id' => $g['padaria'], 'descricao' => 'Câmera da entrada sem imagem', 'status' => 'aberto', 'criado_em' => '2026-11-02 10:00:00'],
        ], [], [], [], [], [], [$g['empresa']]);

        return $felipe;
    }
}
