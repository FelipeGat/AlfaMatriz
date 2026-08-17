<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * O quadro do ciclo de desenvolvimento: a rota abre para quem tem permissão
 * de `tarefas`, e as sete colunas do ciclo aparecem na ordem certa, cada uma
 * com a contagem de tarefas que está nela (T-060).
 */
class QuadroTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-081 A rota tarefas.index abre o quadro para usuário da matriz com permissão de tarefas.
     */
    public function test_rota_do_quadro_abre_para_usuario_da_matriz(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();
    }

    /**
     * @spec:AC-082 O quadro mostra as etapas do trabalho EM CURSO, na ordem e com
     * a contagem — e nenhuma coluna terminal: encerrou, sai do quadro (AC-096).
     */
    public function test_quadro_mostra_etapas_na_ordem_com_contagem(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $sistema = Sistema::factory()->create();

        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'sistema_id' => $sistema->id, 'status' => 'aberta']);
        Tarefa::factory()->count(2)->create(['criado_por_id' => $criador->id, 'status' => 'backlog']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento']);
        Tarefa::factory()->count(3)->create(['criado_por_id' => $criador->id, 'status' => 'em_revisao']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'em_staging']);
        Tarefa::factory()->count(2)->create(['criado_por_id' => $criador->id, 'status' => 'pronta_producao']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'concluida']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'cancelada']);

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();

        $etapas = $resposta->viewData('etapas');

        // Bloqueada teve coluna por um dia e virou marca no card (AC-190), e
        // Ajustes necessários virou a marca de retorno: as duas saem do quadro
        // sem tirar a tarefa do lugar. Em testes, por outro lado, se ABRIU —
        // guardava dois portões com revisor e modo de falha diferentes.
        $this->assertSame(
            ['aberta', 'backlog', 'em_desenvolvimento', 'em_revisao', 'em_staging', 'pronta_producao'],
            array_column($etapas, 'chave'),
            'O quadro é o trabalho em curso: concluída e cancelada não têm coluna, e nem bloqueio nem retorno são etapa.'
        );

        $quantidades = array_column($etapas, 'quantidade', 'chave');
        $this->assertSame(1, $quantidades['aberta']);
        $this->assertSame(2, $quantidades['backlog']);
        $this->assertSame(1, $quantidades['em_desenvolvimento']);
        $this->assertSame(3, $quantidades['em_revisao']);
        $this->assertSame(1, $quantidades['em_staging']);
        $this->assertSame(2, $quantidades['pronta_producao']);
        $this->assertArrayNotHasKey('concluida', $quantidades);
        $this->assertArrayNotHasKey('cancelada', $quantidades);

        // A ordem também vale na renderização, não só no array de apoio.
        $conteudo = $resposta->getContent();
        // "Em andamento" e não mais "Em desenvolvimento": a coluna passou a
        // receber também tarefa operacional, que não é desenvolvida (US-054).
        $rotulos = ['Aberta', 'Backlog', 'Em andamento', 'Em revisão', 'Em staging', 'Pronta p/ produção'];
        $posicoes = collect($rotulos)->map(fn ($rotulo) => strpos($conteudo, $rotulo));

        $this->assertTrue($posicoes->every(fn ($p) => $p !== false));
        $this->assertSame($posicoes->sort()->values()->all(), $posicoes->values()->all());
    }

    /**
     * @spec:AC-195 O limite de WIP conta só o que ANDA: vaga ocupada por tarefa travada
     * não é trabalho em curso, e somá-la faria o quadro acusar excesso justamente quando
     * o time está impedido de produzir. Fila não tem limite — encher o Backlog não
     * atrapalha ninguém, e um alarme ali só ensinaria a ignorar alarme.
     */
    public function test_o_limite_de_wip_conta_so_o_que_anda(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $fluxo = app(FluxoTarefaService::class);

        // Quatro em Em andamento, uma delas travada: andando são 3, no limite.
        $emAndamento = Tarefa::factory()->count(4)->create([
            'criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento',
        ]);
        $fluxo->bloquear($emAndamento->first(), 'Esperando acesso ao servidor.');

        Tarefa::factory()->count(9)->create(['criado_por_id' => $criador->id, 'status' => 'backlog']);

        $etapas = collect($this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->viewData('etapas'))
            ->keyBy('chave');

        $this->assertSame(4, $etapas['em_desenvolvimento']['quantidade']);
        $this->assertSame(3, $etapas['em_desenvolvimento']['andando']);
        $this->assertSame(3, $etapas['em_desenvolvimento']['limite']);
        $this->assertFalse($etapas['em_desenvolvimento']['acimaDoLimite'],
            'Três andando com uma travada está no limite, não acima dele.');

        // Destravar a quarta estoura o limite.
        $fluxo->destravar($emAndamento->first());

        $etapas = collect($this->actingAs($usuario)->get(route('tarefas.index'))->viewData('etapas'))->keyBy('chave');
        $this->assertTrue($etapas['em_desenvolvimento']['acimaDoLimite']);

        // Nove no Backlog não estouram nada: fila não tem limite.
        $this->assertNull($etapas['backlog']['limite']);
        $this->assertFalse($etapas['backlog']['acimaDoLimite']);
    }

    /**
     * @spec:AC-194 "A definir" é prioridade de verdade: ela fecha a ordem da coluna — não
     * é o grau mais baixo, é a decisão que não foi tomada — e o cabeçalho conta quantas
     * aguardam triagem, que é como se acha o que classificar sem ela subir na frente do
     * que alguém já chamou de crítico.
     */
    public function test_a_definir_fecha_a_ordem_e_o_cabecalho_conta_a_triagem(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'aberta',
            'prioridade' => 'nao_definida', 'titulo' => 'Sem triagem',
        ]);
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'aberta',
            'prioridade' => 'baixa', 'titulo' => 'Baixa mesmo',
        ]);

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();

        // Mesmo abaixo da Baixa: sem triagem não é o mesmo que pouco urgente.
        $this->assertSame(
            ['Baixa mesmo', 'Sem triagem'],
            $resposta->viewData('colunas')['aberta']->pluck('titulo')->all()
        );

        $etapas = collect($resposta->viewData('etapas'))->keyBy('chave');
        $this->assertSame(1, $etapas['aberta']['aguardandoTriagem']);
        $this->assertStringContainsString('1 aguardando triagem', $resposta->getContent());
    }

    /**
     * @spec:AC-127 Cada etapa tem a sua cor na COLUNA — faixa no topo e contador
     * tingido, como no Funil de Vendas — e essa cor não invade a borda do card,
     * que continua reservada ao aviso de tarefa esquecida (AC-093).
     */
    public function test_cada_etapa_tem_cor_propria_na_coluna_e_nao_no_card(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        // Uma tarefa em cada etapa, para todas as colunas renderizarem card.
        foreach (array_keys(Tarefa::STATUS) as $status) {
            Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => $status]);
        }

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();
        $html = $resposta->getContent();

        $etapas = collect($resposta->viewData('etapas'))->keyBy('chave');

        // Toda etapa traz um token de cor, e o fluxo ativo não é monocromático.
        $emCurso = collect(Tarefa::STATUS)->keys()
            ->reject(fn ($s) => in_array($s, Tarefa::STATUS_TERMINAIS, true));

        foreach ($emCurso as $status) {
            $this->assertNotEmpty($etapas[$status]['cor'] ?? null, "A etapa {$status} precisa de uma cor.");
        }
        $coresDoFluxo = $emCurso->map(fn ($s) => $etapas[$s]['cor']);
        $this->assertGreaterThan(1, $coresDoFluxo->unique()->count(),
            'As etapas do fluxo ativo não podem compartilhar uma cor só — o quadro ficaria monocromático.');

        // A cor está na COLUNA: faixa no topo do <section> de cada etapa.
        foreach ($emCurso as $status) {
            $cor = $etapas[$status]['cor'];
            $this->assertStringContainsString(
                'border-top: 3px solid rgb(var(--'.$cor.'))',
                $html,
                "A coluna {$status} precisa da faixa de cor no topo."
            );
        }

        // E NÃO no card: a borda do <article> é a prioridade (AC-356), e por
        // isso não muda quando a MESMA tarefa troca de etapa. Comparar tokens
        // não serve mais de prova — `brand` é cor de etapa e é também o tom da
        // prioridade Média —, então a prova é a invariância: mesma tarefa,
        // etapas diferentes, borda igual.
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'aberta', 'prioridade' => 'media',
        ]);

        $bordaDe = function (string $status) use ($usuario, $tarefa) {
            $tarefa->forceFill(['status' => $status])->save();
            $html = $this->actingAs($usuario)->get(route('tarefas.index'))->getContent();
            preg_match('/<article data-tarefa="'.$tarefa->id.'"[^>]*style="border-color: ([^"]+)"/s', $html, $m);

            return $m[1] ?? 'sem-borda';
        };

        $this->assertSame(
            $bordaDe('aberta'),
            $bordaDe('em_desenvolvimento'),
            'A borda do card não pode carregar a cor da etapa — ela é da prioridade da tarefa.'
        );
    }

    /**
     * @spec:AC-359 O quadro abre em tela cheia — e o caminho de volta fica à vista.
     *
     * O ganho medido é sobretudo VERTICAL: as colunas começavam a 321px do topo
     * numa janela de 1000px, ou seja um terço da altura gasto em cabeçalho, abas
     * e filtros antes do primeiro card. Expandido, elas começam a 66px.
     *
     * Este teste guarda o CONTRATO que o modo depende, e que é fácil quebrar sem
     * perceber, porque metade dele vive no CSS: o gancho que a regra procura, a
     * ordem de empilhamento contra o modal e o fundo opaco.
     */
    public function test_o_quadro_expande_para_a_tela_inteira_e_volta(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Tarefa::factory()->create(['criado_por_id' => $criador->id]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // A porta e a volta são o MESMO botão, no cabeçalho do quadro: é o
        // único cabeçalho que sobrevive ao modo.
        $this->assertStringContainsString('alternarTelaCheia()', $html);
        $this->assertStringContainsString('Expandir o quadro para a tela inteira', $html);
        $this->assertStringContainsString('Sair da tela cheia', $html);

        // O gancho do CSS. Se o atributo for renomeado na view, a regra do
        // `app.css` para de casar e o botão vira um clique sem efeito — falha
        // silenciosa, que é o motivo de este teste existir.
        $this->assertStringContainsString('data-corpo-do-quadro', $html);

        // O estado é aplicado ANTES da primeira pintura, como o tema: ligado
        // depois, o quadro nasceria na moldura e pularia para o cheio à vista.
        $posicaoDoScript = strpos($html, 'alfamatriz:quadro-tela-cheia');
        $this->assertNotFalse($posicaoDoScript, 'Falta o script que lê a escolha antes de pintar.');
        $this->assertLessThan(
            strpos($html, 'data-corpo-do-quadro'),
            $posicaoDoScript,
            'O script precisa vir ANTES do quadro, senão o modo é ligado com a tela já desenhada.'
        );

        // Esc fecha de dentro para fora: com uma tarefa aberta, ele fecha o
        // modal e NÃO derruba a tela cheia junto. O modal entra na conta pelo
        // DOM porque guarda o próprio estado — foi assim que o defeito
        // apareceu, um Esc fechando duas coisas.
        $this->assertStringContainsString("querySelectorAll('[data-modal]')", $html);

        $css = file_get_contents(base_path('resources/css/app.css'));
        $this->assertMatchesRegularExpression(
            '/\[data-tela-cheia\]\s*\[data-corpo-do-quadro\]\s*\{[^}]*position:\s*fixed/',
            $css,
            'A regra que joga o quadro para a janela inteira sumiu do app.css.'
        );

        // O fundo do quadro é um VÉU translúcido: sem um opaco embaixo, o menu
        // e a topbar apareciam POR BAIXO das colunas, com o título da página
        // lido por cima do cabeçalho do quadro.
        $this->assertMatchesRegularExpression(
            '/\[data-tela-cheia\]\s*\[data-corpo-do-quadro\]\s*\{[^}]*background-color:\s*rgb\(var\(--canvas\)\)/',
            $css,
            'O quadro em tela cheia precisa de fundo opaco — o `--board` é véu e deixa a página aparecer.'
        );

        // A ordem de empilhamento, conferida contra a fonte e não de memória:
        // a tarefa aberta e os avisos continuam por cima do quadro expandido.
        preg_match('/\[data-tela-cheia\]\s*\[data-corpo-do-quadro\]\s*\{[^}]*z-index:\s*(\d+)/', $css, $doQuadro);
        $this->assertNotEmpty($doQuadro, 'A regra da tela cheia precisa declarar o empilhamento.');

        $modal = file_get_contents(base_path('resources/views/components/modal.blade.php'));
        preg_match('/z-(\d+)/', $modal, $doModal);

        $this->assertLessThan(
            (int) $doModal[1],
            (int) $doQuadro[1],
            'O quadro em tela cheia precisa ficar ABAIXO do modal — a tarefa aberta some por baixo dele.'
        );
    }

    /**
     * @spec:AC-128 Dentro da coluna, a gravidade manda: uma crítica antiga fica acima
     * de uma baixa recente. No empate de prioridade, quem está parado há mais tempo
     * na etapa sobe — o mesmo instante que o card mostra no chip de tempo.
     */
    public function test_coluna_ordena_por_prioridade_e_depois_pelo_mais_parado(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00'));

        // Criadas fora de ordem de propósito: a crítica é a MAIS ANTIGA, que
        // no critério anterior (created_at desc) a jogaria para o fim.
        // `created_at` não é preenchível por atribuição em massa: sem o
        // forceFill as quatro nasceriam no mesmo instante e o desempate não
        // teria o que desempatar.
        $nascidaEm = function (Tarefa $tarefa, $quando) {
            return $tarefa->forceFill(['created_at' => $quando])->save() ? $tarefa : $tarefa;
        };

        $critica = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'aberta',
            'prioridade' => 'critica', 'titulo' => 'Crítica antiga',
        ]);
        $nascidaEm($critica, now()->subDays(30));

        $baixaRecente = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'aberta',
            'prioridade' => 'baixa', 'titulo' => 'Baixa de hoje',
        ]);
        $nascidaEm($baixaRecente, now()->subMinutes(5));

        // Duas de mesma prioridade: a mais parada precisa vir antes.
        $mediaParada = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'aberta',
            'prioridade' => 'media', 'titulo' => 'Média parada',
        ]);
        $nascidaEm($mediaParada, now()->subDays(9));

        $mediaNova = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'aberta',
            'prioridade' => 'media', 'titulo' => 'Média nova',
        ]);
        $nascidaEm($mediaNova, now()->subHours(2));

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();
        $ordem = $resposta->viewData('colunas')['aberta']->pluck('id')->all();

        $this->assertSame(
            [$critica->id, $mediaParada->id, $mediaNova->id, $baixaRecente->id],
            $ordem,
            'A coluna precisa descer da mais grave para a menos grave, e no empate da mais parada para a mais nova.'
        );

        Carbon::setTestNow();
    }

    /**
     * @spec:AC-351 A devolvida por um portão fura a fila da própria prioridade: a
     * volta zera o chip de tempo, e pelo desempate de AC-128 ela cairia no fim da
     * faixa como se fosse trabalho novo — com revisor ou teste esperando a segunda
     * passagem. A gravidade segue mandando acima de tudo, e entre duas devolvidas
     * volta a valer o mais parado primeiro.
     */
    public function test_a_devolvida_fura_a_fila_da_propria_prioridade(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00'));

        $nascidaEm = function (Tarefa $tarefa, $quando) {
            return $tarefa->forceFill(['created_at' => $quando])->save() ? $tarefa : $tarefa;
        };

        // `retorno_de` fica fora do fillable de propósito (a marca nasce da
        // recusa num portão) — o forceFill aqui faz o papel do portão.
        $voltouDe = function (Tarefa $tarefa, string $portao) {
            return $tarefa->forceFill(['retorno_de' => $portao, 'retorno_motivo' => 'Reprovada no exame.'])->save() ? $tarefa : $tarefa;
        };

        // A alta mais parada da bancada, SEM retorno: só pelo desempate de
        // AC-128, nenhuma alta recém-chegada passaria na frente dela.
        $altaParada = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento',
            'prioridade' => 'alta', 'titulo' => 'Alta parada há dias',
        ]);
        $nascidaEm($altaParada, now()->subDays(9));

        // Devolvida há pouco: o chip quase zerado a jogaria para o fim da faixa.
        $devolvidaRecente = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento',
            'prioridade' => 'alta', 'titulo' => 'Alta devolvida agora',
        ]);
        $nascidaEm($devolvidaRecente, now()->subHours(20));
        $voltouDe($devolvidaRecente, 'em_revisao');

        // Devolvida há mais tempo: entre devolvidas, o mais parado volta a valer.
        $devolvidaParada = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento',
            'prioridade' => 'alta', 'titulo' => 'Alta devolvida parada',
        ]);
        $nascidaEm($devolvidaParada, now()->subDays(3));
        $voltouDe($devolvidaParada, 'em_staging');

        // Crítica recém-chegada, sem retorno: a gravidade fica acima do furo.
        $criticaNova = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento',
            'prioridade' => 'critica', 'titulo' => 'Crítica nova',
        ]);
        $nascidaEm($criticaNova, now()->subMinutes(10));

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();
        $ordem = $resposta->viewData('colunas')['em_desenvolvimento']->pluck('id')->all();

        $this->assertSame(
            [$criticaNova->id, $devolvidaParada->id, $devolvidaRecente->id, $altaParada->id],
            $ordem,
            'A gravidade manda; dentro da faixa a devolvida vem antes, e entre devolvidas o mais parado primeiro.'
        );

        Carbon::setTestNow();
    }

    /**
     * @spec:AC-132 As colunas dividem a largura disponível em vez de largura fixa —
     * com cinco colunas numa tela larga sobrava faixa vazia à direita do quadro —
     * e o `min-width` segura a largura de leitura quando a tela aperta.
     */
    public function test_colunas_dividem_a_largura_e_guardam_a_largura_minima(): void
    {
        $usuario = User::factory()->create();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('flex: 1 1 272px; min-width: 272px', $html,
            'A coluna precisa crescer com o espaço e manter a largura mínima de leitura.');
        $this->assertStringNotContainsString('style="width: 276px', $html,
            'Largura fixa deixa sobra à direita quando há menos colunas do que caberia.');
    }

    /**
     * @spec:AC-205 O cabeçalho da coluna DIZ O PORTÃO. Sem a linha, "Em revisão" e
     * "Em staging" são dois nomes que só quem escreveu o fluxo distingue — e separar
     * esses dois portões foi o motivo inteiro de abrir a etapa Em testes.
     *
     * O aviso ganha do portão quando existe: "acima do limite" é notícia de agora, o
     * portão é a descrição fixa da coluna.
     */
    public function test_a_coluna_diz_o_portao_que_ela_examina(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('PR · admin analisa', $html);
        $this->assertStringContainsString('na main · dev valida', $html);
        $this->assertStringContainsString('fila do admin · tag v*', $html);

        // Estourando o WIP, a notícia de agora toma o lugar da descrição fixa.
        Tarefa::factory()->count(4)->create(['criado_por_id' => $criador->id, 'status' => 'em_revisao']);

        $cheio = $this->actingAs($usuario)->get(route('tarefas.index'))->getContent();

        $this->assertStringContainsString('acima do limite', $cheio);

        // A disputa é DO CABEÇALHO DA COLUNA, e o recorte diz isso: a página
        // inteira não fica sem o portão — a legenda da esteira continua
        // ensinando o que a coluna examina, estourada ou não.
        $inicio = strpos($cheio, 'data-status="em_revisao"');
        $cabecalho = substr($cheio, $inicio, strpos($cheio, '</header>', $inicio) - $inicio);

        $this->assertStringNotContainsString('PR · admin analisa', $cabecalho);
    }

    /**
     * A legenda da esteira, atrás do ícone de dúvida do cabeçalho: as sete
     * paradas na ordem do fluxo e as regras que o quadro só mostra uma por
     * vez — a volta dos portões, o bloqueio que é marca e o desvio da
     * operacional. Sob demanda, como os atalhos: orientação fixa cobraria
     * espaço de todo mundo para servir a quem chegou ontem.
     */
    public function test_a_legenda_da_esteira_orienta_sobre_o_fluxo(): void
    {
        $usuario = User::factory()->create();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // A porta e o painel existem, e abrir não recarrega a página.
        $this->assertStringContainsString('title="Como a esteira funciona"', $html);
        $this->assertStringContainsString('fluxoAberto', $html);

        // As paradas aparecem na ordem em que a tarefa passa por elas — a
        // legenda que embaralha a ordem ensina um fluxo que não existe. A
        // âncora é o título DO PAINEL (com o `</h3>`): o mesmo texto aparece
        // antes no `title` do botão, e cortar ali mediria o quadro, não a
        // legenda.
        $inicio = strpos($html, 'Como a esteira funciona</h3>');
        $this->assertNotFalse($inicio, 'O painel da legenda existe.');
        $legenda = substr($html, $inicio);
        $posicoes = array_map(
            fn (string $rotulo) => strpos($legenda, $rotulo),
            ['Aberta', 'Backlog', 'Em andamento', 'Em revisão', 'Em staging', 'Pronta p/ produção', 'Concluída'],
        );

        $this->assertNotContains(false, $posicoes, 'Toda parada da esteira aparece na legenda.');

        $ordenadas = $posicoes;
        sort($ordenadas);
        $this->assertSame($ordenadas, $posicoes, 'As paradas aparecem na ordem do fluxo.');

        // As regras que andam por fora da linha.
        $this->assertStringContainsString('devolve a tarefa para Em andamento', $legenda);
        $this->assertStringContainsString('sai da conta de WIP', $legenda);
        $this->assertStringContainsString('passa a bola sem mover o card', $legenda);
        $this->assertStringContainsString('vai direto para Concluída', $legenda);
    }

    /**
     * @spec:AC-205 Coluna vazia é informação, e o que ela informa muda conforme a
     * etapa: Backlog vazio é fila sem prioridade, Em andamento vazio é ninguém tocando
     * nada. Uma frase genérica repetida seis vezes desperdiça as seis notícias.
     */
    public function test_cada_coluna_vazia_diz_o_que_falta_nela(): void
    {
        $usuario = User::factory()->create();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        foreach (['Fila de triagem vazia', 'Nada priorizado', 'Ninguém tocando nada',
            'Nenhum PR aberto', 'Nada em staging', 'Nada para subir'] as $frase) {
            $this->assertStringContainsString($frase, $html);
        }

        $this->assertStringNotContainsString('Nenhuma tarefa aqui', $html);
    }

    /**
     * @spec:AC-212 Os quatro chips aparecem SEMPRE, e zerados ficam apagados em vez de
     * sumir: o cabeçalho não muda de forma conforme o dia, e "0 travadas" também é
     * notícia. Todos filtram, menos o de hoje — o que foi concluído já saiu do quadro,
     * e ele leva ao Histórico.
     */
    public function test_os_quatro_chips_do_cabecalho_aparecem_sempre_e_filtram(): void
    {
        $usuario = User::factory()->create();

        $chips = collect($this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->viewData('chips'));

        $this->assertCount(4, $chips, 'Zerado o chip fica apagado, não some.');

        $this->assertSame(
            ['0 p/ você', '0 travadas', '0 p/ subir', '0 hoje'],
            $chips->pluck('label')->all()
        );

        $recortes = $chips->pluck('href')->all();
        $this->assertStringContainsString('situacao=esperando_mim', $recortes[0]);
        $this->assertStringContainsString('situacao=travadas', $recortes[1]);
        $this->assertStringContainsString('situacao=prontas', $recortes[2]);
        $this->assertSame(route('tarefas.historico'), $recortes[3],
            'O de hoje não filtra o quadro: o que foi concluído já saiu dele.');
    }

    /**
     * @spec:AC-213 O controle de raias é um segmented control, como o Quadro/Histórico:
     * as três opções são uma escolha ENTRE si. Como texto solto, liam-se como três
     * links independentes — nada dizia que ligar uma desliga as outras.
     */
    public function test_as_raias_sao_um_segmented_control(): void
    {
        $usuario = User::factory()->create();

        $html = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['raias' => 'responsavel']))
            ->assertOk()
            ->getContent();

        // A pílula do componente compartilhado, e a opção ativa marcada como o
        // resto do sistema marca — inclusive para leitor de tela.
        $this->assertStringContainsString('rounded-control border border-line bg-surface p-1', $html);
        $this->assertMatchesRegularExpression(
            '/raias=responsavel"\s+aria-current="page"/u', $html,
            'A raia ligada precisa se anunciar como a atual.'
        );

        foreach (['Nenhuma', 'Responsável', 'Sistema'] as $opcao) {
            $this->assertStringContainsString($opcao, $html);
        }
    }

    /**
     * @spec:AC-214 Nenhum `<x-nav-icon>` recebe `class` ou `style` direto.
     *
     * O componente crava `class="w-full h-full"` no `<svg>` e NÃO mescla atributos —
     * então `class="h-3 w-3"` passada a ele é descartada em silêncio e o ícone estica
     * até o tamanho do pai. Aconteceu em onze lugares de uma vez: o cadeado do menu
     * saiu enorme e quebrou o rótulo em duas linhas.
     *
     * O tamanho vem do `<span>` que o envolve, que é como o resto do repositório faz.
     * Este teste varre o fonte porque o defeito não aparece no HTML: a classe some
     * antes de chegar lá, e o resultado é uma tela torta com markup impecável.
     */
    public function test_nenhum_icone_recebe_classe_direto(): void
    {
        $ofensores = [];

        foreach (File::allFiles(resource_path('views')) as $arquivo) {
            if (! str_ends_with($arquivo->getFilename(), '.blade.php')) {
                continue;
            }

            // A própria definição do componente é quem escreve o `class` do svg.
            if (str_contains($arquivo->getPathname(), 'components/nav-icon.blade.php')) {
                continue;
            }

            preg_match_all('/<x-nav-icon[^>]*>/', $arquivo->getContents(), $usos);

            foreach ($usos[0] as $uso) {
                if (preg_match('/\s(class|style)=/', $uso)) {
                    $ofensores[] = str_replace(resource_path('views').'/', '', $arquivo->getPathname()).': '.$uso;
                }
            }
        }

        $this->assertSame([], $ofensores,
            'O <x-nav-icon> descarta `class` e `style` — o tamanho e a cor vêm do <span> que o '
                ."envolve. Estes ficariam do tamanho do pai, sem erro nenhum na tela:\n  "
                .implode("\n  ", $ofensores));
    }
}
