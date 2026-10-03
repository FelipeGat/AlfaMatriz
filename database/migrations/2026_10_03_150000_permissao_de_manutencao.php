<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A porta da tela de Manutenção e atualizações (#224).
     *
     * A tela nasceu como promessa, sem `permissao:` — informativa, sem dado.
     * Com a aba Erros ela passa a mostrar a mensagem crua do log dos sistemas
     * e a calar erro do vigia, e por isso ganha recurso próprio. A permissão
     * precisa EXISTIR em produção: o deploy roda só `migrate --force`, e sem
     * esta migração a tela nasceria trancada para todo mundo, admin incluído.
     * Mesmo desenho de `2026_09_18_092000_permissao_de_agenda.php`.
     *
     * A régua é a da Agenda: quem EDITA `tarefas`. Quem conserta o erro é quem
     * mexe no quadro, e o perfil de exibição — o monitor da parede, que só lê
     * o quadro — fica de fora, porque log de sistema exposto na sala é o que
     * ninguém negociou ao pendurar o monitor.
     *
     * As ações vêm da linha REAL de `tarefas` de cada perfil, não do seeder: a
     * grade é editável pela tela de usuários, e copiar a linha viva preserva o
     * ajuste feito à mão. O admin entra pela mesma régua (edita tarefas).
     */
    public function up(): void
    {
        $permissaoId = DB::table('permissoes')->where('recurso', 'manutencao')->value('id');

        if (! $permissaoId) {
            $permissaoId = DB::table('permissoes')->insertGetId([
                'recurso' => 'manutencao',
                // Mesma descrição do `PerfilPermissaoSeeder`: é o rótulo da
                // grade, e duas redações fariam a linha mudar de nome conforme
                // quem semeou o banco.
                'descricao' => 'Manutenção e atualizações (erros dos sistemas, changelog e janelas)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $linhasDeTarefas = DB::table('perfil_permissao')
            ->join('permissoes', 'permissoes.id', '=', 'perfil_permissao.permissao_id')
            ->where('permissoes.recurso', 'tarefas')
            ->where('perfil_permissao.editar', true)
            ->select('perfil_permissao.*')
            ->get();

        foreach ($linhasDeTarefas as $linha) {
            $jaExiste = DB::table('perfil_permissao')
                ->where('perfil_id', $linha->perfil_id)
                ->where('permissao_id', $permissaoId)
                ->exists();

            // Linha que já existe foi ajustada na grade e fica como está.
            if ($jaExiste) {
                continue;
            }

            DB::table('perfil_permissao')->insert([
                'perfil_id' => $linha->perfil_id,
                'permissao_id' => $permissaoId,
                'ler' => (bool) $linha->ler,
                'incluir' => (bool) $linha->incluir,
                'editar' => (bool) $linha->editar,
                'imprimir' => (bool) $linha->imprimir,
                'excluir' => (bool) $linha->excluir,
            ]);
        }
    }

    public function down(): void
    {
        $permissaoId = DB::table('permissoes')->where('recurso', 'manutencao')->value('id');

        if (! $permissaoId) {
            return;
        }

        // `manutencao` nasceu aqui: deixá-la seria uma linha órfã na grade.
        DB::table('perfil_permissao')->where('permissao_id', $permissaoId)->delete();
        DB::table('permissoes')->where('id', $permissaoId)->delete();
    }
};
