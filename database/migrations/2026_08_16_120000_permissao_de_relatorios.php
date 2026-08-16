<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A porta da aba de Relatórios.
     *
     * A rota `/relatorios` pede `permissao:relatorios`, e a permissão nova
     * precisa EXISTIR em produção: o deploy roda apenas `migrate --force`, e
     * recurso que só existe no seeder não chega lá — sem esta migração a aba
     * nasceria trancada para todo mundo, admin incluído.
     *
     * Quem já LÊ `dashboard` ganha `relatorios` com as MESMAS ações da sua
     * linha de `dashboard` — e não as do seeder: a grade é editável pela tela
     * de usuários, e copiar a linha real preserva o ajuste feito à mão. É o
     * mesmo desenho de `2026_08_15_130000_permissao_dashboard_comercial.php`,
     * pela mesma razão — a seção financeira dos relatórios mostra o dinheiro
     * da casa, então a régua de entrada é a de quem já vê esses painéis.
     */
    public function up(): void
    {
        $permissaoId = DB::table('permissoes')->where('recurso', 'relatorios')->value('id');

        if (! $permissaoId) {
            $permissaoId = DB::table('permissoes')->insertGetId([
                'recurso' => 'relatorios',
                // Mesma descrição do `PerfilPermissaoSeeder`, de propósito: é o
                // rótulo da grade, e duas redações fariam a linha mudar de nome
                // conforme quem semeou o banco.
                'descricao' => 'Relatórios (comercial, financeiro, desenvolvimento e sistema)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $linhasDeDashboard = DB::table('perfil_permissao')
            ->join('permissoes', 'permissoes.id', '=', 'perfil_permissao.permissao_id')
            ->where('permissoes.recurso', 'dashboard')
            ->where('perfil_permissao.ler', true)
            ->select('perfil_permissao.*')
            ->get();

        foreach ($linhasDeDashboard as $linha) {
            $this->conceder($permissaoId, (int) $linha->perfil_id, [
                'ler' => (bool) $linha->ler,
                'incluir' => (bool) $linha->incluir,
                'editar' => (bool) $linha->editar,
                'imprimir' => (bool) $linha->imprimir,
                'excluir' => (bool) $linha->excluir,
            ]);
        }
    }

    /** Concede sem sobrescrever: linha que já existe foi ajustada na grade e fica como está. */
    private function conceder(int $permissaoId, int $perfilId, array $acoes): void
    {
        $jaExiste = DB::table('perfil_permissao')
            ->where('perfil_id', $perfilId)
            ->where('permissao_id', $permissaoId)
            ->exists();

        if ($jaExiste) {
            return;
        }

        DB::table('perfil_permissao')->insert([
            'perfil_id' => $perfilId,
            'permissao_id' => $permissaoId,
        ] + $acoes);
    }

    public function down(): void
    {
        $permissaoId = DB::table('permissoes')->where('recurso', 'relatorios')->value('id');

        if (! $permissaoId) {
            return;
        }

        // Como na volta de `dashboard_comercial`: `relatorios` nasceu aqui e
        // não é vocabulário de mais ninguém — deixá-la para trás seria uma
        // linha órfã na grade, oferecendo uma tela que a volta acabou de
        // trancar.
        DB::table('perfil_permissao')->where('permissao_id', $permissaoId)->delete();
        DB::table('permissoes')->where('id', $permissaoId)->delete();
    }
};
