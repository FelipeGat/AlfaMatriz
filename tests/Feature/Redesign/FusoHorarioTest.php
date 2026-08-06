<?php

namespace Tests\Feature\Redesign;

use App\Models\Cobranca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O fuso do painel.
 *
 * Parece detalhe de configuração e não é: este painel fala de "hoje" o tempo
 * todo — o que vence hoje, o que já atrasou, quanto entrou no mês, qual é a
 * competência. Rodando em UTC, todas essas perguntas passam a ser respondidas
 * com três horas de adiantamento, e o erro só aparece à noite e na virada do
 * mês, que é justamente quando ninguém está olhando.
 */
class FusoHorarioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-070 O painel raciocina no fuso de quem opera: o dia, o mês e a
     * competência viram no horário do Brasil, não em UTC.
     */
    public function test_o_painel_raciocina_no_fuso_de_quem_opera(): void
    {
        $this->assertSame(
            'America/Sao_Paulo',
            config('app.timezone'),
            'O painel é operado do Brasil; em UTC o dia vira três horas antes.'
        );

        // O padrão precisa estar no config, não só no .env: servidor sem a
        // variável definida tem de cair no fuso certo, e não em UTC.
        $this->assertStringContainsString(
            "env('APP_TIMEZONE', 'America/Sao_Paulo')",
            file_get_contents(base_path('config/app.php')),
            'Sem padrão no config, um ambiente sem APP_TIMEZONE volta silenciosamente para UTC.'
        );
    }

    /**
     * @spec:AC-070 Às 22h de um dia útil, um título que vence hoje ainda não
     * está atrasado — em UTC já seria o dia seguinte.
     */
    public function test_a_noite_nao_antecipa_o_atraso_nem_a_virada_do_mes(): void
    {
        // 22h no fuso do Brasil = 01h do dia seguinte em UTC. É a janela em
        // que o erro aparecia.
        Carbon::setTestNow(Carbon::parse('2026-08-31 22:00:00', 'America/Sao_Paulo'));

        $vence = Cobranca::create([
            'descricao' => 'Vence hoje', 'valor' => 1000.00,
            'data_vencimento' => '2026-08-31', 'status' => 'pendente', 'tipo' => 'avulsa',
        ]);

        $resposta = $this->actingAs(User::factory()->create())->get(route('centro-controle'));
        $resposta->assertOk();

        $atrasado = collect($resposta->viewData('cards'))->firstWhere('rotulo', 'Atrasado');
        $this->assertStringContainsString(
            '0,00',
            $atrasado['valor'],
            'Um título que vence hoje não pode contar como atrasado às 22h.'
        );

        // E a competência ainda é agosto, não setembro.
        $this->assertSame('08/2026', $resposta->viewData('saudacao')['competencia']);

        // A saudação também acompanha: 22h é noite; em UTC seria 01h — e o
        // painel diria "Bom dia" para quem está fechando o mês de madrugada.
        $this->assertSame('Boa noite', $resposta->viewData('saudacao')['periodo']);

        Carbon::setTestNow();
    }

    /**
     * @spec:AC-070 À tarde o painel cumprimenta como tarde. Em UTC, 17h no
     * Brasil vira 20h e a saudação passa a mentir todo fim de expediente.
     */
    public function test_a_saudacao_acompanha_a_hora_de_quem_esta_olhando(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 17:00:00', 'America/Sao_Paulo'));

        $resposta = $this->actingAs(User::factory()->create())->get(route('centro-controle'));

        $this->assertSame('Boa tarde', $resposta->viewData('saudacao')['periodo']);

        Carbon::setTestNow();
    }
}
