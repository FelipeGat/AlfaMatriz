<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use Database\Seeders\QuadroDeTarefasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A massa de demonstração do quadro precisa nascer VELHA.
 *
 * Com seed novo nada envelhece, nenhuma coluna estoura o WIP e nenhuma marca
 * aparece — e o teste em staging passa a impressão de que está tudo certo
 * porque não há nada acontecendo, não porque a tela esteja funcionando. Estes
 * testes são o que impede o seeder de degenerar de volta para isso em silêncio.
 */
class QuadroSemeadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(QuadroDeTarefasSeeder::class);
    }

    /**
     * @spec:AC-204 A idade mora no evento aberto, e não no `created_at`: o chip do
     * card e as réguas de envelhecimento leem o evento. Recuar só a criação deixaria
     * uma tarefa "antiga" com zero minuto na etapa — velha na lista e nova no card.
     */
    public function test_o_quadro_semeado_nasce_envelhecido(): void
    {
        $paradas = Tarefa::with('eventos')->get()->filter(function (Tarefa $tarefa) {
            $limiar = Tarefa::HORAS_ATE_ENVELHECER[$tarefa->status] ?? null;

            if ($limiar === null) {
                return false;
            }

            $aberto = $tarefa->eventos->firstWhere('saiu_em', null);

            return $aberto && $aberto->entrou_em->diffInHours(now()) >= $limiar;
        });

        $this->assertGreaterThanOrEqual(
            3, $paradas->count(),
            'Sem cards acima do limiar, o teste visual não exercita envelhecimento nenhum.'
        );

        // E o dobro do prazo também precisa aparecer: é o segundo nível do
        // aviso, o que diz que passou da hora.
        $criticas = $paradas->filter(function (Tarefa $tarefa) {
            $limiar = Tarefa::HORAS_ATE_ENVELHECER[$tarefa->status];
            $aberto = $tarefa->eventos->firstWhere('saiu_em', null);

            return $aberto->entrou_em->diffInHours(now()) >= $limiar * 2;
        });

        $this->assertNotEmpty($criticas, 'Nenhum card chega ao dobro do prazo: o nível crítico não aparece.');
    }

    /**
     * @spec:AC-204 Alguma coluna precisa ESTOURAR o WIP — o alarme de excesso é o que
     * não aparece em quadro nenhum recém-semeado.
     */
    public function test_alguma_coluna_estoura_o_wip(): void
    {
        $estouradas = collect(Tarefa::LIMITE_DE_WIP)->filter(
            fn (int $limite, string $status) => Tarefa::where('status', $status)
                ->whereNull('bloqueado_em')
                ->count() > $limite
        );

        $this->assertNotEmpty($estouradas, 'Nenhuma coluna acima do limite: o alarme de WIP fica sem prova.');
    }

    /**
     * @spec:AC-204 As três marcas do card precisam estar na massa: travada, retorno e
     * pergunta. Cada uma tem tarja própria, e um quadro sem elas testa só o card
     * simples — que é o único que já se sabia estar certo.
     */
    public function test_as_tres_marcas_aparecem_no_quadro(): void
    {
        $this->assertNotEmpty(Tarefa::whereNotNull('bloqueado_em')->get(), 'Falta tarefa travada.');
        $this->assertNotEmpty(Tarefa::whereNotNull('retorno_de')->get(), 'Falta tarefa devolvida.');

        $comPergunta = Tarefa::whereNotNull('pergunta_em')->get();
        $this->assertNotEmpty($comPergunta, 'Falta tarefa com pergunta em aberto.');

        // A tarja lê o comentário marcado como pergunta: sem ele, o card
        // anuncia que alguém perguntou e não mostra o quê.
        foreach ($comPergunta as $tarefa) {
            $this->assertNotNull(
                $tarefa->comentarios->where('pergunta', true)->last(),
                'A pergunta em aberto precisa do comentário que a abriu.'
            );
        }

        // E o alerta de terceira rodada, que é o sinal que não existia antes.
        $this->assertNotEmpty(
            Tarefa::get()->filter->conversaEmpacada(),
            'Nenhuma conversa na terceira rodada: o selo vermelho não aparece.'
        );
    }

    /**
     * @spec:AC-204 A coluna do ar precisa nascer com os três estados dela: quem
     * espera conferência, quem já foi reprovado no ar e quem voltou de lá para a
     * fila da tag. Semeada só com o estado calmo, ela testaria a única coisa que
     * já se sabia estar certa — e o defeito que ela existe para mostrar é
     * justamente o que aparece depois que a tag subiu.
     */
    public function test_a_coluna_do_ar_nasce_com_os_estados_dela(): void
    {
        $noAr = Tarefa::where('status', 'em_producao')->with('eventos')->get();

        $this->assertGreaterThanOrEqual(3, $noAr->count(), 'A coluna do ar precisa de volume para valer.');

        // A versão é o que o card e o banner dizem estar no ar: sem ela a
        // coluna não responde a pergunta que quem vai conferir faz primeiro.
        $this->assertEmpty(
            $noAr->whereNull('versao_producao'),
            'Toda tarefa no ar tem versão: o motor a cobra na entrada.'
        );

        // E ao menos uma tem validador DIFERENTE do responsável: quem confere
        // no ar nem sempre é quem fez, e a massa precisa cobrir esse caso —
        // quando é a mesma pessoa, o card já se parece com o do staging.
        $apontadas = $noAr->filter(
            fn (Tarefa $tarefa) => $tarefa->interlocutor_id
                && $tarefa->interlocutor_id !== $tarefa->responsavel_id
        );

        $this->assertNotEmpty($apontadas, 'Sem validador apontado, o card não diz quem o quadro espera.');

        $this->assertNotEmpty(
            $noAr->filter(fn (Tarefa $tarefa) => $tarefa->testeDestaPassagem()?->aprovado === false),
            'Falta a reprovada no ar: é o estado do banner que não aparece em quadro calmo.'
        );

        $this->assertNotEmpty(
            Tarefa::where('retorno_de', 'em_producao')->get(),
            'Falta a que voltou do ar para a fila da tag: a tarja nova fica sem prova.'
        );
    }

    /**
     * @spec:AC-204 A fila de triagem também precisa existir: sem tarefa "a definir",
     * o cabeçalho da coluna Aberta e o KPI de triagem ficam sem prova.
     */
    public function test_a_fila_de_triagem_tem_o_que_triar(): void
    {
        $this->assertNotEmpty(Tarefa::where('prioridade', 'nao_definida')->get());
    }
}
