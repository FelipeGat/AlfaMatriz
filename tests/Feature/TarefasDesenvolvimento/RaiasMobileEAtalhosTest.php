<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As três leituras do mesmo quadro: agrupado em raias, uma etapa por vez no
 * celular, e conduzido pelo teclado (US-063).
 */
class RaiasMobileEAtalhosTest extends TestCase
{
    use RefreshDatabase;

    private function criarTarefa(array $atributos = []): Tarefa
    {
        return Tarefa::factory()->create(array_merge([
            'criado_por_id' => User::factory(),
            'status' => 'em_desenvolvimento',
        ], $atributos));
    }

    /**
     * @spec:AC-213 As raias agrupam o quadro sem esconder nada: filtro ESCONDE o que não
     * interessa, raia mostra tudo separado. A pergunta delas é de distribuição — quem
     * está com o quê —, e essa some quando se olha coluna por coluna com todo mundo junto.
     */
    public function test_as_raias_agrupam_o_quadro_por_responsavel(): void
    {
        $usuario = User::factory()->create();
        $joana = User::factory()->create(['name' => 'Joana Dev']);
        $camila = User::factory()->create(['name' => 'Camila Reis']);

        $this->criarTarefa(['responsavel_id' => $joana->id, 'titulo' => 'Da Joana']);
        $this->criarTarefa(['responsavel_id' => $camila->id, 'titulo' => 'Da Camila']);
        $this->criarTarefa(['responsavel_id' => null, 'status' => 'aberta', 'titulo' => 'De ninguém']);

        $raias = $this->actingAs($usuario)->get(route('tarefas.index', ['raias' => 'responsavel']))
            ->assertOk()->viewData('raias');

        $this->assertSame('responsavel', $raias['modo']);

        // "Sem responsável" é raia de verdade, e a ÚLTIMA: ela é uma pergunta
        // em aberto, não um grupo — no meio da lista se leria como mais uma
        // pessoa.
        $this->assertSame(
            ['Camila Reis', 'Joana Dev', 'Sem responsável'],
            array_column($raias['faixas'], 'titulo')
        );

        // Nada se perdeu: o quadro inteiro continua na tela, só separado.
        $total = collect($raias['faixas'])
            ->sum(fn ($faixa) => collect($faixa['colunas'])->sum(fn ($coluna) => $coluna->count()));

        $this->assertSame(3, $total);
    }

    /**
     * @spec:AC-213 Sem raia, o quadro é uma faixa só — a tela não precisa perguntar se há
     * agrupamento para saber o que desenhar dentro da coluna. E o agrupamento por sistema
     * responde a outra pergunta: onde cada produto está travado.
     */
    public function test_sem_raia_o_quadro_e_uma_faixa_so_e_por_sistema_agrupa_por_produto(): void
    {
        $usuario = User::factory()->create();
        $gym = Sistema::factory()->create(['nome' => 'AlfaGym']);

        $this->criarTarefa(['sistema_id' => $gym->id]);
        $this->criarTarefa(['sistema_id' => null]);

        $semRaia = $this->actingAs($usuario)->get(route('tarefas.index'))->viewData('raias');

        $this->assertSame('nenhuma', $semRaia['modo']);
        $this->assertCount(1, $semRaia['faixas']);
        $this->assertNull($semRaia['faixas'][0]['titulo']);

        $porSistema = $this->actingAs($usuario)->get(route('tarefas.index', ['raias' => 'sistema']))
            ->viewData('raias');

        $this->assertSame(['AlfaGym', 'Sem sistema'], array_column($porSistema['faixas'], 'titulo'));
    }

    /**
     * @spec:AC-214 A raia de quem está com mais de duas em andamento ganha selo. Quem está
     * com quatro coisas ao mesmo tempo não está tocando quatro — está revezando entre
     * elas. Vale só por pessoa: sistema com cinco tarefas andando é projeto grande.
     */
    public function test_o_selo_de_trabalho_em_paralelo_conta_so_o_que_anda_e_so_por_pessoa(): void
    {
        $usuario = User::factory()->create();
        $joana = User::factory()->create(['name' => 'Joana Dev']);
        $fluxo = app(FluxoTarefaService::class);

        $tarefas = collect(range(1, 3))->map(fn () => $this->criarTarefa(['responsavel_id' => $joana->id]));

        $selo = fn (string $modo) => collect($this->actingAs($usuario)
            ->get(route('tarefas.index', ['raias' => $modo]))->viewData('raias')['faixas'])
            ->firstWhere('titulo', $modo === 'responsavel' ? 'Joana Dev' : 'Sem sistema')['sobrecarga'];

        $this->assertTrue($selo('responsavel'));

        // Travar uma tira ela da conta: quem espera terceiro não está tocando
        // a tarefa, e o selo mediria pressão que não existe.
        $fluxo->bloquear($tarefas->first(), 'Esperando o cliente.');
        $this->assertFalse($selo('responsavel'));

        // E por sistema o selo não existe: cinco tarefas num produto é projeto
        // grande, não sobrecarga de ninguém.
        $this->assertFalse($selo('sistema'));
    }

    /**
     * @spec:AC-215 No celular o quadro não é quadro: uma etapa por vez, trocada por uma
     * tira de chips que carrega a contagem e o limite. A troca é CSS, não JavaScript —
     * com JS decidindo, o celular mostraria as cinco colunas por um quadro antes de
     * esconder quatro, e esse piscar é pior que a rolagem que ele veio consertar.
     */
    public function test_o_celular_mostra_uma_etapa_por_vez_por_regra_de_css(): void
    {
        $usuario = User::factory()->create();
        $this->criarTarefa();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // A tira de chips existe e só aparece na tela estreita.
        $this->assertStringContainsString('lg:hidden', $html);

        // A etapa visível é uma classe no quadro, não um `x-show` por coluna.
        $this->assertStringContainsString("'etapa-' + etapaMobile", $html);
        $this->assertStringContainsString('data-quadro', $html);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('[data-quadro] section[data-status]', $css);
        $this->assertStringContainsString("[data-quadro].etapa-aberta section[data-status='aberta']", $css);
    }

    /**
     * @spec:AC-216 O quadro anda pelo teclado, e nada dispara enquanto se digita: sem
     * essa guarda, escrever "backlog" na busca moveria cards pelo caminho — o `b`
     * bloqueia e o `c` abre a criação rápida.
     */
    public function test_o_teclado_conduz_o_quadro_e_se_cala_enquanto_se_digita(): void
    {
        $usuario = User::factory()->create();
        $this->criarTarefa();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Escuta na janela: o quadro não recebe foco, e exigir que recebesse
        // obrigaria a clicar no fundo antes da primeira seta funcionar.
        $this->assertStringContainsString('@keydown.window="aoTeclar($event)"', $html);
        $this->assertStringContainsString("evento.target.closest('input, textarea, select, [contenteditable]')", $html);

        // O card leva ao teclado os mesmos dados que leva ao arraste.
        $this->assertStringContainsString('data-destinos=', $html);

        // E a lista de atalhos existe atrás do "?" — atalho que ninguém
        // descobre é atalho que não existe.
        $this->assertStringContainsString('Atalhos do quadro', $html);
        $this->assertStringContainsString('Move a tarefa de etapa', $html);
    }
}
