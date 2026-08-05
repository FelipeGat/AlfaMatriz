<?php

namespace Tests\Feature\Deploy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HttpsAtrasDoProxyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-004 Com a requisição chegando pelo proxy do Funnel (HTTP local
     * sinalizando HTTPS no cabeçalho), os endereços que o painel gera saem em
     * https — sem isso o login quebra na URL pública.
     */
    public function test_enderecos_gerados_saem_em_https_quando_o_proxy_sinaliza_https(): void
    {
        $resposta = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '100.64.0.9',
        ])->get('/');

        // "/" redireciona para o painel: o destino precisa vir em https.
        $resposta->assertRedirect();
        $this->assertStringStartsWith(
            'https://',
            $resposta->headers->get('Location'),
            'O redirecionamento saiu em http: o proxy do Funnel não está sendo respeitado.'
        );

        // E o login, para onde o visitante deslogado é mandado, também.
        $login = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->withHeaders([
            'X-Forwarded-Proto' => 'https',
        ])->get('/dashboard');

        $login->assertRedirect();
        $this->assertStringStartsWith('https://', $login->headers->get('Location'));
    }
}
