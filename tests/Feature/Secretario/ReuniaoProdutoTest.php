<?php

namespace Tests\Feature\Secretario;

use App\Models\Notificacao;
use App\Models\Perfil;
use App\Models\SecretarioReuniao;
use App\Models\SecretarioReuniaoProposta;
use App\Models\SecretarioReuniaoTrecho;
use App\Models\Sistema;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Reunião vinculada ao produto e compartilhada com quem participou (docs/specs/secretario-reuniao-produto). */
class ReuniaoProdutoTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    private User $felipe;
    private User $victor;
    private User $rossini;
    private Sistema $vixId;
    private Sistema $gym;
    private SecretarioReuniao $r;

    protected function setUp(): void
    {
        parent::setUp();
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = $this->dono(telegram: false);
        $this->felipe->update(['name' => 'Felipe Henrique']);
        $this->rossini = $this->admin();
        $this->rossini->update(['name' => 'Rossini']);
        $this->victor = User::factory()->semPerfil()->create(['name' => 'Victor VixCard']);
        $this->victor->perfis()->attach(Perfil::where('slug', 'vixcard')->value('id'));
        $this->vixId = Sistema::factory()->create(['nome' => 'Vix-ID', 'slug' => 'vix-id', 'natureza' => 'vixcard', 'categoria' => null, 'unidade_cobranca' => null]);
        $this->gym = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym', 'natureza' => 'produto']);
        $this->r = SecretarioReuniao::create(['user_id' => $this->felipe->id, 'titulo' => 'RFID para a confecção', 'origem' => 'gravacao', 'mime' => 'audio/webm',
            'tamanho' => 10, 'sha256' => str_repeat('a', 64), 'status' => SecretarioReuniao::PRONTA, 'resumo' => 'Apresentação do controle por RFID.',
            'participantes' => ['Felipe', 'Victor', 'Paulo (cliente)'], 'proximos_passos' => ['Enviar proposta'], 'contexto' => ['cliente' => 'Confecção Minas']]);
        SecretarioReuniaoTrecho::create(['reuniao_id' => $this->r->id, 'ordem' => 1, 'inicio' => 0, 'fim' => 5, 'texto' => 'conversa reservada da transcrição']);
        foreach ([['decisao', 'Fazer piloto com 200 etiquetas', 'pendente'], ['tarefa', 'Montar a proposta comercial', 'aprovada'], ['tarefa', 'Rascunho que o dono não revisou', 'pendente']] as [$cat, $texto, $st]) {
            SecretarioReuniaoProposta::create(['reuniao_id' => $this->r->id, 'user_id' => $this->felipe->id, 'categoria' => $cat, 'texto' => $texto, 'status' => $st, 'classificacao' => 'compromisso', 'evidencias' => [1]]);
        }
    }

    public function test_so_o_dono_vincula_o_produto(): void
    {
        // Desde 31/10/2026 só produto da Alfa: o produto VixCard saiu da lista.
        $this->actingAs($this->felipe)->patch(route('secretario.reunioes.produto', $this->r->id), ['sistema_id' => $this->gym->id])->assertRedirect();
        $this->assertSame($this->gym->id, $this->r->fresh()->sistema_id);
        $this->actingAs($this->felipe)->get(route('secretario.reunioes.index'))->assertOk()->assertSee('Produto')->assertSee('AlfaGym')->assertDontSee('Vix-ID');

        $this->actingAs($this->rossini)->patch(route('secretario.reunioes.produto', $this->r->id), ['sistema_id' => $this->gym->id])->assertNotFound();
        $this->actingAs($this->victor)->patch(route('secretario.reunioes.produto', $this->r->id), ['sistema_id' => $this->gym->id])->assertForbidden();
        $this->assertSame($this->gym->id, $this->r->fresh()->sistema_id);
    }

    public function test_compartilha_com_participante_que_ve_o_retrato_sem_a_transcricao(): void
    {
        $this->actingAs($this->felipe)->get(route('secretario.reunioes.mostrar', $this->r->id))->assertOk()
            ->assertSee('Só você vê esta reunião')->assertSee('citado na reunião');
        $this->assertSame([$this->victor->id], $this->r->sugestaoDeParticipantes(), 'o cliente não tem conta; o Victor é sugerido');

        $this->actingAs($this->victor)->get(route('reunioes.compartilhada', $this->r->id))->assertNotFound();
        $this->actingAs($this->felipe)->put(route('secretario.reunioes.participantes', $this->r->id), ['pessoas' => [$this->victor->id, $this->felipe->id]])
            ->assertSessionHas('status', 'Compartilhada com 1 pessoa(s).');
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->victor->id)->count());

        $this->actingAs($this->victor)->get(route('reunioes.compartilhada', $this->r->id))->assertOk()
            ->assertSee('Apresentação do controle por RFID')->assertSee('Fazer piloto com 200 etiquetas')->assertSee('Montar a proposta comercial')
            ->assertSee('Enviar proposta')->assertSee('Confecção Minas')
            ->assertDontSee('conversa reservada da transcrição')->assertDontSee('Rascunho que o dono não revisou');
        $this->actingAs($this->victor)->get(route('secretario.reunioes.mostrar', $this->r->id))->assertForbidden();

        // Admin não vê sem estar marcado (FR-002).
        $this->actingAs($this->rossini)->get(route('reunioes.compartilhada', $this->r->id))->assertNotFound();

        // Salvar de novo não avisa de novo; desmarcar tira o acesso na hora (FR-003).
        $this->actingAs($this->felipe)->put(route('secretario.reunioes.participantes', $this->r->id), ['pessoas' => [$this->victor->id]]);
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->victor->id)->count());
        $this->actingAs($this->felipe)->put(route('secretario.reunioes.participantes', $this->r->id), ['pessoas' => []]);
        $this->actingAs($this->victor)->get(route('reunioes.compartilhada', $this->r->id))->assertNotFound();
    }

    public function test_aparece_no_painel_vixcard_so_se_o_produto_for_vixcard(): void
    {
        $this->markTestSkipped('A pasta Projetos VixCard foi desligada em 31/10/2026 (o Matriz ficou só com a Alfa e o Grupo Soluções).');
        $this->r->compartilhadaCom()->attach($this->victor->id);
        $this->r->update(['sistema_id' => $this->gym->id]);
        $this->actingAs($this->victor)->get(route('vixcard.projetos'))->assertOk()->assertDontSee('RFID para a confecção');

        $this->r->update(['sistema_id' => $this->vixId->id]);
        $this->actingAs($this->victor)->get(route('vixcard.projetos'))->assertOk()->assertSee('Reuniões dos projetos')->assertSee('RFID para a confecção');
        $this->actingAs($this->victor)->get(route('reunioes.compartilhadas'))->assertOk()->assertSee('RFID para a confecção')->assertSee('Felipe Henrique');
    }
}
