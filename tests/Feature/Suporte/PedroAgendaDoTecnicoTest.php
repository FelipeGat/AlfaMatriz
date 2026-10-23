<?php

namespace Tests\Feature\Suporte;

use App\Models\SuporteConversa;
use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * pedro-dia-do-tecnico, FASE 1 (consulta): em 23/10/2026 o Felipe pediu "a agenda dos técnicos de
 * ontem" e o Pedro respondeu que não tinha agendamento de nenhum dia.
 */
class PedroAgendaDoTecnicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-23 15:00', 'America/Sao_Paulo'));
        (new PerfilPermissaoSeeder)->run();
        app(Conhecimento::class)->gravarAjuste(AcessoInterno::AJUSTE, "+55 27 99884-5397 | Felipe | diretor\n+55 27 3109-3265 | Alexandre | suporte");
        app(DiretorioGestor::class)->gravar('wsl', '2026-10-23 14:50:00', [
            ['id' => -6176, 'nome' => 'Revizza', 'fantasia' => 'Revizza', 'razao' => '', 'cidade' => 'Vila Velha', 'telefones' => [], 'pre' => true],
            ['id' => 167, 'nome' => 'Corais de Parati', 'fantasia' => 'Corais de Parati', 'razao' => '', 'cidade' => 'Vila Velha', 'empresa' => 'GW', 'telefones' => []],
        ], [
            ['id' => 925, 'numero' => '824', 'cliente_id' => -6176, 'descricao' => 'Visita técnica para orçar elevadores', 'status' => 'aberto', 'criado_em' => '2026-10-23 11:53:00'],
            ['id' => 839, 'numero' => '756', 'cliente_id' => 167, 'descricao' => 'Recuperação estrutural da garagem', 'status' => 'em_atendimento', 'criado_em' => '2026-09-30 10:00:00'],
        ], [], [['id' => 9, 'nome' => 'Vitor Emanuel Oliveira de Paula', 'telefones' => []], ['id' => 21, 'nome' => 'Cleison Souza', 'telefones' => []],
            ['id' => 5, 'nome' => 'Cristiano Lopes Lucas', 'telefones' => []]], [], [
            ['id' => 1185, 'atendimento_id' => 925, 'tecnico_id' => 9, 'inicio' => '2026-10-23 08:00', 'fim' => '2026-10-23 10:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
            ['id' => 1181, 'atendimento_id' => 839, 'tecnico_id' => 21, 'inicio' => '2026-10-22 08:00', 'fim' => '2026-10-22 10:00', 'situacao' => 'finalizado', 'observacoes' => '', 'adicionais' => [5]],
            ['id' => 1162, 'atendimento_id' => 839, 'tecnico_id' => 21, 'inicio' => '2026-11-02 08:00', 'fim' => '2026-11-02 18:00', 'situacao' => 'agendado', 'observacoes' => '', 'adicionais' => []],
        ]);
    }

    private function prompt(string $telefone, string $texto): string
    {
        $c = SuporteConversa::create(['telefone' => $telefone, 'ultima_entrada_em' => now()]);
        $c->mensagens()->create(['wamid' => 'wamid.'.uniqid(), 'direcao' => 'entrada', 'tipo' => 'text', 'texto' => $texto, 'enviada_em' => now()]);

        return app(Contexto::class)->prompt($c->fresh());
    }

    public function test_agenda_de_ontem_e_de_hoje_com_o_pre_cliente_e_a_hora_da_leitura(): void
    {
        $p = $this->prompt('5527998845397', 'Preciso da agenda dos técnicos de ontem');

        $this->assertStringContainsString('## AGENDA DOS TÉCNICOS (visitas no Gestor, lido às 14:50', $p);
        $this->assertStringContainsString('Quinta-feira, 22/10/2026', $p);
        $this->assertStringContainsString('- Cleison: 08:00 às 10:00 — nº 756 — Corais de Parati — finalizado — junto: Cristiano', $p);
        $this->assertStringContainsString('- Vitor: 08:00 às 10:00 — nº 824 — Revizza (pré-cliente) — agendado', $p, 'pré-cliente pelo nome, nunca "sem cliente"');
        $this->assertStringContainsString('Lançar ou corrigir visitas da agenda ainda NÃO é possível', $p);
        $this->assertStringNotContainsString('02/11', $p, 'só os dias pedidos e os vizinhos');
    }

    public function test_dia_citado_sem_visita_e_dia_fora_do_retrato(): void
    {
        $p = $this->prompt('5527998845397', 'e a agenda do Victor em 02/11? e em 20/12?');

        $this->assertStringContainsString('- Cleison: 08:00 às 18:00 — nº 756', $p);
        $this->assertStringContainsString("Sábado, 24/10/2026\n- Nenhuma visita de técnico.", $p);
        $this->assertStringContainsString("Domingo, 20/12/2026\n- Fora do período do retrato", $p);
    }

    public function test_suporte_consulta_e_cliente_nao_ve_a_agenda(): void
    {
        $this->assertStringContainsString('## AGENDA DOS TÉCNICOS', $this->prompt('552731093265', 'agenda de hoje'));
        $this->assertStringNotContainsString('AGENDA DOS TÉCNICOS', $this->prompt('5527988887777', 'qual a agenda do Vitor hoje?'));
    }
}
