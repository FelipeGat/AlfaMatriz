<?php

namespace Tests\Unit;

use App\Services\CentralIa\RespostaVisual;
use PHPUnit\Framework\TestCase;

class RespostaVisualTest extends TestCase
{
    public function test_markdown_is_safe_and_formats_tables(): void
    {
        $html = RespostaVisual::apresentar("**Resumo**\n\n| Item | Valor |\n|---|---|\n| Caixa | 1 |\n\n<script>alert(1)</script>\n[x](javascript:alert(1))")['html'];
        self::assertStringContainsString('<strong>Resumo</strong>', $html);
        self::assertStringContainsString('<table>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
    }

    public function test_historical_fixture_preserves_original_and_telegram_amounts(): void
    {
        // Valores fictícios: nenhum número real de cliente entra no Git.
        $d = ['versao'=>1, 'titulo'=>'Exemplo sintético', 'periodo'=>'01–05/01/2026',
            'fonte'=>'Dados fictícios de teste',
            'metricas'=>[
                ['rotulo'=>'Recebido', 'centavos'=>123400, 'criterio'=>'Baixas no período'],
                ['rotulo'=>'Pago', 'centavos'=>200000, 'criterio'=>'Baixas no período'],
                ['rotulo'=>'Resultado realizado', 'centavos'=>-76600, 'criterio'=>'Fluxo de caixa, não lucro'],
            ]];
        $texto = "Resumo da semana: caixa negativo.\n[CENTRAL_RELATORIO_V1]".json_encode($d).'[/CENTRAL_RELATORIO_V1]';
        self::assertSame($d, RespostaVisual::apresentar($texto)['relatorio']);
        self::assertSame(-76600, $d['metricas'][0]['centavos']-$d['metricas'][1]['centavos']);
        self::assertStringContainsString('-766,00', RespostaVisual::telegram($texto));
        self::assertStringNotContainsString('CENTRAL_RELATORIO', RespostaVisual::telegram($texto));
        // A análise em texto continua indo ao celular, não só as métricas.
        self::assertStringContainsString('Resumo da semana: caixa negativo.', RespostaVisual::telegram($texto));
    }

    public function test_fixture_totals_and_graph_contract(): void
    {
        $d = json_decode(file_get_contents(__DIR__.'/../Fixtures/central-ia/relatorio-historico.json'), true);
        self::assertTrue(RespostaVisual::valido($d));
        self::assertSame(60000, array_sum(array_column($d['tabela'], 'centavos')));
        $d['grafico']['tipo'] = 'linha';
        self::assertTrue(RespostaVisual::valido($d));
        $d['metricas'][0]['centavos'] = null;
        self::assertFalse(RespostaVisual::valido($d));
    }

    public function test_secrets_are_removed_using_synthetic_examples(): void
    {
        $fake = 'password=synthetic-secret Bearer '.str_repeat('a',24).' ghp_'.str_repeat('b',24);
        $safe = \App\Services\CentralIa\ProtecaoDeSegredos::texto($fake);
        self::assertStringNotContainsString('synthetic-secret', $safe);
        self::assertStringNotContainsString(str_repeat('a',24), $safe);
        self::assertStringNotContainsString(str_repeat('b',24), $safe);
    }

    public function test_mascarar_nao_quebra_o_json_do_bloco_do_dossie(): void
    {
        $json = json_encode(['resumo' => 'ok', 'password' => 'synthetic-pass', 'duvida' => 'Qual base?', 'tarefa_concluida' => true]);
        $mascarado = \App\Services\CentralIa\ProtecaoDeSegredos::texto($json);
        $d = json_decode($mascarado, true);
        self::assertIsArray($d, $mascarado);
        self::assertSame('Qual base?', $d['duvida']);
        self::assertTrue($d['tarefa_concluida']);
        self::assertStringNotContainsString('synthetic-pass', $mascarado);
    }

    public function test_padroes_de_segredo_sinteticos(): void
    {
        $fakes = [
            'APP_KEY=base64:'.str_repeat('Q', 43).'=',
            '123456789:'.str_repeat('A', 35),
            '42|'.str_repeat('z', 40),
            'CENTRAL_IA_TELEGRAM_TOKEN=valor-sintetico-1234',
            'GITHUB_WEBHOOK_SECRET=outro-sintetico-9876',
            'AKIA'.str_repeat('X', 16),
            'mysql://usuario:senhasintetica@db:3306/x',
            'eyJ'.str_repeat('a', 10).'.'.str_repeat('b', 10).'.'.str_repeat('c', 10),
        ];
        $saida = \App\Services\CentralIa\ProtecaoDeSegredos::texto(implode("\n", $fakes));
        foreach (['QQQQQQQQQQQQQQQQQQQQ', str_repeat('A', 35), str_repeat('z', 40), 'valor-sintetico-1234', 'outro-sintetico-9876', str_repeat('X', 16), 'senhasintetica', str_repeat('b', 10)] as $segredo) {
            self::assertStringNotContainsString($segredo, $saida);
        }
        // Texto comum não é mutilado.
        self::assertSame('O token de acesso expira em 10 minutos.', \App\Services\CentralIa\ProtecaoDeSegredos::texto('O token de acesso expira em 10 minutos.'));
    }

    public function test_invalid_payload_falls_back_without_inventing_missing_values(): void
    {
        foreach ([['versao'=>2], ['versao'=>1,'html'=>'<script>'], null] as $d) {
            $s = '[CENTRAL_RELATORIO_V1]'.json_encode($d).'[/CENTRAL_RELATORIO_V1]';
            self::assertNull(RespostaVisual::apresentar($s)['relatorio']);
            self::assertSame($s, RespostaVisual::telegram($s));
        }
    }
}
