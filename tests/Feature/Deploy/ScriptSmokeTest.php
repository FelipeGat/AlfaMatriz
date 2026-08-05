<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ScriptSmokeTest extends TestCase
{
    private string $fakeBin;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeBin = $this->criarDiretorioTemporario('smoke-bin-');
        $this->log = tempnam(sys_get_temp_dir(), 'smoke-log-');
        unlink($this->log);

        $this->criarCurlFalso();
    }

    protected function tearDown(): void
    {
        $this->removerDiretorio($this->fakeBin);
        if (is_file($this->log)) {
            unlink($this->log);
        }

        parent::tearDown();
    }

    /**
     * @spec:AC-010 Com a URL respondendo em HTTPS, /healthz em 200, a tela de
     * login em 200 e a de cadastro em 404, o script de conferência confirma
     * as quatro checagens e sai com sucesso.
     */
    public function test_smoke_confirma_https_saude_login_e_registro_fechado_e_sai_com_sucesso(): void
    {
        $processo = $this->rodarScript();

        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput().$processo->getOutput());
        $this->assertStringContainsString('https', $processo->getOutput());
        $this->assertStringContainsString('healthz', $processo->getOutput());
        $this->assertStringContainsString('login', $processo->getOutput());
        $this->assertStringContainsString('registro', $processo->getOutput());
    }

    /**
     * @spec:AC-010 Se alguma checagem falhar (aqui, /healthz não volta 200 e
     * o cadastro não está fechado), o script sai com erro listando quais
     * checagens falharam, sem deixar de rodar as demais.
     */
    public function test_smoke_lista_as_checagens_que_falharam_e_sai_com_erro(): void
    {
        $processo = $this->rodarScript([
            'CODIGO_HEALTHZ' => '500',
            'CODIGO_REGISTRO' => '200',
        ]);

        $this->assertNotSame(0, $processo->getExitCode());

        $saida = $processo->getOutput().$processo->getErrorOutput();

        $this->assertStringContainsString('healthz', $saida);
        $this->assertStringContainsString('registro', $saida);

        $this->assertStringContainsString('login', $processo->getOutput());
        $this->assertStringContainsString('https', $processo->getOutput());
    }

    /**
     * @spec:AC-010 Se a URL de conferência não for https, o script recusa a
     * checagem de HTTPS e sai com erro, mesmo que o resto responda bem.
     */
    public function test_smoke_recusa_url_que_nao_e_https(): void
    {
        $processo = $this->rodarScript([], 'http://alfamatriz.exemplo.com');

        $this->assertNotSame(0, $processo->getExitCode());
        $this->assertStringContainsString('https', $processo->getErrorOutput());
    }

    private function rodarScript(array $envExtra = [], ?string $url = null): Process
    {
        $raiz = dirname(__DIR__, 3);
        $script = $raiz.'/deploy/smoke.sh';
        $url = $url ?? 'https://alfamatriz.exemplo.com';

        $processo = new Process(
            ['bash', $script, '--url', $url],
            null,
            array_merge([
                'PATH' => $this->fakeBin.':'.getenv('PATH'),
                'LOG_CHAMADAS' => $this->log,
            ], $envExtra)
        );
        $processo->run();

        return $processo;
    }

    private function criarCurlFalso(): void
    {
        $conteudo = <<<'SH'
#!/usr/bin/env bash
echo "curl $*" >> "$LOG_CHAMADAS"

if [[ "${FALHAR_CONEXAO:-0}" == "1" ]]; then
    exit 1
fi

alvo="${@: -1}"

case "$alvo" in
    *"/healthz")
        codigo="${CODIGO_HEALTHZ:-200}"
        ;;
    *"/login")
        codigo="${CODIGO_LOGIN:-200}"
        ;;
    *"/register")
        codigo="${CODIGO_REGISTRO:-404}"
        ;;
    *)
        codigo="${CODIGO_BASE:-200}"
        ;;
esac

for arg in "$@"; do
    if [[ "$arg" == "-w"* || "$arg" == "%{http_code}" ]]; then
        printf '%s' "$codigo"
        exit 0
    fi
done

exit 0
SH;

        $caminho = $this->fakeBin.'/curl';
        file_put_contents($caminho, $conteudo);
        chmod($caminho, 0755);
    }

    private function criarDiretorioTemporario(string $prefixo): string
    {
        $caminho = sys_get_temp_dir().'/'.$prefixo.uniqid();
        mkdir($caminho, 0755, true);

        return $caminho;
    }

    private function removerDiretorio(string $caminho): void
    {
        if (! is_dir($caminho)) {
            return;
        }

        $itens = scandir($caminho);
        foreach ($itens as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemCaminho = $caminho.'/'.$item;
            if (is_dir($itemCaminho)) {
                $this->removerDiretorio($itemCaminho);
            } else {
                unlink($itemCaminho);
            }
        }

        rmdir($caminho);
    }
}
