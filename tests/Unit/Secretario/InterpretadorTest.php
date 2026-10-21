<?php

namespace Tests\Unit\Secretario;

use App\Services\Secretario\Interpretador;
use App\Services\Secretario\Recorrencia;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * As frases da missão (spec SC-001): pelo menos 18 de 20 certas sem IA, e
 * nenhuma com data inventada. O relógio é fixo — sexta, 16/10/2026, 14:20.
 */
class InterpretadorTest extends TestCase
{
    private function agora(string $quando = '2026-10-16 14:20'): CarbonImmutable
    {
        return CarbonImmutable::parse($quando, 'America/Sao_Paulo');
    }

    public static function frases(): array
    {
        return [
            ['me lembra amanhã às 9h de ligar para o Alexandre', 'lembrete', '2026-10-17 09:00:00', 'Ligar para o Alexandre'],
            ['Matriz, me lembra daqui a 10 minutos de ligar para o Alexandre', 'lembrete', '2026-10-16 14:30:00', 'Ligar para o Alexandre'],
            ['daqui a 2 horas me lembra de cobrar o orçamento', 'lembrete', '2026-10-16 16:20:00', 'Cobrar o orçamento'],
            ['Anota que tenho que falar com o Rossini', 'tarefa', null, 'Falar com o Rossini'],
            ['Preciso enviar a proposta da VixCard na sexta-feira', 'tarefa', '2026-10-23 00:00:00', 'Enviar a proposta da VixCard'],
            ['toda segunda às 8h me lembra de revisar o funil', 'lembrete', '2026-10-19 08:00:00', 'Revisar o funil'],
            ['me lembra todo dia às 18h de fechar o caixa', 'lembrete', '2026-10-16 18:00:00', 'Fechar o caixa'],
            ['todo dia 31 às 9h me lembra de pagar o aluguel', 'lembrete', '2026-10-31 09:00:00', 'Pagar o aluguel'],
            ['me lembra hoje às 8h de tomar o remédio', 'lembrete', '2026-10-17 08:00:00', 'Tomar o remédio'],
            ['me lembra às 7 da noite de buscar as crianças', 'lembrete', '2026-10-16 19:00:00', 'Buscar as crianças'],
            ['me lembra às 3 de ligar pro banco', 'lembrete', '2026-10-16 15:00:00', 'Ligar pro banco'],
            ['me lembra em 30 minutos de beber água', 'lembrete', '2026-10-16 14:50:00', 'Beber água'],
            ['me lembra daqui a meia hora de sair para a reunião', 'lembrete', '2026-10-16 14:50:00', 'Sair para a reunião'],
            ['me lembra dia 20 às 10h de pagar o boleto', 'lembrete', '2026-10-20 10:00:00', 'Pagar o boleto'],
            ['me lembra 25/12 às 9h de ligar para a família', 'lembrete', '2026-12-25 09:00:00', 'Ligar para a família'],
            ['me lembra amanhã de manhã de mandar o relatório', 'lembrete', '2026-10-17 08:00:00', 'Mandar o relatório'],
            ['me lembra depois de amanhã às 14h30 de visitar o cliente RFID', 'lembrete', '2026-10-18 14:30:00', 'Visitar o cliente RFID'],
            ['lembre-me na quarta às 16:00 de confirmar a demonstração', 'lembrete', '2026-10-21 16:00:00', 'Confirmar a demonstração'],
            ['me lembra ao meio-dia de almoçar com o Victor', 'lembrete', '2026-10-17 12:00:00', 'Almoçar com o Victor'],
            ['preciso cobrar o orçamento 191 amanhã às 10h', 'tarefa', '2026-10-17 10:00:00', 'Cobrar o orçamento 191'],
        ];
    }

    #[DataProvider('frases')]
    public function test_frases_da_missao(string $frase, string $tipo, ?string $quando, string $titulo): void
    {
        $r = (new Interpretador)->interpretar($frase, $this->agora());
        $this->assertSame(Interpretador::PROPOSTA, $r['resultado'], $frase);
        $this->assertSame($tipo, $r['dados']['tipo'], $frase);
        $this->assertSame($quando, $r['dados']['quando'], $frase);
        $this->assertSame($titulo, $r['dados']['titulo'], $frase);
    }

