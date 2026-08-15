<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Console\Commands\PortaoDoStaging;
use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O portão do staging refletido no quadro (US-086).
 *
 * Reprovado, o staging fica na versão anterior e o card em Em staging passa a
 * mentir — "pensa que está no staging e não está". O comando chamado pelo
 * deploy bloqueia a coluna com o motivo padrão e, quando o portão volta a
 * passar, destrava exatamente o que ele mesmo travou. Bloqueio de gente é de
 * gente: o robô não toca.
 */
class PortaoDoStagingTest extends TestCase
{
    use RefreshDatabase;

    private function emStaging(array $atributos = []): Tarefa
    {
        return Tarefa::factory()->create(array_merge([
            'criado_por_id' => User::factory(),
            'responsavel_id' => User::factory(),
            'status' => 'em_staging',
        ], $atributos));
    }

    /**
     * @spec:AC-311 O portão reprovado bloqueia quem está em Em staging com o motivo
     * padrão — e o bloqueio avisa no sino, como qualquer bloqueio.
     */
    public function test_reprovou_bloqueia_a_coluna_com_o_motivo_padrao_e_avisa(): void
    {
        $umaEmStaging = $this->emStaging();
        $outraEmStaging = $this->emStaging();
        $foraDoStaging = Tarefa::factory()->create([
            'criado_por_id' => User::factory(),
            'responsavel_id' => User::factory(),
            'status' => 'em_desenvolvimento',
        ]);

        $this->artisan('alfa:portao-staging', ['veredito' => 'reprovou'])->assertExitCode(0);

        foreach ([$umaEmStaging, $outraEmStaging] as $tarefa) {
            $tarefa->refresh();

            $this->assertTrue($tarefa->estaBloqueada());
            $this->assertSame(PortaoDoStaging::MOTIVO, $tarefa->bloqueio_motivo);

            // A etapa não mudou: bloquear é marca, não movimento.
            $this->assertSame('em_staging', $tarefa->status);
        }

        // Quem não está na coluna não é do portão.
        $this->assertFalse($foraDoStaging->fresh()->estaBloqueada());

        // Sem sessão, ninguém "bloqueou": o responsável recebe o aviso.
        $this->assertNotNull(
            Notificacao::where('tipo', 'bloqueio')
                ->where('destinatario_id', $umaEmStaging->responsavel_id)
                ->first(),
        );
    }

    /**
     * @spec:AC-312 O bloqueio manual fica intacto: o motivo é de gente e vale mais
     * que o do robô — nem o relógio é tocado, em nenhum dos dois vereditos.
     */
    public function test_bloqueio_manual_fica_intacto_nos_dois_vereditos(): void
    {
        $bloqueadaPorGente = $this->emStaging();
        $bloqueadaPorGente->forceFill([
            'bloqueado_em' => now()->subDays(2)->startOfSecond(),
            'bloqueio_motivo' => 'Esperando o cliente validar o boleto.',
        ])->save();

        $relogio = $bloqueadaPorGente->fresh()->bloqueado_em;

        $this->artisan('alfa:portao-staging', ['veredito' => 'reprovou'])->assertExitCode(0);
        $this->artisan('alfa:portao-staging', ['veredito' => 'passou'])->assertExitCode(0);

        $bloqueadaPorGente->refresh();

        $this->assertTrue($bloqueadaPorGente->estaBloqueada());
        $this->assertSame('Esperando o cliente validar o boleto.', $bloqueadaPorGente->bloqueio_motivo);
        $this->assertTrue($relogio->equalTo($bloqueadaPorGente->bloqueado_em));
    }

    /**
     * @spec:AC-313 O portão que passa destrava o que ele mesmo travou — só as
     * tarefas com o motivo padrão; o bloqueio manual permanece.
     */
    public function test_passou_destrava_so_o_que_o_portao_travou(): void
    {
        $travadaPeloPortao = $this->emStaging();
        $travadaPorGente = $this->emStaging();
        $travadaPorGente->forceFill([
            'bloqueado_em' => now(),
            'bloqueio_motivo' => 'Esperando a credencial do financeiro.',
        ])->save();

        $this->artisan('alfa:portao-staging', ['veredito' => 'reprovou'])->assertExitCode(0);
        $this->assertTrue($travadaPeloPortao->fresh()->estaBloqueada());

        $this->artisan('alfa:portao-staging', ['veredito' => 'passou'])->assertExitCode(0);

        $this->assertFalse($travadaPeloPortao->fresh()->estaBloqueada());
        $this->assertTrue($travadaPorGente->fresh()->estaBloqueada());
    }
}
