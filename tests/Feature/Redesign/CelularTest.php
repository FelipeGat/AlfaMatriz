<?php

namespace Tests\Feature\Redesign;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Matriz no iPhone com cara de app (pedido do dono, 23/10/2026).
 *
 * O visual é CSS abaixo de 768px e não se prova daqui; o que se prova é o
 * contrato que o torna possível: a página se declara app (manifest, tela
 * cheia, áreas seguras), a barra de abas chega com as portas que a conta
 * abre, e o "voltar" aparece fora das raízes das abas.
 */
class CelularTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_o_manifest_abre_em_tela_cheia_com_icones_que_existem(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);

        foreach ($manifest['icons'] as $icone) {
            $this->assertFileExists(public_path(ltrim($icone['src'], '/')));
        }
    }

    public function test_dentro_e_fora_do_login_a_pagina_se_declara_app(): void
    {
        foreach ([$this->get(route('login')), $this->actingAs(User::factory()->create())->get(route('dashboard'))] as $resposta) {
            $resposta->assertOk();
            $resposta->assertSee('<link rel="manifest" href="/manifest.webmanifest">', escape: false);
            $resposta->assertSee('name="apple-mobile-web-app-capable" content="yes"', escape: false);
            // Sem `viewport-fit=cover` o iPhone não devolve o entalhe e a
            // barra de gestos ao CSS, e a barra de abas fica sob o gesto.
            $resposta->assertSee('viewport-fit=cover', escape: false);
        }
    }

    public function test_a_barra_de_abas_so_oferece_porta_que_abre(): void
    {
        $this->actingAs(User::factory()->create());

        $resposta = $this->get(route('tarefas.index'))->assertOk();

        $resposta->assertSee('class="abas-celular', escape: false);
        $resposta->assertSee('Mais');

        $abas = $this->trechoDasAbas($resposta->getContent());
        $this->assertStringContainsString(route('tarefas.index'), $abas);
        // A conta de teste não tem a Central de IA: a aba não aparece, em vez
        // de levar a um 403.
        $this->assertStringNotContainsString(route('central-ia.index'), $abas);
    }

    public function test_a_aba_agenda_abre_na_lista(): void
    {
        $this->actingAs(User::factory()->create());

        $abas = $this->trechoDasAbas($this->get(route('tarefas.index'))->assertOk()->getContent());

        // A semana tem sete colunas de hora: a 390px nenhum título cabe nelas.
        $this->assertStringContainsString(e(route('agenda.index', ['visao' => 'lista'])), $abas);
    }

    public function test_os_filtros_das_telas_densas_abrem_numa_folha_no_celular(): void
    {
        $this->actingAs(User::factory()->create());

        // O MESMO formulário vira a folha (pelo CSS): uma segunda cópia dos
        // campos ficaria para trás no primeiro filtro novo.
        foreach (['tarefas.index', 'clientes.index', 'relatorios.index'] as $rota) {
            $html = $this->get(route($rota))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'data-folha-filtros'), $rota);
            $this->assertStringContainsString('data-folha-botao', $html, $rota);
        }
    }

    public function test_raiz_de_aba_nao_tem_voltar_e_tela_do_menu_tem(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('tarefas.index'))->assertOk()->assertDontSee('@click="voltar()"', escape: false);
        $this->get(route('dashboard'))->assertOk()->assertSee('@click="voltar()"', escape: false);
    }

    public function test_a_tabela_do_painel_sai_pronta_para_virar_cartao(): void
    {
        // Os rótulos do cartão saem do cabeçalho (`rotularTabelas`); o que a
        // tela precisa entregar é a marca na tabela e o cabeçalho com nome.
        $this->actingAs(User::factory()->create());

        $resposta = $this->get(route('usuarios.index'))->assertOk();

        $resposta->assertSee('data-cartoes', escape: false);
        $resposta->assertSee('<thead>', escape: false);
    }

    private function trechoDasAbas(string $html): string
    {
        $inicio = strpos($html, 'class="abas-celular');
        $this->assertNotFalse($inicio);

        return substr($html, $inicio, strpos($html, '</nav>', $inicio) - $inicio);
    }
}
