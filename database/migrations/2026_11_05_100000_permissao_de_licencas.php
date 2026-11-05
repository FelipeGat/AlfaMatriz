<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Licença ganha porta própria (T-582, 05/11/2026).
     *
     * Liberar, renovar, suspender e reativar exigiam `clientes,editar`, que dá
     * junto o cadastro inteiro do cliente. O dono quer que o financeiro
     * suspenda quem não paga sem reescrever cadastro, então:
     *
     * - `licencas` vira recurso: `incluir` libera e renova (levam tipo e
     *   valor), `editar` suspende e reativa (só ligam e desligam o acesso).
     * - Quem já podia continua podendo: todo perfil com `clientes,editar`
     *   recebe as duas ações. Sem isso, o deploy tiraria o botão de quem
     *   opera licença hoje. A revenda fica de fora: licença é decisão da Alfa,
     *   e o controller já a barrava (`exigirDecisaoDaAlfa`) — a grade só para
     *   de prometer o que a tela recusa.
     * - Perfil novo, "Financeiro – Licenças", decisão do dono (em vez de
     *   ampliar o Financeiro, para ninguém ganhar o poder sem ser escolhido).
     *   Clientes, sistemas e revendas só leitura, que é o que a lista de
     *   clientes precisa para abrir.
     *
     * Vai em migração porque o deploy roda só `migrate --force`.
     */
    public function up(): void
    {
        $licencas = $this->recurso('licencas', 'Licenças dos clientes (liberar, renovar, suspender e reativar)');

        $quemEditaClientes = DB::table('perfil_permissao')
            ->join('permissoes', 'permissoes.id', '=', 'perfil_permissao.permissao_id')
            ->join('perfis', 'perfis.id', '=', 'perfil_permissao.perfil_id')
            ->where('permissoes.recurso', 'clientes')
            ->where('perfil_permissao.editar', true)
            ->where('perfis.slug', '!=', 'revenda')
            ->pluck('perfil_permissao.perfil_id');

        foreach ($quemEditaClientes as $perfilId) {
            $this->conceder((int) $perfilId, $licencas, ['ler' => true, 'incluir' => true, 'editar' => true]);
        }

        $admin = DB::table('perfis')->where('slug', 'admin')->value('id');

        if ($admin) {
            // Mesma linha que o seeder dá ao Administrador em todo recurso.
            $this->conceder((int) $admin, $licencas, ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => true]);
        }

        $perfil = DB::table('perfis')->where('slug', 'financeiro_licencas')->value('id');

        if (! $perfil) {
            $perfil = DB::table('perfis')->insertGetId([
                'slug' => 'financeiro_licencas',
                'nome' => 'Financeiro – Licenças',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->conceder((int) $perfil, $licencas, ['ler' => true, 'incluir' => true, 'editar' => true]);

        $leitura = [
            'clientes' => 'Clientes',
            'sistemas' => 'Sistemas e preços de atacado',
            'revendas' => 'Revendas',
        ];

        foreach ($leitura as $recurso => $descricao) {
            $this->conceder((int) $perfil, $this->recurso($recurso, $descricao), ['ler' => true, 'imprimir' => true]);
        }
    }

    public function down(): void
    {
        // Volta a regra de antes nas rotas (`clientes,editar`), então nada
        // de quem já editava clientes se perde. A conta fica; só o perfil sai
        // de perto dela, como em `2026_08_12_180000_criar_perfil_comercial`.
        $perfil = DB::table('perfis')->where('slug', 'financeiro_licencas')->value('id');

        if ($perfil) {
            DB::table('perfil_user')->where('perfil_id', $perfil)->delete();
            DB::table('perfil_permissao')->where('perfil_id', $perfil)->delete();
            DB::table('perfis')->where('id', $perfil)->delete();
        }

        // `licencas` nasceu aqui: deixá-lo seria linha órfã na grade.
        $ids = DB::table('permissoes')->where('recurso', 'licencas')->pluck('id');

        DB::table('perfil_permissao')->whereIn('permissao_id', $ids)->delete();
        DB::table('permissoes')->whereIn('id', $ids)->delete();
    }

    private function recurso(string $recurso, string $descricao): int
    {
        $id = DB::table('permissoes')->where('recurso', $recurso)->value('id');

        if ($id) {
            return (int) $id;
        }

        // Mesma descrição do `PerfilPermissaoSeeder`: é o rótulo da grade.
        return (int) DB::table('permissoes')->insertGetId([
            'recurso' => $recurso,
            'descricao' => $descricao,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, bool>  $acoes  o que não vier fica negado
     */
    private function conceder(int $perfilId, int $permissaoId, array $acoes): void
    {
        // Linha que já existe foi ajustada na grade e fica como está.
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
            'ler' => $acoes['ler'] ?? false,
            'incluir' => $acoes['incluir'] ?? false,
            'editar' => $acoes['editar'] ?? false,
            'imprimir' => $acoes['imprimir'] ?? false,
            'excluir' => $acoes['excluir'] ?? false,
        ]);
    }
};
