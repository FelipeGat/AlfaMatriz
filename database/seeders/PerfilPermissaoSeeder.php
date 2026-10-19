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
            // Boleto da revenda e baixa automática (cobranca-revendas-boleto): fora do admin, logo abaixo.
            'cobranca_revendas' => 'Cobrança de revendas (boleto e baixa automática)',
            // Orçamentos VixCard (orcamentos-vixcard): também fora do admin — Felipe e Victor.
            'orcamentos' => 'Orçamentos VixCard (propostas em PDF)',
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

            // Recurso próprio, e não uma aba de `tarefas`: a Agenda mostra o
            // prazo E o compromisso — nome, horário e pauta de reunião do time
            // inteiro. Pendurá-la em `tarefas` daria essa visão a todo perfil
            // que lê o quadro, inclusive o de exibição, que é um monitor na
            // parede da sala. Espelha
            // `2026_09_18_092000_permissao_de_agenda.php`, que é quem leva isto
            // a produção; aqui é só o estado inicial de quem semeia.
            'agenda' => 'Agenda (prazos de tarefas e compromissos do time)',

            // A aba de Relatórios atravessa as quatro áreas — funil, caixa,
            // quadro de tarefas e administração — e a seção financeira mostra
            // o dinheiro da casa. Por isso recurso PRÓPRIO, e não carona em
            // `dashboard`: pendurar ali obrigaria todo relatório futuro a
            // carregar junto a visão do caixa, e um recurso só se separa sem
            // dor enquanto ninguém depende do acoplamento. Espelha
            // `2026_08_16_120000_permissao_de_relatorios.php`, que é quem leva
            // isto a produção; aqui é só o estado inicial de quem semeia.
            'relatorios' => 'Relatórios (comercial, financeiro, desenvolvimento e sistema)',

            // Recurso próprio, e não uma aba de `usuarios`: o rastro atravessa
            // as áreas — a mesma tela mostra receita, cliente, salário em
            // despesa e mudança de permissão. Pendurá-lo em qualquer permissão
            // existente daria a visão do sistema inteiro a quem só tinha a de
            // um pedaço. Espelha
            // `2026_08_13_110000_permissao_de_auditoria.php`, que é quem leva
            // isto a produção; aqui é só o estado inicial de quem semeia.
            'auditoria' => 'Auditoria (rastro de quem fez o quê)',

            // A tela de Manutenção deixou de ser só promessa com a aba Erros
            // (#224): ela mostra a mensagem crua do log dos sistemas e cala
            // erro do vigia. Recurso próprio pela mesma razão da Agenda — o
            // perfil de exibição lê o quadro, e o log no monitor da parede é o
            // que ninguém negociou. Espelha
            // `2026_10_03_150000_permissao_de_manutencao.php`, que é quem leva
            // isto a produção; aqui é só o estado inicial de quem semeia.
            'manutencao' => 'Manutenção e atualizações (erros dos sistemas, changelog e janelas)',

            // As telas do AlfaMonitor (#394). Duas portas por decisão do dono:
            // ver o monitoramento não dá a assinatura da Hostinger. Espelha
            // `2026_10_17_150000_permissoes_do_monitor.php`, que é quem leva
            // isto a produção; aqui é só o estado inicial de quem semeia.
            'monitor' => 'Monitor (servidores, sistemas e aparelhos de acesso do AlfaMonitor)',
            'monitor_hostinger' => 'Monitor — assinaturas e domínios da Hostinger',
        ];

        foreach ($recursos as $slug => $descricao) {
            Permissao::updateOrCreate(['recurso' => $slug], ['descricao' => $descricao]);
        }

        $admin = Perfil::updateOrCreate(['slug' => 'admin'], ['nome' => 'Administrador']);
        $financeiro = Perfil::updateOrCreate(['slug' => 'financeiro'], ['nome' => 'Financeiro']);
        $operacao = Perfil::updateOrCreate(['slug' => 'operacao'], ['nome' => 'Operação']);

        // Central e Secretário fora do "tudo do admin": a Central por perfil próprio; o Secretário, logo abaixo.
        // Cobrança de revendas também: emite boleto real em nome da Alfa — só quem recebe o perfil (Felipe, 18/10/2026).
        // Orçamentos VixCard idem: só Felipe e Victor (19/10/2026).
        $todasPermissoes = Permissao::whereNotIn('recurso', ['central_ia', 'central_ia_agentes', 'secretario', 'cobranca_revendas', 'orcamentos'])->pluck('id', 'recurso');

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

        // Relatórios é tela de LEITURA — não existe o que incluir ou editar
        // nela, então as duas ações já nascem negadas em vez de ficarem como
        // promessa vazia na grade. O estado inicial acompanha quem já vê os
        // painéis da casa (`dashboard`): financeiro e operação.
        foreach ([$financeiro, $operacao] as $perfil) {
            $perfil->permissoes()->syncWithoutDetaching([
                $todasPermissoes['relatorios'] => ['ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => true, 'excluir' => false],
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

        // O painel de parede: a conta que fica exposta o dia todo num monitor
        // da sala. Só LER o quadro — nem incluir, nem editar, nem imprimir,
        // porque monitor não escreve, e o que ele não pode fazer é o que sobra
        // de garantia quando alguém encosta no teclado dele.
        //
        // `nao_expira_por_ociosidade` é o que a distingue: nela o relógio de
        // ociosidade não roda. A isenção é da CONTA e não da tela justamente
        // porque a sessão não tem tela — ver
        // `2026_08_21_150000_perfil_de_exibicao_para_painel_de_parede.php`,
        // que é quem leva isto a produção; aqui é só o estado inicial de quem
        // semeia.
        $exibicao = Perfil::updateOrCreate(
            ['slug' => 'exibicao'],
            ['nome' => 'Exibição', 'nao_expira_por_ociosidade' => true],
        );

        $exibicao->permissoes()->syncWithoutDetaching([
            $todasPermissoes['tarefas'] => ['ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => false, 'excluir' => false],
        ]);

        $membro->permissoes()->syncWithoutDetaching([
            $todasPermissoes['tarefas'] => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false],
        ]);

        // A Agenda acompanha quem EDITA o quadro, e o membro é o caso central:
        // é ele quem combina prazo e marca reunião sobre o próprio trabalho.
        // A migração de produção usa a mesma régua — quem tem `tarefas.editar`
        // —, e por isso o perfil de exibição fica de fora nos dois lugares.
        $membro->permissoes()->syncWithoutDetaching([
            $todasPermissoes['agenda'] => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false],
        ]);

        // A Manutenção acompanha a mesma régua da Agenda — quem edita o quadro
        // é quem conserta o erro que o vigia mostra, e quem calibra o que ele
        // ignora. A migração de produção usa a mesma régua.
        $membro->permissoes()->syncWithoutDetaching([
            $todasPermissoes['manutencao'] => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false],
        ]);

        // O Monitor segue a Manutenção, de onde a vitrine dele saiu, e só lê.
        // A Hostinger fica de fora: nasce só no Administrador (ver a migração).
        $membro->permissoes()->syncWithoutDetaching([
            $todasPermissoes['monitor'] => ['ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => true, 'excluir' => false],
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
        // VixCard (acesso-vixcard, 18/10/2026): quadro e Agenda, recortados ao
        // que é da VixCard pelo escopo `EscopoVixcard`. A migração
        // `2026_10_18_140000` leva o mesmo a produção.
        $vixcard = Perfil::updateOrCreate(['slug' => 'vixcard'], ['nome' => 'VixCard']);
        foreach (['tarefas', 'agenda'] as $recurso) {
            if (isset($todasPermissoes[$recurso])) {
                $vixcard->permissoes()->syncWithoutDetaching([
                    $todasPermissoes[$recurso] => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => false, 'excluir' => false],
                ]);
            }
        }

        // Cobrança de revendas (cobranca-revendas-boleto): perfil próprio, que ninguém recebe por padrão.
        $cobrancaRevendas = Perfil::updateOrCreate(['slug' => 'cobranca_revendas'], ['nome' => 'Cobrança de revendas']);
        $cobrancaRevendas->permissoes()->syncWithoutDetaching([
            Permissao::where('recurso', 'cobranca_revendas')->value('id') => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false],
        ]);

        // Orçamentos VixCard (orcamentos-vixcard): perfil próprio; a migração o dá ao Felipe e ao Victor.
        $orcamentos = Perfil::updateOrCreate(['slug' => 'orcamentos'], ['nome' => 'Orçamentos VixCard']);
        $orcamentos->permissoes()->syncWithoutDetaching([
            Permissao::where('recurso', 'orcamentos')->value('id') => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => true],
        ]);

        // O Secretário é de cada pessoa (dado sempre da própria conta): os
        // perfis de pessoas o recebem desde 17/10/2026 — a migração
        // `2026_10_17_230000` leva o mesmo a produção. Revenda e Exibição, não.
        if ($secretario = Permissao::where('recurso', 'secretario')->value('id')) {
            foreach (Perfil::whereIn('slug', ['admin', 'financeiro', 'operacao', 'membro', 'comercial'])->get() as $perfil) {
                $perfil->permissoes()->syncWithoutDetaching([
                    $secretario => ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => false, 'excluir' => true],
                ]);
            }
        }
    }
}
