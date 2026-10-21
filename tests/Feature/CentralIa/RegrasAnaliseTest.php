<?php
namespace Tests\Feature\CentralIa;
use Tests\TestCase;
use App\Services\CentralIa\{FontesDeDados, Roteador};
use Illuminate\Validation\ValidationException;
class RegrasAnaliseTest extends TestCase
{
    public function test_dados_sem_periodo_pedem_esclarecimento(): void
    {
        $this->expectException(ValidationException::class);
        app(FontesDeDados::class)->exigirPeriodo('Traga um levantamento do que tenho a pagar e receber');
    }
    public function test_pedidos_legitimos_nao_pedem_periodo(): void
    {
        $f = app(FontesDeDados::class);
        // Agenda sem data é a de hoje (dono, 10/10/2026).
        $f->exigirPeriodo('me faça um resumo da minha agenda');
        // Pedido de código ou de tela que só cita o assunto.
        $f->exigirPeriodo('corrija o lembrete de contas a pagar');
        $f->exigirPeriodo('quero receber aviso quando o deploy terminar');
        $f->exigirPeriodo('adicione um filtro de status na tela do financeiro');
        // Implementação nunca é relatório.
        $f->exigirPeriodo('levantamento do que tenho a pagar', 'implementar');
        $this->assertSame('hoje', $f->detectar('resumo da agenda')[0]['rotulo']);
    }

    public function test_periodo_explicito_e_codigo_nao_sao_bloqueados(): void
    {
        app(FontesDeDados::class)->exigirPeriodo('Resultado financeiro desta semana');
        app(FontesDeDados::class)->exigirPeriodo('Analise o código do faturamento');
        $r = app(Roteador::class)->decidir('Analise o código', ['claude','codex']);
        // Consulta de código é análise, do Codex; o Claude fica com desenvolvimento (Felipe, 16/10/2026).
        $this->assertSame('codex', $r['agente']);
        $this->assertSame('analisar', $r['modo']);
        $r = app(Roteador::class)->decidir('Analise os dados financeiros desta semana e o que tenho a pagar', ['claude','codex']);
        $this->assertSame('codex', $r['agente']);
    }
}
