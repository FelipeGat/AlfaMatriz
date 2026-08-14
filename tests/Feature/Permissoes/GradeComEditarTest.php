<?php

namespace Tests\Feature\Permissoes;

use App\Models\Lead;
use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cadastrar e editar deixam de ser a mesma coisa.
 *
 * O teste usa leads porque é o recurso mais simples que tem as duas portas
 * abertas na mesma tela — cadastrar e salvar um já cadastrado —, então a
 * diferença entre as duas ações aparece sem nenhum outro estado por perto.
 */
class GradeComEditarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Alguém que registra o que chega e não reescreve o que já está
     * registrado — o perfil que a separação existe para tornar possível.
     */
    private function quemSoCadastra(): User
    {
        $perfil = Perfil::create(['slug' => 'so-cadastra', 'nome' => 'Só cadastra']);
        $permissao = Permissao::firstOrCreate(['recurso' => 'leads'], ['descricao' => 'Leads']);

        $perfil->permissoes()->syncWithoutDetaching([
            $permissao->id => [
                'ler' => true,
                'incluir' => true,
                'editar' => false,
                'imprimir' => false,
                'excluir' => false,
            ],
        ]);

        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->attach($perfil->id);

        return $usuario;
    }

    /**
     * @spec:AC-275 A grade de perfis passa a ter cinco caixas, e o que o
     * administrador marca em Editar é salvo e relido.
     */
    public function test_a_grade_tem_a_caixa_editar_e_ela_e_salva(): void
    {
        // A factory já entrega a conta com o perfil Administrador — é o padrão
        // deste repositório, e existe um `semPerfil()` justamente para o caso
        // contrário.
        $admin = User::factory()->create();

        $perfil = Perfil::create(['slug' => 'para-marcar', 'nome' => 'Para marcar']);
        $permissao = Permissao::firstOrCreate(['recurso' => 'leads'], ['descricao' => 'Leads']);

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['aba' => 'perfis']))
            ->assertOk()
            ->assertSee('Editar', escape: false);

        $this->actingAs($admin)->put(route('perfis.permissoes', $perfil), [
            'grade' => ['leads' => ['ler' => '1', 'editar' => '1']],
        ]);

        $pivot = $perfil->fresh()->permissoes()->where('permissoes.id', $permissao->id)->first()->pivot;

        $this->assertTrue((bool) $pivot->editar, 'A caixa Editar precisa ser salva.');
        $this->assertFalse((bool) $pivot->incluir, 'Marcar Editar não pode marcar Incluir junto.');
    }

    /**
     * @spec:AC-276 Sem a ação editar, alterar um registro que já existe é
     * recusado — é a separação fazendo o que foi pedida.
     */
    public function test_sem_editar_alterar_registro_existente_e_recusado(): void
    {
        $lead = Lead::create([
            'nome' => 'Nome original',
            'tipo_interesse' => 'saas',
            'estagio' => 'lead',
            'estagio_atualizado_em' => now(),
        ]);

        $this->actingAs($this->quemSoCadastra())
            ->put(route('leads.update', $lead), [
                'nome' => 'Nome trocado',
                'tipo_interesse' => 'saas',
            ])
            ->assertForbidden();

        $this->assertSame('Nome original', $lead->fresh()->nome);
    }

    /**
     * @spec:AC-277 Tirar a edição não tira o cadastro: quem só cadastra
     * continua cadastrando.
     */
    public function test_sem_editar_cadastrar_continua_funcionando(): void
    {
        $this->actingAs($this->quemSoCadastra())
            ->post(route('leads.store'), [
                'nome' => 'Lead novo',
                'tipo_interesse' => 'saas',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('leads', ['nome' => 'Lead novo']);
    }
}
