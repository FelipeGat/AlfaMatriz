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
        $this->assertStringNotContainsString('text-ink-mute truncate', $html,
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
     * @spec:AC-202 O responsável virou avatar no rodapé, com a inicial e o nome inteiro
     * no `title`: o nome dele e o do sistema disputavam a mesma linha e os dois saíam
     * truncados. Sem responsável o círculo é tracejado E a frase continua dita (AC-130)
     * — contorno vazio é símbolo, e a fila de triagem não pode depender de quem já
     * aprendeu o símbolo.
     */
    public function test_o_rodape_traz_o_avatar_do_responsavel_e_o_chevron_de_mover(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $dona = User::factory()->create(['name' => 'Joana Ribeiro Dev']);

        Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento',
            'responsavel_id' => $dona->id, 'titulo' => 'Com dona',
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Duas iniciais, não a lista inteira de nomes.
        $this->assertMatchesRegularExpression('/title="Joana Ribeiro Dev">\s*JR\s*</u', $html);

        // E o Mover deixou de gastar uma linha de texto no card. A busca é por
        // BOTÃO com esse rótulo: a expressão ainda aparece em comentários do
        // script do quadro, que não são o que se lê na tela.
        $this->assertStringContainsString('aria-label="Mover tarefa"', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Mover ▾/u', $html);
    }
}
