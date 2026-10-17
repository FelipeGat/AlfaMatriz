<?php

namespace Tests\Feature\Monitor;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A migração das portas do Monitor (#394) — é ela, e não o seeder, que leva
 * as permissões a produção.
 */
class PermissoesDoMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function migracao(): object
    {
        return require database_path('migrations/2026_10_17_150000_permissoes_do_monitor.php');
    }

    private function perfil(string $slug): int
    {
        // Alguns perfis (o de exibição, o comercial) já nascem de migração.
        return (int) (DB::table('perfis')->where('slug', $slug)->value('id')
            ?? DB::table('perfis')->insertGetId(['slug' => $slug, 'nome' => ucfirst($slug), 'created_at' => now(), 'updated_at' => now()]));
    }

    private function linha(int $perfil, string $recurso): ?object
    {
        return DB::table('perfil_permissao')
            ->join('permissoes', 'permissoes.id', '=', 'perfil_permissao.permissao_id')
            ->where('perfil_permissao.perfil_id', $perfil)
            ->where('permissoes.recurso', $recurso)
            ->first(['perfil_permissao.*']);
    }

    public function test_quem_le_a_manutencao_ganha_o_monitor_e_so_o_admin_a_hostinger(): void
    {
        $this->migracao()->down();

        $admin = $this->perfil('admin');
        $membro = $this->perfil('membro');
        $exibicao = $this->perfil('exibicao');

        $manutencao = DB::table('permissoes')->where('recurso', 'manutencao')->value('id')
            ?? DB::table('permissoes')->insertGetId(['recurso' => 'manutencao', 'descricao' => 'Manutenção', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([$admin, $membro] as $perfil) {
            DB::table('perfil_permissao')->insert([
                'perfil_id' => $perfil, 'permissao_id' => $manutencao,
                'ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => $perfil === $admin, 'excluir' => false,
            ]);
        }

        $this->migracao()->up();

        $this->assertTrue((bool) $this->linha($membro, 'monitor')->ler);
        $this->assertFalse((bool) $this->linha($membro, 'monitor')->editar, 'as telas só leem');
        $this->assertFalse((bool) $this->linha($membro, 'monitor')->imprimir, 'imprimir vem da linha da Manutenção');
        $this->assertNull($this->linha($membro, 'monitor_hostinger'));

        $this->assertTrue((bool) $this->linha($admin, 'monitor')->imprimir);
        $this->assertTrue((bool) $this->linha($admin, 'monitor_hostinger')->ler);

        $this->assertNull($this->linha($exibicao, 'monitor'), 'quem não lê a Manutenção não ganha o Monitor');
    }

    public function test_rodar_de_novo_nao_duplica_nem_desfaz_ajuste_da_grade(): void
    {
        $this->migracao()->down();
        $admin = $this->perfil('admin');

        $this->migracao()->up();

        DB::table('perfil_permissao')
            ->where('perfil_id', $admin)
            ->where('permissao_id', DB::table('permissoes')->where('recurso', 'monitor_hostinger')->value('id'))
            ->update(['ler' => false]);

        $this->migracao()->up();

        $this->assertSame(1, DB::table('permissoes')->where('recurso', 'monitor')->count());
        $this->assertFalse((bool) $this->linha($admin, 'monitor_hostinger')->ler);
    }

    public function test_reverter_tira_as_duas_portas(): void
    {
        $this->migracao()->up();
        $this->migracao()->down();

        $this->assertSame(0, DB::table('permissoes')->whereIn('recurso', ['monitor', 'monitor_hostinger'])->count());
    }
}
