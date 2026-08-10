<?php

namespace Tests\Feature\RevendaAutoatendimento;

use App\Models\Cliente;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A conferência da migração do AlfaGym.
 *
 * O comando é o porteiro da virada: sai com sucesso só quando não há nenhuma
 * das três divergências que quebrariam a operação depois.
 */
class ConferenciaDaMigracaoTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $alfaGym;

    protected function setUp(): void
    {
        parent::setUp();

        (new PerfilPermissaoSeeder)->run();

        $this->alfaGym = Sistema::factory()->alfagym()->create([
            'slug' => 'alfagym', 'nome' => 'AlfaGym', 'ativo' => true,
        ]);
    }

    /** Uma revenda já com acesso ao painel — o estado "em ordem". */
    private function revendaComAcesso(string $nome): Revenda
    {
        $revenda = Revenda::create(['nome' => $nome, 'ativo' => true]);

        $usuario = User::factory()->semPerfil()->create(['revenda_id' => $revenda->id]);
        $usuario->perfis()->attach(Perfil::where('slug', 'revenda')->value('id'));

        return $revenda;
    }

    /** @spec:AC-108 A conferência aponta as divergências entre Matriz e AlfaGym. */
    public function test_aponta_as_tres_divergencias_separadamente(): void
    {
        $comAcesso = $this->revendaComAcesso('Invest Soluções');

        // 1. cliente sem revenda
        Cliente::create(['nome' => 'Academia Órfã', 'ativo' => true]);

        // 2. cliente licenciado sem âncora de licença
        $semAncora = Cliente::create(['nome' => 'Academia Sem Âncora', 'revenda_id' => $comAcesso->id, 'ativo' => true]);
        $semAncora->sistemas()->attach($this->alfaGym->id, [
            'ativo' => true, 'licenca_status' => 'ativa', 'licenca_fim_em' => '2026-12-31',
        ]);

        // 3. revenda sem acesso ao painel
        Revenda::create(['nome' => 'Revenda Migrada', 'ativo' => true]);

        $this->artisan('alfa:conferir-migracao')
            ->expectsOutputToContain('Academia Órfã')
            ->expectsOutputToContain('Academia Sem Âncora')
            ->expectsOutputToContain('Revenda Migrada')
            ->expectsOutputToContain('3 divergência(s) encontrada(s)')
            ->assertFailed();
    }

    /** @spec:AC-108 Sem divergência, o comando libera a virada. */
    public function test_base_em_ordem_sai_com_sucesso(): void
    {
        $revenda = $this->revendaComAcesso('Invest Soluções');

        $licenciado = Cliente::create(['nome' => 'Academia Certinha', 'revenda_id' => $revenda->id, 'ativo' => true]);
        $licenciado->sistemas()->attach($this->alfaGym->id, [
            'ativo' => true, 'licenca_status' => 'ativa',
            'licenca_fim_em' => '2026-12-31', 'licenca_id_externo' => '9001',
        ]);

        $this->artisan('alfa:conferir-migracao')
            ->expectsOutputToContain('Nenhuma divergência')
            ->assertSuccessful();
    }

    /** @spec:AC-108 Cliente só pendente de licença não conta como âncora faltando. */
    public function test_pendente_de_licenca_nao_e_divergencia(): void
    {
        $revenda = $this->revendaComAcesso('Invest Soluções');

        // Ainda não tem licença: não há o que ancorar. Acusar isso encheria o
        // relatório de ruído e esconderia as divergências de verdade.
        $pendente = Cliente::create(['nome' => 'Academia Pendente', 'revenda_id' => $revenda->id, 'ativo' => true]);
        $pendente->sistemas()->attach($this->alfaGym->id, ['ativo' => true, 'status_saas' => 'pendente']);

        $this->artisan('alfa:conferir-migracao')
            ->expectsOutputToContain('Nenhuma divergência')
            ->assertSuccessful();
    }

    /** @spec:AC-108 A conferência só olha, nunca corrige. */
    public function test_conferencia_nao_altera_nada(): void
    {
        $revenda = Revenda::create(['nome' => 'Revenda Migrada', 'ativo' => true]);
        Cliente::create(['nome' => 'Academia Órfã', 'ativo' => true]);

        $this->artisan('alfa:conferir-migracao')->assertFailed();

        // Quem corrige é o sincronizador ou o comando de acessos — este aqui
        // serve para você decidir, não para decidir por você.
        $this->assertSame(0, User::where('revenda_id', $revenda->id)->count());
        $this->assertNull(Cliente::where('nome', 'Academia Órfã')->firstOrFail()->revenda_id);
    }

    /** @spec:AC-108 Sem o AlfaGym cadastrado, a conferência avisa em vez de mentir. */
    public function test_sem_o_sistema_cadastrado_o_comando_avisa(): void
    {
        $this->alfaGym->delete();

        $this->artisan('alfa:conferir-migracao')
            ->expectsOutputToContain("Sistema 'alfagym' não está cadastrado")
            ->assertFailed();
    }
}
