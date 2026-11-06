<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\SuporteConversa;
use App\Models\SuporteRoteiro;
use App\Models\User;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Felipe, 06/11/2026: "o Pedro tem que saber que ele entrou em contato no dia anterior, ler o que foi feito para
 * ele no andamento e fazer o que eu fiz, informar que é só abrir o sincronizador". Os dados são fictícios.
 */
class PedroSabeOQueJaAconteceuTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFONE = '5527988887777';

    private const ROTEIRO = 'Catraca em "Apresente o cartão" que não libera (AlfaSync)';

    private User $felipe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-06 09:22', 'America/Sao_Paulo'));
        config(['services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.empresas' => ['INVEST']]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['email' => 'felipe@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
    }

    /** @param list<array> $andamentos */
    private function retrato(array $andamentos, string $telefoneDoCadastro = '(27) 98888-7777'): void
    {
        app(DiretorioGestor::class)->gravar('wsl', now()->toDateTimeString(), [
            ['id' => 9, 'nome' => 'ESCOLA EXEMPLO', 'fantasia' => 'ESCOLA EXEMPLO', 'razao' => '', 'cidade' => 'SERRA', 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => [$telefoneDoCadastro], 'documento' => ''],
        ], [
            ['id' => 952, 'numero' => '839', 'cliente_id' => 9, 'descricao' => "Assunto: SUPORTE TÉCNICO\nCatracas paradas", 'status' => 'aberto', 'empresa_id' => 3, 'assunto_id' => 5,
                'criado_em' => '2026-11-03 08:47:00', 'funcionarios' => [], 'andamentos' => $andamentos],
        ], [['id' => 5, 'nome' => 'SUPORTE TÉCNICO', 'tipo' => 'SERVICO', 'categoria' => 'SUPORTE', 'empresa_id' => 3]]);
    }

    private function conversa(): SuporteConversa
    {
        return SuporteConversa::create(['telefone' => self::TELEFONE, 'gestor_cliente_id' => 9, 'situacao' => 'pedro', 'solicitante_nome' => 'Joaquim', 'ultima_mensagem_em' => now()]);
    }

    private function mensagem(SuporteConversa $c, string $direcao, string $texto, string $quando): void
    {
        $m = $c->mensagens()->create(['direcao' => $direcao, 'tipo' => 'text', 'texto' => $texto]);
        $m->forceFill(['created_at' => Carbon::parse($quando, 'America/Sao_Paulo')])->save();
    }

    public function test_o_pedido_traz_o_que_a_equipe_registrou_e_os_dias_em_que_a_pessoa_falou_disso(): void
    {
        $this->retrato([
            ['em' => '2026-11-04 09:18', 'texto' => "Novo pedido no mesmo atendimento pelo WhatsApp — Joaquim:\nCatracas sem liberar.\n\n(Aberto pelo Pedro, suporte no WhatsApp — Joaquim, +55 (27) 98888-7777.)", 'pedro' => true],
            ['em' => '2026-11-05 08:01', 'texto' => "Novo pedido no mesmo atendimento pelo WhatsApp — Joaquim:\nContinuam sem liberar.", 'pedro' => true],
            ['em' => '2026-11-05 08:02', 'texto' => "Novo pedido no mesmo atendimento pelo WhatsApp — Joaquim:\nE a segunda também.", 'pedro' => true],
            ['em' => '2026-11-05 14:30', 'texto' => 'Mostrado ao cliente como abrir o AlfaSync no computador da portaria; catracas voltaram.', 'pedro' => false],
        ]);

        $prompt = app(Contexto::class)->prompt($this->conversa());

        $this->assertStringContainsString('Registrado pela equipe em 05/11: Mostrado ao cliente como abrir o AlfaSync', $prompt);
        $this->assertStringContainsString('A pessoa falou disso pelo WhatsApp em: 04/11, 05/11.', $prompt);
        // O relato de quem escreveu não vira trabalho da equipe, e a assinatura com o telefone não vai.
        $this->assertStringNotContainsString('Registrado pela equipe em 04/11', $prompt);
        $this->assertStringNotContainsString('Catracas sem liberar', $prompt);
        $this->assertStringNotContainsString('Continuam sem liberar', $prompt);
        $this->assertStringNotContainsString('E a segunda também', $prompt);
        $this->assertStringNotContainsString('98888-7777', $prompt);
        $this->assertStringContainsString(Contexto::O_QUE_JA_ACONTECEU, $prompt);
    }

    public function test_troca_de_etapa_sem_texto_cobranca_e_ordem_da_direcao_nao_vao_ao_pedido(): void
    {
        $this->retrato([
            ['em' => '2026-10-20 09:05', 'texto' => "Alteração de status/prioridade:\nRealizado o reinicio do programa e ficou online normalmente", 'pedro' => false],
            ['em' => '2026-10-20 09:06', 'texto' => "Alteração de status/prioridade:\nFinalizado", 'pedro' => false],
            ['em' => '2026-10-20 09:07', 'texto' => "Encaminhado ao comercial para COBRANÇA (atendimento concluído):\nCobrar do cliente a troca", 'pedro' => false],
            ['em' => '2026-10-20 09:08', 'texto' => 'Direção: chamado na fila de outro técnico.', 'pedro' => false],
            ['em' => '2026-10-20 09:09', 'texto' => 'Central de IA e158 — orientação da direção em 20/10: transferido.', 'pedro' => false],
            ['em' => '2026-10-21 10:00', 'texto' => "Técnico agendado para a portaria.\n\n(Registrado pelo Pedro a pedido de Fulano (+55 (27) 3109-0000).)", 'pedro' => false],
        ]);

        $prompt = app(Contexto::class)->prompt($this->conversa());

        $this->assertStringContainsString('Registrado pela equipe em 20/10: Realizado o reinicio do programa', $prompt);
        // O que uma pessoa da casa pediu para registrar é da equipe, mas a assinatura com o telefone dela não vai.
        $this->assertStringContainsString('Registrado pela equipe em 21/10: Técnico agendado para a portaria.', $prompt);
        $this->assertStringNotContainsString('3109-0000', $prompt);
        $this->assertStringNotContainsString('Finalizado', $prompt);
        $this->assertStringNotContainsString('comercial para COBRANÇA', $prompt);
        $this->assertStringNotContainsString('Cobrar do cliente', $prompt);
        $this->assertStringNotContainsString('na fila de outro técnico', $prompt);
        $this->assertStringNotContainsString('transferido.', $prompt);
    }

    public function test_so_os_tres_registros_mais_novos_vao_ao_pedido(): void
    {
        $this->retrato(array_map(fn ($i) => ['em' => "2026-10-0{$i} 09:00", 'texto' => "Registro número {$i} da equipe no chamado", 'pedro' => false], range(1, 5)));

        $prompt = app(Contexto::class)->prompt($this->conversa());

        $this->assertStringNotContainsString('Registro número 2', $prompt);
        $this->assertStringContainsString('Registro número 3', $prompt);
        $this->assertStringContainsString('Registro número 5', $prompt);
    }

    public function test_quem_escreve_de_fora_do_cadastro_nao_recebe_o_andamento(): void
    {
        $this->retrato([['em' => '2026-11-05 14:30', 'texto' => 'Mostrado ao cliente como abrir o programa.', 'pedro' => false],
            ['em' => '2026-11-05 15:00', 'texto' => 'Relato anterior', 'pedro' => true]], '(27) 97777-1111');

        $prompt = app(Contexto::class)->prompt($this->conversa());

        $this->assertStringContainsString('POR SEGURANÇA', $prompt);
        $this->assertStringNotContainsString('Mostrado ao cliente', $prompt);
        $this->assertStringNotContainsString('A pessoa falou disso pelo WhatsApp', $prompt);
    }

    public function test_retrato_de_maquina_antiga_sem_andamentos_nao_quebra_nem_acrescenta_linha(): void
    {
        app(DiretorioGestor::class)->gravar('wsl', now()->toDateTimeString(), [
            ['id' => 9, 'nome' => 'ESCOLA EXEMPLO', 'fantasia' => 'ESCOLA EXEMPLO', 'razao' => '', 'cidade' => 'SERRA', 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => ['(27) 98888-7777'], 'documento' => ''],
        ], [['id' => 952, 'numero' => '839', 'cliente_id' => 9, 'descricao' => 'Catracas paradas', 'status' => 'aberto', 'criado_em' => '2026-11-03 08:47:00']]);

        $prompt = app(Contexto::class)->prompt($this->conversa());

        $this->assertStringContainsString('Gestor nº 839: Catracas paradas', $prompt);
        $this->assertStringNotContainsString('Registrado pela equipe', $prompt);
    }

    /** O controller só guarda o que as regras dele conhecem: sem a regra, o andamento sumiria em silêncio. */
    public function test_o_andamento_que_a_maquina_manda_chega_ao_retrato(): void
    {
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $andamento = ['em' => '2026-11-05 14:30', 'texto' => 'Mostrado ao cliente como abrir o programa.', 'pedro' => false];
        $corpo = fn (array $a) => ['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(), 'clientes' => [],
            'atendimentos' => [['id' => 952, 'numero' => '839', 'cliente_id' => 9, 'descricao' => 'Catracas', 'status' => 'aberto', 'criado_em' => '2026-11-03 08:47:00', 'funcionarios' => []] + $a]];

        $this->postJson(route('suporte.runner.gestor'), $corpo(['andamentos' => [$andamento]]))->assertOk();
        $this->assertSame([$andamento], json_decode(DB::table('suporte_gestor_retrato')->value('atendimentos'), true)[0]['andamentos']);

        // Máquina antiga, sem o campo: continua valendo.
        $this->postJson(route('suporte.runner.gestor'), $corpo([]))->assertOk();
        $this->assertArrayNotHasKey('andamentos', json_decode(DB::table('suporte_gestor_retrato')->value('atendimentos'), true)[0]);

        // Data esquisita no Gestor não derruba o retrato inteiro.
        $this->postJson(route('suporte.runner.gestor'), $corpo(['andamentos' => [['em' => '', 'texto' => 'Sem data', 'pedro' => false]]]))->assertOk();
    }

    public function test_as_mensagens_marcam_o_dia_quando_a_conversa_vem_de_outro_dia(): void
    {
        $this->retrato([]);
        $c = $this->conversa();
        $this->mensagem($c, 'entrada', 'As catracas não liberam.', '2026-11-04 07:30');
        $this->mensagem($c, 'entrada', 'Continuam com o mesmo problema.', '2026-11-05 08:01');
        $this->mensagem($c, 'pedro', 'O caso segue com a equipe.', '2026-11-05 08:02');
        $this->mensagem($c, 'entrada', 'Preciso fazer isso todas as manhãs?', '2026-11-06 08:41');

        $prompt = app(Contexto::class)->prompt($c);

        $this->assertStringContainsString("[04/11]\nCLIENTE: As catracas não liberam.\n[05/11, ontem]\nCLIENTE: Continuam com o mesmo problema.\nPEDRO: O caso segue com a equipe.\n[06/11, hoje]\nCLIENTE: Preciso fazer isso todas as manhãs?", $prompt);
    }

    public function test_a_conversa_de_hoje_nao_leva_marca_de_dia(): void
    {
        $this->retrato([]);
        $c = $this->conversa();
        $this->mensagem($c, 'entrada', 'As catracas não liberam.', '2026-11-06 07:30');
        $this->mensagem($c, 'pedro', 'Vamos ver isso.', '2026-11-06 07:31');

        $prompt = app(Contexto::class)->prompt($c);

        $this->assertStringContainsString("CLIENTE: As catracas não liberam.\nPEDRO: Vamos ver isso.", $prompt);
        $this->assertStringNotContainsString('hoje]', $prompt);
    }

    public function test_a_virada_do_dia_separa_a_noite_de_ontem_da_madrugada_de_hoje(): void
    {
        $this->retrato([]);
        $c = $this->conversa();
        $this->mensagem($c, 'entrada', 'Mensagem da noite.', '2026-11-05 23:59');
        $this->mensagem($c, 'entrada', 'Mensagem da madrugada.', '2026-11-06 00:01');

        $this->assertStringContainsString("[05/11, ontem]\nCLIENTE: Mensagem da noite.\n[06/11, hoje]\nCLIENTE: Mensagem da madrugada.", app(Contexto::class)->prompt($c));
    }

    public function test_o_roteiro_nasce_antes_dos_outros_de_catraca_e_chega_ao_pedido(): void
    {
        $roteiros = app(Conhecimento::class)->roteiros()->pluck('titulo')->values();
        $this->assertLessThan($roteiros->search('Catraca desligada ou sem comunicação'), $roteiros->search(self::ROTEIRO));

        $texto = app(Conhecimento::class)->roteirosParaOPrompt();
        $this->assertStringContainsString('"Abrir AlfaSync"', $texto);
        $this->assertStringContainsString('todas as manhãs', $texto);
        $this->assertStringContainsString('Provavelmente o computador está sendo desligado', $texto);
        // Quem resolve abrindo o programa não pode gerar chamado por causa do roteiro.
        $this->assertStringContainsString('Não abre atendimento por si só, prioridade alta', $texto);
    }

    public function test_rodar_a_migracao_de_novo_nao_duplica_nem_desfaz_o_que_foi_editado_pela_tela(): void
    {
        SuporteRoteiro::where('titulo', self::ROTEIRO)->update(['orientacao' => 'Texto da tela.', 'atualizado_por' => $this->felipe->id]);

        (require database_path('migrations/2026_11_06_130000_roteiro_catraca_apresente_o_cartao.php'))->up();

        $this->assertSame(1, SuporteRoteiro::where('titulo', self::ROTEIRO)->count());
        $this->assertSame('Texto da tela.', SuporteRoteiro::where('titulo', self::ROTEIRO)->value('orientacao'));
    }
}
