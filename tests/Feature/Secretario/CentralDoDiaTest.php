<?php

namespace Tests\Feature\Secretario;

use App\Models\Perfil;
use App\Models\SecretarioEmail;
use App\Models\SecretarioEmailConta;
use App\Models\SecretarioGestorRetrato;
use App\Models\SecretarioItem;
use App\Models\User;
use App\Services\Secretario\Acompanhamento\Regras;
use App\Services\Secretario\LaFora;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Etapa 8 (docs/specs/secretario-central-do-dia). Quarta, 21/10/2026, 10:00. */
class CentralDoDiaTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00', 'America/Sao_Paulo'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Sao_Paulo'));
        Cache::flush();
    }

    private function rss(array $itens): string
    {
        return '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title>'
            .collect($itens)->map(fn ($i) => '<item><title><![CDATA['.$i[0].']]></title><link>'.$i[1].'</link></item>')->implode('').'</channel></rss>';
    }

    private function fontes(): void
    {
        Http::fake([
            'economia.awesomeapi.com.br/*' => Http::response(['USDBRL' => ['bid' => '5.0734', 'pctChange' => '-0.69', 'high' => '5.098', 'low' => '5.0613']]),
            'api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 26.4, 'weather_code' => 2],
                'daily' => ['temperature_2m_max' => [29.6], 'temperature_2m_min' => [21.2], 'precipitation_probability_max' => [40]]]),
            'ge.globo.com/*' => Http::response($this->rss([['Palmeiras vence <b>clássico</b>', 'https://ge.globo.com/a'], ['Escalação', 'javascript:alert(1)']])),
            'techcrunch.com/*' => Http::response($this->rss([['AI startup raises', 'https://techcrunch.com/m']])),
            'g1.globo.com/rss/g1/tecnologia/*' => Http::response($this->rss([['<script>x</script>Celular novo', 'https://g1.globo.com/b']])),
        ]);
    }

    public function test_la_fora_busca_guarda_e_mostra_com_hora_texto_puro_e_link_https(): void
    {
        $this->fontes();
        $this->artisan('secretario:la-fora')->assertSuccessful();
        $l = app(LaFora::class)->ler();
        $this->assertSame([5.0734, 26, 'parcialmente nublado', 30, 21, 40], [$l['dolar']['compra'], $l['clima']['agora'], $l['clima']['tempo'], $l['clima']['maxima'], $l['clima']['minima'], $l['clima']['chuva']]);
        $this->assertSame('Palmeiras vence clássico', $l['futebol']['manchetes'][0]['titulo']);
        $this->assertNull($l['futebol']['manchetes'][1]['link'], 'Só https');
        $this->assertSame('xCelular novo', $l['tec_brasil']['manchetes'][0]['titulo'], 'Tag some, vira texto');
        $this->assertSame('AI startup raises', $l['tec_mundo']['manchetes'][0]['titulo']);

        $u = $this->dono(telegram: false);
        $this->actingAs($u)->get(route('secretario.meu-dia'))->assertOk()
            ->assertSee('Lá fora')->assertSee('R$ 5,07')->assertSee('Palmeiras vence clássico')->assertSee('Futebol brasileiro')->assertSee('Tecnologia · Mundo')->assertSee('AI startup raises')->assertSee('10:00')->assertDontSee('<script>x</script>', false);

        // Fonte fora do ar: guarda o último bom; com mais de 6 h, "indisponível".
        Http::fake(['*' => Http::response('erro', 500)]);
        $this->artisan('secretario:la-fora')->assertSuccessful();
        $this->assertNotNull(app(LaFora::class)->ler()['dolar']);
        Carbon::setTestNow(now()->addHours(7));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(7));
        $this->assertNull(app(LaFora::class)->ler()['dolar']);
        $this->actingAs($u)->get(route('secretario.meu-dia'))->assertOk()->assertSee('indisponível agora');
    }

    public function test_central_de_gestao_numeros_grupo_solucoes_vixcard_e_isolamento(): void
    {
        $u = $this->dono(telegram: false);
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'compromisso', 'titulo' => 'Visita', 'quando' => '2026-10-21 15:00:00', 'origem' => 'matriz']);
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'tarefa', 'titulo' => 'Vencida', 'quando' => '2026-10-20 09:00:00', 'origem' => 'matriz']);
        $conta = SecretarioEmailConta::create(['user_id' => $u->id, 'rotulo' => 'VixCard', 'host' => 'imap.vix.local', 'porta' => 993, 'seguranca' => 'ssl',
            'usuario' => 'f@vix.local', 'senha' => 'x', 'pasta_entrada' => 'INBOX']);
        foreach ([['2026-10-20 08:00:00', false], ['2026-10-19 08:00:00', false], ['2026-10-15 08:00:00', false], ['2026-10-21 08:00:00', true]] as $n => [$em, $lido]) {
            SecretarioEmail::create(['user_id' => $u->id, 'conta_id' => $conta->id, 'pasta' => 'INBOX', 'uidvalidity' => 1, 'uid' => $n + 1, 'de_email' => 'a@b.c',
                'assunto' => 'x', 'recebido_em' => $em, 'lido' => $lido]);
        }
        SecretarioGestorRetrato::create(['user_id' => $u->id, 'maquina' => 'wsl', 'lido_em' => now()->subHour(), 'dados' => ['blocos' => [
            'orcamentos_aprovacao' => ['quantidade' => 104, 'valor' => 5041515.73, 'itens' => [['texto' => 'Orç. 151 — VD Cariacica', 'data' => '2026-05-26']]],
            'cobrancas_vencidas' => ['quantidade' => 28, 'valor' => 120000, 'itens' => []],
        ]]]);
        $this->actingAs($u);

        $this->post(route('secretario.acompanhamentos.pontos'), ['empresa' => 'invest', 'titulo' => 'Aprovar tabela de preços', 'prazo' => '2026-10-20', 'prioridade' => 'alta'])->assertRedirect();
        $this->post(route('secretario.acompanhamentos.pontos'), ['empresa' => 'outra', 'titulo' => 'x', 'prioridade' => 'alta'])->assertSessionHasErrors('empresa');
        $ponto = SecretarioItem::where('empresa', 'invest')->sole();
        $this->assertTrue($ponto->so_data);

        $r = $this->get(route('secretario.acompanhamentos'))->assertOk();
        $r->assertSeeInOrder(['Compromissos hoje', '1', 'E-mails não lidos (3 dias)', '2', 'Tarefas vencidas', '2']);
        $r->assertSee('Gestor lido às 09:00')->assertSee('104 orçamento(s) aguardando aprovação')->assertSee('Orç. 151')->assertSee('VD Cariacica')
            ->assertSee('Aguardando aprovação');
        $r->assertSee('Aprovar tabela de preços')->assertSee('Ponto de gestão')->assertSee('Vencido');
        $r->assertDontSee('Salvar regras')->assertSee('Regras dos avisos');

        // Outra conta, admin incluído: não vê nem conclui.
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('secretario.acompanhamentos'))->assertOk()->assertDontSee('Aprovar tabela de preços')->assertDontSee('Orç. 151');
        $this->post(route('secretario.acompanhamentos.pontos.concluir', $ponto->id))->assertNotFound();
        $this->actingAs($u)->post(route('secretario.acompanhamentos.pontos.concluir', $ponto->id))->assertRedirect();
        $this->assertSame(SecretarioItem::CONCLUIDO, $ponto->fresh()->status);
    }

    public function test_regras_em_configuracoes_e_memoria_fora_do_menu(): void
    {
        $u = $this->dono(telegram: false);
        $this->actingAs($u)->get(route('secretario.configuracoes'))->assertOk()->assertSee('Avisos de acompanhamento')->assertSee('Salvar regras')
            ->assertDontSee('href="'.route('secretario.memoria').'"', false);
        $form = Regras::padroes();
        $form['regras']['tarefa_vencida']['valor'] = 6;
        $this->put(route('secretario.acompanhamentos.regras'), $form)->assertRedirect(route('secretario.configuracoes').'#avisos');
        $this->assertSame(6, Regras::de($u)['regras']['tarefa_vencida']['valor']);
        $this->get(route('secretario.memoria'))->assertOk(); // a busca continua existindo, só saiu do menu
    }

    public function test_agenda_em_calendario_entrega_os_eventos_do_intervalo(): void
    {
        $u = $this->dono(telegram: false);
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'compromisso', 'titulo' => 'Visita RFID', 'quando' => '2026-10-22 09:00:00', 'origem' => 'matriz']);
        SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'compromisso', 'titulo' => 'Call', 'quando' => '2026-10-22 09:30:00', 'origem' => 'matriz']);
        $this->actingAs($u)->get(route('secretario.agenda'))->assertOk()->assertSee('minhaAgenda(', false);
        $j = $this->getJson(route('secretario.agenda.eventos', ['de' => '2026-10-19', 'ate' => '2026-10-25']))->assertOk()->json('eventos');
        $this->assertSame(['Visita RFID', '2026-10-22T09:00', '2026-10-22T10:00', true], [$j[0]['titulo'], $j[0]['inicio'], $j[0]['fim'], $j[0]['conflito']]);
        $this->getJson(route('secretario.agenda.eventos', ['de' => '2026-10-01', 'ate' => '2026-12-31']))->assertStatus(422);
        $this->getJson(route('secretario.agenda.eventos', ['de' => '2026-10-25', 'ate' => '2026-10-19']))->assertStatus(422);

        $outro = $this->admin();
        $this->actingAs($outro)->getJson(route('secretario.agenda.eventos', ['de' => '2026-10-19', 'ate' => '2026-10-25']))->assertOk()->assertJsonCount(0, 'eventos');
    }
}
