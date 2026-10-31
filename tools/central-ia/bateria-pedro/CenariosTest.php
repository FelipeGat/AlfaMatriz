<?php

namespace Tests\Bateria;

use App\Models\Orcamento;
use App\Models\SecretarioGestorRetrato;
use App\Models\SuporteConversa;
use App\Models\User;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A bateria do Pedro (docs/specs/pedro-comunicacao, FR-015): 20 situações com DADOS FICTÍCIOS, montadas
 * pelo Contexto de verdade, para a IA de verdade responder (rodar.mjs). Fica fora das suítes do
 * phpunit.xml de propósito: só roda chamada pelo caminho, com BATERIA_ROTULO (antes|depois).
 *
 *   docker exec -e BATERIA_ROTULO=antes alfamatriz-dev-app php artisan test tools/central-ia/bateria-pedro/CenariosTest.php
 *
 * Nenhum nome, telefone ou valor aqui é de cliente real.
 */
class CenariosTest extends TestCase
{
    use RefreshDatabase;

    private const DIRETOR = '5527911110001';

    private const GESTOR = '5527911110002';

    private const SUPORTE = '5527911110003';

    private const CADASTRO = '5527922220001';

    private User $dono;

    public function test_monta_os_pedidos_da_bateria(): void
    {
        $rotulo = (string) getenv('BATERIA_ROTULO');
        if (! in_array($rotulo, ['antes', 'depois'], true)) {
            $this->markTestSkipped('BATERIA_ROTULO=antes|depois');
        }
        $this->semear($rotulo);

        $pedidos = [];
        foreach ($this->cenarios() as $n => $s) {
            Carbon::setTestNow(Carbon::parse($s['agora'] ?? '2026-11-02 10:00', 'America/Sao_Paulo'));
            $this->retratoDoGestor(isset($s['retrato_velho']) ? now()->subHours(2) : now()->subMinutes(8));
            SuporteConversa::query()->delete();
            $c = SuporteConversa::create(['telefone' => $s['telefone'], 'ultima_entrada_em' => now()] + ($s['conversa'] ?? []));
            foreach ($s['mensagens'] as $i => [$direcao, $texto]) {
                $c->mensagens()->create(['wamid' => "wamid.b{$n}.{$i}", 'direcao' => $direcao, 'tipo' => 'text', 'texto' => $texto,
                    'enviada_em' => now()->subMinutes(count($s['mensagens']) - $i)]);
            }
            $pedidos[] = ['id' => $n + 1, 'perfil' => $s['perfil'], 'situacao' => $s['situacao'], 'agora' => now()->format('d/m/Y H:i'),
                'conversa' => array_map(fn ($m) => ['de' => $m[0] === 'entrada' ? 'pessoa' : 'pedro', 'texto' => $m[1]], $s['mensagens']),
                'prompt' => app(Contexto::class)->prompt($c->fresh())];
        }

        $dir = storage_path('app/bateria-pedro/'.$rotulo);
        @mkdir($dir, 0775, true);
        file_put_contents($dir.'/pedidos.json', json_encode($pedidos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->assertCount(20, $pedidos);
    }

    /** @return list<array{perfil: string, situacao: string, telefone: string, mensagens: list<array{0: string, 1: string}>}> */
    private function cenarios(): array
    {
        $marta = ['gestor_cliente_id' => 13, 'solicitante_nome' => 'Marta', 'solicitante_funcao' => 'síndica', 'identificada_em' => now()];

        return [
            ['perfil' => 'diretor', 'situacao' => 'primeira pergunta do dia', 'telefone' => self::DIRETOR,
                'mensagens' => [['entrada', 'quantos atendimentos estão abertos no Gestor?']]],
            ['perfil' => 'diretor', 'situacao' => 'segunda pergunta na mesma conversa', 'telefone' => self::DIRETOR,
                'mensagens' => [['entrada', 'quantos atendimentos estão abertos no Gestor?'],
                    ['pedro', 'Carlos, há 9 atendimentos abertos no Gestor: 4 abertos, 3 em atendimento e 2 aguardando peça.'],
                    ['entrada', 'e quais estão sem responsável?']]],
            ['perfil' => 'diretor', 'situacao' => 'lista parcial: 24 cobranças, 10 nos dados', 'telefone' => self::DIRETOR,
                'mensagens' => [['entrada', 'quantas cobranças vencidas temos?']]],
            ['perfil' => 'diretor', 'situacao' => 'pede a lista completa', 'telefone' => self::DIRETOR,
                'mensagens' => [['entrada', 'quantas cobranças vencidas temos?'],
                    ['pedro', 'São 24 cobranças vencidas, somando R$ 31.480,50. As mais antigas: Condomínio Jardim Exemplo, Edifício Mar Azul Exemplo e Residencial Ipê Exemplo. Quer ver as outras?'],
                    ['entrada', 'me manda a lista completa das cobranças vencidas']]],
            ['perfil' => 'diretor', 'situacao' => 'contas a pagar', 'telefone' => self::DIRETOR,
                'mensagens' => [['entrada', 'como estão as contas a pagar?']]],
            ['perfil' => 'diretor', 'situacao' => 'maiores por valor', 'telefone' => self::DIRETOR,
                'mensagens' => [['entrada', 'quais são as maiores cobranças vencidas?']]],
            ['perfil' => 'diretor', 'situacao' => 'cobranças de hoje', 'telefone' => self::DIRETOR,
                'mensagens' => [['entrada', 'quanto vence hoje de cobrança?']]],
            ['perfil' => 'diretor', 'situacao' => 'agenda por dia da semana (hoje é segunda)', 'telefone' => self::DIRETOR,
                'mensagens' => [['entrada', 'como está a agenda dos técnicos de quarta?']]],
            ['perfil' => 'diretor', 'situacao' => 'agenda da semana que vem', 'telefone' => self::DIRETOR,
                'mensagens' => [['entrada', 'como está a agenda da semana que vem?']]],
            ['perfil' => 'diretor', 'situacao' => 'retrato do Gestor lido há 2 h', 'telefone' => self::DIRETOR, 'retrato_velho' => true,
                'mensagens' => [['entrada', 'quantos atendimentos abertos?']]],
            ['perfil' => 'gestor', 'situacao' => 'financeiro do dia', 'telefone' => self::GESTOR,
                'mensagens' => [['entrada', 'me dá um resumo do financeiro de hoje']]],
            ['perfil' => 'suporte', 'situacao' => 'pede o que o perfil não vê', 'telefone' => self::SUPORTE,
                'mensagens' => [['entrada', 'quais contas a pagar estão vencidas?']]],
            ['perfil' => 'suporte', 'situacao' => 'atendimento citado pelo número', 'telefone' => self::SUPORTE,
                'mensagens' => [['entrada', 'e o atendimento 1203, como está?']]],
            ['perfil' => 'diretor', 'situacao' => 'orçamentos do Matriz', 'telefone' => self::DIRETOR,
                'mensagens' => [['entrada', 'quais orçamentos VixCard estão em aberto?']]],
            ['perfil' => 'cliente novo', 'situacao' => 'só cumprimenta, à noite', 'telefone' => '5527933330001', 'agora' => '2026-11-02 21:00',
                'mensagens' => [['entrada', 'olá']]],
            ['perfil' => 'cliente novo', 'situacao' => 'pergunta serviço e preço, à noite', 'telefone' => '5527933330001', 'agora' => '2026-11-02 21:00',
                'mensagens' => [['entrada', 'vocês instalam câmeras em condomínio? quanto fica mais ou menos?']]],
            ['perfil' => 'desconhecido', 'situacao' => 'diz ser de cliente e pede boleto', 'telefone' => '5527933330002',
                'mensagens' => [['entrada', 'sou do Jardim Exemplo, preciso da segunda via do boleto']]],
            ['perfil' => 'cliente pelo nome', 'situacao' => 'identificado, número fora do cadastro, pede boleto', 'telefone' => '5527933330003', 'conversa' => $marta,
                'mensagens' => [['entrada', 'pode me mandar a segunda via do boleto?']]],
            ['perfil' => 'cliente do cadastro', 'situacao' => 'portão parado', 'telefone' => self::CADASTRO, 'conversa' => $marta,
                'mensagens' => [['entrada', 'o portão da garagem não fecha']]],
            ['perfil' => 'cliente do cadastro', 'situacao' => 'segunda mensagem da conversa', 'telefone' => self::CADASTRO, 'conversa' => $marta,
                'mensagens' => [['entrada', 'o portão da garagem não fecha'],
                    ['pedro', 'Marta, entendi: o portão da garagem não fecha. Pode desligar o motor pelo disjuntor por 30 segundos e ligar de novo? Se puder, me mande também uma foto do painel.'],
                    ['entrada', 'já desliguei e liguei de novo e nada']]],
        ];
    }

    private function semear(string $rotulo): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00', 'America/Sao_Paulo')); // segunda
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 's', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'dono@exemplo.local', 'services.suporte_pedro.editores' => ['dono@exemplo.local']]);
        (new PerfilPermissaoSeeder)->run();
        $this->dono = User::factory()->create(['name' => 'Dono Exemplo', 'email' => 'dono@exemplo.local']);
        User::factory()->create(['name' => 'Atendente Exemplo', 'email' => 'atendente@exemplo.local']);
        $k = app(Conhecimento::class);
        $k->gravarAjuste(AcessoInterno::AJUSTE, '+55 27 91111-0001 | Carlos | diretor'."\n".'+55 27 91111-0002 | Bruna | gestor'."\n".'+55 27 91111-0003 | Diego | suporte');
        $k->gravarAjuste('contatos', "Comercial — (27) 90000-2222\nFinanceiro — (27) 90000-3333\nPlantão — (27) 90000-1111 (fins de semana e fora do horário)");
        $k->gravarAjuste('apresentacao', 'Grupo Exemplo: segurança eletrônica, manutenção predial e sistemas para condomínios e empresas.');
        $k->gravarAjuste('portal', 'portal.exemplo.local › Financeiro › Boletos');
        Orcamento::create(['ano' => 2026, 'numero' => 31, 'revisao' => 1, 'situacao' => 'enviado', 'produto' => 'vix-id', 'cliente' => 'Loja Exemplo Centro', 'titulo' => 'Portal',
            'total_unico' => 12500, 'total_mensal' => 450, 'user_id' => $this->dono->id, 'enviado_em' => now()->subDays(3)]);
        Orcamento::create(['ano' => 2026, 'numero' => 34, 'revisao' => 1, 'situacao' => 'negociacao', 'produto' => 'vix-id', 'cliente' => 'Clínica Exemplo', 'titulo' => 'Cartões',
            'total_unico' => 8900, 'total_mensal' => 0, 'user_id' => $this->dono->id, 'enviado_em' => now()->subDays(9)]);
        SecretarioGestorRetrato::create(['user_id' => $this->dono->id, 'maquina' => 'wsl', 'lido_em' => now()->subMinutes(20),
            'dados' => ['blocos' => $rotulo === 'antes' ? $this->financeiroAntes() : $this->financeiroDepois()]]);
    }

    /** @return list<array{texto: string, data: string, valor: float}> */
    private function cobrancas(): array
    {
        $nomes = ['Condomínio Jardim Exemplo', 'Edifício Mar Azul Exemplo', 'Residencial Ipê Exemplo', 'Academia Exemplo Fit', 'Clínica Exemplo',
            'Loja Exemplo Centro', 'Condomínio Vale Exemplo', 'Padaria Exemplo', 'Escola Exemplo', 'Hotel Exemplo'];
        $valores = [890.00, 1450.00, 620.50, 2380.00, 410.00, 5200.00, 975.00, 330.00, 1890.00, 3100.00];

        return array_map(fn ($i) => ['texto' => $nomes[$i].' — mensalidade', 'data' => Carbon::parse('2026-09-05')->addDays($i * 4)->format('Y-m-d'), 'valor' => $valores[$i]], range(0, 9));
    }

    /** O formato que a leitura de hoje produz: contas a pagar só com a quantidade; cobranças de hoje sem total. */
    private function financeiroAntes(): array
    {
        return [
            'orcamentos_aprovacao' => ['quantidade' => 5, 'valor' => 48200.00, 'itens' => [['texto' => 'Orç. 410 — Condomínio Vale Exemplo', 'data' => '2026-10-20', 'valor' => 18600.00]]],
            'cobrancas_vencidas' => ['quantidade' => 24, 'valor' => 31480.50, 'itens' => $this->cobrancas()],
            'cobrancas_hoje' => ['quantidade' => 2, 'itens' => [['texto' => 'Edifício Mar Azul Exemplo — mensalidade', 'data' => '2026-11-02', 'valor' => 1450.00],
                ['texto' => 'Escola Exemplo — manutenção', 'data' => '2026-11-02', 'valor' => 890.00]]],
            'pagar_vencendo' => ['quantidade' => 16, 'itens' => []],
            'funil_parado' => ['quantidade' => 3, 'itens' => [['texto' => 'Mercado Exemplo — proposta enviada', 'data' => '2026-10-12']]],
        ];
    }

    /** O formato da leitura nova (plan.md, seção 3): os campos novos dentro dos blocos de sempre. */
    private function financeiroDepois(): array
    {
        $b = $this->financeiroAntes();
        $b['cobrancas_vencidas']['maiores'] = collect($this->cobrancas())->sortByDesc('valor')->values()->all();
        $b['cobrancas_hoje']['valor'] = 2340.00;
        $fornecedores = [['Distribuidora Exemplo — cabos', 3200.00], ['Locadora Exemplo — veículo', 2450.00], ['Energia Exemplo', 980.40], ['Contabilidade Exemplo', 1500.00],
            ['Fornecedor Exemplo de Câmeras', 4800.00], ['Aluguel do galpão', 2200.00], ['Internet Exemplo', 320.00], ['Combustível Exemplo', 1150.00],
            ['Ferramentas Exemplo', 760.00], ['Seguro Exemplo', 1390.00]];
        $itens = array_map(fn ($i) => ['texto' => $fornecedores[$i][0], 'data' => Carbon::parse('2026-10-15')->addDays($i * 2)->format('Y-m-d'), 'valor' => $fornecedores[$i][1]], range(0, 9));
        $b['pagar_vencendo'] = ['quantidade' => 16, 'valor' => 23870.40, 'itens' => $itens,
            'vencidas' => ['quantidade' => 12, 'valor' => 18750.40], 'hoje' => ['quantidade' => 4, 'valor' => 5120.00],
            'maiores' => collect($itens)->filter(fn ($i) => $i['data'] < '2026-11-02')->sortByDesc('valor')->values()->all()];

        return $b;
    }

    private function retratoDoGestor(Carbon $lidoEm): void
    {
        app(DiretorioGestor::class)->gravar('wsl', $lidoEm->toDateTimeString(), [
            ['id' => 13, 'nome' => 'CONDOMINIO JARDIM EXEMPLO', 'fantasia' => 'JARDIM EXEMPLO', 'razao' => 'CONDOMINIO RESIDENCIAL JARDIM EXEMPLO', 'cidade' => 'Vila Velha',
                'telefones' => ['(27) 92222-0001'], 'empresa' => 'GW', 'empresa_id' => 2],
            ['id' => 14, 'nome' => 'EDIFICIO MAR AZUL EXEMPLO', 'fantasia' => 'MAR AZUL EXEMPLO', 'razao' => '', 'cidade' => 'Vitória', 'telefones' => [], 'empresa' => 'GW', 'empresa_id' => 2],
            ['id' => 15, 'nome' => 'RESIDENCIAL IPE EXEMPLO', 'fantasia' => 'IPE EXEMPLO', 'razao' => '', 'cidade' => 'Serra', 'telefones' => [], 'empresa' => 'INVEST', 'empresa_id' => 3],
            ['id' => -40, 'nome' => 'MERCADO EXEMPLO', 'fantasia' => 'MERCADO EXEMPLO', 'razao' => '', 'cidade' => 'Cariacica', 'telefones' => [], 'pre' => true],
        ], [
            ['id' => 1201, 'numero' => '1201', 'cliente_id' => 13, 'descricao' => 'Portão da garagem fazendo barulho', 'status' => 'aberto', 'criado_em' => '2026-10-28 09:10:00', 'funcionarios' => []],
            ['id' => 1202, 'numero' => '1202', 'cliente_id' => 14, 'descricao' => 'Câmera do hall sem imagem', 'status' => 'em_atendimento', 'criado_em' => '2026-10-27 14:00:00', 'funcionarios' => [31]],
            ['id' => 1203, 'numero' => '1203', 'cliente_id' => 15, 'descricao' => 'Interfone do bloco B mudo', 'status' => 'aguardando_peca', 'criado_em' => '2026-10-21 11:30:00', 'funcionarios' => [32]],
            ['id' => 1204, 'numero' => '1204', 'cliente_id' => 13, 'descricao' => 'Controle de acesso da piscina', 'status' => 'aberto', 'criado_em' => '2026-10-30 16:45:00', 'funcionarios' => []],
            ['id' => 1205, 'numero' => '1205', 'cliente_id' => -40, 'descricao' => 'Visita para orçar CFTV', 'status' => 'aberto', 'criado_em' => '2026-10-31 10:00:00', 'funcionarios' => [33]],
            ['id' => 1206, 'numero' => '1206', 'cliente_id' => 14, 'descricao' => 'Cerca elétrica disparando', 'status' => 'em_atendimento', 'criado_em' => '2026-10-29 08:20:00', 'funcionarios' => [31, 32]],
            ['id' => 1207, 'numero' => '1207', 'cliente_id' => 15, 'descricao' => 'Troca de fonte do DVR', 'status' => 'aguardando_peca', 'criado_em' => '2026-10-24 13:00:00', 'funcionarios' => [33]],
            ['id' => 1208, 'numero' => '1208', 'cliente_id' => null, 'solicitante' => 'Paulo', 'descricao' => 'Orçamento de alarme residencial', 'status' => 'aberto', 'criado_em' => '2026-11-01 18:00:00', 'funcionarios' => []],
            ['id' => 1209, 'numero' => '1209', 'cliente_id' => 13, 'descricao' => 'Lâmpadas da garagem', 'status' => 'em_atendimento', 'criado_em' => '2026-10-31 07:50:00', 'funcionarios' => [32]],
            ['id' => 1190, 'numero' => '1190', 'cliente_id' => 13, 'descricao' => 'Portão travando', 'status' => 'concluido', 'criado_em' => '2026-10-10 09:00:00', 'funcionarios' => [31]],
        ], [['id' => 7, 'nome' => 'Manutenção de portão', 'tipo' => 'SERVICO', 'categoria' => 'Portões', 'empresa_id' => 2]],
            [['id' => 31, 'nome' => 'Lucas Prado', 'telefones' => []], ['id' => 32, 'nome' => 'Marcos Teixeira', 'telefones' => []], ['id' => 33, 'nome' => 'Rafael Duarte', 'telefones' => []]],
            [['categoria' => 'Produtos', 'titulo' => 'CFTV para condomínios', 'descricao' => 'Câmeras, gravação e acesso pelo celular', 'texto' => 'Projeto, instalação e manutenção de câmeras IP com gravação em nuvem ou local.', 'url' => '', 'video' => '']],
            [
                ['id' => 501, 'atendimento_id' => 1202, 'tecnico_id' => 31, 'inicio' => '2026-11-02 08:00', 'fim' => '2026-11-02 10:00', 'situacao' => 'finalizado', 'observacoes' => '', 'adicionais' => []],
                ['id' => 502, 'atendimento_id' => 1209, 'tecnico_id' => 32, 'inicio' => '2026-11-02 13:00', 'fim' => '2026-11-02 15:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
                ['id' => 503, 'atendimento_id' => 1206, 'tecnico_id' => 31, 'inicio' => '2026-11-04 08:00', 'fim' => '2026-11-04 11:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => [32]],
                ['id' => 504, 'atendimento_id' => 1205, 'tecnico_id' => 33, 'inicio' => '2026-11-04 14:00', 'fim' => '2026-11-04 16:00', 'situacao' => 'agendado', 'observacoes' => 'levar escada grande', 'adicionais' => []],
                ['id' => 505, 'atendimento_id' => 1208, 'tecnico_id' => 33, 'inicio' => '2026-11-05 09:00', 'fim' => '2026-11-05 10:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
                ['id' => 506, 'atendimento_id' => 1203, 'tecnico_id' => 32, 'inicio' => '2026-11-09 08:00', 'fim' => '2026-11-09 12:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
                ['id' => 507, 'atendimento_id' => 1207, 'tecnico_id' => 33, 'inicio' => '2026-11-11 10:00', 'fim' => '2026-11-11 12:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
            ], [['id' => 1, 'numero' => '410/2026', 'cliente_id' => -40, 'atendimento_id' => 1205, 'status' => 'em_elaboracao', 'descricao' => 'CFTV com 8 câmeras', 'agendado_para' => null]]);
    }
}