    public function test_sem_quando_pergunta_e_nao_inventa(): void
    {
        $i = new Interpretador;
        $r = $i->interpretar('me lembra de ligar para o Alexandre', $this->agora());
        $this->assertSame(Interpretador::FALTA, $r['resultado']);
        $this->assertSame('quando', $r['falta']);
        $this->assertNull($r['dados']['quando']);
        $this->assertSame(Interpretador::FALTA, $i->interpretar('me lembra todo dia de beber água', $this->agora())['resultado']);
        $this->assertSame(Interpretador::FALTA, $i->interpretar('me lembra amanhã às 9h', $this->agora())['resultado'], 'Sem o quê: pergunta');
    }

    public function test_o_que_nao_e_do_secretario_segue_para_a_central(): void
    {
        $i = new Interpretador;
        foreach (['analise o código do controller', 'preciso de ajuda com o gestor', 'mude o status do orçamento 191 para Financeiro',
            'qual minha agenda de hoje?', 'tenho que entender esse erro'] as $frase) {
            $this->assertSame(Interpretador::NAO_E, $i->interpretar($frase, $this->agora())['resultado'], $frase);
        }
    }

    public function test_ambiguidades_ficam_visiveis_e_nao_sao_inequivocas(): void
    {
        $i = new Interpretador;
        $passou = $i->interpretar('me lembra hoje às 8h de tomar o remédio', $this->agora());
        $this->assertContains('hoje_ja_passou', $passou['avisos']);
        $this->assertFalse($passou['dados']['inequivoco']);
        $tres = $i->interpretar('me lembra às 3 de ligar pro banco', $this->agora());
        $this->assertContains('ajustada', $tres['avisos']);
        $this->assertFalse($tres['dados']['inequivoco']);
        $sexta = $i->interpretar('preciso enviar a proposta na sexta', $this->agora());
        $this->assertContains('proxima_semana', $sexta['avisos']);
        $this->assertTrue($sexta['dados']['so_data']);
        $this->assertTrue($i->interpretar('me lembra daqui a 10 minutos de beber água', $this->agora())['dados']['inequivoco']);
        $this->assertTrue($i->interpretar('me lembra amanhã às 9h de ligar', $this->agora())['dados']['inequivoco']);
    }

    public function test_virada_do_dia(): void
    {
        $i = new Interpretador;
        // 00:30 de sábado: "amanhã" é domingo (o dia seguinte do calendário).
        $this->assertSame('2026-10-18 09:00:00', $i->interpretar('me lembra amanhã às 9h de ligar', $this->agora('2026-10-17 00:30'))['dados']['quando']);
        // 23:30 de sexta: daqui a 1 hora cai no sábado.
        $this->assertSame('2026-10-17 00:30:00', $i->interpretar('me lembra daqui a 1 hora de desligar', $this->agora('2026-10-16 23:30'))['dados']['quando']);
    }

    public function test_recorrencia_no_registro(): void
    {
        $r = (new Interpretador)->interpretar('toda segunda e quarta às 8h me lembra de revisar o funil', $this->agora());
        $this->assertSame(['freq' => 'semanal', 'dias' => [1, 3]], $r['dados']['recorrencia']);
    }

    public function test_campo_do_meu_dia_nao_precisa_de_gatilho(): void
    {
        $r = (new Interpretador)->interpretar('amanhã 15h reunião com a Condonal', $this->agora(), explicito: true);
        $this->assertSame('compromisso', $r['dados']['tipo']);
        $this->assertSame('2026-10-17 15:00:00', $r['dados']['quando']);
        $this->assertSame('Reunião com a Condonal', $r['dados']['titulo']);
    }

    public function test_recorrencia_mensal_cai_no_ultimo_dia(): void
    {
        $fev = Recorrencia::proxima(['freq' => 'mensal', 'dia_mes' => 31], CarbonImmutable::parse('2027-02-01 10:00', 'America/Sao_Paulo'), '09:00');
        $this->assertSame('2027-02-28 09:00', $fev->format('Y-m-d H:i'));
        $seg = Recorrencia::proxima(['freq' => 'semanal', 'dias' => [1]], CarbonImmutable::parse('2026-10-19 08:00', 'America/Sao_Paulo'), '08:00');
        $this->assertSame('2026-10-26 08:00', $seg->format('Y-m-d H:i'), 'A ocorrência de agora não conta de novo');
    }
}
