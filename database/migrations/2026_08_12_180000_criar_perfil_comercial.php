<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Quem vende não é quem opera.
     *
     * O perfil mais próximo era `operacao`, e ele carrega o negócio da Alfa
     * junto: sistemas e preço de atacado, faturamento das revendas, receitas,
     * despesas e os painéis. Quem vende não precisa de nada disso para vender
     * — e o Centro de Controle abre com "Saldo em caixa" na primeira fileira.
     *
     * Daí o perfil ser curto de propósito, como o da revenda: funil, clientes
     * e revendas. Sem painel nenhum, porque os dois que existem falam de
     * dinheiro — o Centro de Controle mostra caixa e receita recorrente, e o
     * Comercial mostra o MRR de atacado.
     *
     * Vai em MIGRAÇÃO, e não só no seeder, porque o deploy roda apenas
     * `migrate --force`: perfil semeado não chega a produção.
     */
    public function up(): void
    {
        $perfilId = DB::table('perfis')->where('slug', 'comercial')->value('id');

        if (! $perfilId) {
            $perfilId = DB::table('perfis')->insertGetId([
                'slug' => 'comercial',
                'nome' => 'Comercial',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Os recursos são criados aqui se faltarem, e não lidos e pronto: a
        // tabela `permissoes` é povoada pelo SEEDER, que não roda em produção.
        // Lendo apenas, esta migração criava o perfil e parava — um perfil sem
        // permissão nenhuma, que entra no painel e não abre tela alguma. As
        // descrições são as mesmas do `PerfilPermissaoSeeder`, de propósito.
        //
        // Sem excluir: apagar cliente, revenda ou lead desfaz histórico de
        // faturamento e de funil. Quem vende registra e corrige; quem apaga é
        // o administrador.
        $recursos = [
            'leads' => 'Funil de vendas / leads',
            'clientes' => 'Clientes',
            'revendas' => 'Revendas',
        ];

        foreach ($recursos as $recurso => $descricao) {
            $permissaoId = DB::table('permissoes')->where('recurso', $recurso)->value('id');

            if (! $permissaoId) {
                $permissaoId = DB::table('permissoes')->insertGetId([
                    'recurso' => $recurso,
                    'descricao' => $descricao,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $jaExiste = DB::table('perfil_permissao')
                ->where('perfil_id', $perfilId)
                ->where('permissao_id', $permissaoId)
                ->exists();

            if ($jaExiste) {
                continue;
            }

            DB::table('perfil_permissao')->insert([
                'perfil_id' => $perfilId,
                'permissao_id' => $permissaoId,
                'ler' => true,
                'incluir' => true,
                'imprimir' => true,
                'excluir' => false,
            ]);
        }
    }

    public function down(): void
    {
        $perfilId = DB::table('perfis')->where('slug', 'comercial')->value('id');

        if (! $perfilId) {
            return;
        }

        // A conta fica, o perfil sai de perto dela. Apagar o usuário porque o
        // perfil dele deixou de existir seria destruir gente para desfazer uma
        // decisão de acesso — quem reverter isto tem de reatribuir o perfil,
        // não recadastrar a pessoa.
        DB::table('perfil_user')->where('perfil_id', $perfilId)->delete();
        DB::table('perfil_permissao')->where('perfil_id', $perfilId)->delete();
        DB::table('perfis')->where('id', $perfilId)->delete();

        // As linhas de `permissoes` FICAM. `leads`, `clientes` e `revendas` são
        // vocabulário compartilhado — todo perfil aponta para elas —, e apagá-las
        // na volta derrubaria o acesso de quem nunca teve nada a ver com este
        // perfil. A ida as cria se faltarem; a volta não as reivindica.
    }
};
