<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * O script do quadro precisa ser JavaScript VÁLIDO.
 *
 * Esta suíte inteira era cega a isso. Todo teste de tela afirma sobre o HTML
 * renderizado, e um erro de sintaxe no `<script>` inline não muda uma vírgula
 * do HTML — ele passa nos 555 testes e mata a tela no navegador, porque o
 * Alpine não chega a inicializar. Aconteceu: uma variável redeclarada dentro de
 * `travarSelecionado` derrubou arrastar, menu, painel e teclado de uma vez, e a
 * primeira notícia veio de quem abriu a tela.
 *
 * O quadro é o único lugar do sistema com um componente Alpine grande escrito
 * dentro do Blade — o resto mora em `resources/js`, onde o `npm run build`
 * falharia no deploy. Aqui não havia portão nenhum.
 */
class ScriptDoQuadroTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-207 O `<script>` do quadro é sintaticamente válido.
     */
    public function test_o_script_do_quadro_e_javascript_valido(): void
    {
        $criador = User::factory()->create();

        // Um card por etapa: os trechos do script variam com o que a página
        // desenha, e um quadro vazio não renderiza metade deles.
        foreach (array_keys(Tarefa::STATUS) as $status) {
            if (! in_array($status, Tarefa::STATUS_TERMINAIS, true)) {
                Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => $status]);
            }
        }

        $html = $this->actingAs(User::factory()->create())
            ->get(route('tarefas.index'))
            ->assertOk()
            ->getContent();

        // Só os inline: os de `src` são o bundle, que o `npm run build` já
        // valida no deploy.
        preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $achados);

        $this->assertNotEmpty($achados[1], 'A página do quadro precisa ter script inline para este teste valer.');

        foreach ($achados[1] as $indice => $script) {
            $arquivo = tempnam(sys_get_temp_dir(), 'quadro').'.js';
            file_put_contents($arquivo, $script);

            $resultado = Process::run(['node', '--check', $arquivo]);
            @unlink($arquivo);

            $this->assertTrue(
                $resultado->successful(),
                "O script inline #{$indice} do quadro não é JavaScript válido — o Alpine não inicializa e a tela "
                    ."morre inteira, sem nada aparecer no HTML.\n\n".$resultado->errorOutput()
            );
        }
    }
}
