<?php

namespace Tests\Feature\Agenda;

use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O contador do sino que atualiza sozinho.
 *
 * O `resumo` é o que o poll do shell busca a cada ~45s: o número da bolinha e o
 * maior id, que é como o navegador percebe que CHEGOU algo novo em vez de só
 * "ainda há não lidas".
 */
class SinoAoVivoTest extends TestCase
{
    use RefreshDatabase;

    private function avisar(User $u, bool $lida = false): Notificacao
    {
        $n = Notificacao::create([
            'destinatario_id' => $u->id,
            'tipo' => 'lembrete',
            'nivel' => 'atencao',
            'icone' => 'clock',
            'titulo' => 'Um aviso',
        ]);

        // `lida_em` fica fora do fillable de propósito (marcar lida passa pelo
        // controller), então aqui é atribuição direta, não mass-assignment.
        if ($lida) {
            $n->lida_em = now();
            $n->save();
        }

        return $n;
    }

    public function test_resumo_conta_as_nao_lidas_e_da_o_ultimo_id(): void
    {
        $ana = User::factory()->create();
        $this->avisar($ana, lida: true);       // lida: não conta
        $this->avisar($ana);                   // não lida
        $ultima = $this->avisar($ana);         // não lida, a mais recente

        $this->actingAs($ana)
            ->getJson(route('notificacoes.resumo'))
            ->assertOk()
            ->assertJson([
                'nao_lidas' => 2,
                'ultimo_id' => $ultima->id,
                // A última vem pronta para o card mostrar título e prévia.
                'ultima' => ['titulo' => 'Um aviso'],
            ]);
    }

    /** O resumo é de CADA um: o contador de uma pessoa não conta o da outra. */
    public function test_resumo_e_escopado_por_pessoa(): void
    {
        $ana = User::factory()->create();
        $beto = User::factory()->create();
        $this->avisar($ana);
        $this->avisar($beto);

        $this->actingAs($beto)
            ->getJson(route('notificacoes.resumo'))
            ->assertOk()
            ->assertJson(['nao_lidas' => 1]);
    }

    public function test_sem_notificacao_o_resumo_zera(): void
    {
        $ana = User::factory()->create();

        $this->actingAs($ana)
            ->getJson(route('notificacoes.resumo'))
            ->assertOk()
            ->assertJson(['nao_lidas' => 0, 'ultimo_id' => 0]);
    }
}
