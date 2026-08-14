<?php

namespace Tests\Feature\Desempenho;

use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O sino é servido por um View composer, e o composer está registrado para
 * DUAS views — a sidebar, que desenha o botão, e o painel irmão dela, que
 * desenha a lista. As duas aparecem em toda tela, então o closure rodava duas
 * vezes e repetia as duas consultas: 4 idas ao banco por página, em vez de 2.
 */
class SinoUmaVezTest extends TestCase
{
    use RefreshDatabase;

    /** Quantas consultas a requisição fez à tabela de notificações. */
    private function consultasDeNotificacao(callable $requisicao): int
    {
        $quantas = 0;

        DB::listen(function ($consulta) use (&$quantas): void {
            if (str_contains($consulta->sql, 'notificacoes')) {
                $quantas++;
            }
        });

        $requisicao();

        return $quantas;
    }

    /**
     * @spec:AC-243 A tabela de notificações é lida no máximo duas vezes por
     * requisição — a lista e a contagem —, e não uma vez por view do sino.
     */
    public function test_o_sino_consulta_uma_vez_por_requisicao(): void
    {
        $usuario = User::factory()->create();

        Notificacao::create([
            'destinatario_id' => $usuario->id,
            'tipo' => 'pergunta',
            'titulo' => 'Alguém perguntou alguma coisa',
        ]);

        $quantas = $this->consultasDeNotificacao(
            fn () => $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()
        );

        $this->assertLessThanOrEqual(2, $quantas,
            "A tela leu a tabela de notificações {$quantas} vezes — ".
            'o composer voltou a rodar uma vez por view.');
    }

    /**
     * O cache do sino não pode atravessar a requisição: aviso novo tem de
     * aparecer no carregamento seguinte. É a contrapartida do teste acima — sem
     * ela, "consultar menos" seria satisfeito por um sino que congela.
     */
    public function test_aviso_novo_aparece_no_carregamento_seguinte(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->get(route('tarefas.index'))
            ->assertOk()
            ->assertDontSee('Aviso que chegou depois');

        Notificacao::create([
            'destinatario_id' => $usuario->id,
            'tipo' => 'pergunta',
            'titulo' => 'Aviso que chegou depois',
        ]);

        $this->actingAs($usuario)->get(route('tarefas.index'))
            ->assertOk()
            ->assertSee('Aviso que chegou depois');
    }
}
