<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Raia é uma grade de DUAS dimensões: pessoas na vertical, seis etapas na
 * horizontal. Sem filtro ela se paga — é o retrato do time. Com filtro, não: o
 * recorte esvazia as células e o custo de layout de cada faixa continua inteiro
 * (6 × 272px de largura, 180px de altura mínima por faixa), então sobra rolar
 * nos dois eixos atrás de meia dúzia de tarefas espalhadas em célula vazia.
 *
 * Encolher a coluna vazia não resolveria: o que sobra é a dimensão a mais, não
 * a largura dela.
 */
class RaiaComFiltroTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Sistema} */
    private function baseComDuasPessoas(): array
    {
        $dono = User::factory()->create(['name' => 'Rafael Lima']);
        $outra = User::factory()->create(['name' => 'Camila Reis']);
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);
        $outro = Sistema::factory()->create(['nome' => 'AlfaPet']);

        Tarefa::factory()->create([
            'criado_por_id' => $dono->id, 'responsavel_id' => $dono->id,
            'sistema_id' => $sistema->id, 'status' => 'em_desenvolvimento',
            'titulo' => 'Tarefa do Rafael no AlfaGym',
        ]);
        Tarefa::factory()->create([
            'criado_por_id' => $dono->id, 'responsavel_id' => $outra->id,
            'sistema_id' => $outro->id, 'status' => 'em_revisao',
            'titulo' => 'Tarefa da Camila no AlfaPet',
        ]);

        return [$dono, $sistema];
    }

    /**
     * @spec:AC-252 Raia com filtro vira tabela agrupada: a etapa deixa de ser
     * posição no espaço e vira coluna do registro.
     */
    public function test_raia_com_filtro_vira_tabela(): void
    {
        [$dono, $sistema] = $this->baseComDuasPessoas();

        $html = $this->actingAs($dono)->get(route('tarefas.index', [
            'raias' => 'responsavel',
            'sistema' => $sistema->id,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-tabela-raias', $html,
            'A raia com filtro continuou desenhando a grade.');

        // A grade não vem junto: o contêiner do quadro e a barra fixa de etapas
        // são dela, e sobrariam medindo uma tela que não existe mais.
        //
        // A âncora da barra é o fundo composto dela, e não `sticky top-0`: essa
        // classe também é do topo da página, que continua na tela.
        $this->assertStringNotContainsString('data-quadro', $html);
        $this->assertStringNotContainsString('background: linear-gradient(var(--board), var(--board)), rgb(var(--canvas))', $html);
    }

    /**
     * @spec:AC-253 Sem filtro, a raia continua sendo a grade de sempre — a troca
     * é resposta ao recorte, não uma mudança de opinião sobre raias.
     */
    public function test_raia_sem_filtro_continua_grade(): void
    {
        [$dono] = $this->baseComDuasPessoas();

        $html = $this->actingAs($dono)->get(route('tarefas.index', [
            'raias' => 'responsavel',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-quadro', $html,
            'A raia sem filtro deixou de desenhar a grade.');
        $this->assertStringNotContainsString('data-tabela-raias', $html);
    }

    /**
     * @spec:AC-254 A coluna que a seção já nomeia não se repete linha a linha.
     */
    public function test_a_coluna_do_eixo_da_raia_nao_se_repete(): void
    {
        [$dono, $sistema] = $this->baseComDuasPessoas();

        // Agrupado por responsável: a coluna de responsável some, a de sistema fica.
        $porPessoa = $this->actingAs($dono)->get(route('tarefas.index', [
            'raias' => 'responsavel', 'sistema' => $sistema->id,
        ]))->assertOk()->getContent();

        $cabecalho = substr($porPessoa, strpos($porPessoa, 'data-tabela-raias'));
        $this->assertStringContainsString('>Sistema</th>', $cabecalho);
        $this->assertStringNotContainsString('>Responsável</th>', $cabecalho);

        // Agrupado por sistema: o contrário.
        $porSistema = $this->actingAs($dono)->get(route('tarefas.index', [
            'raias' => 'sistema', 'prioridade' => 'media',
        ]))->assertOk()->getContent();

        $cabecalho = substr($porSistema, strpos($porSistema, 'data-tabela-raias'));
        $this->assertStringContainsString('>Responsável</th>', $cabecalho);
        $this->assertStringNotContainsString('>Sistema</th>', $cabecalho);
    }

    /**
     * @spec:AC-255 Da tabela se trabalha: a linha abre a tarefa e o menu move de
     * etapa — sem isso o recorte viraria relatório.
     */
    public function test_da_tabela_se_abre_e_se_move(): void
    {
        [$dono, $sistema] = $this->baseComDuasPessoas();
        $tarefa = Tarefa::firstWhere('titulo', 'Tarefa do Rafael no AlfaGym');

        $html = $this->actingAs($dono)->get(route('tarefas.index', [
            'raias' => 'responsavel', 'sistema' => $sistema->id,
        ]))->assertOk()->getContent();

        // Abrir: o mesmo evento que o card do quadro dispara.
        $this->assertStringContainsString("abrir-tarefa', ".$tarefa->id, $html);

        // Mover: o menu do card, com o destino que o fluxo permite.
        $this->assertStringContainsString('Mover para', $html);
        $this->assertStringContainsString(route('tarefas.mover', $tarefa), $html);

        // E o lugar do pedido de motivo viaja junto: mover para etapa que exige
        // texto precisa dele, e sem o marcador o painel não teria onde nascer.
        $this->assertStringContainsString('data-motivo="'.$tarefa->id.'"', $html);
    }

    /**
     * @spec:AC-256 A porta de volta fica à vista, e não desfaz o filtro.
     */
    public function test_a_volta_para_a_grade_mantem_o_filtro(): void
    {
        [$dono, $sistema] = $this->baseComDuasPessoas();

        $comTabela = $this->actingAs($dono)->get(route('tarefas.index', [
            'raias' => 'responsavel', 'sistema' => $sistema->id,
        ]))->assertOk();

        $comTabela->assertSee('data-voltar-ao-quadro', escape: false);
        $comTabela->assertSee('ver como quadro');

        // O link leva à grade COM o filtro ainda ligado.
        $html = $this->actingAs($dono)->get(route('tarefas.index', [
            'raias' => 'responsavel', 'sistema' => $sistema->id, 'vista' => 'quadro',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-quadro', $html);
        $this->assertStringNotContainsString('data-tabela-raias', $html);
        // O filtro sobreviveu: a tarefa do outro sistema continua fora.
        $this->assertStringContainsString('Tarefa do Rafael no AlfaGym', $html);
        $this->assertStringNotContainsString('Tarefa da Camila no AlfaPet', $html);

        // E na grade não se oferece voltar para a grade.
        $this->assertStringNotContainsString('data-voltar-ao-quadro', $html);
    }
}
