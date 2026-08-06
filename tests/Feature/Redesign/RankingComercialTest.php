<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RankingComercialTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-042 O ranking com rosca desenha os arcos a partir dos dados
     * reais, sem quebrar. Divisão por zero e soma de participações passando de
     * 100% são os defeitos clássicos deste tipo de gráfico.
     */
    public function test_rosca_desenha_os_arcos_com_dados_reais(): void
    {
        $usuario = User::factory()->create();
        $revenda = Revenda::create(['nome' => 'Invest', 'ativo' => true]);

        $sistemas = [];
        foreach (['AlfaGym' => 5, 'AlfaControl' => 3, 'AlfaMed' => 2] as $nome => $qtd) {
            $sistema = Sistema::create([
                'nome' => $nome,
                'slug' => strtolower($nome),
                'categoria' => 'saas',
                'unidade_cobranca' => 'cliente',
                'ativo' => true,
            ]);
            $sistemas[$nome] = $sistema;

            foreach (range(1, $qtd) as $i) {
                $cliente = Cliente::create([
                    'nome' => "{$nome} cliente {$i}",
                    'revenda_id' => $revenda->id,
                    'ativo' => true,
                ]);

                DB::table('cliente_sistema')->insert([
                    'cliente_id' => $cliente->id,
                    'sistema_id' => $sistema->id,
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $resposta = $this->actingAs($usuario)->get(route('comercial'));
        $resposta->assertOk();

        $html = $resposta->getContent();

        // Um arco por sistema, na paleta do handoff.
        $this->assertStringContainsString('#029caf', $html);
        $this->assertStringContainsString('#2ec9d9', $html);
        $this->assertStringContainsString('stroke-dasharray', $html);

        // O centro mostra o total: 5 + 3 + 2 = 10 clientes. O Blade deixa
        // espaços em volta do valor, daí a comparação tolerante.
        $this->assertMatchesRegularExpression(
            '/<p[^>]*class="[^"]*\bvalor\b[^"]*"[^>]*>\s*10\s*<\/p>/',
            $html,
            'O centro da rosca precisa mostrar o total de clientes.'
        );

        // As participações somam 100% (com folga de arredondamento) e nenhuma
        // passa disso sozinha.
        preg_match_all('/([\d,]+)%/', $html, $m);
        $participacoes = array_map(fn ($p) => (float) str_replace(',', '.', $p), $m[1]);

        $this->assertNotEmpty($participacoes);
        foreach ($participacoes as $p) {
            $this->assertLessThanOrEqual(100.0, $p, 'Nenhum sistema pode ter mais de 100% de participação.');
        }
        $this->assertEqualsWithDelta(100.0, array_sum($participacoes), 0.5);

        // E o alternador entre as duas métricas existe.
        $this->assertStringContainsString("metrica = 'valor'", $html);
        $this->assertStringContainsString("metrica = 'clientes'", $html);
    }

    /** @spec:AC-042 Sem nenhum sistema, a tela abre com estado vazio em vez de quebrar. */
    public function test_sem_sistemas_a_tela_abre_com_estado_vazio(): void
    {
        $usuario = User::factory()->create();

        $resposta = $this->actingAs($usuario)->get(route('comercial'));

        $resposta->assertOk();
        $resposta->assertSee('Nenhum sistema com clientes ainda.');
    }
}
