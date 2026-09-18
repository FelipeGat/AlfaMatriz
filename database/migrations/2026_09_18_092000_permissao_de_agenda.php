<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A porta da aba de Agenda.
     *
     * A rota `/agenda` pede `permissao:agenda`, e a permissão nova precisa
     * EXISTIR em produção: o deploy roda apenas `migrate --force`, e recurso que
     * só mora no seeder não chega lá — sem esta migração a aba nasceria trancada
     * para todo mundo, admin incluído. Mesmo desenho de
     * `2026_08_16_120000_permissao_de_relatorios.php`.
     *
     * A régua de entrada é quem EDITA `tarefas`, e não quem a lê. As duas
     * diferenças são deliberadas:
     *
     * - **Copiar quem lê incluiria o perfil de exibição**, o painel de parede.
     *   Ele existe para mostrar o quadro e nada além — a sessão dele não expira
     *   por ociosidade justamente porque fica aberta o dia todo num monitor da
     *   sala. Uma agenda com nome, horário e pauta de reunião do time inteiro
     *   exposta nesse monitor é o oposto do recorte que aquele perfil negocia.
     * - **Quem edita o quadro é exatamente quem a Agenda serve**: prazo e
     *   compromisso são combinações sobre o trabalho em andamento, e quem não
     *   mexe no quadro não tem o que combinar ali.
     *
     * As ações vêm da linha REAL de `tarefas` de cada perfil, não do seeder: a
     * grade é editável pela tela de usuários, e copiar a linha viva preserva o
     * ajuste feito à mão. `excluir` é a exceção e vai junto com o que a linha de
     * tarefas disser — desmarcar compromisso é rotina, não destruição de
     * registro, mas quem não pode apagar tarefa também não apaga a agenda dela.
     */
    public function up(): void
    {
        $permissaoId = DB::table('permissoes')->where('recurso', 'agenda')->value('id');

        if (! $permissaoId) {
            $permissaoId = DB::table('permissoes')->insertGetId([
                'recurso' => 'agenda',
                // Mesma descrição do `PerfilPermissaoSeeder`, de propósito: é o
                // rótulo da grade, e duas redações fariam a linha mudar de nome
                // conforme quem semeou o banco.
                'descricao' => 'Agenda (prazos de tarefas e compromissos do time)',
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
        $permissaoId = DB::table('permissoes')->where('recurso', 'agenda')->value('id');

        if (! $permissaoId) {
            return;
        }

        // `agenda` nasceu aqui e não é vocabulário de mais ninguém: deixá-la
        // para trás seria uma linha órfã na grade, oferecendo uma tela que a
        // volta acabou de trancar.
        DB::table('perfil_permissao')->where('permissao_id', $permissaoId)->delete();
        DB::table('permissoes')->where('id', $permissaoId)->delete();
    }
};
