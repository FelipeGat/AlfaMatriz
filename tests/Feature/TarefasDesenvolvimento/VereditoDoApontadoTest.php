<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\Tarefa;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Apontar quem valida é pedir o exame DESSA pessoa.
 *
 * O apontamento era só aviso: qualquer um carimbava a tarefa apontada para
 * outro, e o card dizia "aprovada" sem a conferência pedida ter acontecido.
 * A trava lê o apontado gravado na passagem, e não o interlocutor, que a
 * conversa reescreve. O admin fica de fora: tem autorização para tudo.
 *
 * O dev é membro em todos os casos: o usuário de fábrica é admin, e um dev
 * admin passaria pela trava sem exercitá-la.
 */
class VereditoDoApontadoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Uma tarefa que acabou de subir para o ar apontada para `$validador`.
     *
     * @return array{0: Tarefa, 1: User}
     */
    private function noArApontadaPara(User $validador): array
    {
        $dev = User::factory()->membro()->create(['name' => 'Rafael Lima']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $dev->id,
            'responsavel_id' => $dev->id,
            'status' => 'em_staging',
        ]);

        TarefaRelatorioTeste::create(['tarefa_id' => $tarefa->id, 'aprovado' => true]);

        $this->actingAs($dev)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_producao', 'de_status' => 'em_staging',
            'versao_producao' => 'v1.4.2', 'interlocutor_id' => $validador->id,
        ])->assertSessionMissing('erro');

        return [$tarefa->fresh(), $dev];
    }

    /**
     * @spec:AC-372 Com examinador apontado, só ele e o admin registram o
     * veredito — nem quem faz triagem sem ser admin passa, e a recusa nomeia
     * o apontado.
     */
    public function test_so_o_apontado_e_o_admin_validam_a_tarefa_apontada(): void
    {
        $validador = User::factory()->membro()->create(['name' => 'Alexandre Souza']);
        [$tarefa] = $this->noArApontadaPara($validador);

        $colega = User::factory()->membro()->create();

        // Triagem sem ser admin: a permissão de organizar o quadro não é a
        // de conferir o trabalho.
        $perfil = Perfil::create(['slug' => 'triagem-sem-admin', 'nome' => 'Triagem sem admin']);

        foreach (['tarefas', 'tarefas_triagem'] as $recurso) {
            $perfil->permissoes()->syncWithoutDetaching([
                Permissao::firstOrCreate(['recurso' => $recurso], ['descricao' => $recurso])->id => [
                    'ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => false, 'excluir' => false,
                ],
            ]);
        }

        $triador = User::factory()->semPerfil()->create();
        $triador->perfis()->attach($perfil->id);
        $this->assertTrue($triador->podeTriarTarefas());
        $this->assertFalse($triador->ehAdmin());

        foreach ([$colega, $triador] as $outro) {
            $this->actingAs($outro)->post(route('tarefas.testar', $tarefa), ['aprovado' => '1'])
                ->assertSessionHas('erro', fn ($erro) => str_contains($erro, 'Alexandre Souza'));
        }

        $this->assertNull($tarefa->fresh()->testeDestaPassagem());

        $this->actingAs($validador)->post(route('tarefas.testar', $tarefa), ['aprovado' => '1'])
            ->assertSessionMissing('erro');

        $this->assertSame($validador->id, $tarefa->fresh()->testeDestaPassagem()->user_id);

        $admin = User::factory()->create();
        $this->assertTrue($admin->ehAdmin());

        $this->actingAs($admin)->post(route('tarefas.testar', $tarefa), ['aprovado' => '1'])
            ->assertSessionMissing('erro');

        $this->assertSame($admin->id, $tarefa->fresh()->testeDestaPassagem()->user_id);
    }

    /**
     * @spec:AC-372 O apontado vale para a passagem: uma pergunta respondida
     * troca o interlocutor, mas não troca quem valida.
     */
    public function test_a_conversa_nao_muda_quem_valida(): void
    {
        $validador = User::factory()->membro()->create();
        [$tarefa, $dev] = $this->noArApontadaPara($validador);

        $this->actingAs($validador)->post(route('tarefas.conversar', $tarefa), ['corpo' => 'Qual cliente testo?']);
        $this->actingAs($dev)->post(route('tarefas.conversar', $tarefa), ['corpo' => 'O da matriz.']);
        $this->actingAs($validador)->post(route('tarefas.conversar', $tarefa), ['corpo' => 'E a filial?']);
        $this->actingAs($dev)->post(route('tarefas.conversar', $tarefa), ['corpo' => 'Também.']);
        $this->actingAs($dev)->post(route('tarefas.conversar', $tarefa), ['corpo' => 'Conseguiu?']);
        $this->actingAs($validador)->post(route('tarefas.conversar', $tarefa), ['corpo' => 'Quase.']);

        $this->assertSame($validador->id, $tarefa->fresh()->apontadoDestaPassagem()?->id);

        $this->actingAs($dev)->post(route('tarefas.testar', $tarefa), ['aprovado' => '1'])
            ->assertSessionHas('erro');

        $this->assertNull($tarefa->fresh()->testeDestaPassagem());
    }

    /**
     * @spec:AC-372 Os botões de veredito só aparecem para o apontado e o
     * admin; os outros leem de quem o card está esperando.
     */
    public function test_so_o_apontado_ve_os_botoes(): void
    {
        $validador = User::factory()->membro()->create(['name' => 'Alexandre Souza']);
        [$tarefa] = $this->noArApontadaPara($validador);
        $colega = User::factory()->membro()->create();

        $this->actingAs($colega)->get(route('tarefas.index'))->assertOk()
            ->assertSee('Validação com')
            ->assertSee('Alexandre Souza')
            ->assertDontSee('Aprovar no ar');

        $this->actingAs($validador)->get(route('tarefas.index'))->assertOk()
            ->assertSee('Aprovar no ar');

        $this->actingAs(User::factory()->create())->get(route('tarefas.index'))->assertOk()
            ->assertSee('Aprovar no ar');
    }

    /**
     * @spec:AC-372 O carimbo do painel de mover é a outra porta do veredito:
     * com testador apontado no staging, quem move não carimba por ele — e,
     * depois de o apontado aprovar, o movimento passa sem sobrescrevê-lo.
     */
    public function test_o_carimbo_do_painel_nao_valida_pelo_apontado(): void
    {
        $dev = User::factory()->membro()->create();
        $testador = User::factory()->membro()->create(['name' => 'Alexandre Souza']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $dev->id, 'responsavel_id' => $dev->id, 'status' => 'em_revisao',
        ]);

        $this->actingAs($dev)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_staging', 'de_status' => 'em_revisao', 'interlocutor_id' => $testador->id,
        ])->assertSessionMissing('erro');

        $subir = fn () => $this->actingAs($dev)->post(route('tarefas.mover', $tarefa->fresh()), [
            'status' => 'em_producao', 'de_status' => 'em_staging',
            'versao_producao' => 'v1.4.2', 'relatorio_aprovado' => '1',
        ]);

        $subir()->assertSessionHas('erro', fn ($erro) => str_contains($erro, 'Alexandre Souza'));
        $this->assertSame('em_staging', $tarefa->fresh()->status);
        $this->assertSame(0, $tarefa->relatoriosTeste()->count());

        $this->actingAs($testador)->post(route('tarefas.testar', $tarefa->fresh()), ['aprovado' => '1']);

        $subir()->assertSessionMissing('erro');
        $this->assertSame('em_producao', $tarefa->fresh()->status);
        $this->assertSame([$testador->id], $tarefa->relatoriosTeste()->pluck('user_id')->all());
    }

    /**
     * @spec:AC-372 Sem apontado a coluna continua fila: qualquer um valida.
     */
    public function test_sem_apontado_qualquer_um_valida(): void
    {
        $dev = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $dev->id, 'responsavel_id' => $dev->id, 'status' => 'em_staging',
        ]);
        $alguem = User::factory()->membro()->create();

        $this->actingAs($alguem)->post(route('tarefas.testar', $tarefa), ['aprovado' => '1'])
            ->assertSessionMissing('erro');

        $this->assertTrue($tarefa->fresh()->testeDestaPassagem()->aprovado);
    }
}
