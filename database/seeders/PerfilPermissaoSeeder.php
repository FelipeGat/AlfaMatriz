<?php

namespace Database\Seeders;

use App\Models\Perfil;
use App\Models\Permissao;
use Illuminate\Database\Seeder;

class PerfilPermissaoSeeder extends Seeder
{
    public function run(): void
    {
        $recursos = [
            'usuarios' => 'Usuários do sistema',
            'revendas' => 'Revendas',
            'clientes' => 'Clientes',
            'sistemas' => 'Sistemas e preços de atacado',
            'cobrancas' => 'Receitas / contas a receber',
            'contas_pagar' => 'Despesas / contas a pagar',
            'financeiro' => 'Caixa / contas financeiras',
            'dashboard' => 'Dashboard',
            'leads' => 'Funil de vendas / leads',
            'faturamento' => 'Faturamento',
        ];

        foreach ($recursos as $slug => $descricao) {
            Permissao::updateOrCreate(['recurso' => $slug], ['descricao' => $descricao]);
        }

        $admin = Perfil::updateOrCreate(['slug' => 'admin'], ['nome' => 'Administrador']);
        $financeiro = Perfil::updateOrCreate(['slug' => 'financeiro'], ['nome' => 'Financeiro']);
        $operacao = Perfil::updateOrCreate(['slug' => 'operacao'], ['nome' => 'Operação']);

        $todasPermissoes = Permissao::pluck('id', 'recurso');

        foreach ($todasPermissoes as $permissaoId) {
            $admin->permissoes()->syncWithoutDetaching([
                $permissaoId => ['ler' => true, 'incluir' => true, 'imprimir' => true, 'excluir' => true],
            ]);
        }

        foreach (['cobrancas', 'contas_pagar', 'financeiro', 'dashboard'] as $recurso) {
            $financeiro->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => true, 'imprimir' => true, 'excluir' => false],
            ]);
        }
        foreach (['revendas', 'clientes', 'sistemas'] as $recurso) {
            $financeiro->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => false, 'imprimir' => true, 'excluir' => false],
            ]);
        }

        foreach (['revendas', 'clientes', 'sistemas', 'dashboard'] as $recurso) {
            $operacao->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => true, 'imprimir' => true, 'excluir' => false],
            ]);
        }
        foreach (['cobrancas', 'contas_pagar', 'financeiro'] as $recurso) {
            $operacao->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => false, 'imprimir' => true, 'excluir' => false],
            ]);
        }
        foreach (['leads', 'faturamento'] as $recurso) {
            $operacao->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => true, 'imprimir' => true, 'excluir' => false],
            ]);
        }
        foreach (['leads', 'faturamento'] as $recurso) {
            $financeiro->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => false, 'imprimir' => true, 'excluir' => false],
            ]);
        }
    }
}
