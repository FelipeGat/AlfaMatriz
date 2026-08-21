<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A conta que fica exposta o dia inteiro num monitor da sala.
     *
     * O quadro de tarefas é a tela com mais chance de ficar aberta o dia
     * inteiro à vista de todos, e o relógio de ociosidade a derruba a cada
     * meia hora — o que torna o painel de parede inútil. A saída pedida foi
     * isentar a TELA do relógio, e ela não serve: a sessão não tem tela. Do
     * quadro, a barra lateral leva a Caixa, Faturamento, Auditoria e Usuários
     * e permissões em um clique. Isentar o quadro isentaria a conta inteira,
     * e quem sentasse na máquina destravada teria o painel todo — inclusive
     * dar permissão a si mesmo, que é o único estrago que sobrevive ao fechar
     * a aba.
     *
     * A isenção passa a ser da CONTA, e não da tela. É o mesmo desenho do
     * modo quiosque do Grafana e do wallboard do Jira: o que fica exposto não
     * é a sessão de alguém com poderes, é uma conta que só enxerga aquilo.
     * Deixar ESTA aberta o dia todo expõe o quadro — que é exatamente o que
     * se quis pôr na parede.
     *
     * A marca fica no PERFIL e não no usuário porque é decisão de acesso, e
     * decisão de acesso neste sistema mora no perfil. Ela não é editável pela
     * tela da matriz de permissões, de propósito: uma caixinha de "não expira"
     * ao lado das outras acabaria marcada no Administrador numa tarde apertada,
     * e aí a regra inteira teria ido embora sem ninguém decidir isso.
     *
     * Vai em MIGRAÇÃO, e não só no seeder, porque o deploy roda apenas
     * `migrate --force`: perfil semeado não chega a produção.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('perfis', 'nao_expira_por_ociosidade')) {
            Schema::table('perfis', function (Blueprint $tabela) {
                $tabela->boolean('nao_expira_por_ociosidade')->default(false);
            });
        }

        $perfilId = DB::table('perfis')->where('slug', 'exibicao')->value('id');

        if (! $perfilId) {
            $perfilId = DB::table('perfis')->insertGetId([
                'slug' => 'exibicao',
                'nome' => 'Exibição',
                'nao_expira_por_ociosidade' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('perfis')->where('id', $perfilId)
                ->update(['nao_expira_por_ociosidade' => true, 'updated_at' => now()]);
        }

        // O recurso é criado aqui se faltar, e não lido e pronto: `permissoes`
        // é povoada pelo SEEDER, que não roda em produção. Lendo apenas, esta
        // migração criaria um perfil sem permissão nenhuma — que entra no
        // painel e não abre tela alguma. Mesmo cuidado da migração do perfil
        // comercial.
        $permissaoId = DB::table('permissoes')->where('recurso', 'tarefas')->value('id');

        if (! $permissaoId) {
            $permissaoId = DB::table('permissoes')->insertGetId([
                'recurso' => 'tarefas',
                'descricao' => 'Tarefas de desenvolvimento',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $jaExiste = DB::table('perfil_permissao')
            ->where('perfil_id', $perfilId)
            ->where('permissao_id', $permissaoId)
            ->exists();

        if (! $jaExiste) {
            // SÓ ler. Nem incluir, nem editar, nem imprimir: monitor de parede
            // não escreve, e o que ele não pode fazer é o que sobra de garantia
            // quando alguém encosta no teclado dele.
            DB::table('perfil_permissao')->insert([
                'perfil_id' => $perfilId,
                'permissao_id' => $permissaoId,
                'ler' => true,
                'incluir' => false,
                'editar' => false,
                'imprimir' => false,
                'excluir' => false,
            ]);
        }
    }

    public function down(): void
    {
        $perfilId = DB::table('perfis')->where('slug', 'exibicao')->value('id');

        if ($perfilId) {
            // A conta fica, o perfil sai de perto dela — mesma escolha da
            // migração do perfil comercial. Quem reverter isto reatribui o
            // perfil; não recadastra ninguém.
            DB::table('perfil_user')->where('perfil_id', $perfilId)->delete();
            DB::table('perfil_permissao')->where('perfil_id', $perfilId)->delete();
            DB::table('perfis')->where('id', $perfilId)->delete();
        }

        if (Schema::hasColumn('perfis', 'nao_expira_por_ociosidade')) {
            Schema::table('perfis', function (Blueprint $tabela) {
                $tabela->dropColumn('nao_expira_por_ociosidade');
            });
        }

        // A linha de `permissoes` FICA: `tarefas` é vocabulário compartilhado —
        // o time inteiro aponta para ela — e apagá-la na volta derrubaria o
        // acesso de quem nunca teve nada a ver com o painel de parede.
    }
};
