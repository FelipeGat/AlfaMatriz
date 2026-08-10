<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Sistema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O que a Matriz pode fazer com um sistema é declarado na linha dele, não
 * deduzido do slug. Estes testes protegem as duas pontas: a declaração chegar
 * em produção, e o código perguntar a coisa certa.
 */
class CapacidadesDoSistemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-155 O AlfaGym em produção acorda com as capacidades que já
     * exercia. A carga vive na migration porque o deploy roda `migrate`, não
     * `db:seed` — se dependesse do seeder, publicar a generalização faria as
     * ações de licença sumirem da tela sem nada acusar.
     */
    public function test_backfill_da_migration_da_ao_alfagym_o_que_ele_ja_fazia(): void
    {
        // Volta ao mundo anterior à migration: a coluna não existe e a linha do
        // AlfaGym está lá, como está em produção hoje.
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        DB::table('sistemas')->insert([
            'nome' => 'AlfaGym', 'slug' => 'alfagym', 'categoria' => 'saas',
            'unidade_cobranca' => 'academia ativa', 'ativo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // É a própria migration que precisa preencher — sem seeder nenhum,
        // exatamente como `deploy/publicar.sh` faz.
        $this->artisan('migrate')->assertSuccessful();

        $alfagym = Sistema::where('slug', 'alfagym')->firstOrFail();

        $this->assertTrue($alfagym->suporta('gerencia_licenca'), 'Sem esta capacidade as ações de licença somem da tela do gym.');
        $this->assertTrue($alfagym->suporta('provisiona_revenda'));
        $this->assertTrue($alfagym->suporta('provisiona_cliente'));
        $this->assertTrue($alfagym->suporta('exige_admin_no_cliente'));
        $this->assertTrue($alfagym->suporta('sincroniza'));
    }

    /**
     * @spec:AC-156 Um sistema sem a capacidade declarada não a tem. É o padrão
     * seguro: sistema novo não ganha poder por descuido.
     */
    public function test_sistema_sem_capacidade_declarada_nao_suporta_nada(): void
    {
        $sistema = Sistema::factory()->create();

        $this->assertFalse($sistema->suporta('gerencia_licenca'));
        $this->assertFalse($sistema->suporta('sincroniza'));
    }

    /**
     * @spec:AC-156 Capacidade nula (linha antiga, anterior ao backfill) não
     * quebra — responde "não sabe fazer", em vez de estourar.
     */
    public function test_capacidade_nula_nao_quebra(): void
    {
        $sistema = Sistema::factory()->create();
        DB::table('sistemas')->where('id', $sistema->id)->update(['capacidades' => null]);

        $this->assertFalse($sistema->fresh()->suporta('sincroniza'));
    }

    /**
     * @spec:AC-157 Na Fase 1 o AlfaControl só lê: quem opera revenda, cliente
     * e licença continua sendo o painel dele.
     */
    public function test_alfacontrol_na_fase_1_so_le(): void
    {
        $alfacontrol = Sistema::factory()->alfacontrol()->create();

        $this->assertTrue($alfacontrol->suporta('sincroniza'));
        $this->assertTrue($alfacontrol->suporta('sincroniza_modulos'));

        $this->assertFalse($alfacontrol->suporta('gerencia_licenca'));
        $this->assertFalse($alfacontrol->suporta('provisiona_revenda'));
        $this->assertFalse($alfacontrol->suporta('provisiona_cliente'));
        $this->assertFalse($alfacontrol->suporta('exige_admin_no_cliente'));
    }

    /**
     * @spec:AC-158 A consulta por capacidade acha os sistemas certos — é o que
     * substitui o `where('slug', 'alfagym')` espalhado pelos controllers.
     */
    public function test_consulta_por_capacidade_filtra_os_sistemas(): void
    {
        Sistema::factory()->alfagym()->create();
        Sistema::factory()->alfacontrol()->create();
        Sistema::factory()->create();

        $this->assertSame(
            ['alfacontrol', 'alfagym'],
            Sistema::comCapacidade('sincroniza')->pluck('slug')->sort()->values()->all()
        );

        $this->assertSame(
            ['alfagym'],
            Sistema::comCapacidade('gerencia_licenca')->pluck('slug')->all()
        );
    }

    /**
     * @spec:AC-159 `integravel()` separa "cadastrado" de "pronto para
     * conversar". Sistema sem endereço ou sem chave é o estado normal entre
     * publicar a integração e configurá-la — não é erro, e o sync o ignora.
     */
    public function test_integravel_exige_ativo_endereco_e_chave(): void
    {
        $this->assertFalse(
            Sistema::factory()->create(['base_url' => 'https://x.test', 'token' => null])->integravel(),
            'Sem chave não dá para conversar.'
        );

        $this->assertFalse(
            Sistema::factory()->create(['base_url' => null, 'token' => 'x'])->integravel(),
            'Sem endereço não dá para conversar.'
        );

        $this->assertFalse(
            Sistema::factory()->create(['base_url' => 'https://x.test', 'token' => 'x', 'ativo' => false])->integravel(),
            'Sistema desativado não participa.'
        );

        $this->assertTrue(
            Sistema::factory()->configurado()->create()->integravel()
        );
    }

    /**
     * @spec:AC-160 A factory cria sistemas distintos sem colidir no slug
     * único. Sem isto nenhum teste multi-sistema é possível.
     */
    public function test_factory_cria_varios_sistemas_sem_colidir(): void
    {
        $sistemas = Sistema::factory()->count(3)->create();

        $this->assertCount(3, $sistemas->pluck('slug')->unique());
    }
}
