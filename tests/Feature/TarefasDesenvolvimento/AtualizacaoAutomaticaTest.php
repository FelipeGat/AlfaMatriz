<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O quadro se atualiza sozinho — sem trazer o quadro a cada pergunta.
 *
 * O quadro é de time: a mesma tarefa passa por três pessoas no mesmo dia. Até
 * aqui a tela só via o que a PRÓPRIA pessoa fazia, e quem a deixava aberta
 * trabalhava sobre o retrato do momento em que abriu — movia um card que já
 * tinha sido movido e recebia "Alguém já moveu esta tarefa" (AC-208), que é a
 * recusa certa dita tarde demais.
 *
 * A parte que estes testes guardam não é o "perguntar de tempos em tempos": é
 * o PESO. O quadro custa ~900 KB, e foi por isso que as ações do modal pararam
 * de devolvê-lo (AC-229). Pedi-lo de meio em meio minuto, por pessoa, traria o
 * mesmo problema pela porta dos fundos — daí a assinatura, e daí os dois lados
 * que aqui se exercitam: ela muda quando o quadro muda, e NÃO muda quando não.
 */
class AtualizacaoAutomaticaTest extends TestCase
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
     * A assinatura que a página imprimiu — é ela que a tela devolve na pergunta.
     */
    private function assinaturaDaPagina(User $usuario): string
    {
        $html = $this->actingAs($usuario)
            ->get(route('tarefas.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/let assinaturaDoQuadro = "[0-9a-f]{32}"/',
            $html,
            'A página do quadro precisa nascer com a assinatura, senão a primeira pergunta vem sem nada a comparar.',
        );

        preg_match('/let assinaturaDoQuadro = "([0-9a-f]{32})"/', $html, $achado);

        return $achado[1];
    }

    private function perguntar(User $usuario, string $assinatura, array $recorte = [])
    {
        return $this->actingAs($usuario)->getJson(
            route('tarefas.atualizacoes', ['assinatura' => $assinatura] + $recorte)
        );
    }

    /**
     * @spec:AC-362 Sem novidade, a pergunta não monta o quadro — a resposta é a assinatura
     * de volta e mais nada. É o que permite perguntar de meio em meio minuto.
     */
    public function test_a_pergunta_sem_novidade_nao_devolve_o_quadro(): void
    {
        $usuario = User::factory()->create();
        $this->criarTarefa(['titulo' => 'Corrigir o boleto da Orbe']);

        $assinatura = $this->assinaturaDaPagina($usuario);

        $resposta = $this->perguntar($usuario, $assinatura);

        $resposta->assertOk();
        // O quadro nulo é o ponto inteiro da feature: com ele preenchido, a
        // atualização automática custaria ~900 KB por pessoa e por pergunta.
        $resposta->assertJsonPath('quadro', null);
        $resposta->assertJsonPath('assinatura', $assinatura);
        $resposta->assertDontSee('Corrigir o boleto da Orbe');
    }

    /**
     * @spec:AC-362 O movimento de OUTRA pessoa chega à minha tela: a assinatura muda e o
     * quadro volta redesenhado, com o card já na coluna nova.
     */
    public function test_o_movimento_de_outra_pessoa_traz_o_quadro_redesenhado(): void
    {
        $usuario = User::factory()->create();
        $colega = User::factory()->create();
        $tarefa = $this->criarTarefa(['titulo' => 'Corrigir o boleto da Orbe']);

        $assinatura = $this->assinaturaDaPagina($usuario);

        $this->actingAs($colega)
            ->post(route('tarefas.mover', $tarefa), [
                'status' => 'em_revisao',
                'de_status' => 'em_desenvolvimento',
            ]);

        $resposta = $this->perguntar($usuario, $assinatura);

        $resposta->assertOk();
        $this->assertNotSame($assinatura, $resposta->json('assinatura'));
        $this->assertNotNull($resposta->json('quadro'));
        $this->assertStringContainsString('Corrigir o boleto da Orbe', $resposta->json('quadro'));

        // E a assinatura que voltou é a nova: devolvê-la encerra a conversa,
        // em vez de o quadro inteiro vir de novo no meio minuto seguinte.
        $this->perguntar($usuario, $resposta->json('assinatura'))
            ->assertJsonPath('quadro', null);
    }

    /**
     * @spec:AC-362 Mudança que não toca a linha da tarefa também chega: o "3/5" do
     * checklist, a conversa e os anexos são de outras tabelas, e nenhuma carimba a tarefa.
     */
    public function test_o_que_muda_so_nos_filhos_tambem_muda_a_assinatura(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();
        $item = $tarefa->itens()->create(['texto' => 'Conferir o valor']);

        $assinatura = $this->assinaturaDaPagina($usuario);

        // O relógio anda um segundo antes da edição, e não é conveniência de
        // teste: `updated_at` tem precisão de SEGUNDO, então gravar dentro do
        // mesmo segundo em que a assinatura foi lida deixa a marca igual — é o
        // limite conhecido, documentado em `assinaturaDoQuadro`. Sem andar o
        // relógio, o teste mediria essa fresta em vez do que se propõe a medir:
        // que a edição do FILHO chega. Na tela real, quem lê e quem grava são
        // pessoas diferentes em instantes diferentes.
        $this->travel(1)->seconds();

        // Direto no model, sem passar pelo controlador: é o filho mudando com a
        // linha de `tarefas` intacta, que é justamente o caso que um
        // `max(updated_at)` só da tarefa deixaria passar.
        $item->update(['feito' => true]);
        $this->assertSame(
            $tarefa->updated_at->toDateTimeString(),
            $tarefa->fresh()->updated_at->toDateTimeString(),
            'Se a tarefa passar a ser carimbada pelo item, este teste deixa de provar o que se propõe.',
        );

        $this->assertNotNull(
            $this->perguntar($usuario, $assinatura)->assertOk()->json('quadro'),
            'Marcar um item muda o "3/5" do card sem tocar na linha da tarefa: quem tem de ver isso é a marca do filho.',
        );
    }

    /**
     * @spec:AC-362 Um comentário CORRIGIDO chega — ele não muda contagem nem maior id, e a
     * tarja de pergunta do card é texto de comentário.
     */
    public function test_o_comentario_corrigido_muda_a_assinatura(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();
        $comentario = $tarefa->comentarios()->create([
            'autor_id' => $usuario->id,
            'corpo' => 'Falta o retorno do banco',
        ]);

        $assinatura = $this->assinaturaDaPagina($usuario);

        // O relógio anda pela mesma razão do teste do checklist: a correção
        // dentro do mesmo segundo da leitura cai no limite de precisão de
        // `updated_at`, e não é ele que este teste está medindo.
        $this->travel(1)->seconds();

        $comentario->update(['corpo' => 'Falta o retorno do banco emissor']);

        $this->assertNotNull(
            $this->perguntar($usuario, $assinatura)->json('quadro'),
            'Corrigir um comentário não muda contagem nem maior id: quem tem de ver isso é o max(updated_at).',
        );
    }

    /**
     * @spec:AC-362 O quadro que volta é o do RECORTE de quem pergunta — a atualização
     * automática não desfaz sozinha o filtro que estava na tela.
     */
    public function test_o_recorte_viaja_na_pergunta(): void
    {
        $usuario = User::factory()->create();
        $orbe = Sistema::factory()->create(['nome' => 'Orbe']);
        $outro = Sistema::factory()->create(['nome' => 'Vega']);

        $this->criarTarefa(['titulo' => 'Boleto da Orbe', 'sistema_id' => $orbe->id]);
        $this->criarTarefa(['titulo' => 'Relatorio da Vega', 'sistema_id' => $outro->id]);

        $recorte = ['sistema' => (string) $orbe->id];

        $quadro = $this->perguntar($usuario, 'assinatura-que-nao-confere', $recorte)->json('quadro');

        $this->assertStringContainsString('Boleto da Orbe', $quadro);
        $this->assertStringNotContainsString('Relatorio da Vega', $quadro);
    }

    /**
     * @spec:AC-362 Toda ação parcial devolve a assinatura NOVA, inclusive as que não
     * devolvem o quadro — senão a própria ação de quem está na tela faria a atualização
     * automática pedir o quadro inteiro logo depois, desfazendo a economia dos pedaços.
     */
    public function test_a_acao_parcial_devolve_a_assinatura_nova(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();
        $item = $tarefa->itens()->create(['texto' => 'Conferir o valor']);

        $assinatura = $this->assinaturaDaPagina($usuario);

        // Mesmo motivo dos dois testes acima: marcar o item só mexe no
        // `updated_at` dele, e no mesmo segundo da leitura a marca não muda.
        $this->travel(1)->seconds();

        $resposta = $this->actingAs($usuario)
            ->putJson(route('tarefas.itens.update', $item), ['feito' => '1']);

        $resposta->assertOk();
        // O quadro continua fora da resposta — a economia dos `pedacos` é o que
        // esta feature não pode desfazer.
        $resposta->assertJsonPath('quadro', null);
        $this->assertNotSame($assinatura, $resposta->json('assinatura'));

        // E com ela em mãos a tela está em dia: nada de quadro inteiro no meio
        // minuto seguinte.
        $this->perguntar($usuario, $resposta->json('assinatura'))
            ->assertJsonPath('quadro', null);
    }

    /**
     * @spec:AC-362 A pergunta é do quadro, e só de quem enxerga o quadro: usuário de
     * revenda toma 403 nela como em qualquer outra rota de tarefas (AC-095).
     */
    public function test_a_pergunta_esta_fora_do_alcance_de_quem_nao_ve_o_quadro(): void
    {
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $usuario = User::factory()->create(['revenda_id' => $revenda->id]);
        $tarefa = $this->criarTarefa(['titulo' => 'Corrigir o boleto da Orbe']);

        $resposta = $this->actingAs($usuario)
            ->getJson(route('tarefas.atualizacoes', ['assinatura' => 'qualquer']));

        $resposta->assertForbidden();
        $resposta->assertDontSee($tarefa->titulo);
    }
}
