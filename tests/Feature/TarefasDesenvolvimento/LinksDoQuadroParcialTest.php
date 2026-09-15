<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os links do quadro apontam para o QUADRO, e não para a ação que o redesenhou.
 *
 * O `_quadro` é desenhado em três lugares (ver `dadosDoQuadro`): ao abrir a
 * tela, na resposta parcial de uma ação — que é um POST em `/tarefas/5/mover` —
 * e na atualização automática, que é um GET em `/tarefas/atualizacoes`. Os
 * endereços do cabeçalho nasciam de `request()->fullUrlWithQuery()`, que é a
 * URL da REQUISIÇÃO CORRENTE: depois de mover um card, o ✕ da pílula de recorte
 * apontava para `/tarefas/5/mover` e o clique dava 405 Method Not Allowed;
 * depois dos trinta segundos da atualização automática, apontava para o
 * endpoint JSON e o clique despejava o JSON na tela.
 *
 * O recorte continua viajando na query string — o que muda é só a base.
 */
class LinksDoQuadroParcialTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Sistema, 2: Tarefa} */
    private function baseComRecorte(): array
    {
        $dono = User::factory()->create(['name' => 'Rafael Lima']);
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $dono->id,
            'responsavel_id' => $dono->id,
            'sistema_id' => $sistema->id,
            'status' => 'em_desenvolvimento',
            'titulo' => 'Tarefa do Rafael no AlfaGym',
        ]);

        return [$dono, $sistema, $tarefa];
    }

    /**
     * @spec:AC-355 O ✕ da pílula de recorte continua levando ao quadro depois de
     * mover um card — a ação redesenha o cabeçalho, e ele não pode nascer
     * apontando para a rota da própria ação.
     */
    public function test_a_pilula_do_recorte_aponta_para_o_quadro_depois_de_mover(): void
    {
        [$dono, $sistema, $tarefa] = $this->baseComRecorte();

        // O `fetch` das ações parciais manda `form.action + location.search`:
        // a query do recorte chega no POST, e é dela que o cabeçalho é feito.
        $quadro = $this->actingAs($dono)
            ->postJson(route('tarefas.mover', $tarefa).'?sistema='.$sistema->id.'&responsavel='.$dono->id, [
                'de_status' => 'em_desenvolvimento',
                'status' => 'em_revisao',
            ])
            ->assertOk()
            ->json('quadro');

        $this->assertNotNull($quadro, 'mover devolve o quadro inteiro');

        $link = $this->linkDaPilula($quadro, 'sistema');

        // A base é a tela, não a ação: `/tarefas/5/mover` só aceita POST, e o
        // clique no ✕ voltava 405.
        $this->assertStringStartsWith(route('tarefas.index').'?', $link);
        $this->assertStringNotContainsString('/mover', $link);

        // E o ✕ continua tirando SÓ o seu filtro.
        $this->assertStringContainsString('responsavel='.$dono->id, $link);
        $this->assertStringNotContainsString('sistema='.$sistema->id, $link);
    }

    /**
     * @spec:AC-354 O mesmo vale para a atualização automática: ela redesenha o
     * quadro a cada trinta segundos, e os links não podem passar a apontar para
     * o endpoint JSON que os trouxe.
     */
    public function test_os_links_do_cabecalho_sobrevivem_a_atualizacao_automatica(): void
    {
        [$dono, $sistema] = $this->baseComRecorte();

        $quadro = $this->actingAs($dono)
            ->getJson(route('tarefas.atualizacoes', [
                'assinatura' => 'de-antes',
                'sistema' => $sistema->id,
                'raias' => 'responsavel',
            ]))
            ->assertOk()
            ->json('quadro');

        $this->assertNotNull($quadro, 'a assinatura velha traz o quadro redesenhado');

        $this->assertStringStartsWith(
            route('tarefas.index').'?',
            $this->linkDaPilula($quadro, 'sistema'),
        );

        // O "ver só estas" de cada faixa é desenhado pela mesma partial e tinha
        // o mesmo defeito.
        $this->assertStringNotContainsString('href="'.route('tarefas.atualizacoes'), $quadro);
    }

    /**
     * O endereço do ✕ de uma pílula, pelo parâmetro que ela tira.
     */
    private function linkDaPilula(string $html, string $parametro): string
    {
        $encontrou = preg_match(
            '/<a href="([^"]+)"\s+data-tirar-filtro="'.preg_quote($parametro, '/').'"/',
            $html,
            $achados,
        );

        $this->assertSame(1, $encontrou, "a pílula de {$parametro} não está no quadro");

        return htmlspecialchars_decode($achados[1]);
    }
}
