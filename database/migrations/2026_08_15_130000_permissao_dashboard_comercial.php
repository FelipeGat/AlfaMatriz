<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A porta do Dashboard Comercial, agora que ele tem porta própria.
     *
     * A rota `/comercial` deixou de pedir `dashboard` e passou a pedir
     * `dashboard_comercial` — separação que permite dar painel ao perfil
     * `comercial` sem dar visão do caixa da empresa. Só que a permissão nova
     * precisa EXISTIR em produção: o deploy roda apenas `migrate --force`, e
     * recurso que só existe no seeder não chega lá. Sem esta migração, a
     * troca da rota trancaria o painel para todo mundo — inclusive para quem
     * o via ontem.
     *
     * Daí as duas concessões:
     *
     * 1. Quem já LÊ `dashboard` ganha `dashboard_comercial` com as MESMAS
     *    ações da sua linha de `dashboard` — e não as do seeder: a grade é
     *    editável pela tela de usuários, e copiar a linha real preserva o
     *    ajuste feito à mão, enquanto o seeder preservaria só o estado
     *    inicial. Era exatamente o acesso que essas contas tinham quando a
     *    rota pedia `dashboard`.
     *
     * 2. O perfil `comercial` ganha só `ler` e `imprimir`: o painel dele é
     *    consulta ao próprio desempenho. Definir meta é gestão e continua
     *    atrás de `dashboard` (ver a rota `comercial.metas.salvar`).
     */
    public function up(): void
    {
        $permissaoId = DB::table('permissoes')->where('recurso', 'dashboard_comercial')->value('id');

        if (! $permissaoId) {
            $permissaoId = DB::table('permissoes')->insertGetId([
                'recurso' => 'dashboard_comercial',
                // Mesma descrição do `PerfilPermissaoSeeder`, de propósito: é o
                // rótulo da grade, e duas redações fariam a linha mudar de nome
                // conforme quem semeou o banco.
                'descricao' => 'Dashboard Comercial (funil, metas, vendas por vendedor)',
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

        $comercialId = DB::table('perfis')->where('slug', 'comercial')->value('id');

        if ($comercialId) {
            $this->conceder($permissaoId, (int) $comercialId, [
                'ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => true, 'excluir' => false,
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
        $permissaoId = DB::table('permissoes')->where('recurso', 'dashboard_comercial')->value('id');

        if (! $permissaoId) {
            return;
        }

        // Como na volta da permissão de auditoria: `dashboard_comercial`
        // nasceu aqui e não é vocabulário de mais ninguém — deixá-la para trás
        // seria uma linha órfã na grade, oferecendo uma tela que a volta
        // acabou de trancar de novo atrás de `dashboard`.
        DB::table('perfil_permissao')->where('permissao_id', $permissaoId)->delete();
        DB::table('permissoes')->where('id', $permissaoId)->delete();
    }
};
