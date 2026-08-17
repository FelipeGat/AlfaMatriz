<?php

namespace Tests\Feature\Desempenho;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O pedido de motivo — o formulário que abre ao travar, concluir ou arrastar
 * para uma etapa que exige texto — estava impresso DENTRO de cada card, guardado
 * por `pendente.id === {id}`. Só que `pendente` é estado do quadro: um por vez,
 * nunca dois. Cada card baixava a própria cópia e 119 delas nunca serviriam.
 *
 * Medido em 14/08/2026: 4090 bytes por card, 479 KB num quadro de 120 tarefas —
 * 26% da tela.
 *
 * Ele passou a existir uma vez só, como molde, e a ser clonado para dentro do
 * card que o pediu. O segundo teste guarda o requisito que motivou tudo isso: o
 * pedido de texto aparece COLADO no card de que fala. O painel já foi flutuante
 * no rodapé do quadro e foi revertido justamente por perder essa ligação.
 */
class PainelDoMotivoTest extends TestCase
{
    use RefreshDatabase;

    private function quadroCom(int $quantas, User $usuario): void
    {
        $sistema = Sistema::factory()->create();

        Tarefa::factory()->count($quantas)->create([
            'criado_por_id' => $usuario->id,
            'sistema_id' => $sistema->id,
            'status' => 'em_desenvolvimento',
        ]);
    }

    /**
     * @spec:AC-250 O formulário de motivo aparece uma vez só no HTML, e cada card
     * carrega apenas o lugar onde ele vai ser desenhado.
     */
    public function test_o_pedido_de_motivo_existe_uma_vez_so(): void
    {
        $usuario = User::factory()->create();
        $this->quadroCom(20, $usuario);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O molde: um, e só um. A âncora é a TAG, e não o nome solto — ele
        // aparece de novo no seletor do script que faz o clone.
        $this->assertSame(1, substr_count($html, '<template data-molde-do-motivo>'),
            'O formulário de motivo voltou a ser impresso mais de uma vez.');

        // O campo de texto e o botão de enviar moram no molde — não podem
        // aparecer vinte vezes.
        $this->assertSame(1, substr_count($html, 'x-ref="textoPendente"'),
            'O campo de motivo voltou a ser impresso por card.');

        // Cada card carrega o lugar, e nada além dele. Conta a TAG: o nome
        // aparece de novo no seletor e no comentário do script que faz o clone.
        $this->assertSame(20, substr_count($html, '<div data-motivo="'),
            'Nem todo card tem o lugar onde o motivo é desenhado.');
    }

    /**
     * @spec:AC-250 O pedido de motivo continua sendo desenhado DENTRO do card
     * daquela tarefa — o requisito que fez o painel sair do rodapé do quadro.
     */
    public function test_o_motivo_e_desenhado_dentro_do_card_da_tarefa(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
            'titulo' => 'Tarefa que vai ser travada',
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O lugar do motivo está DENTRO do <article> daquela tarefa: entre a
        // abertura do card e o fechamento dele. Se ele escapar para o rodapé do
        // quadro, este teste cai — que é exatamente o que se quer guardar.
        $abreCard = strpos($html, 'data-tarefa="'.$tarefa->id.'"');
        $this->assertNotFalse($abreCard, 'O card da tarefa não apareceu no quadro.');

        $fechaCard = strpos($html, '</article>', $abreCard);
        $lugarDoMotivo = strpos($html, 'data-motivo="'.$tarefa->id.'"');

        $this->assertNotFalse($lugarDoMotivo, 'O card não tem onde desenhar o pedido de motivo.');
        $this->assertGreaterThan($abreCard, $lugarDoMotivo);
        $this->assertLessThan($fechaCard, $lugarDoMotivo,
            'O pedido de motivo saiu de dentro do card — ele já foi flutuante uma vez e foi revertido.');
    }

    /**
     * @spec:AC-250 O quadro com 120 tarefas encolhe: o que sobrava eram 120
     * cópias de um formulário que só pode abrir uma vez.
     */
    public function test_o_quadro_encolhe_com_o_molde_unico(): void
    {
        $usuario = User::factory()->create();
        $this->quadroCom(120, $usuario);

        $bytes = strlen($this->actingAs($usuario)
            ->get(route('tarefas.index'))->assertOk()->getContent());

        // Recalibrado em 14/08/2026: o movimento livre (US-079) fez o menu
        // "Mover ▾" de quem triaga — que é quem este teste loga — listar o
        // quadro inteiro, e o mesmo quadro passou a ~1,74 MB. Crescimento de
        // menu, não volta do formulário por card. O teto continua pegando a
        // regressão que este teste vigia: ela custava +479 KB, que a partir
        // daqui estoura qualquer valor até ~2,2 MB.
        //
        // Recalibrado de novo em 17/08/2026, e pelo mesmo critério: a rolagem
        // automática do arrasto (AC-360) são ~7 KB no <script> do quadro, que
        // é UM por página — 120 tarefas custam os mesmos 7 KB que uma. O antigo
        // teto estava a 1,7 KB do valor medido, perto demais para distinguir o
        // que ele vigia de qualquer linha nova. Com 1,9 MB sobram 44 KB de
        // folga e a regressão de +479 KB continua estourando.
        $this->assertLessThan(1_900_000, $bytes, sprintf(
            'O quadro com 120 tarefas pesa %.1f MB — o formulário de motivo '.
            'voltou a ser impresso por card.', $bytes / 1_048_576
        ));
    }
}
