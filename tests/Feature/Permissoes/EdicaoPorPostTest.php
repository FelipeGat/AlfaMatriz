<?php

namespace Tests\Feature\Permissoes;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mover, dar baixa e bloquear são edição, e o verbo `POST` não muda isso.
 *
 * É a metade da separação que o `ChecarPermissao` não consegue inferir
 * sozinho: ele lê o verbo, e o verbo aqui mente. Sem estas rotas fixando a
 * ação, quem recebesse só "cadastrar" continuaria movendo o quadro inteiro e
 * dando baixa em toda cobrança — e a grade de perfis diria que não.
 */
class EdicaoPorPostTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Alguém com "cadastrar" em tarefas e sem "editar" — inclusive com a
     * capacidade de triagem, para que a recusa não possa ser confundida com
     * falta dela.
     */
    private function quemSoCadastraTarefas(): User
    {
        $perfil = Perfil::create(['slug' => 'so-cadastra-tarefas', 'nome' => 'Só cadastra tarefas']);

        foreach (['tarefas', 'tarefas_triagem'] as $recurso) {
            $permissao = Permissao::firstOrCreate(['recurso' => $recurso], ['descricao' => $recurso]);

            $perfil->permissoes()->syncWithoutDetaching([
                $permissao->id => [
                    'ler' => true,
                    'incluir' => true,
                    'editar' => false,
                    'imprimir' => false,
                    'excluir' => false,
                ],
            ]);
        }

        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->attach($perfil->id);

        return $usuario;
    }

    /**
     * @spec:AC-278 Mover uma tarefa exige a ação editar, mesmo sendo POST —
     * mover mexe no que já existe.
     */
    public function test_mover_tarefa_exige_editar(): void
    {
        $tarefa = Tarefa::factory()->create(['status' => 'aberta']);

        $this->actingAs($this->quemSoCadastraTarefas())
            ->post(route('tarefas.mover', $tarefa), [
                'de_status' => 'aberta',
                'para_status' => 'em_desenvolvimento',
            ])
            ->assertForbidden();

        $this->assertSame('aberta', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-278 Bloquear também: a tarefa não sai do lugar, mas ganha uma
     * marca que ela não tinha.
     */
    public function test_bloquear_tarefa_exige_editar(): void
    {
        $tarefa = Tarefa::factory()->create(['status' => 'em_desenvolvimento']);

        $this->actingAs($this->quemSoCadastraTarefas())
            ->post(route('tarefas.bloquear', $tarefa), ['motivo' => 'esperando resposta'])
            ->assertForbidden();

        $this->assertNull($tarefa->fresh()->bloqueado_em);
    }

    /**
     * @spec:AC-277 A contraprova: quem só cadastra continua cadastrando. Sem
     * ela, este arquivo provaria apenas que a pessoa não pode nada.
     */
    public function test_quem_so_cadastra_ainda_cria_tarefa(): void
    {
        $this->actingAs($this->quemSoCadastraTarefas())
            ->post(route('tarefas.store'), [
                'titulo' => 'Tarefa nova',
                'tipo' => 'operacional',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tarefas', ['titulo' => 'Tarefa nova']);
    }
}
