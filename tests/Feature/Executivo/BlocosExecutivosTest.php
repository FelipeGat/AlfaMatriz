<?php

namespace Tests\Feature\Executivo;

use App\Models\Notificacao;
use App\Models\Perfil;
use App\Models\SecretarioEmail;
use App\Models\SecretarioEmailConta;
use App\Models\SecretarioItem;
use App\Models\Sistema;
use App\Models\SuporteConversa;
use App\Models\User;
use App\Models\VigiaErro;
use App\Services\Secretario\ConsultaDeEmails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Os blocos da API Executiva: o que sai (lista branca), de quem (só do dono do
 * token) e o que acontece quando a fonte falha. Sábado, 14/11/2026, 10:00.
 */
class BlocosExecutivosTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-14 10:00', 'America/Sao_Paulo'));
        config(['services.executivo.conta' => 'felipe@alfa.test']);
        $this->felipe = User::factory()->create(['email' => 'felipe@alfa.test']);
        $this->felipe->perfis()->syncWithoutDetaching([Perfil::where('slug', 'admin')->value('id')]);
    }

    private function bloco(string $bloco, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withToken($this->felipe->createToken('t', ['executivo:ler'])->plainTextToken)
            ->getJson('/api/executivo/'.$bloco.$query);
    }

    private function conta(User $u, array $o = []): SecretarioEmailConta
    {
        return SecretarioEmailConta::create($o + ['user_id' => $u->id, 'rotulo' => 'VixCard', 'host' => 'h', 'porta' => 993,
            'seguranca' => 'ssl', 'usuario' => 'f@vix.local', 'senha' => 'senha-secreta', 'lida_em' => now()->subMinutes(5)]);
    }

    private function email(User $u, SecretarioEmailConta $c, array $o = []): SecretarioEmail
    {
        static $n = 0;
        $n++;

        return SecretarioEmail::create($o + ['user_id' => $u->id, 'conta_id' => $c->id, 'pasta' => 'INBOX', 'uidvalidity' => 1, 'uid' => $n,
            'message_id' => "m{$n}", 'de_nome' => 'Maria', 'de_email' => 'maria@cliente.com', 'assunto' => 'Contrato', 'recebido_em' => now()->subHour(),
            'tipo' => 'comercial', 'urgencia' => 'alta', 'pede_retorno' => true, 'enviado' => false, 'respondido' => false]);
    }

    public function test_indice_lista_os_blocos(): void
    {
        $this->bloco('')->assertOk()->assertJsonCount(8, 'dados.blocos');
    }

    public function test_bloco_inexistente_da_404(): void
    {
        $this->bloco('xyz')->assertNotFound();
    }

    public function test_todo_bloco_tem_o_envelope_completo(): void
    {
        foreach (['agenda', 'emails', 'pendencias', 'alertas', 'pedro', 'fontes'] as $b) {
            $this->bloco($b)->assertOk()
                ->assertJsonStructure(['fonte', 'periodo', 'consultado_em', 'atualizado_em', 'situacao', 'motivo', 'dados'])
                ->assertJsonPath('consultado_em', now()->toIso8601String());
        }
    }

    public function test_emails_so_metadados_sem_endereco_corpo_ou_senha(): void
    {
        $c = $this->conta($this->felipe);
        $this->email($this->felipe, $c);

        $r = $this->bloco('emails')->assertOk()->assertJsonPath('situacao', 'ok');
        $r->assertJsonPath('dados.importantes_hoje.0.remetente', null)
            ->assertJsonPath('dados.importantes_hoje.0.aguardando_resposta', true);

        $texto = $r->getContent();
        foreach (['maria@cliente.com', 'senha-secreta', 'f@vix.local', 'message_id', 'imap'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $texto);
        }
        $this->assertSame(
            ['recebido_em', 'remetente', 'assunto', 'tipo', 'urgente', 'aguardando_resposta', 'caixa'],
            array_keys($r->json('dados.importantes_hoje.0')),
        );
    }

    public function test_emails_de_outra_conta_nao_aparecem(): void
    {
        $outra = User::factory()->create();
        $this->email($outra, $this->conta($outra), ['assunto' => 'Segredo alheio']);
        $this->conta($this->felipe);

        $r = $this->bloco('emails')->assertOk();
        $this->assertStringNotContainsString('Segredo alheio', $r->getContent());
        $this->assertSame(0, $r->json('dados.recebidos_hoje'));
    }

    public function test_emails_informam_falha_e_desatualizacao_da_caixa(): void
    {
        $c = $this->conta($this->felipe);

        $c->update(['lida_em' => now()->subMinutes(90)]);
        $this->bloco('emails')->assertJsonPath('situacao', 'desatualizado');

        $c->update(['situacao' => 'erro']);
        $r = $this->bloco('emails')->assertOk()->assertJsonPath('situacao', 'falha');
        $this->assertNotEmpty($r->json('motivo'));
    }

    public function test_emails_sem_caixa_e_nao_configurado_com_dados_nulos(): void
    {
        $this->bloco('emails')->assertOk()->assertJsonPath('situacao', 'nao_configurado')->assertJsonPath('dados', null);
    }

    public function test_agenda_omite_titulo_sem_participantes_e_informa_google_ausente(): void
    {
        SecretarioItem::create(['user_id' => $this->felipe->id, 'tipo' => 'compromisso', 'titulo' => str_repeat('Reunião ', 30),
            'quando' => now()->addHours(2), 'status' => SecretarioItem::ABERTO, 'origem' => 'chat']);

        $r = $this->bloco('agenda?de=2026-11-14&ate=2026-11-14')->assertOk();
        $item = $r->json('dados.itens.0');

        $this->assertLessThanOrEqual(80, mb_strlen($item['titulo']));
        $this->assertSame(['origem', 'titulo', 'inicio', 'fim', 'dia_inteiro', 'conflito'], array_keys($item));
        $this->assertSame('2026-11-14 a 2026-11-14', $r->json('periodo'));
        // Sem Google conectado a fonte diz isso; nunca vira "ok" em silêncio.
        $this->assertContains($r->json('situacao'), ['falha', 'nao_configurado']);
        $this->assertNotEmpty($r->json('motivo'));
    }

    public function test_agenda_valida_periodo(): void
    {
        $this->bloco('agenda?de=hoje')->assertStatus(422);
        $this->bloco('agenda?de=2026-11-14&ate=2026-11-01')->assertStatus(422);
        $this->bloco('agenda?de=2026-11-01&ate=2026-12-31')->assertStatus(422);
    }

    public function test_alertas_leem_so_as_notificacoes_dele_e_nao_marcam_como_lidas(): void
    {
        $outra = User::factory()->create();
        Notificacao::create(['destinatario_id' => $this->felipe->id, 'tipo' => 'pergunta', 'nivel' => 'atencao', 'titulo' => 'Dele', 'meta' => 'x', 'rota' => '/x']);
        Notificacao::create(['destinatario_id' => $outra->id, 'tipo' => 'pergunta', 'nivel' => 'atencao', 'titulo' => 'Alheia', 'meta' => 'x', 'rota' => '/x']);

        $r = $this->bloco('alertas')->assertOk();

        $this->assertSame(['Conteúdo omitido por privacidade'], array_column($r->json('dados.notificacoes_nao_lidas'), 'titulo'));
        $this->assertSame(0, Notificacao::whereNotNull('lida_em')->count());
        $this->assertStringNotContainsString('/x', $r->getContent());
    }

    public function test_pedro_so_contagens_sem_cliente_nem_telefone(): void
    {
        SuporteConversa::create(['telefone' => '5521999998888', 'nome_perfil' => 'Cliente Sigiloso', 'situacao' => 'humano',
            'fila_desde' => now()->subMinutes(42), 'ultima_entrada_em' => now()->subMinutes(42), 'ultima_mensagem_em' => now()->subMinutes(50)]);

        $r = $this->bloco('pedro')->assertOk()->assertJsonPath('situacao', 'ok');

        $this->assertSame(1, $r->json('dados.na_fila'));
        $this->assertSame(42, $r->json('dados.fila_mais_antiga_min'));
        foreach (['5521999998888', 'Cliente Sigiloso'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $r->getContent());
        }
    }

    public function test_pendencias_e_fontes_respondem_sem_erro_numa_base_vazia(): void
    {
        $this->bloco('pendencias')->assertOk()->assertJsonPath('situacao', 'ok')->assertJsonStructure(['dados' => ['esperam_voce', 'numeros']]);
        $r = $this->bloco('fontes')->assertOk();
        $this->assertSame(['google_agenda', 'emails', 'quadro', 'pedro'], array_column($r->json('dados.fontes'), 'fonte'));
    }

    public function test_falha_de_fonte_vira_situacao_falha_sem_vazar_a_excecao(): void
    {
        $this->app->bind(ConsultaDeEmails::class, fn () => new class extends ConsultaDeEmails
        {
            public function situacaoEmail(User $user): array
            {
                throw new \RuntimeException('SELECT secreto FROM senhas');
            }
        });

        $r = $this->bloco('emails')->assertOk()->assertJsonPath('situacao', 'falha');
        $this->assertStringNotContainsString('senhas', $r->getContent());
        $this->assertStringContainsString('RuntimeException', $r->json('motivo'));
    }

    public function test_blocos_nao_aceitam_escolher_a_conta(): void
    {
        $this->bloco('emails?user_id=1')->assertStatus(422);
    }

    public function test_regressao_qa_privacidade_em_texto_livre(): void
    {
        $c = $this->conta($this->felipe, ['rotulo' => 'Rua Exemplo, 123, apartamento 45']);
        $e = $this->email($this->felipe, $c, [
            'de_nome' => 'pessoa@example.test',
            'assunto' => 'Documento 123.456.789-00 CNPJ 12.345.678/0001-90 telefone (27) 99999-1234 diagnóstico médico RG AB123456',
        ]);
        $r = $this->bloco('emails')->assertOk();
        $texto = json_encode($r->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['pessoa@example.test', '123.456.789-00', '12.345.678/0001-90', '99999-1234', 'diagnóstico', 'AB123456', 'Rua Exemplo'] as $dado) {
            $this->assertStringNotContainsString($dado, $texto);
        }
        $this->assertSame('pessoa@example.test', $e->fresh()->de_nome);
        $this->assertStringContainsString('123.456.789-00', $e->fresh()->assunto);
    }

    public function test_regressao_qa_agenda_resolve_defaults_antes_de_validar(): void
    {
        foreach (['?ate=2027-11-14', '?ate=2026-11-01', '?de=2026-11-14&ate=2026-11-29'] as $query) {
            $this->bloco('agenda'.$query)->assertStatus(422);
        }
        foreach (['', '?de=2026-11-14', '?ate=2026-11-14', '?ate=2026-11-28', '?de=2026-11-14&ate=2026-11-28'] as $query) {
            $this->bloco('agenda'.$query)->assertOk();
        }
    }

    public function test_textos_equivalentes_e_conteudo_ambiguo_sao_minimizados(): void
    {
        $c = $this->conta($this->felipe);
        $this->email($this->felipe, $c, ['de_nome' => 'Paciente Exemplo', 'assunto' => 'Resultado clínico confidencial', 'tipo' => 'Identificador AB987']);
        $r = $this->bloco('emails')->assertOk();
        $r->assertJsonPath('dados.importantes_hoje.0.remetente', null)
            ->assertJsonPath('dados.importantes_hoje.0.assunto', 'Conteúdo omitido por privacidade')
            ->assertJsonPath('dados.importantes_hoje.0.tipo', 'outro');
        $this->assertStringNotContainsString('Paciente Exemplo', $r->getContent());
        SecretarioItem::create(['user_id' => $this->felipe->id, 'tipo' => 'compromisso', 'titulo' => 'Rua Exemplo, 123 — CPF 123.456.789-00',
            'quando' => now()->addHour(), 'status' => SecretarioItem::ABERTO, 'origem' => 'chat']);
        $this->bloco('agenda')->assertJsonPath('dados.itens.0.titulo', 'Conteúdo omitido por privacidade');
        Notificacao::create(['destinatario_id' => $this->felipe->id, 'tipo' => 'pergunta', 'nivel' => 'atencao',
            'titulo' => 'Contato pessoa@example.test (27) 99999-1234', 'meta' => 'x', 'rota' => '/x']);
        $this->bloco('alertas')->assertJsonPath('dados.notificacoes_nao_lidas.0.titulo', 'Conteúdo omitido por privacidade');
    }

    /** Regressão e909: `nivel` do vigia é string livre na entrada; CPF gravado nele não pode sair. */
    public function test_alertas_so_devolvem_classificacao_de_lista_fechada(): void
    {
        $sistema = Sistema::factory()->create();
        VigiaErro::create([
            'sistema_id' => $sistema->id, 'ambiente' => 'producao', 'assinatura' => hash('sha256', 'x'), 'padrao' => 'p',
            'nivel' => '123.456.789-00', 'mensagem' => 'm', 'primeira_vez' => now()->subHour(), 'ultima_vez' => now()->subMinutes(5), 'total' => 2,
        ]);
        VigiaErro::create([
            'sistema_id' => $sistema->id, 'ambiente' => 'producao', 'assinatura' => hash('sha256', 'y'), 'padrao' => 'p',
            'nivel' => 'ERROR', 'mensagem' => 'm', 'primeira_vez' => now()->subHour(), 'ultima_vez' => now()->subMinutes(6), 'total' => 1,
        ]);
        Notificacao::create(['destinatario_id' => $this->felipe->id, 'tipo' => 'pessoa@example.test', 'nivel' => 'CPF 123.456.789-00',
            'titulo' => 't', 'meta' => 'x', 'rota' => '/x']);

        $r = $this->bloco('alertas')->assertOk();

        $this->assertStringNotContainsString('123.456.789-00', $r->getContent());
        $this->assertStringNotContainsString('pessoa@example.test', $r->getContent());
        $r->assertJsonPath('dados.erros_dos_sistemas.0.nivel', 'desconhecido')
            ->assertJsonPath('dados.erros_dos_sistemas.1.nivel', 'error')
            ->assertJsonPath('dados.notificacoes_nao_lidas.0.tipo', 'outro')
            ->assertJsonPath('dados.notificacoes_nao_lidas.0.nivel', 'desconhecido');
    }

    /** Regressão e911: `tipo` em minúsculas com `_` passava na regex; só tipo conhecido sai. */
    public function test_tipo_de_notificacao_so_sai_se_for_conhecido(): void
    {
        Notificacao::create(['destinatario_id' => $this->felipe->id, 'tipo' => 'diagnostico_hiv_positivo', 'nivel' => 'atencao',
            'titulo' => 't', 'meta' => 'x', 'rota' => '/x']);
        Notificacao::create(['destinatario_id' => $this->felipe->id, 'tipo' => 'pergunta', 'nivel' => 'atencao',
            'titulo' => 't', 'meta' => 'x', 'rota' => '/x']);

        $r = $this->bloco('alertas')->assertOk();

        $this->assertStringNotContainsString('diagnostico_hiv_positivo', $r->getContent());
        $tipos = collect($r->json('dados.notificacoes_nao_lidas'))->pluck('tipo')->sort()->values()->all();
        $this->assertSame(['outro', 'pergunta'], $tipos);
    }
}
