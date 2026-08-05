<?php

namespace Tests\Feature\FluxoDeploy;

use Tests\TestCase;

class InventarioPainelTest extends TestCase
{
    private const INVENTARIO = 'deploy/alfadeploy-systems-alfamatriz.toml';

    /** @var array<string, string> */
    private array $campos = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->campos = $this->lerCampos(base_path(self::INVENTARIO));
    }

    /**
     * @spec:AC-032 O AlfaMatriz entra no inventário do painel com o que ele
     * precisa para acompanhar: qual container, onde fica a aplicação e como
     * checar a saúde.
     */
    public function test_inventario_traz_os_dados_de_acompanhamento(): void
    {
        $this->assertSame('AlfaMatriz', $this->campos['name'] ?? null);
        $this->assertSame('alfamatriz', $this->campos['key'] ?? null);
        $this->assertSame('laravel', $this->campos['stack'] ?? null, 'A stack precisa bater com a dos irmãos AlfaHome e Gestor.');

        // O painel aponta para o STAGING: é ele que o executor atualiza sozinho.
        $this->assertSame('116', $this->campos['lxc'] ?? null);
        $this->assertSame('10.0.3.116', $this->campos['ssh_host'] ?? null);
        $this->assertSame('/var/www/alfamatriz', $this->campos['dir'] ?? null);

        $this->assertArrayHasKey('health_host', $this->campos);
        $this->assertArrayHasKey('health_port', $this->campos);
        $this->assertStringContainsString('staging', $this->campos['health_host']);
    }

    /**
     * @spec:AC-033 O inventário omite os campos que alimentam a
     * re-anonimização e a restauração de contas de teste. Sem eles, essas
     * ações não têm alvo — e não conseguem embaralhar a base real de clientes
     * e do financeiro da Alfa.
     */
    public function test_inventario_nao_da_alvo_para_acao_destrutiva(): void
    {
        $proibidos = [
            'mysql_container', 'db', 'user_table', 'email_col',
            'pass_col', 'name_col', 'admin_id', 'cliente_id', 'seed_domain',
        ];

        foreach ($proibidos as $campo) {
            $this->assertArrayNotHasKey(
                $campo,
                $this->campos,
                "O campo \"{$campo}\" daria alvo para a re-anonimização do painel apagar dados reais."
            );
        }

        // A omissão precisa estar explicada no arquivo: sem isso, alguém
        // "completa" o cadastro no futuro achando que faltou preencher.
        $conteudo = file_get_contents(base_path(self::INVENTARIO));
        $this->assertStringContainsString('OMISSÕES DELIBERADAS', $conteudo);
    }

    /**
     * @return array<string, string>
     */
    private function lerCampos(string $caminho): array
    {
        $this->assertFileExists($caminho);

        $campos = [];

        foreach (file($caminho, FILE_IGNORE_NEW_LINES) as $linha) {
            $linha = trim($linha);

            if ($linha === '' || str_starts_with($linha, '#') || ! str_contains($linha, '=')) {
                continue;
            }

            [$chave, $valor] = explode('=', $linha, 2);
            $campos[trim($chave)] = trim(trim($valor), '"');
        }

        return $campos;
    }
}
