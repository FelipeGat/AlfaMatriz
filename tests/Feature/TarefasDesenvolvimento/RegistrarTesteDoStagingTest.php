<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O teste do staging registrado por quem testa (US-084).
 *
 * No processo do time, quem valida o staging não é o responsável pela tarefa —
 * e o carimbo que só viajava no movimento para Pronta p/ produção deixava esse
 * teste sem onde existir. A rota própria registra o veredito sem mover o card,
 * assina o relatório e avisa o responsável; o portão da produção passa a se
 * apoiar no teste de quem de fato testou.
 */
class RegistrarTesteDoStagingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Uma tarefa de desenvolvimento em Em staging, com dev responsável.
     *
     * @return array{0: Tarefa, 1: User}
     */
    private function emStaging(): array
    {
        $dev = User::factory()->create(['name' => 'Rafael Lima']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $dev->id,
            'responsavel_id' => $dev->id,
            'status' => 'em_staging',
        ]);

        return [$tarefa, $dev];
    }

    /**
     * @spec:AC-303 Qualquer pessoa do quadro registra o teste do staging — sem ser
     * responsável nem fazer triagem — e o card não sai do lugar: registrar não é mover.
     */
    public function test_membro_que_nao_e_responsavel_registra_o_teste_sem_mover_o_card(): void
    {
        [$tarefa, $dev] = $this->emStaging();
        $testador = User::factory()->membro()->create(['name' => 'Alexandre Souza']);

        $this->actingAs($testador)->post(route('tarefas.testar', $tarefa), [
            'aprovado' => '1',
        ])->assertSessionMissing('erro');

        $tarefa->refresh();

        $relatorio = $tarefa->relatoriosTeste()->latest('id')->first();

        $this->assertNotNull($relatorio, 'O registro do teste não é restrito a quem move.');
        $this->assertTrue($relatorio->aprovado);
        $this->assertSame($testador->id, $relatorio->user_id);

        // Registrar não move: a tarefa fica onde está, com o mesmo dono.
        $this->assertSame('em_staging', $tarefa->status);
        $this->assertSame($dev->id, $tarefa->responsavel_id);
    }

    /**
     * @spec:AC-305 O teste aprovado registrado pelo card libera a ida para Pronta p/
     * produção sem carimbar de novo no painel de mover — e sem relatório nenhum a
     * passagem continua recusada, como hoje.
     */
    public function test_teste_aprovado_registrado_libera_a_ida_para_pronta_producao(): void
    {
        [$tarefa, $dev] = $this->emStaging();
        $testador = User::factory()->membro()->create();

        // Sem teste registrado, o portão recusa — nada mudou aqui.
        $this->actingAs($dev)->post(route('tarefas.mover', $tarefa), [
            'status' => 'pronta_producao',
            'de_status' => 'em_staging',
        ]);

        $this->assertSame('em_staging', $tarefa->fresh()->status);

        // O testador registra a aprovação pelo card...
        $this->actingAs($testador)->post(route('tarefas.testar', $tarefa), [
            'aprovado' => '1',
            'notas' => 'Fluxo completo validado no staging.',
        ]);

        // ...e o mesmo movimento passa, sem o carimbo do painel.
        $this->actingAs($dev)->post(route('tarefas.mover', $tarefa->fresh()), [
            'status' => 'pronta_producao',
            'de_status' => 'em_staging',
        ]);

        $this->assertSame('pronta_producao', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-304 O relatório diz quem testou: a assinatura viaja com o registro e
     * o histórico da tarefa mostra o nome ao lado do veredito.
     */
    public function test_o_historico_mostra_quem_testou_ao_lado_do_veredito(): void
    {
        [$tarefa, $dev] = $this->emStaging();
        $testador = User::factory()->membro()->create(['name' => 'Alexandre Souza']);

        $this->actingAs($testador)->post(route('tarefas.testar', $tarefa), [
            'aprovado' => '1',
        ]);

        // A tarefa encerra o ciclo — é no histórico que o veredito vira acervo.
        $this->actingAs($dev)->post(route('tarefas.mover', $tarefa), [
            'status' => 'pronta_producao', 'de_status' => 'em_staging',
        ]);
        $this->actingAs($dev)->post(route('tarefas.mover', $tarefa->fresh()), [
            'status' => 'concluida', 'de_status' => 'pronta_producao', 'versao_producao' => 'v9.9.9',
        ]);

        $this->assertSame('concluida', $tarefa->fresh()->status);

        $this->actingAs($dev)->get(route('tarefas.historico'))
            ->assertSee('Aprovado')
            ->assertSee('por Alexandre Souza');
    }

    /**
     * @spec:AC-306 Reprovar exige dizer o que falhou: reprovação sem notas manda o
     * dev abrir o staging e adivinhar — a mesma regra do retorno de portão.
     */
    public function test_reprovar_sem_notas_e_recusado(): void
    {
        [$tarefa] = $this->emStaging();
        $testador = User::factory()->membro()->create();

        $this->actingAs($testador)->post(route('tarefas.testar', $tarefa), [
            'aprovado' => '0',
        ])->assertSessionHas('erro');

        $this->assertSame(0, $tarefa->relatoriosTeste()->count());

        // Com as notas, o mesmo veredito entra.
        $this->actingAs($testador)->post(route('tarefas.testar', $tarefa), [
            'aprovado' => '0',
            'notas' => 'O boleto sai com o valor antigo no staging.',
        ])->assertSessionMissing('erro');

        $relatorio = $tarefa->relatoriosTeste()->latest('id')->first();

        $this->assertNotNull($relatorio);
        $this->assertFalse($relatorio->aprovado);
    }

    /**
     * @spec:AC-307 Registrar o teste avisa o responsável com o veredito — e quem
     * testa a própria tarefa não recebe aviso de si mesmo.
     */
    public function test_registrar_avisa_o_responsavel_e_nao_avisa_a_si_mesmo(): void
    {
        [$tarefa, $dev] = $this->emStaging();
        $testador = User::factory()->membro()->create(['name' => 'Alexandre Souza']);

        $this->actingAs($testador)->post(route('tarefas.testar', $tarefa), [
            'aprovado' => '0',
            'notas' => 'A tela de cobrança quebra ao filtrar.',
        ]);

        $aviso = Notificacao::where('destinatario_id', $dev->id)->where('tipo', 'teste_staging')->first();

        $this->assertNotNull($aviso, 'O responsável é quem age sobre o veredito — precisa saber dele.');
        $this->assertStringContainsString('reprovou', $aviso->titulo);
        $this->assertSame($tarefa->id, $aviso->tarefa_id);

        // O dev valida a própria tarefa: veredito registrado, sino em silêncio.
        Notificacao::query()->delete();

        $this->actingAs($dev)->post(route('tarefas.testar', $tarefa), [
            'aprovado' => '1',
        ]);

        $this->assertSame(2, $tarefa->relatoriosTeste()->count());
        $this->assertSame(0, Notificacao::count());
    }

    /**
     * @spec:AC-308 Fora do staging não há o que registrar: o teste do staging é
     * sobre o trabalho da etapa Em staging, e a recusa explica isso.
     */
    public function test_fora_do_staging_o_registro_e_recusado(): void
    {
        $testador = User::factory()->membro()->create();

        $emRevisao = Tarefa::factory()->create([
            'criado_por_id' => User::factory(),
            'responsavel_id' => User::factory(),
            'status' => 'em_revisao',
        ]);

        $operacional = Tarefa::factory()->create([
            'criado_por_id' => User::factory(),
            'responsavel_id' => User::factory(),
            'tipo' => 'operacional',
            'status' => 'em_desenvolvimento',
        ]);

        foreach ([$emRevisao, $operacional] as $tarefa) {
            $this->actingAs($testador)->post(route('tarefas.testar', $tarefa), [
                'aprovado' => '1',
            ])->assertSessionHas('erro');

            $this->assertSame(0, $tarefa->relatoriosTeste()->count());
        }
    }
}
