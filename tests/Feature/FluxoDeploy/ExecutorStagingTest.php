<?php

namespace Tests\Feature\FluxoDeploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Exercita o executor de staging de verdade: monta um repositório git
 * descartável, com binários falsos no PATH, e confere o que ele faz — em vez
 * de só ler o texto do script.
 */
class ExecutorStagingTest extends TestCase
{
    private string $repo;

    private string $bin;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = $this->tmp('staging-repo-');
        $this->bin = $this->tmp('staging-bin-');
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
     * @spec:AC-065 Havendo novidade na main e com a suíte passando, o executor
     * traz o código, instala dependências, compila o front-end, migra e
     * recarrega os caches.
     */
    public function test_aplica_a_novidade_quando_a_suite_passa(): void
    {
        $this->criarFerramentas(testesPassam: true);

        $processo = $this->rodar();

        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());

        $chamadas = $this->chamadas();
        foreach (['php artisan test', 'npm ci', 'npm run build', 'php artisan migrate --force'] as $esperada) {
            $this->assertStringContainsString($esperada, $chamadas, "O executor precisa rodar: {$esperada}");
        }

        // Chegou na versão nova.
        $this->assertSame($this->shaDaMain(), $this->shaAtual());
    }

    /**
     * @spec:AC-066 Com a suíte falhando, nada é aplicado: o staging volta para
     * a versão anterior e o executor termina com erro. É o que substitui, aqui,
     * o portão de CI dos outros sistemas.
     */
    public function test_portao_segura_o_deploy_quando_o_teste_falha(): void
    {
        $shaAnterior = $this->shaAtual();
        $this->criarFerramentas(testesPassam: false);

        $processo = $this->rodar();

        $this->assertNotSame(0, $processo->getExitCode(), 'Teste falhando não pode terminar em sucesso.');
        $this->assertStringContainsString('portão REPROVOU', $processo->getOutput());

        // O staging ficou onde estava.
        $this->assertSame($shaAnterior, $this->shaAtual(), 'O código novo não pode ter ficado aplicado.');

        // E nada de aplicação chegou a rodar.
        $chamadas = $this->chamadas();
        foreach (['npm run build', 'php artisan migrate --force'] as $proibida) {
            $this->assertStringNotContainsString($proibida, $chamadas, "Não pode rodar \"{$proibida}\" com o portão reprovado.");
        }
    }

    /** @spec:AC-065 Sem novidade na main, o executor não faz nada. */
    public function test_sem_novidade_nao_faz_nada(): void
    {
        $this->criarFerramentas(testesPassam: true);

        // Deixa o staging já na ponta da main. O fetch é necessário: a
        // referência local de origin/main ainda aponta para a versão antiga.
        $this->git('fetch --quiet origin main');
        $this->git('merge --ff-only origin/main');
        $this->assertSame($this->shaDaMain(), $this->shaAtual(), 'O cenário exige o staging já atualizado.');

        $processo = $this->rodar();

        $this->assertSame(0, $processo->getExitCode());
        $this->assertStringContainsString('UPTODATE', $processo->getOutput());
        $this->assertStringNotContainsString('php artisan migrate', $this->chamadas());
    }

    /**
     * @spec:AC-065 O executor não depende do PATH de quem o chama. O cron do
     * root roda com PATH=/usr/bin:/bin, onde `pct` (em /usr/sbin) não existe —
     * e o script falhava a cada 5 minutos sem ninguém notar.
     */
    public function test_nao_depende_do_path_de_quem_chama(): void
    {
        $script = file_get_contents(base_path('deploy/deploy-staging-alfamatriz.sh'));

        $this->assertMatchesRegularExpression(
            '/export PATH=.*\/usr\/sbin/',
            $script,
            'O script precisa garantir /usr/sbin no PATH: é onde mora o pct.'
        );

        // E a garantia tem de vir antes de qualquer uso de ferramenta externa.
        $posPath = strpos($script, 'export PATH=');
        $posUso = strpos($script, 'pct exec');

        $this->assertNotFalse($posPath);
        $this->assertNotFalse($posUso);
        $this->assertLessThan($posUso, $posPath, 'O PATH precisa ser definido antes do primeiro uso do pct.');
    }

    /**
     * @spec:AC-065 Com o marcador de pausa, o executor respeita e sai. */
    public function test_respeita_a_pausa(): void
    {
        $this->criarFerramentas(testesPassam: true);
        touch($this->repo.'/.deploy-paused');

        $processo = $this->rodar();

        $this->assertSame(0, $processo->getExitCode());
        $this->assertStringContainsString('PAUSADO', $processo->getOutput());
        $this->assertStringNotContainsString('php artisan test', $this->chamadas());
    }

    /**
     * @spec:AC-065 O portão roda a suíte na versão nova, mas o route/view cache
     * da aplicação vigente fica para trás e os testes carregam o cache antigo
     * (reprovando rotas/views novas). O executor limpa os caches ANTES do
     * portão — depois da aplicação, o próprio script os recria na versão nova.
     */
    public function test_limpa_route_e_view_cache_antes_do_portao(): void
    {
        $this->criarFerramentas(testesPassam: true);

        $processo = $this->rodar();

        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());

        $chamadas = $this->chamadas();

        // As duas limpezas rodaram...
        $this->assertStringContainsString('php artisan route:clear', $chamadas);
        $this->assertStringContainsString('php artisan view:clear', $chamadas);

        // ...e rodaram ANTES do portão: o cache velho não pode contaminar a suíte.
        $posLimpeza = strpos($chamadas, 'php artisan route:clear');
        $posTeste = strpos($chamadas, 'php artisan test');

        $this->assertNotFalse($posLimpeza, 'A limpeza precisa existir.');
        $this->assertNotFalse($posTeste, 'O portão precisa existir.');
        $this->assertLessThan($posTeste, $posLimpeza, 'A limpeza precisa vir antes do portão.');
    }

    /**
     * @spec:AC-065 Dois gatilhos apontam para o mesmo diretório — o cron de 5
     * em 5 minutos e o botão do painel. O segundo a chegar desiste em vez de
     * rodar `git merge` e `composer install` por cima do primeiro.
     */
    public function test_nao_roda_duas_vezes_ao_mesmo_tempo(): void
    {
        $this->criarFerramentas(testesPassam: true);
        $shaAnterior = $this->shaAtual();

        // O vizinho é a própria trava: um mkdir feito por quem chegou antes.
        mkdir($this->travaDoTeste());

        $processo = $this->rodar();

        // Desistir não é erro: para o cron, achar o vizinho rodando é o dia
        // normal. Erro seria terminar diferente de zero e pintar a unit de
        // vermelho a cada cinco minutos.
        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());
        $this->assertStringContainsString('já há um deploy em curso', $processo->getOutput());

        // E, principalmente: não encostou no repositório.
        $chamadas = $this->chamadas();
        foreach (['php artisan test', 'composer install', 'npm run build'] as $proibida) {
            $this->assertStringNotContainsString($proibida, $chamadas, "Não pode rodar \"{$proibida}\" com outro deploy em curso.");
        }
        // Comparado com o SHA de ANTES, não com origin/main: sem o fetch —
        // que ele não chegou a fazer — a referência local ainda aponta para a
        // versão velha, e a comparação passaria por acidente.
        $this->assertSame($shaAnterior, $this->shaAtual(), 'O código novo não pode ter sido aplicado.');
    }

    /**
     * @spec:AC-065 Trava esquecida por queda no meio do caminho não pode
     * bloquear todo deploy seguinte: passada uma hora, ela é assumida como
     * abandonada. É a mesma regra do vigia de tags.
     */
    public function test_trava_antiga_e_assumida(): void
    {
        $this->criarFerramentas(testesPassam: true);

        $trava = $this->travaDoTeste();
        mkdir($trava);
        touch($trava, time() - 7200);

        $processo = $this->rodar();

        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());
        $this->assertStringContainsString('trava antiga', $processo->getOutput());
        $this->assertSame($this->shaDaMain(), $this->shaAtual(), 'Trava abandonada não pode impedir o deploy.');
    }

    /** @spec:AC-065 A trava é liberada ao sair, inclusive quando o portão reprova. */
    public function test_a_trava_e_liberada_ao_sair(): void
    {
        $this->criarFerramentas(testesPassam: false);

        $this->rodar();

        $this->assertDirectoryDoesNotExist(
            $this->travaDoTeste(),
            'Portão reprovado também precisa devolver a trava — senão o próximo deploy não roda.'
        );
    }

    /**
     * @spec:AC-065 Os marcadores que o deploy grava na raiz da aplicação são
     * estado do ambiente, não código — e precisam estar ignorados pelo git.
     *
     * O painel AlfaDeploy mede divergência local com `git status --porcelain`,
     * que conta arquivo não rastreado junto com modificado. Um marcador fora
     * do .gitignore vira selo "±N adaptações locais" permanente no staging, e
     * esse selo é o que denuncia container servindo código velho.
     */
    public function test_marcadores_do_deploy_nao_sujam_o_repositorio(): void
    {
        // Escritos por deploy-staging-alfamatriz.sh e pelo vigia de tags.
        $marcadores = [
            '.deploy-gate', '.deploy-tag-state', '.deploy-tag-failed',
            '.deploy-paused', '.deploy-tag.lock',
        ];

        foreach ($marcadores as $marcador) {
            $processo = new Process(['git', 'check-ignore', '-q', $marcador], base_path());
            $processo->run();

            $this->assertSame(
                0,
                $processo->getExitCode(),
                "O marcador {$marcador} precisa estar no .gitignore: no servidor ele aparece como arquivo não rastreado."
            );
        }
    }

    // ------------------------------------------------------------- apoio

    /**
     * Onde a trava deste teste vive: dentro do repositório descartável, nunca
     * no /run do sistema — ver o comentário em `rodar()`.
     */
    private function travaDoTeste(): string
    {
        return $this->repo.'/deploy.lock.d';
    }

    private function rodar(): Process
    {
        $processo = new Process(
            ['bash', base_path('deploy/deploy-staging-alfamatriz.sh'), '--local', '--dir', $this->repo],
            $this->repo,
            [
                'PATH' => $this->bin.':'.getenv('PATH'),
                'ALFA_LOG' => $this->log,
                'LOG' => $this->repo.'/deploy.log',
                'HOME' => $this->repo,
                // Trava PRÓPRIA, obrigatória: a suíte roda dentro do portão,
                // ou seja, dentro de um deploy que já segura a trava de
                // verdade. Com o caminho padrão, todo teste daqui encontraria
                // "já há um deploy em curso", não rodaria nada, e o portão
                // reprovaria o próprio código que estava validando.
                'TRAVA' => $this->travaDoTeste(),
            ]
        );
        $processo->run();

        return $processo;
    }

    /**
     * Repositório com uma "origin/main" adiantada em um commit: é a novidade
     * que o executor precisa enxergar.
     */
    private function montarRepositorio(): void
    {
        $origem = $this->tmp('staging-origem-');

        $this->executar(['git', 'init', '--quiet', '--bare', $origem]);

        $trabalho = $this->tmp('staging-trabalho-');
        $this->executar(['git', 'clone', '--quiet', $origem, $trabalho]);
        $this->executar(['git', 'config', 'user.email', 'teste@exemplo.com'], $trabalho);
        $this->executar(['git', 'config', 'user.name', 'Teste'], $trabalho);

        file_put_contents($trabalho.'/versao.txt', 'v1');
        $this->executar(['git', 'add', '-A'], $trabalho);
        $this->executar(['git', 'commit', '--quiet', '-m', 'v1'], $trabalho);
        $this->executar(['git', 'branch', '-M', 'main'], $trabalho);
        $this->executar(['git', 'push', '--quiet', 'origin', 'main'], $trabalho);

        // `git init --bare` deixa HEAD em master; sem isso o clone abaixo vem
        // sem commit nenhum e o executor não teria o que comparar.
        $this->executar(['git', 'symbolic-ref', 'HEAD', 'refs/heads/main'], $origem);

        // O "staging" é um clone parado no v1...
        $this->executar(['git', 'clone', '--quiet', '--branch', 'main', $origem, $this->repo]);

        // ...enquanto a origem recebe o v2.
        file_put_contents($trabalho.'/versao.txt', 'v2');
        $this->executar(['git', 'commit', '--quiet', '-am', 'v2'], $trabalho);
        $this->executar(['git', 'push', '--quiet', 'origin', 'main'], $trabalho);

        $this->apagar($trabalho);
    }

    private function criarFerramentas(bool $testesPassam): void
    {
        // `php` registra a chamada; falha só no `artisan test` quando pedido.
        $php = "#!/usr/bin/env bash\n"
            ."echo \"php \$*\" >> \"\$ALFA_LOG\"\n"
            .'if [ "$1" = "artisan" ] && [ "$2" = "test" ]; then exit '.($testesPassam ? '0' : '1')."; fi\n"
            ."exit 0\n";

        $this->binario('php', $php);

        foreach (['composer', 'npm', 'systemctl', 'pct'] as $ferramenta) {
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

    private function shaAtual(): string
    {
        return trim($this->git('rev-parse HEAD'));
    }

    private function shaDaMain(): string
    {
        return trim($this->git('rev-parse origin/main'));
    }

    private function git(string $args): string
    {
        $processo = Process::fromShellCommandline('git '.$args, $this->repo);
        $processo->run();

        return $processo->getOutput();
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
}
