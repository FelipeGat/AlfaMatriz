<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Exercita o publicar.sh de verdade, num repositório git descartável com
 * binários falsos — inclusive o `curl` das duas checagens de saúde.
 *
 * O git é REAL: são as cópias de trabalho (git worktree) e a troca de symlink
 * que estão sendo testadas, e falsificá-las testaria o falso.
 */
class ScriptPublicarTest extends TestCase
{
    private string $instalacao;

    private string $bin;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instalacao = $this->tmp('publicar-app-');
        $this->bin = $this->tmp('publicar-bin-');
        $this->log = $this->instalacao.'/chamadas.log';

        $this->montarInstalacao();
        $this->criarFerramentas();
    }

    protected function tearDown(): void
    {
        $this->apagar($this->instalacao);
        $this->apagar($this->bin);

        parent::tearDown();
    }

    /**
     * @spec:AC-167 A versão nova é construída na cópia que NÃO está no ar.
     *
     * Até esta entrega, `git checkout`, `composer install` e `npm run build`
     * rodavam dentro do diretório que o nginx estava servindo: por ~2 minutos
     * o sistema misturava código velho e novo, com o `vendor` sendo reescrito
     * por baixo de quem estava usando.
     *
     * O teste não confere só onde os comandos rodaram: cada ferramenta falsa
     * registra também para onde o symlink `atual` apontava naquele instante. É
     * isso que prova que o que estava publicado não foi tocado enquanto a
     * versão nova era montada.
     */
    public function test_a_versao_nova_e_construida_fora_do_ar(): void
    {
        // Primeira publicação: deixa a instalação com uma versão no ar.
        $this->assertSame(0, $this->publicar()->getExitCode());

        $noArAntes = $this->paraOndeAponta('atual');
        $reserva = $this->paraOndeAponta('preparo');

        $this->limparChamadas();
        $processo = $this->publicar();
        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());

        foreach (['composer install', 'npm ci', 'npm run build', 'php artisan migrate'] as $etapa) {
            $chamada = $this->primeiraChamada($etapa);

            $this->assertNotNull($chamada, "etapa esperada não rodou: {$etapa}");
            $this->assertSame(
                $reserva,
                $chamada['pwd'],
                "\"{$etapa}\" precisa rodar na cópia de reserva, não na que está no ar."
            );
            $this->assertSame(
                $noArAntes,
                $chamada['atual'],
                "Enquanto \"{$etapa}\" roda, quem está no ar tem de continuar sendo a versão anterior."
            );
        }

        // E, no fim, a troca aconteceu.
        $this->assertSame($reserva, $this->paraOndeAponta('atual'));
        $this->assertSame($noArAntes, $this->paraOndeAponta('preparo'));
    }

    /**
     * @spec:AC-167 Publicar duas vezes alterna entre as duas cópias — é o que
     * mantém a versão anterior sempre inteira no disco, pronta para voltar.
     */
    public function test_publicacoes_seguidas_alternam_entre_as_duas_copias(): void
    {
        $this->publicar();
        $primeira = $this->paraOndeAponta('atual');

        $this->publicar();
        $segunda = $this->paraOndeAponta('atual');

        $this->publicar();
        $terceira = $this->paraOndeAponta('atual');

        $this->assertNotSame($primeira, $segunda, 'A segunda publicação tem de ir para a outra cópia.');
        $this->assertSame($primeira, $terceira, 'A terceira volta para a primeira cópia.');
    }

    /**
     * @spec:AC-168 A troca só acontece depois de a versão preparada responder
     * saudável na porta de ensaio — a porta que só o próprio servidor alcança.
     *
     * É o equivalente ao que o AlfaControl faz perguntando /actuator/health ao
     * container alvo antes de mexer no upstream do nginx.
     */
    public function test_versao_que_reprova_no_ensaio_nao_entra_no_ar(): void
    {
        $this->publicar();
        $noAr = $this->paraOndeAponta('atual');

        $this->limparChamadas();
        $processo = $this->publicar(['SAUDE_ENSAIO' => '503']);

        $this->assertSame(1, $processo->getExitCode(), 'Reprovar no ensaio precisa terminar com erro.');
        $this->assertSame($noAr, $this->paraOndeAponta('atual'), 'O que estava no ar não pode ter mudado.');
        $this->assertStringContainsString('não passou no ensaio', $processo->getErrorOutput());
    }

    /**
     * @spec:AC-169 Etapa que falha para a publicação sem tocar no que está
     * publicado, e diz qual etapa foi.
     */
    public function test_etapa_que_falha_para_tudo_e_nao_troca_a_versao(): void
    {
        $this->publicar();
        $noAr = $this->paraOndeAponta('atual');

        $this->limparChamadas();
        $processo = $this->publicar(['FALHAR_EM' => 'composer']);

        $this->assertSame(1, $processo->getExitCode());
        $this->assertStringContainsString('dependências PHP', $processo->getErrorOutput());
        $this->assertSame($noAr, $this->paraOndeAponta('atual'));

        // E as etapas seguintes nem chegaram a rodar.
        $this->assertNull($this->primeiraChamada('npm ci'));
        $this->assertNull($this->primeiraChamada('php artisan migrate'));
    }

    /**
     * @spec:AC-170 Saúde reprovada DEPOIS da troca desfaz a troca: a versão
     * anterior volta ao ar por onde saiu, inteira, e o comando sai 2 — o
     * código que diz ao vigia "subiu, quebrou e já voltou".
     */
    public function test_saude_ruim_depois_da_troca_desfaz_a_troca(): void
    {
        $this->publicar();
        $noArAntes = $this->paraOndeAponta('atual');

        $this->limparChamadas();
        $processo = $this->publicar(['SAUDE_PUBLICA' => '503']);

        $this->assertSame(2, $processo->getExitCode(), 'Trocar e voltar tem código próprio, para o vigia distinguir.');
        $this->assertSame(
            $noArAntes,
            $this->paraOndeAponta('atual'),
            'A versão anterior tem de estar de volta no ar.'
        );
        $this->assertStringContainsString('desfazendo', $processo->getErrorOutput());

        // A ressalva precisa aparecer: o banco não volta junto.
        $this->assertStringContainsString('migrações', $processo->getErrorOutput());
    }

    /**
     * @spec:AC-175 O portão (a suíte, no staging) roda DENTRO da cópia em
     * preparo e depois das dependências. Reprovando, nada é publicado.
     *
     * Antes desta entrega o portão rodava depois de o código novo já ter sido
     * mesclado no diretório que estava NO AR: durante toda a rodada da suíte o
     * staging servia código não verificado, e reprovar exigia um `git reset
     * --hard` em cima do site vivo.
     */
    public function test_portao_reprovado_nao_publica_nada(): void
    {
        $this->publicar();
        $noAr = $this->paraOndeAponta('atual');
        $reserva = $this->paraOndeAponta('preparo');

        $this->limparChamadas();
        $processo = $this->publicar(portao: 'php artisan test', envExtra: ['FALHAR_EM' => 'php artisan test']);

        $this->assertSame(1, $processo->getExitCode());
        $this->assertStringContainsString('portão REPROVOU', $processo->getErrorOutput());
        $this->assertSame($noAr, $this->paraOndeAponta('atual'));

        $portao = $this->primeiraChamada('php artisan test');
        $this->assertNotNull($portao, 'O portão precisa ter rodado.');
        $this->assertSame($reserva, $portao['pwd'], 'O portão roda na cópia em preparo.');

        // As dependências vêm antes do portão COM as de desenvolvimento: a
        // suíte vive nelas, e com `--no-dev` o `php artisan test` nem existe.
        $instalacao = $this->primeiraChamada('composer install');
        $this->assertNotNull($instalacao);
        $this->assertStringNotContainsString(
            '--no-dev',
            $instalacao['comando'],
            'Com --no-dev a suíte do portão não estaria instalada para rodar.'
        );

        // E nada depois do portão reprovado.
        $this->assertNull($this->primeiraChamada('npm ci'));
        $this->assertNull($this->primeiraChamada('php artisan migrate'));
    }

    /**
     * @spec:AC-175 Passando o portão, a cópia volta ao estado de produção
     * antes de ir para o ar: o que é publicado não pode carregar pacote de
     * desenvolvimento junto só porque a suíte precisou deles para rodar.
     */
    public function test_portao_aprovado_publica_sem_as_dependencias_de_desenvolvimento(): void
    {
        $processo = $this->publicar(portao: 'php artisan test');
        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());

        $chamadas = file_get_contents($this->log);

        $comDev = strpos($chamadas, 'composer install --no-interaction');
        $semDev = strpos($chamadas, 'composer install --no-dev');
        $portao = strpos($chamadas, 'php artisan test');

        $this->assertNotFalse($comDev, 'O portão precisa das dependências de desenvolvimento.');
        $this->assertNotFalse($semDev, 'A cópia publicada precisa ficar sem elas.');
        $this->assertLessThan($portao, $comDev);
        $this->assertGreaterThan($portao, $semDev, 'A limpeza vem depois do portão, senão a suíte não roda.');
    }

    /**
     * @spec:AC-173 O `.env` é da instalação, não da versão: cada cópia só
     * aponta para o arquivo compartilhado. Sem isto, publicar exigiria copiar
     * segredo de pasta em pasta a cada troca — e uma cópia ficaria para trás no
     * dia em que uma senha mudasse.
     */
    public function test_cada_copia_aponta_para_o_env_compartilhado(): void
    {
        mkdir($this->instalacao.'/compartilhado', 0755, true);
        file_put_contents($this->instalacao.'/compartilhado/.env', "APP_ENV=production\n");

        $this->publicar();

        $env = $this->paraOndeAponta('atual').'/.env';

        $this->assertTrue(is_link($env), 'O .env da cópia precisa ser um link para o compartilhado.');
        $this->assertSame($this->instalacao.'/compartilhado/.env', readlink($env));
    }

    /**
     * @spec:AC-174 Publicar num servidor ainda no formato antigo recusa e
     * manda converter.
     *
     * Improvisar o layout aqui seria pior que falhar: o Nginx continuaria
     * servindo a raiz enquanto a publicação trocaria symlinks que ninguém lê.
     * O deploy passaria, a saúde responderia 200 — e a versão nova não estaria
     * no ar, que é a forma mais cara de descobrir um problema.
     */
    public function test_publicar_recusa_instalacao_no_formato_antigo(): void
    {
        mkdir($this->instalacao.'/vendor', 0755, true);

        $processo = $this->publicar();

        $this->assertSame(1, $processo->getExitCode());
        $this->assertStringContainsString('formato antigo', $processo->getErrorOutput());
        $this->assertStringContainsString('converter-para-azul-verde.sh', $processo->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->instalacao.'/versoes');
    }

    /**
     * @spec:AC-176 O vigia de produção e o executor de staging chamam o mesmo
     * script. Enquanto cada um tinha a sua cópia das etapas, elas derivaram: a
     * carga de referência foi acrescentada num e faltou no outro, e recurso
     * novo nasceu invisível em produção.
     */
    public function test_vigia_e_staging_publicam_pelo_mesmo_script(): void
    {
        foreach ([
            'deploy/deploy-tag-watcher-alfamatriz.sh',
            'deploy/deploy-staging-alfamatriz.sh',
        ] as $script) {
            $fonte = file_get_contents(base_path($script));

            $this->assertStringContainsString(
                'publicar.sh',
                $fonte,
                "{$script} precisa publicar pelo motor comum, não por uma cópia própria das etapas."
            );

            foreach (['composer install', 'npm run build', 'artisan migrate'] as $etapa) {
                foreach (preg_split('/\R/', $fonte) as $numero => $linha) {
                    if (preg_match('/^\s*#/', $linha)) {
                        continue;
                    }

                    $this->assertStringNotContainsString(
                        $etapa,
                        $linha,
                        "{$script}:".($numero + 1)." tem a sua própria cópia de \"{$etapa}\" — é assim que as duas esteiras divergem."
                    );
                }
            }
        }
    }

    // ------------------------------------------------------------- apoio

    private function publicar(array $envExtra = [], ?string $portao = null): Process
    {
        $comando = ['bash', base_path('deploy/publicar.sh'), '--dir', $this->instalacao, '--ref', 'v1.0.0'];

        if ($portao !== null) {
            $comando[] = '--portao';
            $comando[] = $portao;
        }

        $comando[] = '--url-publica';
        $comando[] = 'http://publico.invalido/healthz';

        $processo = new Process($comando, $this->instalacao, array_merge([
            'PATH' => $this->bin.':'.getenv('PATH'),
            'ALFA_LOG' => $this->log,
            'ALFA_DIR' => $this->instalacao,
            'HOME' => $this->instalacao,
            // Sem espera entre as tentativas: os cenários de falha
            // custariam 20 segundos cada um.
            'TENTATIVAS_SAUDE' => '2',
            'ESPERA_SAUDE' => '0',
        ], $envExtra));
        $processo->run();

        return $processo;
    }

    private function montarInstalacao(): void
    {
        $this->executar(['git', 'init', '--quiet', $this->instalacao]);
        $this->executar(['git', 'config', 'user.email', 'teste@exemplo.com'], $this->instalacao);
        $this->executar(['git', 'config', 'user.name', 'Teste'], $this->instalacao);

        mkdir($this->instalacao.'/public', 0755, true);
        file_put_contents($this->instalacao.'/versao.txt', "v1\n");

        $this->executar(['git', 'add', '-A'], $this->instalacao);
        $this->executar(['git', 'commit', '--quiet', '-m', 'inicial'], $this->instalacao);
        $this->executar(['git', 'tag', 'v1.0.0'], $this->instalacao);
    }

    /**
     * Cada ferramenta falsa registra o que foi chamada, de onde, e para onde o
     * symlink `atual` apontava naquele instante — é essa terceira informação
     * que permite provar que o que está no ar não é tocado durante o preparo.
     */
    private function criarFerramentas(): void
    {
        foreach (['composer', 'npm', 'php', 'sudo', 'systemctl'] as $ferramenta) {
            $this->binario($ferramenta, <<<'BASH'
#!/usr/bin/env bash
echo "NOME $* | pwd=$(pwd) | atual=$(readlink "$ALFA_DIR/atual")" >> "$ALFA_LOG"
if [[ -n "${FALHAR_EM:-}" ]] && [[ "NOME $*" == "${FALHAR_EM}"* ]]; then
    echo "NOME: falha simulada para teste" >&2
    exit 1
fi
exit 0
BASH);
        }

        // Duas saúdes independentes: a do ensaio (127.0.0.1, antes da troca) e
        // a do endereço público (depois da troca). É a diferença entre elas
        // que separa "não entrou" de "entrou, quebrou e voltou".
        $this->binario('curl', <<<'BASH'
#!/usr/bin/env bash
echo "curl $* | pwd=$(pwd) | atual=$(readlink "$ALFA_DIR/atual")" >> "$ALFA_LOG"
for arg in "$@"; do
    case "$arg" in
        *127.0.0.1*) printf '%s' "${SAUDE_ENSAIO:-200}"; exit 0 ;;
    esac
done
printf '%s' "${SAUDE_PUBLICA:-200}"
BASH);
    }

    private function binario(string $nome, string $conteudo): void
    {
        $caminho = $this->bin.'/'.$nome;
        file_put_contents($caminho, str_replace('NOME', $nome, $conteudo));
        chmod($caminho, 0755);
    }

    /** @return array{comando: string, pwd: string, atual: string}|null */
    private function primeiraChamada(string $prefixo): ?array
    {
        if (! is_file($this->log)) {
            return null;
        }

        foreach (preg_split('/\R/', file_get_contents($this->log)) as $linha) {
            if (! str_starts_with($linha, $prefixo)) {
                continue;
            }

            $partes = array_map('trim', explode('|', $linha));

            return [
                'comando' => $partes[0],
                'pwd' => $this->semPrefixo($partes[1] ?? '', 'pwd='),
                'atual' => $this->semPrefixo($partes[2] ?? '', 'atual='),
            ];
        }

        return null;
    }

    private function semPrefixo(string $texto, string $prefixo): string
    {
        return str_starts_with($texto, $prefixo) ? substr($texto, strlen($prefixo)) : $texto;
    }

    private function paraOndeAponta(string $link): string
    {
        return (string) readlink($this->instalacao.'/'.$link);
    }

    private function limparChamadas(): void
    {
        file_put_contents($this->log, '');
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

        // O macOS entrega /var como symlink para /private/var, e os caminhos
        // que o script devolve (readlink, pwd) já vêm resolvidos. Sem resolver
        // aqui, as comparações do teste falhariam por um prefixo.
        return realpath($caminho) ?: $caminho;
    }

    private function apagar(string $caminho): void
    {
        if (is_dir($caminho)) {
            (new Process(['rm', '-rf', $caminho]))->run();
        }
    }
}
