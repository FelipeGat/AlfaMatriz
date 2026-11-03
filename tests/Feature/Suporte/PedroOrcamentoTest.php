<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorOrcamento;
use App\Models\User;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use App\Services\Suporte\OrcamentoDoGestor;
use App\Services\Suporte\Pedro;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * pedro-orcamento (02/11/2026): o proprietário pede um orçamento ao Pedro pelo WhatsApp, por texto
 * ou áudio. Dados fictícios: Residencial Mar Azul, Padaria Sol, Clínica Horizonte.
 */
class PedroOrcamentoTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    private const FELIPE = '5527998845397';

    private const WALLAS = '5527999990001';

    private const SUPORTE = '552731093265';

    private const CLIENTE = '5527988887777';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'felipe@teste.local']);
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid('', true)]]]));
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, "+55 27 99884-5397 | Felipe | diretor | felipe@gestor.local\n+55 27 99999-0001 | Wallas | diretor\n+55 27 3109-3265 | Alexandre | suporte");
        $this->retrato();
    }

    private function retrato(?array $empresas = [['id' => 1, 'nome' => 'DELTA', 'imposto_nf' => 17.0], ['id' => 2, 'nome' => 'GW', 'imposto_nf' => 15.0], ['id' => 3, 'nome' => 'INVEST', 'imposto_nf' => 15.0]]): void
    {
        app(DiretorioGestor::class)->gravar('wsl', '2026-11-03 09:55:00', [
            ['id' => 501, 'nome' => 'Residencial Mar Azul', 'fantasia' => 'Residencial Mar Azul', 'razao' => '', 'cidade' => 'Vila Velha', 'empresa' => 'INVEST', 'empresa_id' => 3, 'telefones' => []],
            ['id' => 503, 'nome' => 'Padaria Sol', 'fantasia' => 'Padaria Sol', 'razao' => '', 'cidade' => 'Serra', 'empresa' => 'DELTA', 'empresa_id' => 1, 'telefones' => []],
            ['id' => -900, 'nome' => 'Clínica Horizonte', 'fantasia' => 'Clínica Horizonte', 'razao' => '', 'cidade' => 'Vitória', 'telefones' => [], 'pre' => true],
        ], [
            ['id' => 1001, 'numero' => '812', 'cliente_id' => 501, 'descricao' => 'Motor do portão travando na abertura', 'status' => 'aberto', 'criado_em' => '2026-10-30 09:00:00'],
            ['id' => 1002, 'numero' => '813', 'cliente_id' => 503, 'descricao' => 'Câmera da entrada sem imagem', 'status' => 'em_atendimento', 'criado_em' => '2026-10-31 09:00:00'],
            ['id' => 1003, 'numero' => '700', 'cliente_id' => 501, 'descricao' => 'Troca de lâmpadas', 'status' => 'concluido', 'criado_em' => '2026-09-01 09:00:00'],
            // Outros assuntos em aberto (sessão 2): só o do mesmo assunto do orçamento é sugerido.
            ['id' => 1004, 'numero' => '815', 'cliente_id' => 501, 'descricao' => 'Visita técnica', 'assunto_id' => 27, 'status' => 'aberto', 'criado_em' => '2026-11-01 09:00:00'],
            ['id' => 1005, 'numero' => '814', 'cliente_id' => 503, 'descricao' => 'Portão da garagem não fecha', 'status' => 'aberto', 'criado_em' => '2026-11-01 10:00:00'],
        ], [['id' => 27, 'nome' => 'CFTV', 'tipo' => 'SERVICO', 'categoria' => 'SEGURANÇA ELETRONICA', 'empresa_id' => 3],
            ['id' => 3, 'nome' => 'PORTÃO / ACESSO', 'tipo' => 'SERVICO', 'categoria' => 'CONTOLE DE ACESSO', 'empresa_id' => 3]], [], [], [], [], $empresas);
    }

    private function conversa(string $telefone, string $texto, array $mais = []): SuporteConversa
    {
        $c = SuporteConversa::firstOrCreate(['telefone' => $telefone], ['ultima_entrada_em' => now()]);
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => $texto, 'enviada_em' => now()] + $mais);

        return $c->fresh();
    }

    /** A IA respondeu com o bloco; devolve a última mensagem do Pedro. */
    private function responder(SuporteConversa $c, array $bloco): string
    {
        $p = app(Pedro::class)->enfileirar($c->id);
        $p->update(['situacao' => 'reservado']);
        app(Pedro::class)->concluir($this->felipe, $p->id, '<<<PEDRO '.json_encode($bloco, JSON_UNESCAPED_UNICODE).' PEDRO>>>', null);

        return (string) $c->mensagens()->reorder()->where('direcao', 'pedro')->orderByDesc('id')->value('texto');
    }

    private function previa(SuporteConversa $c, array $orcamento): string
    {
        return $this->responder($c, ['resposta' => 'Organizei o orçamento.', 'acao' => 'previa_orcamento', 'orcamento' => $orcamento]);
    }

    private function sim(SuporteConversa $c): string
    {
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'Sim', 'enviada_em' => now()]);

        return $this->responder($c->fresh(), ['resposta' => 'Criando!', 'acao' => 'confirmar_orcamento']);
    }

    /** Ele mudou algo depois da prévia; a IA manda só o que mudou (FR-026). */
    private function corrigir(SuporteConversa $c, string $texto, array $correcao): string
    {
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => $texto, 'enviada_em' => now()]);

        return $this->responder($c->fresh(), ['resposta' => 'Ajustei.', 'acao' => 'corrigir_orcamento', 'correcao' => $correcao]);
    }

    private function repetir(SuporteConversa $c): string
    {
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'tenta de novo', 'enviada_em' => now()]);

        return $this->responder($c->fresh(), ['resposta' => 'Tentando.', 'acao' => 'repetir_orcamento']);
    }

    /** A máquina reserva o próximo da fila. @return array{0: int, 1: string} */
    private function reservar(): array
    {
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $o = $this->postJson(route('suporte.runner.orcamentos'), ['maquina' => 'wsl'])->assertOk()->json('orcamentos.0');

        return [$o['id'], $o['chave']];
    }

    private function feito(int $id, array $resultado): void
    {
        $this->postJson(route('suporte.runner.orcamento-feito', $id), ['maquina' => 'wsl'] + $resultado)->assertOk();
    }

    private const COMPLETO = ['cliente' => 'gestor:501', 'empresa_id' => 3, 'sem_nf' => false, 'atendimento' => '812', 'descricao' => 'Manutenção do motor do portão',
        'observacoes' => 'Motor travando na abertura, conforme o atendimento 812.',
        'itens' => [['nome' => 'Manutenção do motor', 'tipo' => 'servico', 'quantidade' => 1, 'valor_unitario' => 450],
            ['nome' => 'Controle 433 MHz', 'tipo' => 'produto', 'quantidade' => 3, 'valor_unitario' => 80]]];

    public function test_o_proprietario_ve_o_trecho_e_as_empresas_com_a_nf(): void
    {
        $p = app(Contexto::class)->prompt($this->conversa(self::FELIPE, 'Pedro, faz um orçamento para o Mar Azul pela Invest, com nota'));

        $this->assertStringContainsString('CRIAR ORÇAMENTO NO GESTOR (quando ele pedir', $p);
        $this->assertStringContainsString('responder|abrir_atendimento|previa_agenda|confirmar_agenda|previa_orcamento|corrigir_orcamento|confirmar_orcamento|repetir_orcamento', $p);
        $this->assertStringContainsString('CORREÇÃO: depois de uma prévia', $p);
        $this->assertStringContainsString('Só fale de atendimento em aberto do MESMO assunto do orçamento', $p);
        $this->assertStringContainsString('- 3: INVEST (NF no orçamento: 15%)', $p);
        $this->assertStringContainsString('- 1: DELTA (NF no orçamento: 17%)', $p);
        $this->assertStringContainsString('- gestor:501: [CLIENTE] Residencial Mar Azul', $p);
        $this->assertStringContainsString('criar orçamento no Gestor (cliente ou pré-cliente que já existe', $p);
        $this->assertStringNotContainsString('felipe@gestor.local', $p, 'o e-mail do Gestor não vai à IA');
    }

    public function test_quem_nao_pode_nao_recebe_o_trecho(): void
    {
        $wallas = app(Contexto::class)->prompt($this->conversa(self::WALLAS, 'faz um orçamento para o Mar Azul'));
        $this->assertStringNotContainsString('previa_orcamento', $wallas);
        $this->assertStringContainsString(OrcamentoDoGestor::SEM_VINCULO, $wallas);
        $this->assertStringContainsString('criar orçamento no Gestor ainda não: falta ligar o número dele ao usuário dele no Gestor', $wallas);

        $suporte = app(Contexto::class)->prompt($this->conversa(self::SUPORTE, 'faz um orçamento para o Mar Azul'));
        $this->assertStringNotContainsString('previa_orcamento', $suporte);
        $this->assertStringContainsString(OrcamentoDoGestor::SO_PROPRIETARIOS, $suporte);

        $cliente = app(Contexto::class)->prompt($this->conversa(self::CLIENTE, 'quero um orçamento de portão'));
        $this->assertStringNotContainsString('CRIAR ORÇAMENTO NO GESTOR', $cliente);
        $this->assertStringNotContainsString('previa_orcamento', $cliente);
    }

    public function test_previa_completa_montada_pelo_sistema_com_a_conta_da_tela(): void
    {
        $c = $this->conversa(self::FELIPE, 'Pedro, orçamento para o Mar Azul pela Invest, com nota, motor do portão conforme o atendimento 812: manutenção R$ 450 e 3 controles a R$ 80');

        $msg = $this->previa($c, self::COMPLETO);

        $this->assertSame("Felipe, organizei o orçamento assim:\n"
            ."Cliente: Residencial Mar Azul (cliente, Vila Velha)\n"
            ."Empresa: INVEST\n"
            ."Vendedor: Felipe\n"
            ."Nota fiscal: com NF (15% da INVEST no Gestor)\n"
            ."Atendimento relacionado: nº 812 — Motor do portão travando na abertura\n"
            ."Descrição: Manutenção do motor do portão\n"
            ."Itens:\n"
            ."• Item 1: 1 × Manutenção do motor (serviço) — R$ 450,00\n"
            ."• Item 2: 3 × Controle 433 MHz (material) — R$ 80,00 cada = R$ 240,00\n"
            ."Subtotal: R$ 690,00\n"
            ."NF (15%): R$ 103,50\n"
            ."Total: R$ 793,50\n"
            ."Observações: Motor travando na abertura, conforme o atendimento 812.\n\n"
            .'Posso criar esse orçamento no Gestor?', $msg);
        $this->assertSame([], $c->fresh()->previa_orcamento['problemas']);
        $this->assertSame(0, SuporteGestorOrcamento::count(), 'a prévia não grava nada');
    }

    public function test_sem_nf_e_retrato_antigo_sem_o_percentual(): void
    {
        $semNf = $this->previa($this->conversa(self::FELIPE, '3 controles para a Padaria Sol pela Delta, sem nota, 80 cada'),
            ['cliente' => 'gestor:503', 'empresa_id' => 1, 'sem_nf' => true, 'descricao' => 'Controles de portão',
                'itens' => [['nome' => 'Controle', 'tipo' => 'produto', 'quantidade' => 3, 'valor_unitario' => 80]]]);
        $this->assertStringContainsString("Nota fiscal: sem NF\n", $semNf);
        $this->assertStringContainsString("Subtotal: R$ 240,00\nTotal: R$ 240,00", $semNf);
        $this->assertStringNotContainsString('NF (', $semNf);

        $this->retrato(null);
        $antigo = $this->previa($this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest com nota'), self::COMPLETO);
        $this->assertStringContainsString('Nota fiscal: com NF (o Gestor soma o imposto da empresa ao criar)', $antigo);
        $this->assertStringContainsString("NF: calculada pelo Gestor ao criar\nTotal: R$ 690,00 + NF", $antigo);
        $this->assertStringEndsWith(OrcamentoDoGestor::PERGUNTA, $antigo);
    }

    public function test_nada_inventado_o_que_falta_bloqueia_e_o_sim_nao_grava(): void
    {
        $c = $this->conversa(self::FELIPE, 'faz um orçamento para o Mar Azul de manutenção no portão, 1.200 com nota já');
        $msg = $this->previa($c, ['cliente' => 'gestor:501', 'empresa_id' => null, 'sem_nf' => null, 'valor_com_nf' => true, 'descricao' => 'Manutenção do portão',
            'itens' => [['nome' => 'Manutenção', 'tipo' => 'servico', 'quantidade' => 1, 'valor_unitario' => '1.200,00'],
                ['nome' => 'Cabo', 'tipo' => 'material', 'quantidade' => 2.5, 'valor_unitario' => 10], ['nome' => 'Visita', 'tipo' => null, 'quantidade' => null, 'valor_unitario' => 0]]]);

        $this->assertStringStartsWith("Felipe, para montar o orçamento para Residencial Mar Azul falta acertar:\n", $msg);
        $this->assertStringNotContainsString('Subtotal', $msg, 'com pendência, só o que falta, sem a prévia cheia de "?"');
        // Valor "já com nota" diz que há nota (sem a pergunta); sem a empresa, a conta espera por ela.
        $this->assertStringContainsString('• falta a empresa que emite o orçamento (DELTA, GW, INVEST) — no cadastro, o cliente é da INVEST; com ela eu tiro a nota do valor que você disse.', $msg);
        $this->assertStringNotContainsString('com ou sem nota fiscal', $msg);
        $this->assertStringContainsString('• falta o valor: item 1 (Manutenção).', $msg, 'texto com ponto e vírgula não vira número adivinhado');
        $this->assertStringContainsString('• item 2 (Cabo): a quantidade precisa ser um número inteiro', $msg);
        $this->assertStringContainsString('• é serviço ou material: item 3 (Visita)?', $msg);
        $this->assertStringContainsString('• falta a quantidade: item 3 (Visita).', $msg, 'só o único item de SERVIÇO tem a quantidade sugerida');
        $this->assertStringContainsString('• o valor precisa ser maior que zero: item 3 (Visita).', $msg);
        $this->assertStringNotContainsString(OrcamentoDoGestor::PERGUNTA, $msg);

        $this->assertStringStartsWith('Ainda não criei, porque conferi no Gestor e falta acertar:', $this->sim($c));
        $this->assertSame(0, SuporteGestorOrcamento::count());
    }

    public function test_cliente_que_nao_esta_no_gestor_ou_nao_foi_citado(): void
    {
        $inventado = $this->previa($this->conversa(self::FELIPE, 'orçamento para o Condomínio Inexistente'), ['cliente' => 'gestor:777'] + self::COMPLETO);
        $this->assertStringContainsString('• o cliente não foi confirmado no Gestor: diga o nome como está cadastrado (não crio cliente nem pré-cliente).', $inventado);

        $naoCitado = $this->previa($this->conversa(self::FELIPE, 'orçamento para a Padaria Sol'), self::COMPLETO);
        $this->assertStringContainsString('o cliente não foi confirmado no Gestor', $naoCitado, 'o código tem de ser de um cliente que ele citou');
    }

    public function test_atendimento_de_outro_cliente_inexistente_e_o_aviso_dos_abertos(): void
    {
        $pedido = fn () => $this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest com nota');

        $this->assertStringContainsString('• o atendimento nº 813 é de outro cliente (Padaria Sol): corrija o número ou tire o vínculo.', $this->previa($pedido(), ['atendimento' => '813'] + self::COMPLETO));
        $this->assertStringContainsString('• não achei o atendimento nº 999 no Gestor.', $this->previa($pedido(), ['atendimento' => '999'] + self::COMPLETO));

        $semNumero = $this->previa($pedido(), ['atendimento' => null] + self::COMPLETO);
        $this->assertStringContainsString('Atendimento relacionado: sem vínculo', $semNumero);
        $this->assertStringContainsString("Atenção:\n• Residencial Mar Azul tem o atendimento nº 812 — Motor do portão travando na abertura em aberto, do mesmo assunto: se o orçamento for dele, diga; senão, fica sem vínculo.", $semNumero);
        $this->assertStringNotContainsString('nº 700', $semNumero, 'concluído não é "em aberto"');
        $this->assertStringNotContainsString('nº 815', $semNumero, 'o 815 é de CFTV (pelo assunto), e o orçamento é de portão');
        $this->assertStringEndsWith(OrcamentoDoGestor::PERGUNTA, $semNumero, 'o aviso não bloqueia: sem atendimento, segue sem vínculo');
    }

    public function test_pre_cliente_que_ja_existe_vale(): void
    {
        $msg = $this->previa($this->conversa(self::FELIPE, 'orçamento para a Clínica Horizonte pela GW, sem nota'), ['cliente' => 'gestor:-900', 'empresa_id' => 2, 'sem_nf' => true,
            'descricao' => 'Instalação de câmeras', 'itens' => [['nome' => 'Câmera', 'tipo' => 'produto', 'quantidade' => 4, 'valor_unitario' => 300]]]);

        $this->assertStringContainsString('Cliente: Clínica Horizonte (pré-cliente, Vitória)', $msg);
        $this->sim(SuporteConversa::where('telefone', self::FELIPE)->sole());
        $this->assertSame([null, 900], [SuporteGestorOrcamento::sole()->pedido['cliente_id'], SuporteGestorOrcamento::sole()->pedido['pre_cliente_id']]);
    }

    public function test_sim_grava_uma_vez_pela_fila_e_o_resultado_do_gestor_volta_com_numero_e_links(): void
    {
        $c = $this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest, com nota, conforme o atendimento 812');
        $this->previa($c, self::COMPLETO);

        $this->assertSame(OrcamentoDoGestor::CRIANDO, $this->sim($c));
        $fila = SuporteGestorOrcamento::sole();
        $this->assertSame('o'.$c->id.'-20261103100000', $fila->chave);
        // Pela coluna JSON, 450.0 volta como 450: o Gestor aceita os dois.
        $this->assertEquals(['autor_email' => 'felipe@gestor.local', 'pedido_por' => 'Felipe', 'cliente_id' => 501, 'pre_cliente_id' => null, 'empresa_id' => 3, 'sem_nf' => false,
            'atendimento' => '812', 'descricao' => 'Manutenção do motor do portão', 'observacoes' => 'Motor travando na abertura, conforme o atendimento 812.', 'validade' => null,
            'forma_pagamento' => null, 'prazo_pagamento' => null,
            'itens' => [['nome' => 'Manutenção do motor', 'tipo' => 'servico', 'quantidade' => 1, 'valor_unitario' => 450.0, 'descricao' => null],
                ['nome' => 'Controle 433 MHz', 'tipo' => 'produto', 'quantidade' => 3, 'valor_unitario' => 80.0, 'descricao' => null]],
            'total_previa' => 793.5], $fila->pedido);

        // O "sim" repetido (ou a mensagem que chegou duas vezes) não põe nada de novo na fila.
        $this->assertSame('Esse orçamento já foi enviado ao Gestor e está na fila, a máquina ainda não gravou. Assim que ele confirmar, mando aqui o número e o link.', $this->sim($c->fresh()));
        $this->assertSame(1, SuporteGestorOrcamento::count());

        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $lista = $this->postJson(route('suporte.runner.orcamentos'), ['maquina' => 'wsl'])->assertOk()->json('orcamentos');
        $this->assertSame([$fila->id, $fila->chave, 'felipe@gestor.local', 501], [$lista[0]['id'], $lista[0]['chave'], $lista[0]['autor_email'], $lista[0]['cliente_id']]);
        $this->assertSame([], $this->postJson(route('suporte.runner.orcamentos'), ['maquina' => 'wsl'])->json('orcamentos'), 'reservado não sai de novo');

        $this->postJson(route('suporte.runner.orcamento-feito', $fila->id), ['maquina' => 'wsl', 'ok' => true, 'orcamento_id' => 991, 'numero' => '045/2026', 'empresa' => 'INVEST',
            'valor_total' => 793.5, 'imposto_nf_percentual' => 15, 'link' => 'https://gestor.exemplo/orcamentos/991', 'pdf' => 'https://gestor.exemplo/orcamentos/991/imprimir', 'ja_estava' => false])->assertOk();
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === "✅ Orçamento criado no Gestor.\nNº 045/2026 — INVEST, em elaboração, com Felipe como vendedor.\n"
            ."Total calculado pelo Gestor: R$ 793,50 (com NF de 15%).\nAbrir no Gestor: https://gestor.exemplo/orcamentos/991\nPDF (com login no Gestor): https://gestor.exemplo/orcamentos/991/imprimir");

        $p = app(Contexto::class)->prompt($this->conversa(self::FELIPE, 'e o orçamento do Mar Azul?'));
        $this->assertStringContainsString('- Orçamento "Manutenção do motor do portão", prévia de R$ 793,50: GRAVADO, nº 045/2026', $p);
    }

    public function test_total_do_gestor_diferente_e_link_de_fora_nao_vao_calados(): void
    {
        $c = $this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest com nota, atendimento 812');
        $this->previa($c, self::COMPLETO);
        $this->sim($c);
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $id = $this->postJson(route('suporte.runner.orcamentos'), ['maquina' => 'wsl'])->json('orcamentos.0.id');

        $this->postJson(route('suporte.runner.orcamento-feito', $id), ['maquina' => 'wsl', 'ok' => true, 'numero' => '046/2026', 'empresa' => 'INVEST', 'valor_total' => 800.4,
            'imposto_nf_percentual' => 16, 'link' => 'https://outro.site/x', 'ja_estava' => true])->assertOk();
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === "Esse orçamento já estava no Gestor: nada foi criado de novo.\nNº 046/2026 — INVEST, em elaboração, com Felipe como vendedor.\n"
            ."Total calculado pelo Gestor: R$ 800,40 (com NF de 16%).\nNa prévia eu mostrei R$ 793,50: vale o do Gestor.");
        $this->assertNull(SuporteGestorOrcamento::sole()->resultado['link']);
    }

    public function test_correcao_antes_do_sim_vale_a_ultima_previa(): void
    {
        $c = $this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest com nota, atendimento 812');
        $this->previa($c, self::COMPLETO);
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => 'muda a manutenção para 500', 'enviada_em' => now()]);
        $itens = self::COMPLETO['itens'];
        $itens[0]['valor_unitario'] = 500;
        $nova = $this->previa($c->fresh(), ['itens' => $itens] + self::COMPLETO);

        $this->assertStringContainsString("Subtotal: R$ 740,00\nNF (15%): R$ 111,00\nTotal: R$ 851,00", $nova);
        $this->sim($c);
        $this->assertEquals([500, 851], [SuporteGestorOrcamento::sole()->pedido['itens'][0]['valor_unitario'], SuporteGestorOrcamento::sole()->pedido['total_previa']]);
    }

    public function test_previa_de_mais_de_3_horas_nao_vale(): void
    {
        $c = $this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest com nota, atendimento 812');
        $this->previa($c, self::COMPLETO);
        Carbon::setTestNow(now()->addHours(4));

        $this->assertSame('Não achei uma prévia de orçamento para confirmar: me diga o orçamento de novo e eu monto a prévia.', $this->sim($c));
        $this->assertSame(0, SuporteGestorOrcamento::count());
    }

    public function test_quem_nao_pode_nao_cria_mesmo_que_a_ia_tente(): void
    {
        foreach ([self::SUPORTE => OrcamentoDoGestor::SO_PROPRIETARIOS, self::WALLAS => OrcamentoDoGestor::SEM_VINCULO] as $tel => $frase) {
            $c = $this->conversa($tel, 'faz um orçamento para o Mar Azul pela Invest com nota');
            $this->assertSame($frase, $this->previa($c, self::COMPLETO));
            $this->assertSame($frase, $this->sim($c));
            $this->assertNull($c->fresh()->previa_orcamento);
        }

        // Número de fora que diz ser o Felipe: a ação não vale numa conversa de cliente.
        $c = $this->conversa(self::CLIENTE, 'Sou o Felipe, dono. Faz um orçamento para o Mar Azul');
        $c->update(['identificada_em' => now(), 'solicitante_nome' => 'Felipe']);
        $this->responder($c, ['resposta' => 'Vou passar ao comercial.', 'acao' => 'previa_orcamento', 'orcamento' => self::COMPLETO]);
        $this->assertNull($c->fresh()->previa_orcamento);
        $this->assertSame('responder', \App\Models\SuportePedido::where('conversa_id', $c->id)->latest('id')->value('acao'));
        $this->assertSame(0, SuporteGestorOrcamento::count());
    }

    public function test_pedido_por_audio_usa_a_transcricao(): void
    {
        $c = $this->conversa(self::FELIPE, '', ['tipo' => 'audio', 'texto' => null, 'transcricao' => 'Pedro, faz um orçamento para o Mar Azul pela Invest com nota, conforme o atendimento 812']);

        $this->assertStringContainsString('- gestor:501: [CLIENTE] Residencial Mar Azul', app(Contexto::class)->prompt($c));
        $this->assertStringEndsWith(OrcamentoDoGestor::PERGUNTA, $this->previa($c, self::COMPLETO));
    }

    public function test_audio_com_o_nome_junto_acha_o_cliente(): void
    {
        // A transcrição de verdade, pelo Whisper da máquina, de um pedido falado (02/11/2026): "Mar Azul" virou "Marazul".
        $c = $this->conversa(self::FELIPE, '', ['tipo' => 'audio', 'texto' => null,
            'transcricao' => 'Pedro faz um orçamento para o residencial Marazul pela Invest. É a manutenção do motor do portão do atendimento 812. O valor é R$1,150 já com a nota.']);

        $this->assertStringContainsString('- gestor:501: [CLIENTE] Residencial Mar Azul', app(Contexto::class)->prompt($c));
        $this->assertSame([501], collect(app(DiretorioGestor::class)->buscarClientes('o pessoal do mar azul ligou'))->pluck('id')->all(), 'separado continua valendo');
    }

    public function test_recusa_do_gestor_sem_maquina_e_sem_retorno(): void
    {
        $c = $this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest com nota, atendimento 812');
        $this->previa($c, self::COMPLETO);
        $this->sim($c);
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $id = $this->postJson(route('suporte.runner.orcamentos'), ['maquina' => 'wsl'])->json('orcamentos.0.id');
        $this->postJson(route('suporte.runner.orcamento-feito', $id), ['maquina' => 'wsl', 'ok' => false, 'erro' => 'o usuário de quem pediu não cria orçamento pela tela do Gestor'])->assertOk();
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === "❌ Não criei o orçamento no Gestor: o usuário de quem pediu não cria orçamento pela tela do Gestor. Nada ficou gravado.\n"
            .'Corrija o que precisar e eu remonto a prévia, ou responda "tentar de novo" para reenviar o mesmo pedido.');

        // Nenhuma máquina pegou: vence e avisa.
        $pendente = SuporteGestorOrcamento::create(['conversa_id' => $c->id, 'chave' => 'o-pendente', 'pedido_por' => 'Felipe', 'pedido' => ['descricao' => 'x', 'sem_nf' => true]]);
        // Pegou e não respondeu: avisa uma vez, para conferir; o resultado que chegar depois ainda vale.
        $preso = SuporteGestorOrcamento::create(['conversa_id' => $c->id, 'chave' => 'o-preso', 'pedido_por' => 'Felipe', 'pedido' => ['descricao' => 'y', 'sem_nf' => true],
            'situacao' => 'reservado', 'reservado_em' => now()]);
        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->artisan('suporte:pedro')->assertSuccessful();

        $this->assertSame(['falhou', 'nenhuma máquina gravou no Gestor'], [$pendente->fresh()->situacao, $pendente->fresh()->erro]);
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === "❌ Não criei o orçamento no Gestor: nenhuma máquina gravou no Gestor. Nada ficou gravado.\n"
            .'Corrija o que precisar e eu remonto a prévia, ou responda "tentar de novo" para reenviar o mesmo pedido.');
        $avisos = collect(Http::recorded())->filter(fn ($par) => str_starts_with((string) ($par[0]['text']['body'] ?? ''), 'O Gestor ainda não confirmou o orçamento que comecei a criar às 10:00.'));
        $this->assertCount(1, $avisos, 'o aviso de conferir sai uma vez só');
        $this->assertSame('reservado', $preso->fresh()->situacao);
        $this->postJson(route('suporte.runner.orcamento-feito', $preso->id), ['maquina' => 'wsl', 'ok' => true, 'numero' => '047/2026', 'valor_total' => 100])->assertOk();
        $this->assertSame('feito', $preso->fresh()->situacao);
    }

    // ── Ajustes do dono depois dos 7 cenários (02/11/2026, sessão 2; FR-022 a FR-027) ──────────────

    public function test_valor_com_nf_vira_valor_sem_nota_com_a_conta_e_a_quantidade_sugerida(): void
    {
        // Cenário 4 da bateria de 02/11: "R$ 1.150 já com a nota", sem dizer a quantidade.
        $c = $this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest, manutenção do portão, 1.150 já com nota, conforme o 812');
        $msg = $this->previa($c, ['cliente' => 'gestor:501', 'empresa_id' => 3, 'sem_nf' => null, 'valor_com_nf' => true, 'atendimento' => '812', 'descricao' => 'Manutenção do portão',
            'itens' => [['nome' => 'Manutenção do portão', 'tipo' => 'servico', 'quantidade' => null, 'valor_unitario' => 1150]]]);

        $this->assertStringContainsString("Nota fiscal: com NF (15% da INVEST no Gestor)\n", $msg, 'o valor "já com nota" diz que há nota');
        $this->assertStringContainsString("• Item 1: 1 × Manutenção do portão (serviço) — R$ 1.000,00 (quantidade 1 sugerida por mim)\n"
            ."Subtotal: R$ 1.000,00\nNF (15%): R$ 150,00\nTotal: R$ 1.150,00\n"
            ."Conta da nota: você disse o valor já com NF; tirei os 15% da INVEST:\n"
            ."• Item 1: R$ 1.150,00 ÷ 1,15 = R$ 1.000,00 sem nota\n"
            .'O Gestor soma a NF de volta e o total fica R$ 1.150,00, igual ao que você disse.', $msg);
        $this->assertStringEndsWith(OrcamentoDoGestor::PERGUNTA_COM_SUGERIDA, $msg);

        $this->assertSame(OrcamentoDoGestor::CRIANDO, $this->sim($c));
        $pedido = SuporteGestorOrcamento::sole()->pedido;
        $this->assertEquals([1, 1000, false, 1150], [$pedido['itens'][0]['quantidade'], $pedido['itens'][0]['valor_unitario'], $pedido['sem_nf'], $pedido['total_previa']]);
    }

    public function test_valor_com_nf_que_nao_bate_no_centavo_mostra_a_diferenca_e_o_total_de_um_item(): void
    {
        // R$ 1.000 com 15%: nenhum centavo sem nota dá R$ 1.000,00 de volta pela regra do Gestor.
        $msg = $this->previa($this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest, revisão do portão, mil reais com nota'),
            ['cliente' => 'gestor:501', 'empresa_id' => 3, 'sem_nf' => false, 'valor_com_nf' => true, 'sem_vinculo' => true, 'descricao' => 'Revisão do portão',
                'itens' => [['nome' => 'Revisão', 'tipo' => 'servico', 'quantidade' => 1, 'valor_unitario' => 1000]]]);
        $this->assertStringContainsString("Total: R$ 1.000,01\n", $msg);
        $this->assertStringContainsString("• Item 1: R$ 1.000,00 ÷ 1,15 ≈ R$ 869,57 sem nota\n"
            .'O Gestor soma a NF de volta e o total fica R$ 1.000,01: R$ 0,01 acima do que você disse, por arredondamento de centavos.', $msg);

        // O TOTAL com nota de um item com quantidade: duas instalações, R$ 2.300 com nota.
        $total = $this->previa($this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest, 2 instalações de motor de portão, 2.300 no total com nota'),
            ['cliente' => 'gestor:501', 'empresa_id' => 3, 'sem_nf' => false, 'total_com_nf' => 2300, 'sem_vinculo' => true, 'descricao' => 'Instalação de motores de portão',
                'itens' => [['nome' => 'Instalação de motor', 'tipo' => 'servico', 'quantidade' => 2, 'valor_unitario' => null]]]);
        $this->assertStringContainsString("• Item 1: 2 × Instalação de motor (serviço) — R$ 1.000,00 cada = R$ 2.000,00\n", $total);
        $this->assertStringContainsString("• Total com NF R$ 2.300,00 ÷ 1,15 = R$ 2.000,00 sem nota (2 × R$ 1.000,00)\n"
            .'O Gestor soma a NF de volta e o total fica R$ 2.300,00, igual ao que você disse.', $total);
        $this->assertStringEndsWith(OrcamentoDoGestor::PERGUNTA, $total);
    }

    public function test_valor_com_nf_sem_como_calcular_diz_o_que_falta(): void
    {
        $base = ['cliente' => 'gestor:501', 'empresa_id' => 3, 'sem_vinculo' => true, 'descricao' => 'Revisão do portão'];
        $semNota = $this->previa($this->conversa(self::FELIPE, 'Mar Azul pela Invest, sem nota, 1.150 com nota'), $base + ['sem_nf' => true, 'valor_com_nf' => true,
            'itens' => [['nome' => 'Revisão', 'tipo' => 'servico', 'quantidade' => 1, 'valor_unitario' => 1150]]]);
        $this->assertStringContainsString('• você disse o valor já com a nota, e o orçamento está sem nota: sem nota, qual o valor de cada item?', $semNota);

        $varios = $this->previa($this->conversa(self::FELIPE, 'Mar Azul pela Invest, motor e controle, 2.000 no total com nota'), $base + ['sem_nf' => false, 'total_com_nf' => 2000,
            'itens' => [['nome' => 'Motor', 'tipo' => 'produto', 'quantidade' => 1, 'valor_unitario' => null], ['nome' => 'Controle', 'tipo' => 'produto', 'quantidade' => 2, 'valor_unitario' => null]]]);
        $this->assertStringContainsString('• com mais de um item, me diga o valor de cada um (com ou sem nota), e não só o total.', $varios);

        $this->retrato(null);
        $semPercentual = $this->previa($this->conversa(self::FELIPE, 'Mar Azul pela Invest, 1.150 com nota'), $base + ['sem_nf' => false, 'valor_com_nf' => true,
            'itens' => [['nome' => 'Revisão', 'tipo' => 'servico', 'quantidade' => 1, 'valor_unitario' => 1150]]]);
        $this->assertStringContainsString('• não tenho agora o percentual de NF da INVEST: me diga o valor de cada item sem a nota.', $semPercentual);
    }

    public function test_quantidade_so_e_sugerida_no_servico_unico(): void
    {
        $material = $this->previa($this->conversa(self::FELIPE, 'Mar Azul pela Invest com nota, controle de portão a 80'), ['cliente' => 'gestor:501', 'empresa_id' => 3,
            'sem_nf' => false, 'sem_vinculo' => true, 'descricao' => 'Controle de portão', 'itens' => [['nome' => 'Controle', 'tipo' => 'produto', 'quantidade' => null, 'valor_unitario' => 80]]]);
        $this->assertStringContainsString('• falta a quantidade: item 1 (Controle).', $material);
        $this->assertStringNotContainsString('sugerida', $material);

        // Cenário 1 da bateria de 02/11: "manutenção R$ 450 e 3 controles" — o serviço é um só, e os controles têm quantidade.
        $comMaterial = $this->previa($this->conversa(self::FELIPE, 'Mar Azul pela Invest com nota, conforme o 812: manutenção 450 e 3 controles a 80'),
            ['itens' => [['nome' => 'Manutenção do motor', 'tipo' => 'servico', 'quantidade' => null, 'valor_unitario' => 450]] + [1 => self::COMPLETO['itens'][1]]] + self::COMPLETO);
        $this->assertStringContainsString("• Item 1: 1 × Manutenção do motor (serviço) — R$ 450,00 (quantidade 1 sugerida por mim)
• Item 2: 3 × Controle 433 MHz (material) — R$ 80,00 cada = R$ 240,00
", $comMaterial);
        $this->assertStringEndsWith(OrcamentoDoGestor::PERGUNTA_COM_SUGERIDA, $comMaterial);

        // Dois serviços sem quantidade: pergunta.
        $doisServicos = $this->previa($this->conversa(self::FELIPE, 'Mar Azul pela Invest com nota: manutenção e visita'), ['itens' => [
            ['nome' => 'Manutenção', 'tipo' => 'servico', 'quantidade' => null, 'valor_unitario' => 450], ['nome' => 'Visita', 'tipo' => 'servico', 'quantidade' => null, 'valor_unitario' => 150]]] + self::COMPLETO);
        $this->assertStringContainsString('• falta a quantidade: item 1 (Manutenção) e item 2 (Visita).', $doisServicos);
    }

    public function test_com_o_que_falta_o_cabecalho_diz_o_atendimento_achado(): void
    {
        // Cenário 2 da bateria de 02/11: "pega aquele atendimento do Mar Azul sobre o portão".
        $msg = $this->previa($this->conversa(self::FELIPE, 'Pedro, pega aquele atendimento do Mar Azul sobre o portão e monta um orçamento para mim.'),
            ['cliente' => 'gestor:501', 'empresa_id' => null, 'sem_nf' => null, 'atendimento' => '812', 'descricao' => 'Motor do portão travando na abertura', 'itens' => []]);
        $this->assertStringStartsWith("Felipe, para montar o orçamento para Residencial Mar Azul (atendimento nº 812 — Motor do portão travando na abertura) falta acertar:\n"
            .'• falta a empresa que emite o orçamento (DELTA, GW, INVEST) — no cadastro, o cliente é da INVEST.', $msg);
    }

    public function test_atendimento_so_do_mesmo_assunto_e_sem_vinculo_nao_volta(): void
    {
        // Cenário 3 da bateria de 02/11: controles de portão para a Padaria Sol, que tem o 813 (câmera) em aberto.
        $c = $this->conversa(self::FELIPE, '3 controles de portão para a Padaria Sol pela Delta, sem nota, 80 cada');
        $msg = $this->previa($c, ['cliente' => 'gestor:503', 'empresa_id' => 1, 'sem_nf' => true, 'descricao' => '3 controles de portão',
            'itens' => [['nome' => 'Controle de portão', 'tipo' => 'produto', 'quantidade' => 3, 'valor_unitario' => 80]]]);
        $this->assertStringNotContainsString('813', $msg, 'câmera não é sugerida para controle de portão');
        $this->assertStringContainsString('• Padaria Sol tem o atendimento nº 814 — Portão da garagem não fecha em aberto, do mesmo assunto: se o orçamento for dele, diga; senão, fica sem vínculo.', $msg);

        // Ele disse que não é de nenhum: a pergunta não volta, e o resto da prévia fica.
        $semVinculo = $this->corrigir($c, 'não é de nenhum atendimento', ['atendimento' => 'nenhum']);
        $this->assertStringNotContainsString('Atenção', $semVinculo);
        $this->assertStringContainsString("Atendimento relacionado: sem vínculo\nDescrição: 3 controles de portão\n", $semVinculo);
        $this->assertTrue($c->fresh()->previa_orcamento['orcamento']['sem_vinculo']);

        // O que ele citou de outro assunto vale, com um aviso que não trava.
        $citado = $this->previa($this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest com nota, motor do portão, conforme o 815'), ['atendimento' => '815'] + self::COMPLETO);
        $this->assertStringContainsString('• o atendimento nº 815 é de câmeras, e o orçamento é de portão: confira se o vínculo está certo.', $citado);
        $this->assertStringEndsWith(OrcamentoDoGestor::PERGUNTA, $citado);

        $this->assertSame(['portão'], OrcamentoDoGestor::temas('3 controles de portão'));
        $this->assertSame(['câmeras'], OrcamentoDoGestor::temas('Câmera da entrada sem imagem'));
        $this->assertSame(['controle de acesso'], OrcamentoDoGestor::temas('Controle de acesso facial na portaria'), '"controle de acesso" não é controle de portão');
        $this->assertSame(['alarme'], OrcamentoDoGestor::temas('Manutenção da cerca elétrica'));
        $this->assertSame([], OrcamentoDoGestor::temas('Visita técnica'));
    }

    public function test_faltas_agrupadas_e_o_aviso_dado_nao_se_repete_na_correcao(): void
    {
        $c = $this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest com nota: motor e cremalheira do portão');
        $msg = $this->previa($c, ['cliente' => 'gestor:501', 'empresa_id' => 3, 'sem_nf' => false, 'descricao' => 'Troca do motor do portão',
            'itens' => [['nome' => 'Motor', 'tipo' => 'produto', 'quantidade' => 1, 'valor_unitario' => null], ['nome' => 'Cremalheira', 'tipo' => 'produto', 'quantidade' => 2, 'valor_unitario' => null]]]);
        $this->assertStringContainsString("falta acertar:\n• falta o valor: item 1 (Motor) e item 2 (Cremalheira).\n\nAtenção:\n• Residencial Mar Azul tem o atendimento nº 812", $msg);

        $nova = $this->corrigir($c, 'motor 1.800 e cremalheira 120 cada', ['itens' => [['item' => 1, 'valor_unitario' => 1800], ['item' => 2, 'valor_unitario' => 120]]]);
        $this->assertStringContainsString("• Item 1: 1 × Motor (material) — R$ 1.800,00\n• Item 2: 2 × Cremalheira (material) — R$ 120,00 cada = R$ 240,00\n", $nova);
        $this->assertStringNotContainsString('Atenção', $nova, 'o aviso do 812 já foi dado nesta prévia');
        $this->assertStringEndsWith(OrcamentoDoGestor::PERGUNTA, $nova);
    }

    public function test_correcao_por_partes_mantem_o_que_nao_mudou(): void
    {
        $c = $this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest, com nota, conforme o atendimento 812');
        $this->previa($c, self::COMPLETO);

        $valor = $this->corrigir($c, 'muda o item 2 para 90', ['itens' => [['item' => 2, 'valor_unitario' => 90]]]);
        $this->assertStringContainsString("Descrição: Manutenção do motor do portão\nItens:\n• Item 1: 1 × Manutenção do motor (serviço) — R$ 450,00\n"
            ."• Item 2: 3 × Controle 433 MHz (material) — R$ 90,00 cada = R$ 270,00\nSubtotal: R$ 720,00\nNF (15%): R$ 108,00\nTotal: R$ 828,00\n"
            .'Observações: Motor travando na abertura, conforme o atendimento 812.', $valor);

        $delta = $this->corrigir($c, 'é pela Delta', ['empresa_id' => 1]);
        $this->assertStringContainsString("Empresa: DELTA\n", $delta);
        $this->assertStringContainsString("NF (17%): R$ 122,40\nTotal: R$ 842,40", $delta, 'a conta é refeita com o percentual da empresa nova');

        $semNota = $this->corrigir($c, 'sem nota', ['sem_nf' => true]);
        $this->assertStringContainsString("Nota fiscal: sem NF\n", $semNota);
        $this->assertStringContainsString("Subtotal: R$ 720,00\nTotal: R$ 720,00", $semNota);

        $this->corrigir($c, 'tira os controles, põe uma visita de 150 e a descrição é revisão do motor', ['descricao' => 'Revisão do motor do portão',
            'itens' => [['item' => 2, 'remover' => true], ['nome' => 'Visita técnica', 'tipo' => 'servico', 'quantidade' => 1, 'valor_unitario' => 150]]]);
        $this->assertSame(OrcamentoDoGestor::CRIANDO, $this->sim($c));
        $pedido = SuporteGestorOrcamento::sole()->pedido;
        $this->assertSame([1, true, 'Revisão do motor do portão', '812', 'Motor travando na abertura, conforme o atendimento 812.'],
            [$pedido['empresa_id'], $pedido['sem_nf'], $pedido['descricao'], $pedido['atendimento'], $pedido['observacoes']]);
        $this->assertEquals([['Manutenção do motor', 450], ['Visita técnica', 150]], collect($pedido['itens'])->map(fn ($i) => [$i['nome'], $i['valor_unitario']])->all());
        $this->assertEquals(600, $pedido['total_previa']);
    }

    public function test_correcao_depois_de_enviado(): void
    {
        $c = $this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest, com nota, conforme o atendimento 812');
        $this->previa($c, self::COMPLETO);
        $this->sim($c);
        [$id] = $this->reservar();
        // O Gestor recusou: nada ficou lá, e a correção vira uma prévia nova, com outra chave.
        $this->feito($id, ['ok' => false, 'erro' => 'o atendimento nº 812 é de outro cliente']);
        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertStringEndsWith(OrcamentoDoGestor::PERGUNTA, $this->corrigir($c, 'tira o vínculo', ['atendimento' => 'nenhum']));
        $this->assertSame(OrcamentoDoGestor::CRIANDO, $this->sim($c));
        $this->assertSame(['falhou', 'pendente'], SuporteGestorOrcamento::orderBy('id')->pluck('situacao')->all());
        $this->assertNull(SuporteGestorOrcamento::latest('id')->first()->pedido['atendimento']);

        [$id2] = $this->reservar();
        $this->feito($id2, ['ok' => true, 'orcamento_id' => 991, 'numero' => '045/2026', 'valor_total' => 793.5, 'link' => 'https://gestor.exemplo/orcamentos/991']);
        $this->assertSame('Esse orçamento já está no Gestor (nº 045/2026): a mudança agora é na tela do Gestor, em https://gestor.exemplo/orcamentos/991.',
            $this->corrigir($c, 'muda a manutenção para 500', ['itens' => [['item' => 1, 'valor_unitario' => 500]]]));
    }

    public function test_acompanha_e_tentar_de_novo_reenvia_o_mesmo_pedido(): void
    {
        $c = $this->conversa(self::FELIPE, 'orçamento para o Mar Azul pela Invest, com nota, conforme o atendimento 812');
        $this->previa($c, self::COMPLETO);
        $this->sim($c);
        [$id, $chave] = $this->reservar();
        // A ponte não respondeu: não dá para saber se gravou. O aviso diz isso, e a repetição usa a mesma chave.
        $this->feito($id, ['ok' => false, 'erro' => 'a ponte não devolveu resposta — conferir no Gestor antes de repetir']);
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === '⚠️ O Gestor não confirmou o orçamento: a ponte não devolveu resposta — conferir no Gestor antes de repetir. Ele pode ter sido criado.'
            ."\n".'Responda "tentar de novo" e eu reenvio o MESMO pedido: se ele já estiver lá, só trago o número, sem criar outro.');

        // Uma prévia nova para o mesmo cliente fica travada até a repetição.
        Carbon::setTestNow(now()->addMinutes(1));
        $trava = $this->previa($this->conversa(self::FELIPE, 'faz de novo o orçamento do Mar Azul pela Invest'), self::COMPLETO);
        $this->assertStringContainsString('• o orçamento que confirmei às 10:00 para este cliente ainda não teve resposta certa do Gestor: responda "tentar de novo"', $trava);
        $this->assertStringStartsWith('Ainda não criei, porque conferi no Gestor e falta acertar:', $this->sim($c->fresh()));

        $this->assertStringStartsWith('Reenviei ao Gestor o MESMO pedido, com a mesma chave', $this->repetir($c));
        $this->assertSame([1, 'pendente'], [SuporteGestorOrcamento::count(), SuporteGestorOrcamento::sole()->situacao]);
        $this->artisan('suporte:pedro')->assertSuccessful();
        $this->assertSame('pendente', SuporteGestorOrcamento::sole()->situacao, 'o prazo da máquina conta da volta à fila');

        $this->assertSame([$id, $chave], $this->reservar());
        $this->feito($id, ['ok' => true, 'orcamento_id' => 991, 'numero' => '045/2026', 'empresa' => 'INVEST', 'valor_total' => 793.5, 'imposto_nf_percentual' => 15,
            'link' => 'https://gestor.exemplo/orcamentos/991', 'pdf' => 'https://gestor.exemplo/orcamentos/991/imprimir', 'ja_estava' => true]);
        Http::assertSent(fn ($r) => str_starts_with((string) ($r['text']['body'] ?? ''), "Esse orçamento já estava no Gestor: nada foi criado de novo.\nNº 045/2026 — INVEST"));
        $this->assertSame('Esse orçamento já está no Gestor: nº 045/2026 — https://gestor.exemplo/orcamentos/991. Para outro orçamento, me diga qual e eu monto a prévia.', $this->repetir($c));
    }

    public function test_sem_retorno_tambem_se_repete_com_a_mesma_chave(): void
    {
        $c = $this->conversa(self::FELIPE, 'orçamento para o Mar Azul');
        $preso = SuporteGestorOrcamento::create(['conversa_id' => $c->id, 'chave' => 'o-preso', 'pedido_por' => 'Felipe', 'pedido' => ['descricao' => 'y', 'sem_nf' => true, 'cliente_id' => 501],
            'situacao' => 'reservado', 'reservado_em' => now()]);
        $this->assertSame('Esse orçamento já foi enviado ao Gestor e está gravando agora. Assim que ele confirmar, mando aqui o número e o link.', $this->repetir($c),
            'gravando agora: não repete no meio da gravação');

        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('suporte:pedro')->assertSuccessful();
        Http::assertSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), 'Responda "tentar de novo" e eu reenvio o mesmo pedido, sem criar outro'));
        $this->assertStringStartsWith('Reenviei ao Gestor o MESMO pedido', $this->repetir($c));
        $this->assertSame(['pendente', 'o-preso', null], [$preso->fresh()->situacao, $preso->fresh()->chave, $preso->fresh()->erro]);
    }

    public function test_fecho_do_bloco_com_letra_repetida_ainda_vale(): void
    {
        $bloco = Contexto::ler("<<<PEDRO\n".json_encode(['resposta' => 'Vou montar.', 'acao' => 'previa_orcamento', 'orcamento' => self::COMPLETO], JSON_UNESCAPED_UNICODE)."\nPPEDRO>>>", 3500);

        $this->assertSame('previa_orcamento', $bloco['acao'] ?? null);
        $this->assertSame('gestor:501', $bloco['orcamento']['cliente'] ?? null);
        $this->assertNull(Contexto::ler('<<<PEDRO {"resposta": "x", "acao": "responder"', 3500), 'sem fecho nenhum continua fora do formato');
    }

    public function test_lista_aceita_o_email_do_gestor_e_recusa_o_que_nao_e_email(): void
    {
        $this->assertSame([], AcessoInterno::linhasInvalidas("+55 27 99884-5397 | Felipe | diretor | felipe@gestor.local\n+55 27 3109-3265 | Alexandre | suporte"));
        $this->assertSame(['+55 27 99884-5397 | Felipe | diretor | felipe'], AcessoInterno::linhasInvalidas('+55 27 99884-5397 | Felipe | diretor | felipe'));
        $this->assertSame('felipe@gestor.local', app(AcessoInterno::class)->deQuem(self::FELIPE)['gestor_email']);
        $this->assertNull(app(AcessoInterno::class)->deQuem(self::WALLAS)['gestor_email']);
    }

    public function test_retrato_da_maquina_traz_as_empresas_com_o_percentual(): void
    {
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $base = ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(), 'clientes' => [], 'atendimentos' => []];

        $this->postJson(route('suporte.runner.gestor'), $base + ['empresas' => [['id' => 3, 'nome' => 'INVEST', 'imposto_nf' => 15]]])->assertOk();
        $this->assertSame([3 => ['id' => 3, 'nome' => 'INVEST', 'imposto_nf' => 15.0]], app(DiretorioGestor::class)->empresas());

        $this->postJson(route('suporte.runner.gestor'), $base)->assertOk();
        $this->assertSame([], (new DiretorioGestor)->empresas(), 'máquina antiga: sem a chave, sem percentual');
        $this->postJson(route('suporte.runner.gestor'), $base + ['empresas' => [['id' => 3, 'nome' => 'INVEST', 'imposto_nf' => 150]]])->assertStatus(422);
    }
}
