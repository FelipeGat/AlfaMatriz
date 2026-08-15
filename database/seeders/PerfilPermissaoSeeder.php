<?php

namespace Database\Seeders;

use App\Models\Perfil;
use App\Models\Permissao;
use Illuminate\Database\Seeder;

class PerfilPermissaoSeeder extends Seeder
{
    /**
     * Este seeder é o ESTADO INICIAL da grade, não a verdade sobre ela.
     *
     * Desde que a tela de usuários passou a editar permissões, rodá-lo de novo
     * reescreve o que foi ajustado por lá: os `syncWithoutDetaching` abaixo
     * levam valores explícitos para cada ação. Em produção isso não acontece —
     * o deploy roda só `migrate --force` —, mas em desenvolvimento um
     * `db:seed` desfaz o ajuste sem avisar. O perfil `admin` é o único imune,
     * porque a tela não o edita.
     *
     * `editar` acompanha `incluir` em toda linha daqui, e isso é o ESTADO
     * INICIAL — não a regra. A ação nasceu separada justamente para poder
     * divergir: um perfil que registra o que chega sem reescrever o que já
     * está registrado. Quem separa de verdade é o administrador, na grade;
     * aqui os dois coincidem porque é o que a migração
     * `2026_08_15_090000_separar_editar_de_incluir.php` faz com o dado que já
     * existe, e um estado inicial diferente do backfill faria a mesma grade
     * ter dois começos possíveis dependendo de como o banco nasceu.
     */
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

            // Recurso PRÓPRIO, e não uma aba de `dashboard`: aquele fala do
            // dinheiro da casa (Centro de Controle abre com saldo em caixa,
            // Comercial mostra MRR de atacado) — dado que o perfil `comercial`
            // não deveria ver. Este é só o funil: metas, avanço por estágio e
            // venda por vendedor. Separar os dois é o que permite dar painel a
            // quem vende sem dar visão do caixa da empresa (15/08/2026).
            'dashboard_comercial' => 'Dashboard Comercial (funil, metas, vendas por vendedor)',

            'leads' => 'Funil de vendas / leads',
            'faturamento' => 'Faturamento',
            'tarefas' => 'Tarefas de desenvolvimento',

            // Triagem é CAPACIDADE, não cargo: decidir a prioridade de uma
            // tarefa e escolher quem a faz. Recurso próprio porque quem
            // trabalha no quadro não é necessariamente quem organiza o
            // trabalho dos outros — e sem separar, as duas coisas viriam
            // juntas por acidente, como vinham.
            'tarefas_triagem' => 'Triagem de tarefas (priorizar e direcionar)',

            // Recurso próprio, e não uma aba de `usuarios`: o rastro atravessa
            // as áreas — a mesma tela mostra receita, cliente, salário em
            // despesa e mudança de permissão. Pendurá-lo em qualquer permissão
            // existente daria a visão do sistema inteiro a quem só tinha a de
            // um pedaço. Espelha
            // `2026_08_13_110000_permissao_de_auditoria.php`, que é quem leva
            // isto a produção; aqui é só o estado inicial de quem semeia.
            'auditoria' => 'Auditoria (rastro de quem fez o quê)',
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
                $permissaoId => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => true],
            ]);
        }

        foreach (['cobrancas', 'contas_pagar', 'financeiro', 'dashboard', 'dashboard_comercial'] as $recurso) {
            $financeiro->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false],
            ]);
        }
        foreach (['revendas', 'clientes', 'sistemas'] as $recurso) {
            $financeiro->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => true, 'excluir' => false],
            ]);
        }

        foreach (['revendas', 'clientes', 'sistemas', 'dashboard', 'dashboard_comercial'] as $recurso) {
            $operacao->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false],
            ]);
        }
        foreach (['cobrancas', 'contas_pagar', 'financeiro'] as $recurso) {
            $operacao->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => true, 'excluir' => false],
            ]);
        }
        foreach (['leads', 'faturamento'] as $recurso) {
            $operacao->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false],
            ]);
        }
        foreach (['leads', 'faturamento'] as $recurso) {
            $financeiro->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => true, 'excluir' => false],
            ]);
        }

        // Quem trabalha no quadro sem organizar o trabalho dos outros.
        //
        // O perfil existe para que "abrir e tocar tarefa" deixe de exigir a
        // capacidade de priorizar e direcionar. Ele NÃO é uma versão reduzida
        // do time: comentar, bloquear, mexer no checklist e mover as próprias
        // tarefas seguem abertos, porque travar isso não impede ninguém de
        // trabalhar em algo não pedido — impede de REGISTRAR, e aí o quadro
        // passa a mentir sobre o que está acontecendo.
        $membro = Perfil::updateOrCreate(['slug' => 'membro'], ['nome' => 'Membro do time']);

        $membro->permissoes()->syncWithoutDetaching([
            $todasPermissoes['tarefas'] => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false],
        ]);

        // Quem vende não é quem opera. O perfil mais próximo era `operacao`, e
        // ele traz junto o negócio da Alfa: sistemas e preço de atacado,
        // faturamento, receitas, despesas e os painéis de dinheiro da casa.
        //
        // Ganhou painel em 15/08/2026 — mas o PRÓPRIO (`dashboard_comercial`),
        // não o `dashboard` do Centro de Controle/Comercial: aquele mostra
        // saldo em caixa e MRR de atacado, dado que este perfil não deveria
        // ver. `User::temEscopoComercial()` é quem decide, na hora de montar
        // a tela, se a conta vê o funil da empresa inteira (tem `dashboard`)
        // ou só o próprio (só tem `dashboard_comercial`) — e é essa mesma
        // pergunta que faz o `leads.index` restringir o quadro aos leads onde
        // ela é a vendedora.
        //
        // Espelha `2026_08_12_180000_criar_perfil_comercial.php`, que é quem
        // leva isto a produção — aqui é só o estado inicial de quem semeia.
        $comercial = Perfil::updateOrCreate(['slug' => 'comercial'], ['nome' => 'Comercial']);

        foreach (['leads', 'clientes', 'revendas'] as $recurso) {
            $comercial->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false],
            ]);
        }

        $comercial->permissoes()->syncWithoutDetaching([
            $todasPermissoes['dashboard_comercial'] => ['ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => true, 'excluir' => false],
        ]);

        // A revenda entra no MESMO painel da Alfa, então o perfil dela precisa
        // ser curto de propósito: só revendas e clientes. Reusar `operacao`
        // mostraria sistemas, dashboard, leads e faturamento — o negócio da
        // Alfa, não o portfólio da revenda. O que restringe QUAIS revendas e
        // clientes ela vê continua sendo o `revenda_id` do usuário.
        $revenda = Perfil::updateOrCreate(['slug' => 'revenda'], ['nome' => 'Revenda']);

        foreach (['revendas', 'clientes'] as $recurso) {
            $revenda->permissoes()->syncWithoutDetaching([
                $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false],
            ]);
        }
    }
}
