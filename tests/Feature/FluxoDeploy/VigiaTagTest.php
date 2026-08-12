<?php

namespace Tests\Feature\FluxoDeploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Exercita o vigia de tag de verdade, num repositório descartável com
 * binários falsos — inclusive o `curl` do health-check.
 */
class VigiaTagTest extends TestCase
{
    private string $repo;

    private string $bin;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = $this->tmp('vigia-repo-');
        $this->bin = $this->tmp('vigia-bin-');
        $this->log = $this->repo.'/chamadas.log';

        $this->montarRepositorio();
    }

    protected function tearDown(): void
    {
        $this->apagar($this->repo);
        $this->apagar($this->bin);

        parent::tearDown();
    }

    /**
     * @spec:AC-067 Havendo tag nova, o vigia aplica ela em produção e registra
     * a versão. Sem tag nova, ele não faz nada — alteração na main sozinha
     * nunca chega ao faturamento.
     */
    public function test_aplica_a_tag_nova_e_ignora_alteracao_sem_tag(): void
    {
        $this->criarFerramentas(saude: '200');

        $processo = $this->rodar();
        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());

        $chamadas = $this->chamadas();
        $this->assertStringContainsString('checkout --detach', $chamadas);
        $this->assertStringContainsString('php artisan migrate --force', $chamadas);
        $this->assertSame('v1.0.0', trim(file_get_contents($this->repo.'/.deploy-tag-state')));

        // E a versão entrou pelo azul/verde: o que está no ar é uma das duas
        // cópias, apontada por um symlink — e não mais o próprio diretório em
        // que o vigia trabalhou.
        $this->assertMatchesRegularExpression(
            '#/versoes/(azul|verde)$#',
            (string) readlink($this->repo.'/atual'),
            'A tag precisa ter sido publicada numa das cópias, pela troca do symlink.'
        );

        // Rodar de novo, sem tag nova, não pode aplicar coisa alguma.
        file_put_contents($this->log, '');
        $segunda = $this->rodar();

        $this->assertSame(0, $segunda->getExitCode());
        $this->assertStringContainsString('UPTODATE', $segunda->getOutput());
        $this->assertStringNotContainsString('php artisan migrate', $this->chamadas());
    }

    /**
     * @spec:AC-068 O banco é copiado ANTES das migrações, e a ordem importa:
     * uma migração ruim em cima de faturamento real precisa ter volta.
     */
    public function test_faz_backup_antes_de_migrar(): void
    {
        $this->criarFerramentas(saude: '200');

        $this->rodar();

        $chamadas = $this->chamadas();
        $posBackup = strpos($chamadas, 'BACKUP');
        $posMigrate = strpos($chamadas, 'php artisan migrate');

        $this->assertNotFalse($posBackup, 'O backup precisa ser chamado.');
        $this->assertNotFalse($posMigrate);
        $this->assertLessThan($posMigrate, $posBackup, 'O backup tem de vir ANTES da migração.');
    }

    /**
     * @spec:AC-068 Saúde ruim depois de aplicar: o vigia marca a falha e para.
     * Na execução seguinte ele não tenta de novo — insistir em cima de um
     * sistema quebrado só piora.
     */
    public function test_saude_ruim_marca_falha_e_nao_tenta_de_novo(): void
    {
        $this->criarFerramentas(saude: '500');

        $processo = $this->rodar();

        $this->assertNotSame(0, $processo->getExitCode());
        $this->assertFileExists($this->repo.'/.deploy-tag-failed');
        $this->assertFileDoesNotExist($this->repo.'/.deploy-tag-state', 'Versão quebrada não pode ser registrada como aplicada.');

        $estado = json_decode(file_get_contents($this->repo.'/deploy-status.json'), true);
        $this->assertSame('falha', $estado['estado'] ?? null, 'O painel precisa enxergar a falha.');

        // Segunda execução: bloqueada pelo marcador.
        file_put_contents($this->log, '');
        $segunda = $this->rodar();

        $this->assertSame(0, $segunda->getExitCode());
        $this->assertStringContainsString('BLOQUEADO', $segunda->getOutput());
        $this->assertStringNotContainsString('php artisan migrate', $this->chamadas());
    }

    /**
     * @spec:AC-170 Versão que passa no ensaio, entra no ar e derruba a saúde
     * pública volta sozinha — e o vigia registra a falha e para.
     *
     * É o caso que o azul/verde tornou barato: a versão anterior continua
     * inteira na outra cópia, então desfazer é trocar um symlink de volta. Sem
     * isso, a produção ficaria quebrada até alguém acordar.
     */
    public function test_versao_que_quebra_depois_de_entrar_volta_sozinha(): void
    {
        // Passa no ensaio (127.0.0.1), reprova no endereço público.
        $this->criarFerramentas(saude: '200', saudePublica: '503');

        $processo = $this->rodar();

        $this->assertNotSame(0, $processo->getExitCode());
        $this->assertStringContainsString('troca foi DESFEITA', $processo->getOutput());

        // A versão quebrada não pode ficar registrada como aplicada, e a
        // esteira precisa ficar bloqueada: sem isso, o vigia traria a mesma
        // tag de volta em cinco minutos.
        $this->assertFileDoesNotExist($this->repo.'/.deploy-tag-state');
        $this->assertFileExists($this->repo.'/.deploy-tag-failed');

        $estado = json_decode(file_get_contents($this->repo.'/deploy-status.json'), true);
        $this->assertSame('falha', $estado['estado'] ?? null);
    }

    /**
     * @spec:AC-069 O vigia roda pelo cron, que entrega um PATH mínimo
     * (/usr/bin:/bin). As ferramentas de build vivem em /usr/local/bin — se o
     * script não completar o PATH sozinho, ele funciona quando alguém o chama
     * à mão e falha a cada 5 minutos pelo agendador, sempre no mesmo ponto e
     * antes do health check.
     *
     * Este teste existe porque foi exatamente o que aconteceu: a publicação de
     * v2026.08.06 morreu em "composer: command not found". Os outros testes
     * não pegaram porque todos põem o diretório de binários falsos no PATH
     * antes de rodar — o PATH pobre do cron nunca era exercitado.
     *
     * A verificação é na fonte, e não no comportamento, por um motivo
     * concreto: reproduzir o caso exigiria escrever um composer falso em
     * /usr/local/bin da máquina que roda os testes. O que importa aqui é o
     * script não depender do PATH que recebe — e disso a fonte dá conta.
     */
    public function test_o_vigia_completa_o_path_que_o_cron_nao_entrega(): void
    {
        $fonte = file_get_contents(base_path('deploy/deploy-tag-watcher-alfamatriz.sh'));

        $this->assertMatchesRegularExpression(
            '/^export PATH=.*\/usr\/local\/bin/m',
            $fonte,
            'Sem completar o PATH, o vigia não acha o composer quando roda pelo cron.'
        );

        // Acrescentado ao FIM: prefixar tiraria a prioridade de quem chamou o
        // script com um PATH próprio — inclusive a suíte, que injeta binários
        // falsos e precisa que eles ganhem dos reais.
        $this->assertMatchesRegularExpression(
            '/^export PATH="\$PATH:/m',
            $fonte,
            'O PATH de quem chamou precisa continuar tendo prioridade.'
        );

        // O irmão do staging já tinha essa correção; este script nasceu depois
        // e não a herdou. Se um dia ela sair de lá, este teste avisa.
        $this->assertMatchesRegularExpression(
            '/^export PATH=.*\/usr\/local\/bin/m',
            file_get_contents(base_path('deploy/deploy-staging-alfamatriz.sh')),
            'O executor de staging perdeu a correção de PATH que já tinha.'
        );
    }

    /**
     * @spec:AC-067 Tag recriada no remoto não pode congelar a esteira. Sem
     * `--force`, o fetch sai 1 com "would clobber existing tag" e o vigia
     * aborta antes de olhar qualquer tag — inclusive a nova, que nada tem a
     * ver com a que foi movida.
     *
     * Aconteceu em 2026-08-11: a v2026.08.11.3 foi recriada apontando para
     * outro commit e a produção ficou 1h20 sem ver a v2026.08.11.7, falhando
     * de 5 em 5 minutos com uma linha de log que não dizia o motivo.
     */
    public function test_tag_recriada_no_remoto_nao_congela_a_esteira(): void
    {
        // Aqui o fetch precisa ser REAL: é ele que está sendo testado.
        $this->criarFerramentas(saude: '200', fetchDeMentira: false);

        $origem = $this->tmp('vigia-origem-');
        $this->executar(['git', 'init', '--quiet', '--bare', $origem]);
        $this->executar(['git', 'remote', 'add', 'origin', $origem], $this->repo);
        $this->executar(['git', 'push', '--quiet', 'origin', 'HEAD', '--tags'], $this->repo);

        // O movimento acontece POR FORA, noutra cópia — é o que reproduz o
        // incidente. Movendo a tag no próprio repositório do vigia, ele já
        // ficaria de acordo com o remoto e não haveria clobber nenhum: o
        // teste passaria com ou sem `--force`, que foi o meu primeiro erro
        // ao escrevê-lo.
        $outra = $this->tmp('vigia-outra-');
        $this->executar(['git', 'clone', '--quiet', $origem, $outra.'/copia']);
        $copia = $outra.'/copia';
        $this->executar(['git', 'config', 'user.email', 'teste@exemplo.com'], $copia);
        $this->executar(['git', 'config', 'user.name', 'Teste'], $copia);

        file_put_contents($copia.'/versao.txt', 'v2');
        $this->executar(['git', 'commit', '--quiet', '-am', 'segundo'], $copia);

        // A v1.0.0 do remoto muda de commit — o clobber que trava tudo.
        $this->executar(['git', 'tag', '-f', 'v1.0.0'], $copia);
        $this->executar(['git', 'push', '--quiet', '--force', 'origin', 'v1.0.0'], $copia);

        // E chega a release nova, que é a que precisa entrar.
        $this->executar(['git', 'tag', 'v1.1.0'], $copia);
        $this->executar(['git', 'push', '--quiet', 'origin', 'v1.1.0'], $copia);

        // O vigia continua com a v1.0.0 velha apontando para o commit velho,
        // exatamente como o container de produção estava.
        $this->assertNotSame(
            trim((new Process(['git', 'rev-parse', 'v1.0.0'], $copia))->mustRun()->getOutput()),
            trim((new Process(['git', 'rev-parse', 'v1.0.0'], $this->repo))->mustRun()->getOutput()),
            'O cenário exige a tag local divergindo da do remoto.'
        );

        // O vigia parte de um estado anterior e da tag velha no repositório
        // local, como o container estava.
        file_put_contents($this->repo.'/.deploy-tag-state', "v1.0.0\n");

        $processo = $this->rodar();

        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());
        $this->assertStringNotContainsString('fetch de tags FALHOU', $processo->getOutput());
        $this->assertSame('v1.1.0', trim(file_get_contents($this->repo.'/.deploy-tag-state')));

        $this->apagar($origem);
        $this->apagar($outra);
    }

    /**
     * @spec:AC-067 Fetch que falha precisa dizer POR QUE no log e parar de
     * mostrar verde no painel — sem, porém, gravar o marcador que bloqueia as
     * próximas tentativas: falha de rede se resolve tentando de novo.
     */
    public function test_falha_de_fetch_aparece_no_log_e_na_telemetria(): void
    {
        $this->criarFerramentas(saude: '200');

        // git que falha só no fetch, com uma mensagem reconhecível.
        $this->binario('git', <<<'BASH'
#!/usr/bin/env bash
echo "git $*" >> "$ALFA_LOG"
if [ "$1" = "fetch" ]; then echo "fatal: could not read from remote" >&2; exit 1; fi
exec /usr/bin/git "$@"
BASH);

        file_put_contents($this->repo.'/.deploy-tag-state', "v1.0.0\n");

        $processo = $this->rodar();

        $this->assertNotSame(0, $processo->getExitCode());

        // O motivo, e não só "FALHOU".
        $this->assertStringContainsString('could not read from remote', $processo->getOutput());

        // O painel precisa enxergar falha, com a tag que continua no ar.
        $status = json_decode(file_get_contents($this->repo.'/deploy-status.json'), true);
        $this->assertSame('falha', $status['estado']);
        $this->assertSame('v1.0.0', $status['tag']);

        // E o bloqueio NÃO pode ter sido gravado: soluço de rede não vira
        // parada que só sai com alguém apagando arquivo no servidor.
        $this->assertFileDoesNotExist($this->repo.'/.deploy-tag-failed');
    }

    // ------------------------------------------------------------- apoio

    private function rodar(): Process
    {
        $processo = new Process(
            ['bash', base_path('deploy/deploy-tag-watcher-alfamatriz.sh'), '--dir', $this->repo],
            $this->repo,
            [
                'PATH' => $this->bin.':'.getenv('PATH'),
                'ALFA_LOG' => $this->log,
                'LOG' => $this->repo.'/deploy-tag.log',
                'HEALTH_URL' => 'https://exemplo.invalido/healthz',
                'HOME' => $this->repo,
                // Quem confere a saúde agora é o publicar.sh, que insiste
                // algumas vezes antes de desistir. Sem encurtar a espera, cada
                // cenário de falha custaria 20 segundos à suíte.
                'TENTATIVAS_SAUDE' => '2',
                'ESPERA_SAUDE' => '0',
            ]
        );
        $processo->run();

        return $processo;
    }

    private function montarRepositorio(): void
    {
        $this->executar(['git', 'init', '--quiet', $this->repo]);
        $this->executar(['git', 'config', 'user.email', 'teste@exemplo.com'], $this->repo);
        $this->executar(['git', 'config', 'user.name', 'Teste'], $this->repo);

        mkdir($this->repo.'/deploy', 0755, true);
        mkdir($this->repo.'/public', 0755, true);

        // backup.sh de mentira: registra que foi chamado, para o teste poder
        // conferir a ORDEM entre backup e migração.
        file_put_contents(
            $this->repo.'/deploy/backup.sh',
            "#!/usr/bin/env bash\necho BACKUP >> \"\$ALFA_LOG\"\nexit 0\n"
        );

        file_put_contents($this->repo.'/versao.txt', 'v1');
        $this->executar(['git', 'add', '-A'], $this->repo);
        $this->executar(['git', 'commit', '--quiet', '-m', 'inicial'], $this->repo);
        $this->executar(['git', 'tag', 'v1.0.0'], $this->repo);
    }

    private function criarFerramentas(string $saude, bool $fetchDeMentira = true, ?string $saudePublica = null): void
    {
        // `git` real, menos o fetch — a maioria dos cenários não tem remoto.
        // Quem testa o próprio fetch pede o git inteiro (`fetchDeMentira:
        // false`) e monta um remoto de verdade.
        $this->binario('git', $fetchDeMentira ? <<<'BASH'
#!/usr/bin/env bash
echo "git $*" >> "$ALFA_LOG"
if [ "$1" = "fetch" ]; then exit 0; fi
exec /usr/bin/git "$@"
BASH : <<<'BASH'
#!/usr/bin/env bash
echo "git $*" >> "$ALFA_LOG"
exec /usr/bin/git "$@"
BASH);

        // A saúde do ensaio (127.0.0.1, antes da troca) e a do endereço
        // público (depois da troca) podem divergir — é essa diferença que
        // separa "não entrou" de "entrou, quebrou e voltou sozinho".
        $publica = $saudePublica ?? $saude;
        $this->binario('curl', <<<BASH
#!/usr/bin/env bash
echo "curl \$*" >> "\$ALFA_LOG"
for arg in "\$@"; do
    case "\$arg" in
        *127.0.0.1*) printf '{$saude}'; exit 0 ;;
    esac
done
printf '{$publica}'
exit 0
BASH);

        foreach (['composer', 'npm', 'php', 'systemctl'] as $ferramenta) {
            $this->binario($ferramenta, "#!/usr/bin/env bash\necho \"{$ferramenta} \$*\" >> \"\$ALFA_LOG\"\nexit 0\n");
        }
    }

    private function binario(string $nome, string $conteudo): void
    {
        $caminho = $this->bin.'/'.$nome;
        file_put_contents($caminho, $conteudo);
        chmod($caminho, 0755);
    }

    private function chamadas(): string
    {
        return is_file($this->log) ? file_get_contents($this->log) : '';
    }

    /** @param array<int, string> $comando */
    private function executar(array $comando, ?string $cwd = null): void
    {
        $processo = new Process($comando, $cwd);
        $processo->run();

        $this->assertSame(0, $processo->getExitCode(), implode(' ', $comando).': '.$processo->getErrorOutput());
    }

    private function tmp(string $prefixo): string
    {
        $caminho = sys_get_temp_dir().'/'.$prefixo.bin2hex(random_bytes(6));
        mkdir($caminho, 0755, true);

        return $caminho;
    }

    private function apagar(string $caminho): void
    {
        if (is_dir($caminho)) {
            (new Process(['rm', '-rf', $caminho]))->run();
        }
    }

    /**
     * @spec:AC-163 Duas execuções ao mesmo tempo não aplicam a mesma tag duas
     * vezes.
     *
     * Aconteceu ao publicar a v2026.08.10: uma execução manual e o timer das
     * :05 chamaram `migrate --force` juntas, a segunda estourou em "Table
     * already exists" e gravou o marcador — que BLOQUEIA todo deploy seguinte.
     * A produção ficou correta e travada ao mesmo tempo.
     */
    public function test_execucao_concorrente_desiste_em_silencio(): void
    {
        $this->criarFerramentas(saude: '200');

        // A trava de uma execução que ainda está de pé.
        mkdir($this->repo.'/.deploy-tag.lock');

        $processo = $this->rodar();

        $this->assertSame(0, $processo->getExitCode());
        $this->assertStringContainsString('outra execução em andamento', $processo->getOutput());
        $this->assertStringNotContainsString('php artisan migrate', $this->chamadas());
        $this->assertFileDoesNotExist($this->repo.'/.deploy-tag-failed', 'Concorrência não é falha: não pode bloquear o próximo deploy.');
    }

    /**
     * @spec:AC-163 Trava esquecida por queda no meio do caminho não bloqueia
     * para sempre — seria o mesmo modo de falha do marcador de erro.
     */
    public function test_trava_antiga_e_assumida(): void
    {
        $this->criarFerramentas(saude: '200');

        mkdir($this->repo.'/.deploy-tag.lock');
        touch($this->repo.'/.deploy-tag.lock', time() - 7200);

        $processo = $this->rodar();

        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());
        $this->assertStringContainsString('trava antiga', $processo->getOutput());
        $this->assertStringContainsString('php artisan migrate --force', $this->chamadas());
    }

    /** A trava é liberada ao sair, senão a execução seguinte se auto-bloqueia. */
    public function test_a_trava_e_liberada_ao_sair(): void
    {
        $this->criarFerramentas(saude: '200');

        $this->rodar();
        $this->assertDirectoryDoesNotExist($this->repo.'/.deploy-tag.lock');

        // E a execução seguinte roda normalmente (UPTODATE, sem tag nova).
        $segunda = $this->rodar();
        $this->assertStringContainsString('UPTODATE', $segunda->getOutput());
    }
}
