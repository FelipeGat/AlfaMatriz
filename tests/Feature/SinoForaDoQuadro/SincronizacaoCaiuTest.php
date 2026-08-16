<?php

namespace Tests\Feature\SinoForaDoQuadro;

use App\Models\Notificacao;
use App\Models\Sistema;
use App\Models\User;
use App\Services\SincronizadorSistemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A queda de sincronização deixa marca e faz barulho UMA vez (US-095). O ciclo
 * é horário: sem a marca, um sistema fora do ar era 24 falhas idênticas num
 * stdout que ninguém lê — e nenhuma linha no banco.
 */
class SincronizacaoCaiuTest extends TestCase
{
    use RefreshDatabase;

    private function sistemaConfigurado(): Sistema
    {
        return Sistema::factory()->alfagym()->create([
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-de-teste',
            'ativo' => true,
        ]);
    }

    /** @return array<string, mixed> uma página vazia válida do contrato */
    private function paginaVazia(): array
    {
        return [
            'contrato' => '1.0',
            'sistema' => 'alfagym',
            'pagina' => ['numero' => 1, 'tamanho' => 200, 'total_itens' => 0, 'total_paginas' => 1],
            'dados' => [],
        ];
    }

    /**
     * @spec:AC-340 A primeira falha depois de um sucesso marca a queda e avisa
     * os admins; a segunda falha seguida NÃO avisa de novo — senão o aviso
     * vira condição disfarçada, uma linha por hora.
     */
    public function test_primeira_falha_marca_e_avisa_uma_vez(): void
    {
        $adminA = User::factory()->create();
        $adminB = User::factory()->create();
        $membro = User::factory()->membro()->create();

        $sistema = $this->sistemaConfigurado();

        Http::fake(['*gym.alfasolucoes.cloud/*' => Http::response([], 500)]);

        $resultado = (new SincronizadorSistemaService($sistema))->sincronizar();

        $this->assertFalse($resultado['ok']);
        $this->assertNotNull($sistema->fresh()->sincronizacao_caiu_em);

        $avisos = Notificacao::where('tipo', 'sincronizacao')->get();

        $this->assertEqualsCanonicalizing(
            [$adminA->id, $adminB->id],
            $avisos->pluck('destinatario_id')->all(),
        );
        $this->assertSame('critico', $avisos->first()->nivel);
        $this->assertStringContainsString('AlfaGym parou de sincronizar', $avisos->first()->titulo);
        $this->assertSame(0, Notificacao::where('destinatario_id', $membro->id)->count());

        // O ciclo seguinte continua falhando: nada de aviso novo.
        (new SincronizadorSistemaService($sistema->fresh()))->sincronizar();

        $this->assertSame(2, Notificacao::where('tipo', 'sincronizacao')->count());
    }

    /**
     * @spec:AC-341 O primeiro ciclo bom depois da queda apaga a marca e avisa
     * que voltou — o incidente fecha no sino, não na memória de quem viu cair.
     */
    public function test_sucesso_depois_da_queda_fecha_o_incidente(): void
    {
        $admin = User::factory()->create();

        $sistema = $this->sistemaConfigurado();

        // A marca de queda já existe (o ciclo anterior falhou e avisou).
        $sistema->forceFill([
            'sincronizacao_caiu_em' => now()->subHours(3),
            'sincronizacao_motivo' => 'AlfaGym não respondeu (timeout ou fora do ar).',
        ])->save();

        Http::fake(['*gym.alfasolucoes.cloud/*' => Http::response($this->paginaVazia())]);

        $resultado = (new SincronizadorSistemaService($sistema))->sincronizar();

        $this->assertTrue($resultado['ok']);
        $this->assertNull($sistema->fresh()->sincronizacao_caiu_em);

        $aviso = Notificacao::where('tipo', 'sincronizacao')->sole();

        $this->assertSame($admin->id, $aviso->destinatario_id);
        $this->assertSame('marca', $aviso->nivel);
        $this->assertStringContainsString('voltou a sincronizar', $aviso->titulo);
        $this->assertStringContainsString('Fora do ciclo desde', $aviso->meta);
    }

    /**
     * @spec:AC-341 Sistema sem endereço ou chave nunca ganha marca de queda: é o
     * estado normal entre publicar a integração e configurá-la, não uma queda.
     */
    public function test_sistema_sem_configuracao_nao_marca_queda(): void
    {
        User::factory()->create();

        $sistema = Sistema::factory()->alfagym()->create(['token' => null, 'ativo' => true]);

        $resultado = (new SincronizadorSistemaService($sistema))->sincronizar();

        $this->assertFalse($resultado['ok']);
        $this->assertNull($sistema->fresh()->sincronizacao_caiu_em);
        $this->assertSame(0, Notificacao::count());
    }
}
