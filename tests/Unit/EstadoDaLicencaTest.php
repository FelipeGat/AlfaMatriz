<?php

namespace Tests\Unit;

use App\Models\ClienteSistema;
use Tests\TestCase;

/**
 * A tabela-verdade do estado da licença.
 *
 * Estava escrita duas vezes na Blade e as duas já divergiam. Aqui ela é testada
 * sem banco e sem HTTP, que é o que permite cobrir todas as combinações sem
 * montar um cenário para cada uma.
 */
class EstadoDaLicencaTest extends TestCase
{
    private function vinculo(array $atributos): ClienteSistema
    {
        $pivot = new ClienteSistema;
        $pivot->forceFill($atributos);

        return $pivot;
    }

    public function test_sem_vinculo_nem_licenca_nao_tem_estado(): void
    {
        $vinculo = $this->vinculo(['status_saas' => null, 'licenca_id_externo' => null]);

        $this->assertSame('sem_licenca', $vinculo->estado());
        $this->assertSame('neutro', $vinculo->tom());
        $this->assertFalse($vinculo->temLicenca());
    }

    public function test_pendente_aguarda_liberacao(): void
    {
        $vinculo = $this->vinculo(['status_saas' => 'pendente']);

        $this->assertSame('pendente', $vinculo->estado());
        $this->assertTrue($vinculo->pendente());
        $this->assertSame('atencao', $vinculo->tom());
    }

    public function test_licenca_com_folga_esta_ativa(): void
    {
        $vinculo = $this->vinculo([
            'status_saas' => 'ativo',
            'licenca_id_externo' => '91',
            'licenca_fim_em' => now()->addMonths(3)->toDateString(),
        ]);

        $this->assertSame('ativa', $vinculo->estado());
        $this->assertSame('bom', $vinculo->tom());
        $this->assertTrue($vinculo->temLicenca());
    }

    public function test_licenca_perto_do_fim_esta_vencendo(): void
    {
        $vinculo = $this->vinculo([
            'status_saas' => 'ativo',
            'licenca_id_externo' => '91',
            'licenca_fim_em' => now()->addDays(5)->toDateString(),
        ]);

        $this->assertSame('vencendo', $vinculo->estado());
        $this->assertSame('atencao', $vinculo->tom());
    }

    public function test_o_dia_do_vencimento_ainda_e_do_cliente(): void
    {
        $vinculo = $this->vinculo([
            'status_saas' => 'ativo',
            'licenca_id_externo' => '91',
            'licenca_fim_em' => now()->toDateString(),
        ]);

        $this->assertSame('vencendo', $vinculo->estado(), 'Vencer hoje não é ter vencido.');
    }

    public function test_licenca_com_fim_no_passado_esta_vencida(): void
    {
        $vinculo = $this->vinculo([
            'status_saas' => 'ativo',
            'licenca_id_externo' => '91',
            'licenca_fim_em' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame('vencida', $vinculo->estado());
        $this->assertSame('atencao', $vinculo->tom());
    }

    public function test_bloqueado_vence_qualquer_data(): void
    {
        $vinculo = $this->vinculo([
            'status_saas' => 'bloqueado',
            'licenca_id_externo' => '91',
            'licenca_fim_em' => now()->addYear()->toDateString(),
        ]);

        $this->assertSame('suspensa', $vinculo->estado());
        $this->assertTrue($vinculo->suspensa());
        $this->assertSame('critico', $vinculo->tom());
    }

    /**
     * `bloqueia_acesso` é a POLÍTICA da licença no AlfaGym ("bloquear ao
     * vencer"), sempre verdadeira. Usá-la para decidir o estado marcaria toda
     * a base como suspensa — foi um bug real, corrigido em produção.
     */
    public function test_a_politica_de_bloqueio_nao_decide_o_estado(): void
    {
        $vinculo = $this->vinculo([
            'status_saas' => 'ativo',
            'bloqueia_acesso' => true,
            'licenca_id_externo' => '91',
            'licenca_fim_em' => now()->addMonths(3)->toDateString(),
        ]);

        $this->assertSame('ativa', $vinculo->estado());
    }

    public function test_licenca_sem_data_de_fim_conta_como_ativa(): void
    {
        $vinculo = $this->vinculo([
            'status_saas' => 'ativo',
            'licenca_id_externo' => '91',
            'licenca_fim_em' => null,
        ]);

        $this->assertSame('ativa', $vinculo->estado());
    }

    public function test_a_ordem_de_exibicao_poe_o_problema_primeiro(): void
    {
        $estados = [
            'ativa' => $this->vinculo(['status_saas' => 'ativo', 'licenca_id_externo' => '1', 'licenca_fim_em' => now()->addYear()->toDateString()]),
            'suspensa' => $this->vinculo(['status_saas' => 'bloqueado', 'licenca_id_externo' => '2']),
            'pendente' => $this->vinculo(['status_saas' => 'pendente']),
            'vencida' => $this->vinculo(['status_saas' => 'ativo', 'licenca_id_externo' => '3', 'licenca_fim_em' => now()->subMonth()->toDateString()]),
            'vencendo' => $this->vinculo(['status_saas' => 'ativo', 'licenca_id_externo' => '4', 'licenca_fim_em' => now()->addDays(3)->toDateString()]),
        ];

        $ordenados = collect($estados)->sortBy(fn (ClienteSistema $v) => $v->gravidade())->keys()->all();

        $this->assertSame(['suspensa', 'vencida', 'vencendo', 'pendente', 'ativa'], $ordenados);
    }
}
