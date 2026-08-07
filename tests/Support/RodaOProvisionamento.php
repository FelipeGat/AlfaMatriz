<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Apoio para os testes que conferem o que o provisionamento FARIA no servidor.
 *
 * O script é executado de verdade, mas com um Proxmox de mentira no PATH: cada
 * comando enviado ao container é registrado num log em vez de acontecer. Assim
 * dá para assertar o conteúdo exato de um arquivo de configuração, a ordem das
 * etapas e a idempotência — sem nenhum servidor real por perto.
 *
 * Os comandos chegam ao log como `container: bash -lc <comando>`, e um comando
 * pode ocupar várias linhas (heredoc). Por isso existem dois acessos: o log
 * bruto, para conferir blocos de texto, e as chamadas linha a linha, para
 * conferir a presença de um comando curto.
 */
trait RodaOProvisionamento
{
    private string $provisionarBin;

    private string $provisionarEstado;

    private string $provisionarLog;

    protected function prepararProvisionamentoFalso(): void
    {
        $this->provisionarBin = $this->criarTemporario('prov-bin-');
        $this->provisionarEstado = $this->criarTemporario('prov-estado-');
        $this->provisionarLog = $this->provisionarEstado.'/chamadas.log';

        $pct = <<<'BASH'
#!/usr/bin/env bash
echo "pct $*" >> "$ALFA_LOG"
case "$1" in
    config)
        [ -f "$ALFA_ESTADO/criado" ] && exit 0
        exit 1
        ;;
    create) touch "$ALFA_ESTADO/criado"; exit 0 ;;
    status)
        [ -f "$ALFA_ESTADO/rodando" ] && echo "status: running" || echo "status: stopped"
        exit 0
        ;;
    start) touch "$ALFA_ESTADO/rodando"; exit 0 ;;
    exec)
        shift 3
        echo "container: $*" >> "$ALFA_LOG"
        exit 0
        ;;
    push)
        shift
        echo "push: $*" >> "$ALFA_LOG"
        exit 0
        ;;
esac
exit 0
BASH;

        $this->criarBinarioFalso('pct', $pct);

        foreach (['ssh', 'scp', 'sleep', 'cp'] as $ferramenta) {
            $this->criarBinarioFalso(
                $ferramenta,
                "#!/usr/bin/env bash\necho \"{$ferramenta} \$*\" >> \"\$ALFA_LOG\"\nexit 0\n"
            );
        }

        // O `grep` do script consulta /etc/pve, que não existe fora do Proxmox.
        $this->criarBinarioFalso('grep', "#!/usr/bin/env bash\nexit 0\n");
    }

    protected function limparProvisionamentoFalso(): void
    {
        $this->removerRecursivo($this->provisionarBin ?? '');
        $this->removerRecursivo($this->provisionarEstado ?? '');
    }

    /** Executa o provisionamento e devolve a saída (stdout + stderr). */
    protected function provisionar(string $ambiente = 'producao'): string
    {
        $processo = new Process(
            ['bash', base_path('deploy/provisionar.sh'), '--local', '--ambiente', $ambiente],
            base_path(),
            [
                'PATH' => $this->provisionarBin.':'.getenv('PATH'),
                'ALFA_LOG' => $this->provisionarLog,
                'ALFA_ESTADO' => $this->provisionarEstado,
            ]
        );
        $processo->run();

        $this->assertSame(
            0,
            $processo->getExitCode(),
            "O provisionamento precisa terminar com sucesso.\n".$processo->getErrorOutput().$processo->getOutput()
        );

        return $processo->getOutput().$processo->getErrorOutput();
    }

    /** Tudo que foi enviado ao container, como um texto só (aguenta heredoc). */
    protected function logDoProvisionamento(): string
    {
        return is_file($this->provisionarLog) ? file_get_contents($this->provisionarLog) : '';
    }

    /** Esquece o que foi registrado até aqui — para conferir a segunda execução. */
    protected function esquecerChamadas(): void
    {
        file_put_contents($this->provisionarLog, '');
    }

    private function criarBinarioFalso(string $nome, string $conteudo): void
    {
        $caminho = $this->provisionarBin.'/'.$nome;
        file_put_contents($caminho, $conteudo);
        chmod($caminho, 0755);
    }

    private function criarTemporario(string $prefixo): string
    {
        $caminho = sys_get_temp_dir().'/'.$prefixo.bin2hex(random_bytes(6));
        mkdir($caminho, 0755, true);

        return $caminho;
    }

    private function removerRecursivo(string $caminho): void
    {
        if ($caminho === '' || ! is_dir($caminho)) {
            return;
        }

        foreach (scandir($caminho) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $alvo = $caminho.'/'.$item;
            is_dir($alvo) ? $this->removerRecursivo($alvo) : unlink($alvo);
        }

        rmdir($caminho);
    }
}
