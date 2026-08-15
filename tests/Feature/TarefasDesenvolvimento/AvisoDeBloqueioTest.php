<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O bloqueio avisa no sino (US-085).
 *
 * A tarja âmbar só aparece para quem ABRE o quadro — e "staging quebrou" não
 * pode esperar alguém abrir. O aviso vai para quem pode agir: o responsável
 * (é o trabalho dele que parou) e quem faz triagem (é quem vai atrás do
 * impedimento). Quem bloqueou já sabe o que fez.
 */
class AvisoDeBloqueioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-309 Bloquear avisa o responsável e quem triaga, com o título da
     * tarefa e o motivo — menos quem bloqueou.
     */
    public function test_bloquear_avisa_responsavel_e_quem_triaga_menos_quem_bloqueou(): void
    {
        $dev = User::factory()->membro()->create(['name' => 'Rafael Lima']);
        $adminA = User::factory()->create(['name' => 'Camila Reis']);
        $adminB = User::factory()->create(['name' => 'Bruno Costa']);
        $testador = User::factory()->membro()->create(['name' => 'Alexandre Souza']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $adminA->id,
            'responsavel_id' => $dev->id,
            'status' => 'em_staging',
        ]);

        $this->actingAs($testador)->post(route('tarefas.bloquear', $tarefa), [
            'motivo' => 'Staging não subiu — erro 500 no deploy.',
        ]);

        $avisos = Notificacao::where('tipo', 'bloqueio')->get();

        // Responsável e os dois admins; o testador que bloqueou, não.
        $this->assertEqualsCanonicalizing(
            [$dev->id, $adminA->id, $adminB->id],
            $avisos->pluck('destinatario_id')->all(),
        );

        $aviso = $avisos->firstWhere('destinatario_id', $dev->id);

        $this->assertStringContainsString($tarefa->titulo, $aviso->titulo);
        $this->assertStringContainsString('erro 500', $aviso->meta);
        $this->assertSame($tarefa->id, $aviso->tarefa_id);
    }

    /**
     * @spec:AC-310 Ninguém é avisado duas vezes nem se auto-avisa: o responsável
     * que também triaga recebe um aviso só, e quem bloqueou não recebe nenhum.
     */
    public function test_ninguem_recebe_duas_vezes_nem_se_auto_avisa(): void
    {
        // O dev é admin (triaga E é responsável) e bloqueia a própria tarefa.
        $dev = User::factory()->create(['name' => 'Camila Reis']);
        $outroAdmin = User::factory()->create(['name' => 'Bruno Costa']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $dev->id,
            'responsavel_id' => $dev->id,
            'status' => 'em_desenvolvimento',
        ]);

        $this->actingAs($dev)->post(route('tarefas.bloquear', $tarefa), [
            'motivo' => 'Esperando a credencial do financeiro.',
        ]);

        $avisos = Notificacao::where('tipo', 'bloqueio')->get();

        // Só o outro admin — uma vez. Quem bloqueou não se auto-avisa, mesmo
        // sendo responsável e triagem ao mesmo tempo.
        $this->assertSame([$outroAdmin->id], $avisos->pluck('destinatario_id')->all());
    }
}
