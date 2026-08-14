<?php

namespace Tests\Feature\Seguranca;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O nginx do container já põe `X-Frame-Options`, `X-Content-Type-Options` e
 * `Referrer-Policy` (ver `deploy/nginx-alfamatriz.conf`), mas a suíte roda
 * sem ele — por isso estes três nunca tiveram teste até o aplicativo passar
 * a emiti-los também (AC-268).
 */
class CabecalhosDeSegurancaTest extends TestCase
{
    use RefreshDatabase;

    /** @spec:AC-266 Tela não autenticada chega com Content-Security-Policy prendendo ao próprio site. */
    public function test_tela_nao_autenticada_traz_content_security_policy(): void
    {
        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    /** @spec:AC-266 Tela autenticada também traz a política de conteúdo. */
    public function test_tela_autenticada_traz_content_security_policy(): void
    {
        $csp = $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
    }

    /** @spec:AC-267 Em produção, a resposta traz Strict-Transport-Security com validade de ao menos um ano. */
    public function test_em_producao_traz_strict_transport_security_com_um_ano(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $hsts = $this->get(route('login'))->headers->get('Strict-Transport-Security');

        $this->assertNotNull($hsts);
        preg_match('/max-age=(\d+)/', $hsts, $m);
        $this->assertNotEmpty($m);
        $this->assertGreaterThanOrEqual(31536000, (int) $m[1]);
    }

    /** @spec:AC-267 Fora de produção não promete HTTPS para sempre. */
    public function test_fora_de_producao_nao_traz_strict_transport_security(): void
    {
        $response = $this->get(route('login'));

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    /** @spec:AC-268 O próprio aplicativo emite X-Frame-Options, X-Content-Type-Options e Referrer-Policy. */
    public function test_aplicativo_emite_os_cabecalhos_que_hoje_so_o_nginx_poe(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
    }
}
