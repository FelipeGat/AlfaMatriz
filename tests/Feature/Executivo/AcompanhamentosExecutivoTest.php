<?php

namespace Tests\Feature\Executivo;

use App\Models\Notificacao;
use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioEmail;
use App\Models\SecretarioEmailConta;
use App\Models\SecretarioItem;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\Executivo\Acompanhamentos;
use App\Services\Executivo\Envelope;
use App\Services\Executivo\ItemExecutivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Acompanhamentos na API Executiva (spec `api-executiva-acompanhamentos`, Fase 1).
 * Dados fictícios. Sábado, 14/11/2026, 10:00.
 */
class AcompanhamentosExecutivoTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-14 10:00', 'America/Sao_Paulo'));
        config(['services.executivo.conta' => 'felipe@alfa.test']);
        $this->felipe = User::factory()->create(['email' => 'felipe@alfa.test', 'name' => 'Felipe Gat']);
        $this->felipe->perfis()->syncWithoutDetaching([Perfil::where('slug', 'admin')->value('id')]);
    }

    private function secao(string $secao, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withToken($this->felipe->createToken('t', ['executivo:ler'])->plainTextToken)
            ->getJson('/api/executivo/acompanhamentos?secao='.$secao.$query);
    }

    private function perguntaPara(User $quem, array $o = []): Tarefa
    {
        $t = Tarefa::factory()->create(['status' => 'em_desenvolvimento'] + $o);
        $t->forceFill(['pergunta_em' => now()->subHours(2), 'pergunta_para_id' => $quem->id, 'pergunta_de_id' => $this->felipe->id])->save();

        return $t;
    }

    public function test_pergunta_vira_item_comum_sem_texto_livre(): void
    {
        $rossini = User::factory()->create(['name' => 'Rossini Santos']);
        $t = $this->perguntaPara($this->felipe, ['responsavel_id' => $rossini->id, 'prioridade' => 'critica', 'titulo' => 'Rua das Flores, 25 CPF 123.456.789-00']);

        $r = $this->secao('perguntas')->assertOk();
        $item = $r->json('dados.itens.0');

        $this->assertSame('matriz:tarefa:'.$t->id.':pergunta', $item['id']);
        $this->assertSame('pergunta', $item['tipo']);
        $this->assertSame('felipe', $item['ator_esperado']);
        $this->assertSame('Rossini', $item['responsavel']);
        $this->assertSame('aguardando_resposta', $item['situacao']);
        $this->assertSame('alta', $item['prioridade']);
        $this->assertSame('/tarefas?tarefa='.$t->id, $item['link']);
        $this->assertTrue($item['aberta']);
        $this->assertNull($item['cliente_empresa']);
        $this->assertStringNotContainsString('Rua das Flores', $r->getContent());
        $this->assertStringNotContainsString('123.456', $r->getContent());
    }

    public function test_prioridade_a_definir_vira_null_e_nome_de_conta_inativa_nao_sai(): void
    {
        $inativo = User::factory()->create(['name' => 'Fulano Antigo', 'ativo' => false]);
        $this->perguntaPara($this->felipe, ['responsavel_id' => $inativo->id, 'prioridade' => 'nao_definida']);

        $item = $this->secao('perguntas')->json('dados.itens.0');

        $this->assertNull($item['prioridade']);
        $this->assertNull($item['responsavel']);
    }

    public function test_total_e_do_conjunto_completo_e_a_pagina_nao_repete(): void
    {
        foreach (range(1, 5) as $i) {
            $this->perguntaPara($this->felipe);
        }

        $p1 = $this->secao('perguntas', '&limite=2&pagina=1')->assertOk();
        $p3 = $this->secao('perguntas', '&limite=2&pagina=3')->assertOk();
        $p2 = $this->secao('perguntas', '&limite=2&pagina=2');

        $this->assertSame(5, $p1->json('dados.total'));
        $this->assertSame(2, $p1->json('dados.retornados'));
        $this->assertTrue($p1->json('dados.truncado'));
        $this->assertSame(1, $p3->json('dados.retornados'));
        $this->assertFalse($p3->json('dados.truncado'));

        $ids = array_merge(...array_map(fn ($r) => array_column($r->json('dados.itens'), 'id'), [$p1, $p2, $p3]));
        $this->assertCount(5, array_unique($ids));
    }

    public function test_ordem_estavel_prazo_depois_prioridade_e_nulo_por_ultimo(): void
    {
        $semPrazo = SecretarioItem::create(['user_id' => $this->felipe->id, 'origem' => 'chat', 'tipo' => 'tarefa', 'titulo' => 'a', 'empresa' => 'invest', 'prioridade' => 'alta']);
        $tarde = SecretarioItem::create(['user_id' => $this->felipe->id, 'origem' => 'chat', 'tipo' => 'tarefa', 'titulo' => 'b', 'empresa' => 'invest', 'prioridade' => 'alta', 'quando' => '2026-11-20 09:00', 'so_data' => true]);
        $cedo = SecretarioItem::create(['user_id' => $this->felipe->id, 'origem' => 'chat', 'tipo' => 'tarefa', 'titulo' => 'c', 'empresa' => 'invest', 'prioridade' => 'baixa', 'quando' => '2026-11-16 09:00', 'so_data' => true]);

        $ids = array_column($this->secao('pontos')->json('dados.itens'), 'id');

        $this->assertSame(['matriz:ponto:'.$cedo->id, 'matriz:ponto:'.$tarde->id, 'matriz:ponto:'.$semPrazo->id], $ids);
    }

    public function test_pontos_so_da_conta_e_empresa_de_lista_fechada(): void
    {
        $outro = User::factory()->create();
        SecretarioItem::create(['user_id' => $outro->id, 'origem' => 'chat', 'tipo' => 'tarefa', 'titulo' => 'alheio', 'empresa' => 'invest']);
        SecretarioItem::create(['user_id' => $this->felipe->id, 'origem' => 'chat', 'tipo' => 'tarefa', 'titulo' => 'meu', 'empresa' => 'invest']);
        SecretarioItem::create(['user_id' => $this->felipe->id, 'origem' => 'chat', 'tipo' => 'tarefa', 'titulo' => 'x', 'empresa' => 'cliente_secreto_ltda']);

        $r = $this->secao('pontos');

        $this->assertSame(2, $r->json('dados.total'));
        $empresas = array_column($r->json('dados.itens'), 'cliente_empresa');
        $this->assertContains('Invest', $empresas);
        $this->assertNotContains('cliente_secreto_ltda', $empresas);
        $this->assertStringNotContainsString('alheio', $r->getContent());
    }

    public function test_notificacao_e_pendencia_voltam_as_duas_e_ligadas(): void
    {
        $t = $this->perguntaPara($this->felipe);
        $aviso = Notificacao::create(['destinatario_id' => $this->felipe->id, 'tipo' => 'pergunta', 'nivel' => 'atencao', 'icone' => 'duvida',
            'titulo' => 'Título livre com a@b.com', 'meta' => 'x', 'rota' => '/x', 'tarefa_id' => $t->id]);

        $pergunta = $this->secao('perguntas')->json('dados.itens.0');
        $notificacoes = $this->secao('notificacoes');
        $notificacao = $notificacoes->json('dados.itens.0');

        $this->assertSame('matriz:notificacao:'.$aviso->id, $pergunta['notificacao_relacionada']);
        $this->assertSame($pergunta['id'], $notificacao['possivel_duplicata_de']);
        $this->assertSame(1, $notificacoes->json('dados.total'));
        $this->assertStringNotContainsString('a@b.com', $notificacoes->getContent());
    }

    public function test_aviso_tratado_e_historico_e_o_de_outra_conta_nao_aparece(): void
    {
        $outro = User::factory()->create();
        $base = ['tipo' => 'comentario', 'nivel' => 'marca', 'icone' => 'x', 'titulo' => 't', 'meta' => 'm', 'rota' => '/x'];
        Notificacao::create($base + ['destinatario_id' => $this->felipe->id])->forceFill(['lida_em' => now()->subDay()])->save();
        Notificacao::create($base + ['destinatario_id' => $this->felipe->id])->forceFill(['lida_em' => now()->subDays(30)])->save();
        Notificacao::create($base + ['destinatario_id' => $outro->id]);

        $itens = $this->secao('notificacoes')->json('dados.itens');

        $this->assertCount(1, $itens);
        $this->assertFalse($itens[0]['aberta']);
        $this->assertTrue($itens[0]['historica']);
        $this->assertSame('concluida', $itens[0]['situacao']);
    }

    public function test_alertas_abertos_so_da_conta(): void
    {
        $outro = User::factory()->create();
        SecretarioAcompanhamento::create(['user_id' => $this->felipe->id, 'regra' => 'tarefa_vencida', 'chave' => 'k1', 'prioridade' => 'alta', 'texto' => 'Texto com 12.345.678/0001-90', 'link' => 'https://x.test/y', 'situacao' => 'ativo']);
        SecretarioAcompanhamento::create(['user_id' => $outro->id, 'regra' => 'tarefa_vencida', 'chave' => 'k2', 'prioridade' => 'alta', 'texto' => 't', 'situacao' => 'ativo']);

        $r = $this->secao('alertas');

        $this->assertSame(1, $r->json('dados.total'));
        $this->assertStringNotContainsString('12.345', $r->getContent());
        $this->assertStringNotContainsString('x.test', $r->getContent());
        $this->assertSame('/secretario/acompanhamentos', $r->json('dados.itens.0.link'));
    }

    public function test_sem_secao_devolve_totais_e_secao_invalida_da_422(): void
    {
        $this->perguntaPara($this->felipe);
        app('auth')->forgetGuards();
        $r = $this->withToken($this->felipe->createToken('t', ['executivo:ler'])->plainTextToken)->getJson('/api/executivo/acompanhamentos')->assertOk();

        $this->assertSame(1, $r->json('dados.secoes.perguntas'));
        $this->assertNotEmpty($r->json('dados.cobertura'));

        $this->secao('inexistente')->assertStatus(422);
        $this->secao('perguntas', '&limite=1000')->assertStatus(422);
    }

    public function test_so_leitura_e_sem_user_id(): void
    {
        app('auth')->forgetGuards();
        $token = $this->felipe->createToken('t', ['executivo:ler'])->plainTextToken;

        $this->withToken($token)->postJson('/api/executivo/acompanhamentos', [])->assertStatus(405);
        $this->withToken($token)->getJson('/api/executivo/acompanhamentos?user_id=2')->assertStatus(422);
    }

    public function test_email_sai_como_classe_indefinida_sem_remetente_nem_assunto(): void
    {
        $outro = User::factory()->create();
        $base = ['pasta' => 'INBOX', 'uidvalidity' => 1, 'message_id' => 'x', 'tipo' => 'normal', 'urgencia' => 'normal', 'pede_retorno' => true, 'recebido_em' => now()->subDay(),
            'de_nome' => 'Rua das Flores, 25', 'de_email' => 'fornecedor@promo.test', 'assunto' => 'Oferta 123.456.789-00'];
        $conta = fn (User $u) => SecretarioEmailConta::create(['user_id' => $u->id, 'rotulo' => 'Caixa', 'host' => 'h', 'porta' => 993, 'seguranca' => 'ssl',
            'usuario' => 'u', 'senha' => 's', 'pasta_entrada' => 'INBOX', 'pasta_enviados' => 'Sent', 'situacao' => 'ok'])->id;
        SecretarioEmail::create($base + ['user_id' => $this->felipe->id, 'conta_id' => $conta($this->felipe), 'uid' => 1]);
        SecretarioEmail::create($base + ['user_id' => $outro->id, 'conta_id' => $conta($outro), 'uid' => 2]);

        $r = $this->secao('emails')->assertOk();

        $this->assertSame(1, $r->json('dados.total'));
        $this->assertSame('indefinida', $r->json('dados.itens.0.classe'));
        $this->assertSame('indefinido', $r->json('dados.itens.0.ator_esperado'));
        foreach (['Rua das Flores', 'promo.test', '123.456', 'Oferta'] as $texto) {
            $this->assertStringNotContainsString($texto, $r->getContent());
        }
    }

    public function test_atividade_traz_so_tipo_e_hora_da_conta(): void
    {
        $outro = User::factory()->create();
        SecretarioItem::create(['user_id' => $this->felipe->id, 'origem' => 'chat', 'tipo' => 'tarefa', 'titulo' => 'Ligar para 12.345.678/0001-90', 'empresa' => 'invest', 'status' => SecretarioItem::ABERTO]);
        SecretarioItem::create(['user_id' => $outro->id, 'origem' => 'chat', 'tipo' => 'tarefa', 'titulo' => 'alheio', 'empresa' => 'invest', 'status' => SecretarioItem::ABERTO]);

        $r = $this->secao('atividade')->assertOk();

        $this->assertSame(1, $r->json('dados.total'));
        $this->assertStringStartsWith('matriz:atividade:item:', $r->json('dados.itens.0.id'));
        $this->assertFalse($r->json('dados.itens.0.aberta'));
        $this->assertStringNotContainsString('12.345', $r->getContent());
    }

    public function test_nenhum_campo_de_texto_livre_sai_em_secao_alguma(): void
    {
        $this->perguntaPara($this->felipe, ['titulo' => 'Segredo-TITULO-77']);
        SecretarioItem::create(['user_id' => $this->felipe->id, 'origem' => 'chat', 'tipo' => 'tarefa', 'titulo' => 'Segredo-PONTO-77', 'empresa' => 'invest', 'status' => SecretarioItem::ABERTO]);
        SecretarioAcompanhamento::create(['user_id' => $this->felipe->id, 'regra' => 'r', 'chave' => 'k', 'prioridade' => 'alta', 'texto' => 'Segredo-ALERTA-77', 'situacao' => 'ativo']);
        Notificacao::create(['tipo' => 'pergunta', 'nivel' => 'atencao', 'icone' => 'x', 'titulo' => 'Segredo-AVISO-77', 'meta' => 'Segredo-META-77', 'rota' => '/x', 'destinatario_id' => $this->felipe->id]);

        foreach (Acompanhamentos::SECOES as $secao) {
            $this->assertStringNotContainsString('Segredo-', $this->secao($secao)->assertOk()->getContent(), $secao);
        }
    }

    public function test_varios_avisos_da_mesma_tarefa_ligam_so_o_do_tipo_e_preferem_o_aberto(): void
    {
        $t = $this->perguntaPara($this->felipe);
        $base = ['destinatario_id' => $this->felipe->id, 'nivel' => 'atencao', 'icone' => 'x', 'titulo' => 't', 'meta' => 'm', 'rota' => '/x', 'tarefa_id' => $t->id];
        // O aberto nasce ANTES e o já tratado depois: só a preferência pelo aberto (e não "o mais novo") liga o aberto.
        $aberto = Notificacao::create($base + ['tipo' => 'pergunta']);
        $lido = Notificacao::create($base + ['tipo' => 'pergunta']);
        $lido->forceFill(['lida_em' => now()->subDay()])->save();
        $this->assertNotNull($lido->fresh()->lida_em);
        $this->assertNull($aberto->fresh()->lida_em);
        $this->assertGreaterThan($aberto->id, $lido->id);
        $outroTipo = Notificacao::create($base + ['tipo' => 'comentario']);

        $pergunta = $this->secao('perguntas')->json('dados.itens.0');
        $this->assertSame('matriz:notificacao:'.$aberto->id, $pergunta['notificacao_relacionada']);

        $porId = collect($this->secao('notificacoes')->json('dados.itens'))->keyBy('id');
        $this->assertSame($pergunta['id'], $porId['matriz:notificacao:'.$aberto->id]['possivel_duplicata_de']);
        $this->assertSame($pergunta['id'], $porId['matriz:notificacao:'.$lido->id]['possivel_duplicata_de']);
        // Comentário na mesma tarefa não é a obrigação da pergunta; e o histórico continua listado.
        $this->assertNull($porId['matriz:notificacao:'.$outroTipo->id]['possivel_duplicata_de']);
        $this->assertCount(3, $porId);
    }

    public function test_aviso_de_tipo_sem_pendencia_correspondente_nao_liga(): void
    {
        $t = $this->perguntaPara($this->felipe);
        Notificacao::create(['destinatario_id' => $this->felipe->id, 'tipo' => 'retorno', 'nivel' => 'atencao', 'icone' => 'x', 'titulo' => 't', 'meta' => 'm', 'rota' => '/x', 'tarefa_id' => $t->id]);

        $this->assertNull($this->secao('perguntas')->json('dados.itens.0.notificacao_relacionada'));
        $this->assertNull($this->secao('notificacoes')->json('dados.itens.0.possivel_duplicata_de'));
    }

    public function test_sem_permissao_nenhuma_secao_devolve_itens_e_o_total_nao_vira_zero(): void
    {
        $sem = User::factory()->semPerfil()->create();
        $exibicao = User::factory()->create();
        $exibicao->perfis()->sync([Perfil::where('slug', 'exibicao')->value('id')]);
        $this->perguntaPara($this->felipe);
        Notificacao::create(['destinatario_id' => $sem->id, 'tipo' => 'pergunta', 'nivel' => 'atencao', 'icone' => 'x', 'titulo' => 't', 'meta' => 'm', 'rota' => '/x']);

        foreach ([$sem, $exibicao] as $quem) {
            $geral = app(Acompanhamentos::class)->montar($quem, null);
            $this->assertSame('ok', $geral['situacao']);
            $this->assertSame(Acompanhamentos::SECOES, $geral['dados']['sem_acesso']);
            $this->assertNotContains(0, $geral['dados']['secoes']);

            foreach (Acompanhamentos::SECOES as $secao) {
                $r = app(Acompanhamentos::class)->montar($quem, $secao);
                $this->assertSame('nao_configurado', $r['situacao'], $secao);
                $this->assertSame([], $r['dados']['itens'], $secao);
                $this->assertNull($r['dados']['total'], $secao);
            }
        }
    }

    public function test_falha_de_fonte_devolve_dados_null_e_o_openapi_declara_isso(): void
    {
        $r = Envelope::proteger('matriz.teste', null, fn () => throw new RuntimeException('segredo-SQL'));

        $this->assertSame('falha', $r['situacao']);
        $this->assertNull($r['dados']);
        $this->assertStringNotContainsString('segredo-SQL', json_encode($r));

        $yaml = file_get_contents(base_path('docs/specs/api-executiva-leitura/openapi.yaml'));
        $bloco = substr($yaml, strpos($yaml, 'AcompanhamentosEnvelope:'));
        $this->assertStringContainsString('- type: "null"', $bloco);
    }

    /** Dá à conta do token só os recursos pedidos (`recurso:ler`), no lugar do perfil admin. */
    private function soPermissoes(array $recursos): void
    {
        $perfil = Perfil::create(['slug' => 'parcial_'.implode('_', $recursos ?: ['nada']), 'nome' => 'Parcial']);
        foreach ($recursos as $recurso) {
            $perm = Permissao::firstOrCreate(['recurso' => $recurso], ['descricao' => $recurso]);
            $perfil->permissoes()->attach($perm->id, ['ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => false, 'excluir' => false]);
        }
        $this->felipe->perfis()->sync([$perfil->id]);
        $this->felipe->esquecerPermissoes();
    }

    public function test_http_sem_permissao_recusa_cada_secao_com_total_nulo(): void
    {
        $this->perguntaPara($this->felipe);
        $this->soPermissoes([]);

        foreach (Acompanhamentos::SECOES as $secao) {
            $r = $this->secao($secao)->assertOk();
            $this->assertSame('nao_configurado', $r->json('situacao'), $secao);
            $this->assertNull($r->json('dados.total'), $secao);
            $this->assertSame([], $r->json('dados.itens'), $secao);
        }
    }

    public function test_http_permissao_parcial_so_libera_as_secoes_da_sua_porta(): void
    {
        $this->perguntaPara($this->felipe);
        SecretarioItem::create(['user_id' => $this->felipe->id, 'origem' => 'chat', 'tipo' => 'tarefa', 'titulo' => 'x', 'empresa' => 'invest', 'status' => SecretarioItem::ABERTO]);

        // Só o quadro: decisões e perguntas abrem; pontos e demais não.
        $this->soPermissoes(['tarefas']);
        $this->assertSame('ok', $this->secao('perguntas')->json('situacao'));
        $this->assertSame(1, $this->secao('perguntas')->json('dados.total'));
        $this->assertSame('nao_configurado', $this->secao('pontos')->json('situacao'));
        $this->assertNull($this->secao('pontos')->json('dados.total'));

        // Só o Secretário: o inverso.
        $this->soPermissoes(['secretario']);
        $this->assertSame('nao_configurado', $this->secao('perguntas')->json('situacao'));
        $this->assertNull($this->secao('perguntas')->json('dados.total'));
        $this->assertSame(1, $this->secao('pontos')->json('dados.total'));
    }

    public function test_conta_com_permissao_nao_recebe_sem_acesso(): void
    {
        $geral = app(Acompanhamentos::class)->montar($this->felipe, null);

        $this->assertSame([], $geral['dados']['sem_acesso']);
        $this->assertNull($geral['motivo']);
    }

    public function test_secao_desconhecida_no_servico_e_422_e_nao_falha_de_fonte(): void
    {
        $this->expectException(ValidationException::class);

        app(Acompanhamentos::class)->montar($this->felipe, 'inexistente');
    }

    public function test_item_rejeita_valor_fora_do_contrato(): void
    {
        $itens = new ItemExecutivo;
        $item = $itens->montar([
            'id' => 'matriz:tarefa:5:pergunta', 'aberta' => true,
            'classe' => 'cliente_do_joao', 'cliente_empresa' => 'Condomínio Rua das Flores, 25',
            'link' => 'https://evil.test/x', 'possivel_duplicata_de' => 'texto livre 123.456.789-00',
            'notificacao_relacionada' => 'matriz:notificacao:abc',
        ]);

        $this->assertNull($item['classe']);
        $this->assertNull($item['cliente_empresa']);
        $this->assertNull($item['link']);
        $this->assertNull($item['possivel_duplicata_de']);
        $this->assertNull($item['notificacao_relacionada']);

        foreach (['//evil.test/x', 'javascript:alert(1)', '/a b', "/x\n/y", 'tarefas'] as $link) {
            $this->assertNull($itens->montar(['id' => 'matriz:ponto:1', 'link' => $link])['link'], $link);
        }

        $ok = $itens->montar(['id' => 'matriz:email:9', 'classe' => 'indefinida', 'cliente_empresa' => 'Invest',
            'link' => '/tarefas?tarefa=12', 'notificacao_relacionada' => 'matriz:notificacao:3']);
        $this->assertSame(['indefinida', 'Invest', '/tarefas?tarefa=12', 'matriz:notificacao:3'],
            [$ok['classe'], $ok['cliente_empresa'], $ok['link'], $ok['notificacao_relacionada']]);
    }

    public function test_item_com_id_fora_do_contrato_e_erro_e_nao_publica_identificador_inventado(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ItemExecutivo)->montar(['id' => 'Segredo livre']);
    }

    public function test_avisos_sao_lidos_uma_vez_por_requisicao(): void
    {
        $this->perguntaPara($this->felipe);
        \DB::enableQueryLog();
        app(Acompanhamentos::class)->montar($this->felipe, null);
        $consultas = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "notificacoes"') || str_contains($q['query'], 'from `notificacoes`'));

        $this->assertCount(1, $consultas);
    }
}
