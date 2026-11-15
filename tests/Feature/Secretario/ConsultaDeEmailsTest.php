<?php

namespace Tests\Feature\Secretario;

use App\Models\SecretarioEmail;
use App\Models\SecretarioEmailConta;
use App\Models\User;
use App\Services\Secretario\ConsultaDeEmails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Caracterização do texto que o Secretário já devolve (spec api-executiva-leitura,
 * tarefa 2.1): a extração da consulta estruturada NÃO pode mudar uma vírgula dele.
 * Sábado, 14/11/2026, 10:00.
 */
class ConsultaDeEmailsTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    private SecretarioEmailConta $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-14 10:00', 'America/Sao_Paulo'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 10:00', 'America/Sao_Paulo'));
        $this->u = User::factory()->create();
        $this->conta = SecretarioEmailConta::create(['user_id' => $this->u->id, 'rotulo' => 'VixCard', 'host' => 'h', 'porta' => 993,
            'seguranca' => 'ssl', 'usuario' => 'f@vix.local', 'senha' => 'x', 'lida_em' => now()->subMinutes(5)]);
    }

    private function email(array $o): SecretarioEmail
    {
        static $n = 0;
        $n++;

        return SecretarioEmail::create($o + ['user_id' => $this->u->id, 'conta_id' => $this->conta->id, 'pasta' => 'INBOX', 'uidvalidity' => 1,
            'uid' => $n, 'message_id' => "m{$n}", 'de_nome' => 'Cliente', 'de_email' => 'c@fora.com', 'assunto' => 'Assunto', 'recebido_em' => now()->subHour(),
            'tipo' => 'outro', 'urgencia' => 'normal', 'pede_retorno' => false, 'enviado' => false, 'respondido' => false]);
    }

    public function test_importantes_do_dia_texto(): void
    {
        $this->email(['de_nome' => 'Maria', 'assunto' => 'Contrato', 'urgencia' => 'alta', 'tipo' => 'comercial', 'recebido_em' => now()->subHours(2)]);
        $this->email(['assunto' => 'Retorno', 'pede_retorno' => true, 'recebido_em' => now()->subHour()]);
        $this->email(['assunto' => 'Banal']);
        $this->email(['assunto' => 'Meu envio', 'enviado' => true, 'urgencia' => 'alta']);
        $this->email(['assunto' => 'Ontem', 'urgencia' => 'alta', 'recebido_em' => now()->subDay()]);

        $texto = app(ConsultaDeEmails::class)->importantes($this->u, CarbonImmutable::now());

        $this->assertSame(
            "📧 E-mails importantes hoje: 2 de 3 recebido(s).\n"
            ."• 08:00 Maria — Contrato (comercial, urgente) · VixCard\n"
            ."• 09:00 Cliente — Retorno (aguardando resposta) · VixCard\n"
            .'Caixas: VixCard: lida às 09:55.',
            $texto,
        );
    }

    public function test_aguardando_texto(): void
    {
        $this->email(['assunto' => 'Pede retorno', 'pede_retorno' => true, 'recebido_em' => now()->subDays(2)]);
        $this->email(['assunto' => 'Já respondido', 'pede_retorno' => true, 'respondido' => true]);
        $this->email(['assunto' => 'Velho demais', 'pede_retorno' => true, 'recebido_em' => now()->subDays(9)]);

        $this->assertSame(
            "📨 Aguardando sua resposta (últimos 7 dias): 1.\n"
            ."• 12/11 10:00 Cliente — Pede retorno (aguardando resposta) · VixCard\n"
            .'Caixas: VixCard: lida às 09:55.',
            app(ConsultaDeEmails::class)->aguardando($this->u),
        );
    }

    public function test_aguardando_sem_nada_e_rodape_de_caixa_com_erro(): void
    {
        $this->conta->update(['situacao' => 'erro', 'lida_em' => now()->subDay()]);

        $this->assertSame(
            "📨 Aguardando sua resposta (últimos 7 dias): 0.\n"
            ."Nenhuma mensagem pedindo retorno sem resposta nos enviados.\n"
            .'Caixas: VixCard: não lida desde 13/11 10:00 ⚠.',
            app(ConsultaDeEmails::class)->aguardando($this->u),
        );
    }

    public function test_situacao_email_por_caixa_e_a_pior_delas(): void
    {
        $c = app(ConsultaDeEmails::class);
        $outro = User::factory()->create();

        $this->assertSame('nao_configurado', $c->situacaoEmail($outro)['estado']);
        $this->assertSame('ok', $c->situacaoEmail($this->u)['estado']);

        $this->conta->update(['lida_em' => now()->subMinutes(61)]);
        $this->assertSame('desatualizado', $c->situacaoEmail($this->u)['estado']);

        config(['services.executivo.email_desatualizado_min' => 120]);
        $this->assertSame('ok', $c->situacaoEmail($this->u)['estado']);

        $this->conta->update(['lida_em' => null]);
        $this->assertSame('desconectado', $c->situacaoEmail($this->u)['estado']);

        $this->conta->update(['situacao' => 'erro', 'lida_em' => now()->subMinutes(2)]);
        $s = $c->situacaoEmail($this->u);
        $this->assertSame('falha', $s['estado']);
        $this->assertSame('VixCard', $s['caixas'][0]['rotulo']);

        // Duas caixas: uma boa não esconde a ruim.
        $this->conta->update(['situacao' => 'ok', 'lida_em' => now()->subMinutes(2)]);
        SecretarioEmailConta::create(['user_id' => $this->u->id, 'rotulo' => 'Alfa', 'host' => 'h', 'porta' => 993, 'seguranca' => 'ssl',
            'usuario' => 'a@alfa.local', 'senha' => 'x', 'situacao' => 'erro', 'lida_em' => now()->subDay()]);
        $this->assertSame('falha', $c->situacaoEmail($this->u)['estado']);
    }
}
