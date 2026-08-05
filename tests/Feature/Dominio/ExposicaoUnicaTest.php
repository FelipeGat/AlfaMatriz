<?php

namespace Tests\Feature\Dominio;

use Tests\TestCase;

class ExposicaoUnicaTest extends TestCase
{
    private const SCRIPT = 'deploy/provisionar.sh';

    /**
     * @spec:AC-028 O provisionamento não publica mais o endereço do Tailscale
     * na internet: desliga o Funnel e mantém só o `serve`, que responde dentro
     * do tailnet. Rodar o script de novo não pode reabrir a porta pública.
     */
    public function test_provisionamento_desliga_o_funnel_e_mantem_o_acesso_interno(): void
    {
        $script = file_get_contents(base_path(self::SCRIPT));

        // O acesso interno continua: `serve` apontando para o Nginx local.
        $this->assertMatchesRegularExpression(
            '/tailscale serve .*http:\/\/127\.0\.0\.1:80/',
            $script,
            'O acesso pelo tailnet precisa continuar existindo como emergência.'
        );

        // O Funnel é explicitamente desligado.
        $this->assertMatchesRegularExpression(
            '/tailscale funnel .*off/',
            $script,
            'O provisionamento precisa desligar o Funnel.'
        );

        // E em nenhum lugar ele volta a ser ligado.
        foreach (preg_split('/\R/', $script) as $numero => $linha) {
            if (preg_match('/tailscale funnel/', $linha) && ! preg_match('/off/', $linha)) {
                // Comentários explicando a decisão são permitidos.
                if (str_starts_with(ltrim($linha), '#')) {
                    continue;
                }

                $this->fail(
                    'Linha '.($numero + 1).' religa o Funnel, reabrindo a segunda porta pública: '.trim($linha)
                );
            }
        }
    }

    /**
     * @spec:AC-024 O provisionamento instala e habilita o túnel Cloudflare,
     * que passa a ser o caminho público do painel.
     */
    public function test_provisionamento_instala_o_tunel_cloudflare(): void
    {
        $script = file_get_contents(base_path(self::SCRIPT));

        $this->assertStringContainsString('cloudflared', $script);
        $this->assertStringContainsString('systemctl enable --now cloudflared', $script);
    }
}
