<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O card do quadro: mostra o sistema vinculado, quanto tempo a tarefa está
 * parada na etapa atual e ganha destaque quando esse tempo vira sinal de
 * tarefa esquecida (T-062).
 */
class CardTarefaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @spec:AC-084 O card mostra o sistema da tarefa.
     */
    public function test_card_mostra_o_sistema_da_tarefa(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'sistema_id' => $sistema->id,
            'status' => 'backlog',
        ]);

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();
        $resposta->assertSee('AlfaGym');
    }

    /**
     * @spec:AC-084 Tarefa sem sistema mostra "Sem sistema" no card.
     */
    public function test_card_mostra_sem_sistema_quando_a_tarefa_nao_tem_vinculo(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'sistema_id' => null,
            'status' => 'backlog',
        ]);

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();
        $resposta->assertSee('Sem sistema');
    }

    /**
     * @spec:AC-092 Tarefa recém-criada, que ainda não teve tempo de acumular
     * nenhuma etapa fechada, aparece com "agora" no card.
     */
    public function test_card_mostra_agora_para_tarefa_recem_criada(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00'));
        Tarefa::factory()->create(['criado_por_id' => $criador->id]);

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();
        $resposta->assertSee('agora');
    }

    /**
     * @spec:AC-092 O card mostra em forma curta ("3h") o tempo que a tarefa
     * está parada na etapa atual, contado a partir do evento aberto.
     */
    public function test_card_mostra_tempo_curto_na_etapa_em_horas(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-10 06:00:00'));
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'status' => 'em_desenvolvimento',
        ]);
        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => 'backlog',
            'para_status' => 'em_desenvolvimento',
            'entrou_em' => now(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00'));
        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();
        $resposta->assertSee('3h');
    }

    /**
     * @spec:AC-092 Passados dias inteiros, o card mostra a forma curta em
     * dias ("2d").
     */
    public function test_card_mostra_tempo_curto_na_etapa_em_dias(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-08 09:00:00'));
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'status' => 'em_desenvolvimento',
        ]);
        TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => 'backlog',
            'para_status' => 'em_desenvolvimento',
            'entrou_em' => now(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00'));
        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();
        $resposta->assertSee('2d');
    }

    /**
     * @spec:AC-093 Tarefa parada há mais de 24h em Aberta ganha destaque de
     * atenção.
     */
    public function test_tarefa_parada_mais_de_24h_em_aberta_ganha_destaque_de_atencao(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00'));
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'status' => 'aberta',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00'));
        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();
        $resposta->assertSee('data-esquecida="atencao"', false);
    }

    /**
     * @spec:AC-093 Passando do dobro da régua, o destaque da etapa vira crítico.
     *
     * Em revisão herdou as 24h que Em testes tinha: revisar um PR é fila, e a
     * régua de fila é mais curta que a da bancada.
     */
    public function test_tarefa_parada_mais_do_que_o_dobro_da_regua_ganha_destaque_critico(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00'));
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'status' => 'em_revisao',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00'));
        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();
        $resposta->assertSee('data-esquecida="critico"', false);
    }

    /**
     * @spec:AC-193 O envelhecimento vale em todas as etapas de trabalho, cada uma com a
     * sua régua — e o Backlog fica de fora, porque lá ficar parada é o que a tarefa deve
     * fazer. Em andamento aguenta 72h antes de acender: três dias escrevendo código é
     * trabalho, três dias esperando alguém testar é fila.
     */
    public function test_envelhecimento_tem_regua_propria_por_etapa_e_poupa_o_backlog(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        // 48h em Em andamento ainda é trabalho: abaixo das 72h, nada acende.
        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00'));
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento']);

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00'));
        $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()
            ->assertDontSee('data-esquecida', false);

        // Passando das 72h, acende — o que a régua fixa de antes nunca via.
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00'));
        $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()
            ->assertSee('data-esquecida="atencao"', false);

        // O Backlog é fila: ficar parada lá é o que a tarefa deve fazer.
        Tarefa::query()->update(['status' => 'backlog']);

        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00'));
        $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()
            ->assertDontSee('data-esquecida', false);
    }

    /**
     * @spec:AC-126 Cada prioridade tem a sua cor: nenhum par compartilha tom, e a
     * escala sobe do mais discreto (Baixa) ao mais grave (Crítica).
     *
     * Antes de existir a Crítica, `baixa` e `media` dividiam o tom neutro — dois
     * dos quatro níveis eram indistinguíveis no quadro e a escala perdia o meio.
     * Com "A definir" (AC-194) são cinco, e Alta desceu para o âmbar mais quente
     * para não dividir tom com ela: um é gravidade, o outro é triagem que falta.
     */
    public function test_cada_prioridade_tem_cor_distinta(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        foreach (array_keys(Tarefa::PRIORIDADES) as $prioridade) {
            Tarefa::factory()->create([
                'criado_por_id' => $criador->id,
                'titulo' => "Tarefa {$prioridade}",
                'prioridade' => $prioridade,
            ]);
        }

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Cada selo de prioridade traz o token de cor do seu tom; recolhemos o
        // token que acompanha cada rótulo e exigimos quatro valores distintos.
        $tokens = [];
        foreach (Tarefa::PRIORIDADES as $chave => $rotulo) {
            $this->assertMatchesRegularExpression('/'.preg_quote($rotulo, '/').'/u', $html,
                "O rótulo {$rotulo} precisa aparecer no quadro.");

            preg_match('/<span[^>]*--([a-z-]+)[^>]*>\s*'.preg_quote($rotulo, '/').'\s*<\/span>/u', $html, $m);
            $tokens[$chave] = $m[1] ?? 'sem-token';
        }

        $this->assertCount(count(Tarefa::PRIORIDADES), array_unique($tokens),
            'Cada prioridade precisa de um tom próprio — tons repetidos: '.json_encode($tokens, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @spec:AC-356 A borda do card é a prioridade — e continua sendo mesmo quando a
     * tarefa está travada, voltou de um portão, tem pergunta ou envelheceu.
     *
     * A borda já foi a precedência de sinais do protótipo. Ela repetia: os quatro
     * sinais desenham tarja própria dentro do card, ou tingem o selo de tempo do
     * rodapé. A prioridade era a única sem eco — vivia num selo mono de 9,5px que
     * só se lê parando em cima do card, e o quadro é olhado de relance.
     */
    public function test_a_borda_do_card_segue_a_prioridade(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $cards = [];
        foreach (array_keys(Tarefa::PRIORIDADES) as $prioridade) {
            $cards[$prioridade] = Tarefa::factory()->create([
                'criado_por_id' => $criador->id,
                'prioridade' => $prioridade,
            ])->id;
        }

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Por referência: o segundo trecho do teste recarrega o quadro, e a
        // closure precisa ler o HTML novo, não o da primeira visita.
        $bordaDe = function (int $id) use (&$html) {
            // Até o `;`: na Crítica o mesmo atributo carrega o tinte do corpo
            // (AC-357), que não é a borda e não entra na comparação.
            preg_match('/<article data-tarefa="'.$id.'"[^>]*style="border-color: ([^";]+)/s', $html, $m);

            return $m[1] ?? 'sem-borda';
        };

        $bordas = array_map($bordaDe, $cards);

        // Nenhum par de prioridades divide borda: é o mesmo requisito do selo
        // (AC-126), agora na moldura do card.
        $this->assertCount(count($bordas), array_unique($bordas),
            'Cada prioridade precisa de uma borda própria — repetidas: '.json_encode($bordas, JSON_UNESCAPED_UNICODE));

        // Baixa não tem tom na escala e fica na linha neutra: se todo card
        // tivesse borda colorida, nenhuma se destacaria.
        $this->assertSame('var(--line)', $bordas['baixa']);
        $this->assertStringContainsString('--crit', $bordas['critica']);

        // E os sinais não tomam a borda de volta: uma crítica travada, com
        // pergunta em aberto, devolvida do staging e parada há uma semana
        // continua com a borda da prioridade dela.
        $tarefa = Tarefa::find($cards['critica']);
        $tarefa->forceFill([
            'status' => 'em_desenvolvimento',
            'bloqueado_em' => now()->subWeek(),
            'bloqueio_motivo' => 'Esperando a credencial do gateway.',
            'retorno_de' => 'em_staging',
            'retorno_motivo' => 'O boleto saiu sem o nosso número.',
            'pergunta_em' => now()->subDay(),
            'pergunta_de_id' => $criador->id,
            'pergunta_para_id' => $usuario->id,
        ])->save();

        Carbon::setTestNow(Carbon::now()->addWeek());

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('--crit', $bordaDe($cards['critica']),
            'Travada, devolvida, com pergunta e envelhecida, a borda continua sendo a da prioridade.');
    }

    /**
     * @spec:AC-357 As duas pontas da escala não dependem só do tom.
     *
     * "A definir" tracejada porque ela não é um grau: é a triagem que falta, e o
     * tracejado já é o idioma da casa para lacuna — o círculo sem sistema, no
     * rodapé deste mesmo card. A Crítica tinge o corpo porque uma coluna cheia se
     * lê pela mancha antes de se ler pela moldura.
     */
    public function test_a_forma_reforca_a_triagem_que_falta_e_a_critica(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $cards = [];
        foreach (array_keys(Tarefa::PRIORIDADES) as $prioridade) {
            $cards[$prioridade] = Tarefa::factory()->create([
                'criado_por_id' => $criador->id,
                'prioridade' => $prioridade,
            ])->id;
        }

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $aberturaDe = function (int $id) use ($html) {
            preg_match('/<article data-tarefa="'.$id.'"[^>]*>/s', $html, $m);

            return $m[0] ?? '';
        };

        foreach ($cards as $prioridade => $id) {
            $card = $aberturaDe($id);

            // "A definir" tracejada: ela não é um grau de gravidade, é a
            // triagem que ainda não aconteceu (AC-194).
            $this->assertSame($prioridade === 'nao_definida', str_contains($card, 'border-dashed'),
                "A borda tracejada é de 'A definir' e só dela — {$prioridade} não confere.");

            // A Crítica tinge o corpo, e assim para de depender do tom da
            // borda para se destacar na coluna.
            $this->assertSame($prioridade === 'critica', str_contains($card, '--crit-tint'),
                "O corpo tingido é da Crítica e só dela — {$prioridade} não confere.");
        }

        // O tinte entra POR CIMA do fundo do card, não no lugar dele: o token é
        // translúcido, e sem o fundo opaco embaixo ele cairia sobre o board.
        $this->assertStringContainsString('bg-card-quadro', $aberturaDe($cards['critica']));
    }

    /**
     * @spec:AC-358 No card, nenhuma cor diz duas coisas.
     *
     * As tarjas emprestavam tom da família de sinal — pergunta no teal da marca,
     * bloqueio e retorno no mesmo âmbar. Funcionava enquanto a borda do card era
     * o aviso de esquecida; desde que ela virou a prioridade (AC-356), todo tom
     * de sinal já quer dizer um grau, e a tarja passou a pintar duas coisas com
     * a mesma cor DENTRO DO MESMO CARD: bloqueio lia como Crítica, pergunta como
     * Média. Cada notícia ganhou matiz próprio, e a prova é medida, não opinião.
     */
    public function test_no_card_nenhuma_cor_diz_duas_coisas(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $comPergunta = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'em_revisao', 'prioridade' => 'media',
            'pergunta_em' => now(), 'pergunta_de_id' => $criador->id, 'pergunta_para_id' => $usuario->id,
        ]);
        $emExame = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'em_revisao', 'interlocutor_id' => $usuario->id,
        ]);
        $travada = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'prioridade' => 'nao_definida',
            'bloqueado_em' => now(), 'bloqueio_motivo' => 'Esperando a credencial do gateway.',
        ]);
        $devolvida = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento',
            'retorno_de' => 'em_staging', 'retorno_motivo' => 'O boleto saiu sem o nosso número.',
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $card = function (Tarefa $tarefa) use ($html) {
            $inicio = strpos($html, 'data-tarefa="'.$tarefa->id.'"');
            $this->assertNotFalse($inicio, "A tarefa {$tarefa->id} não apareceu no quadro.");

            return substr($html, $inicio, strpos($html, '</article>', $inicio) - $inicio);
        };

        // Cada tarja fala pelo seu token, e por nenhum outro. A conferência é
        // pelos dois lados: a cor certa presente E as das outras notícias
        // ausentes — só a primeira metade deixaria passar uma tarja que
        // acumulasse tons.
        $tarjas = [
            'pergunta' => $card($comPergunta),
            'exame' => $card($emExame),
            'bloqueio' => $card($travada),
            'retorno' => $card($devolvida),
        ];

        foreach ($tarjas as $noticia => $trecho) {
            $this->assertStringContainsString("var(--{$noticia}-tint)", $trecho,
                "A tarja de {$noticia} precisa usar o tom próprio dela.");

            foreach (array_keys($tarjas) as $outra) {
                if ($outra !== $noticia) {
                    $this->assertStringNotContainsString("var(--{$outra}-tint)", $trecho,
                        "O card de {$noticia} não pode pintar nada com o tom de {$outra}.");
                }
            }
        }

        // E a prova de fundo: as nove notícias coloridas do card ficam a mais de
        // ΔE 25 umas das outras, NOS DOIS TEMAS. Abaixo disso duas cores se
        // confundem de relance, que é como o quadro é olhado — foi o que
        // aconteceu com Alta e "A definir", ΔE 8 no claro, os dois âmbares.
        $css = file_get_contents(base_path('resources/css/app.css'));

        $canais = function (string $token, string $tema) use ($css): array {
            $bloco = $tema === 'escuro'
                ? substr($css, strpos($css, ':root {'), strpos($css, '.theme-light {') - strpos($css, ':root {'))
                : substr($css, strpos($css, '.theme-light {'));

            preg_match('/--'.$token.':\s*(\d+)\s+(\d+)\s+(\d+);/', $bloco, $m);
            $this->assertNotEmpty($m, "O tema {$tema} não declara `--{$token}` em canais R G B.");

            return [(int) $m[1], (int) $m[2], (int) $m[3]];
        };

        // ΔE76 no espaço Lab — a mesma régua que escolheu estes matizes.
        $lab = function (array $rgb): array {
            [$r, $g, $b] = array_map(function (int $c): float {
                $v = $c / 255;

                return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, $rgb);

            $f = fn (float $t): float => $t > 0.008856 ? $t ** (1 / 3) : (7.787 * $t) + 16 / 116;
            $x = $f((0.4124 * $r + 0.3576 * $g + 0.1805 * $b) / 0.95047);
            $y = $f(0.2126 * $r + 0.7152 * $g + 0.0722 * $b);
            $z = $f((0.0193 * $r + 0.1192 * $g + 0.9505 * $b) / 1.08883);

            return [116 * $y - 16, 500 * ($x - $y), 200 * ($y - $z)];
        };

        $noticias = ['brand', 'amber', 'triagem', 'crit', 'pergunta', 'exame', 'bloqueio', 'retorno'];

        foreach (['escuro', 'claro'] as $tema) {
            foreach ($noticias as $i => $uma) {
                foreach (array_slice($noticias, $i + 1) as $outra) {
                    [$l1, $a1, $b1] = $lab($canais($uma, $tema));
                    [$l2, $a2, $b2] = $lab($canais($outra, $tema));
                    $distancia = sqrt(($l1 - $l2) ** 2 + ($a1 - $a2) ** 2 + ($b1 - $b2) ** 2);

                    $this->assertGreaterThan(25, $distancia, sprintf(
                        'No tema %s, `%s` e `%s` estão a ΔE %.1f — abaixo de 25 as duas se confundem de relance.',
                        $tema, $uma, $outra, $distancia
                    ));
                }
            }
        }
    }

    /**
     * @spec:AC-129 O resumo é lido no próprio card; card sem resumo não reserva espaço vazio.
     */
    public function test_card_mostra_o_resumo_e_omite_a_linha_quando_nao_ha(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Com resumo',
            'resumo' => 'Academia reclamou que o export não traz linhas.',
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Academia reclamou que o export não traz linhas.', $html);

        // Sem resumo, nenhum parágrafo de resumo é emitido para aquele card.
        Tarefa::query()->delete();
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Sem resumo',
            'resumo' => null,
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Sem resumo', $html);
        $this->assertStringNotContainsString('leading-[1.4] text-ink-mute truncate', $html,
            'Card sem resumo não pode emitir a linha do resumo.');
    }

    /**
     * @spec:AC-130 A falta de responsável é dita no card, não deduzida da ausência do nome.
     */
    public function test_card_sem_responsavel_diz_que_nao_tem(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $responsavel = User::factory()->create(['name' => 'Joana Dev']);

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Ainda não direcionada',
            'responsavel_id' => null,
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Sem responsável', $html);

        // Com responsável, o nome ocupa o mesmo segmento — e o aviso some.
        Tarefa::query()->delete();
        Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Direcionada',
            'responsavel_id' => $responsavel->id,
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Joana Dev', $html);
        $this->assertStringNotContainsString('Sem responsável', $html);
    }

    /**
     * @spec:AC-202 O rodapé traz o ícone do sistema no círculo e o responsável escrito
     * ao lado: a marca do produto é reconhecida sem ler, e duas iniciais no círculo
     * exigiam decorar quem é "JR". Do nome saem o primeiro e o último — só o primeiro
     * empata duas Julianas —, com o nome inteiro no `title`. Sem responsável a frase
     * continua dita por extenso (AC-130): a fila de triagem não pode depender de quem
     * já aprendeu o símbolo do contorno vazio.
     */
    public function test_o_rodape_traz_o_responsavel_pelo_nome_e_o_chevron_de_mover(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $dona = User::factory()->create(['name' => 'Joana Ribeiro Dev']);

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento',
            'responsavel_id' => $dona->id, 'titulo' => 'Com dona',
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Primeiro e último, sem o nome do meio — e não as iniciais.
        // `[^>]*` entre o title e o `>`: o texto carrega classe e estilo depois
        // do title, e prender a asserção à ordem dos atributos a faria quebrar
        // a cada ajuste de estilo sem nada ter mudado na tela.
        $this->assertMatchesRegularExpression('/title="Joana Ribeiro Dev"[^>]*>\s*Joana Dev\s*</u', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*JR\s*</u', $html);

        // E o Mover deixou de gastar uma linha de texto no card. A busca é por
        // BOTÃO com esse rótulo: a expressão ainda aparece em comentários do
        // script do quadro, que não são o que se lê na tela.
        $this->assertStringContainsString('aria-label="Mover de etapa"', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Mover ▾/u', $html);
    }

    /**
     * @spec:AC-084 O círculo do rodapé carrega a MARCA do sistema, não mais as iniciais
     * de quem responde: o ícone identifica o produto de relance, sem ler. Sistema sem
     * arquivo de marca cai nas iniciais do próprio nome, que é o que o `x-marca-sistema`
     * já faz nas outras telas; tarefa sem sistema fica com o círculo vazio e a frase.
     */
    public function test_o_circulo_do_rodape_traz_a_marca_do_sistema(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym']);

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'backlog',
            'sistema_id' => $sistema->id, 'titulo' => 'Com sistema',
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O nome do sistema saiu do texto do rodapé e virou o `title` do
        // círculo, com o arquivo da marca dentro dele.
        //
        // `/marcas/`, e não `/sistemas/`: a pasta foi renomeada porque o nome
        // antigo sombreava a rota `sistemas` no nginx do servidor. Ver
        // `tests/Feature/Deploy/RotaSombreadaPorArquivoTest`.
        $this->assertMatchesRegularExpression(
            '/title="AlfaGym"[^>]*>\s*<img src="\/marcas\/alfagym\.svg"/u', $html);
    }

    /**
     * O card imprime o número da tarefa, que é como ela é chamada fora da tela.
     *
     * O `id` já era o identificador de todo o resto — a URL, o `data-tarefa`, o
     * nome do modal — e era o único dado da tarefa que nunca aparecia para quem
     * olha o quadro: para pedir "aquela do boleto duplicado" era preciso
     * descrever a tarefa inteira.
     */
    public function test_o_card_mostra_o_numero_da_tarefa(): void
    {
        $usuario = User::factory()->create();

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => User::factory(),
            'status' => 'backlog',
            'titulo' => 'Corrigir boleto duplicado',
        ]);

        $this->assertSame('#'.$tarefa->id, $tarefa->codigo(), 'O número da tarefa é o id, sem contador paralelo.');

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Prefixo do título, no MESMO parágrafo: como item próprio do flex ele
        // abriria uma coluna fixa que tira largura das duas linhas do título.
        $this->assertMatchesRegularExpression(
            '/#'.$tarefa->id.'<\/span>\s*Corrigir boleto duplicado/u',
            $html,
            'O número precisa vir colado ao título, dentro do parágrafo dele.'
        );
    }
}
