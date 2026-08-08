<?php

namespace Tests\Feature;

use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisionadorAlfaGymTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $sistema = Sistema::factory()->create([
            'slug' => 'alfagym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-de-teste',
            'ativo' => true,
        ]);

        $revenda = Revenda::create([
            'nome' => 'Alpha Rev',
            'cnpj' => '12.345.678/0001-99',
            'contato_nome' => 'João',
            'contato_email' => 'contato@alpharev.com',
            'ativo' => true,
        ]);

        return compact('sistema', 'revenda');
    }

    private function fakeProvisionamento(): void
    {
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/revendas' => Http::response([
                'contrato' => '1.0',
                'sistema' => 'alfagym',
                'gerado_em' => '2026-08-08T12:00:00-03:00',
                'dados' => [
                    'id_externo' => '42',
                    'nome' => 'Alpha Rev',
                    'admin_id_externo' => '7',
                    'email_admin' => 'admin@alpharev.com',
                ],
            ], 201),
        ]);
    }

    public function test_provisiona_revenda_e_ancora_no_sistema(): void
    {
        ['sistema' => $sistema, 'revenda' => $revenda] = $this->cenario();
        $this->fakeProvisionamento();

        $this->actingAs(User::factory()->create());

        $this->post(route('revendas.provisionar', $revenda), [
            'nome_admin' => 'Admin Alpha',
            'email_admin' => 'admin@alpharev.com',
            'senha_admin' => 'senha-forte-123',
        ])->assertSessionHas('status', 'Revenda Alpha Rev provisionada no AlfaGym.');

        $this->assertDatabaseHas('origens_externas', [
            'entidade_type' => Revenda::class,
            'entidade_id' => $revenda->id,
            'sistema_id' => $sistema->id,
            'id_externo' => '42',
        ]);

        $this->assertSame('42', $revenda->fresh()->idExternoNoSistema($sistema));
    }

    public function test_provisionar_chama_o_gym_com_a_chave_da_matriz(): void
    {
        $this->cenario();
        $revenda = Revenda::firstOrFail();
        $this->actingAs(User::factory()->create());

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/revendas' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym',
                'dados' => ['id_externo' => '42', 'nome' => 'Alpha Rev', 'admin_id_externo' => '7', 'email_admin' => 'a@b.com'],
            ], 201),
        ]);

        $this->post(route('revendas.provisionar', $revenda), [
            'nome_admin' => 'Admin Alpha',
            'email_admin' => 'admin@alpharev.com',
            'senha_admin' => 'senha-forte-123',
        ]);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/matriz/v1/revendas')
                && $request->hasHeader('X-Matriz-Key', 'chave-de-teste')
                && $request['nome_revenda'] === 'Alpha Rev'
                && $request['email_admin'] === 'admin@alpharev.com';
        });
    }

    public function test_nao_provisiona_revenda_ja_ancorada(): void
    {
        ['sistema' => $sistema, 'revenda' => $revenda] = $this->cenario();
        $revenda->ancorarEm($sistema, '9');
        $this->actingAs(User::factory()->create());

        Http::fake();

        $this->post(route('revendas.provisionar', $revenda), [
            'nome_admin' => 'Admin Alpha',
            'email_admin' => 'admin@alpharev.com',
            'senha_admin' => 'senha-forte-123',
        ])->assertSessionHas('erro');

        Http::assertNothingSent();
    }

    public function test_revenda_ja_provisionada_nao_mostra_botao(): void
    {
        ['sistema' => $sistema, 'revenda' => $revenda] = $this->cenario();
        $revenda->ancorarEm($sistema, '42');

        $this->actingAs(User::factory()->create());
        $this->get(route('revendas.index'))->assertOk()
            ->assertDontSee('provisionar-revenda-'.$revenda->id);
    }

    public function test_revenda_sem_ancora_mostra_botao_de_provisionamento(): void
    {
        ['revenda' => $revenda] = $this->cenario();

        $this->actingAs(User::factory()->create());
        $this->get(route('revendas.index'))->assertOk()
            ->assertSee('provisionar-revenda-'.$revenda->id)
            ->assertSee('Provisionar no AlfaGym');
    }
}
