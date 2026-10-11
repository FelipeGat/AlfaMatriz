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
    public function test_periodo_explicito_e_codigo_nao_sao_bloqueados(): void
    {
        app(FontesDeDados::class)->exigirPeriodo('Resultado financeiro desta semana');
        app(FontesDeDados::class)->exigirPeriodo('Analise o código do faturamento');
        $r = app(Roteador::class)->decidir('Analise o código', ['claude','codex']);
        $this->assertSame('claude', $r['agente']);
        $this->assertSame('analisar', $r['modo']);
        $r = app(Roteador::class)->decidir('Analise os dados financeiros desta semana e o que tenho a pagar', ['claude','codex']);
        $this->assertSame('codex', $r['agente']);
    }
}
