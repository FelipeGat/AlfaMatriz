<?php

namespace Tests\Feature\Redesign;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Rede de segurança do redesign.
 *
 * Escrito ANTES de mexer no visual: o redesign toca 59 arquivos Blade de um
 * sistema em produção, e este teste é o que avisa se alguma tela parar de
 * renderizar por causa de um componente, variável ou classe removida.
 */
class TelasAbremTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Toda tela do painel, por nome de rota. Sem parâmetros: são as telas que
     * abrem direto pelo menu.
     */
    private const TELAS = [
        'dashboard',
        'comercial',
        'revendas.index',
        'revendas.create',
        'clientes.index',
        'clientes.create',
        'sistemas.index',
        'faturamento.index',
        'cobrancas.index',
        'cobrancas.create',
        'contas-pagar.index',
        'contas-pagar.create',
        'contas-fixas-pagar.index',
        'contas-financeiras.index',
        'contas-financeiras.create',
        'cadastros-auxiliares.index',
        'profile.edit',
    ];

    /**
     * Rotas que existem mas não são tela: `fornecedores.index` só redireciona
     * para Cadastros Auxiliares (FornecedorController@index), então cobrar 200
     * dela seria testar a coisa errada.
     */
    private const NAO_SAO_TELA = [
        'fornecedores.index',
    ];

    /**
     * @spec:AC-042 Toda tela do painel abre para quem está autenticado. É a
     * rede que impede o redesign de derrubar faturamento ou financeiro sem
     * ninguém perceber.
     */
    public function test_todas_as_telas_do_painel_abrem_para_usuario_autenticado(): void
    {
        $usuario = User::factory()->create();

        $quebradas = [];

        foreach (self::TELAS as $rota) {
            $resposta = $this->actingAs($usuario)->get(route($rota));

            if ($resposta->getStatusCode() !== 200) {
                $quebradas[] = $rota.' respondeu '.$resposta->getStatusCode();
            }
        }

        $this->assertSame([], $quebradas, "Telas quebradas:\n".implode("\n", $quebradas));
    }

    /**
     * @spec:AC-042 A lista acima precisa acompanhar as rotas do sistema: uma
     * tela nova sem cobertura passaria despercebida justamente no redesign.
     */
    public function test_a_lista_cobre_todas_as_telas_sem_parametro(): void
    {
        $semCobertura = [];

        foreach (Route::getRoutes() as $rota) {
            $nome = $rota->getName();

            // Rota sem nome próprio recebe um `generated::xxx` do Laravel
            // (visível quando as rotas estão em cache, como no servidor).
            // Não são telas do menu: não entram na rede.
            if (! $nome || str_starts_with($nome, 'generated::')) {
                continue;
            }

            if (! in_array('GET', $rota->methods(), true)) {
                continue;
            }

            // Telas com parâmetro (edição, extrato) e as de autenticação
            // ficam fora: não abrem direto pelo menu.
            if (str_contains($rota->uri(), '{') || $this->ehRotaDeAutenticacao($nome)) {
                continue;
            }

            if (! in_array($nome, self::TELAS, true) && ! in_array($nome, self::NAO_SAO_TELA, true)) {
                $semCobertura[] = $nome;
            }
        }

        $this->assertSame(
            [],
            $semCobertura,
            "Estas telas existem mas não estão na rede de segurança do redesign:\n".implode("\n", $semCobertura)
        );
    }

    private function ehRotaDeAutenticacao(string $nome): bool
    {
        foreach (['login', 'logout', 'password', 'verification', 'healthz'] as $prefixo) {
            if (str_starts_with($nome, $prefixo)) {
                return true;
            }
        }

        return false;
    }
}
