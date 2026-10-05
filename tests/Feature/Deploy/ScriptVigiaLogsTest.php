<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * O script do vigia de logs (`deploy/vigia-logs/enviar-erros.sh`, #219): o
 * que ele tira de um log de verdade, e quando ele envia. O `curl` e o
 * `docker` são falsos — nada sai da máquina.
 */
class ScriptVigiaLogsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/vigia-teste-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/bin', 0777, true);

        file_put_contents($this->dir.'/laravel.log', $this->logDeExemplo());
        file_put_contents($this->dir.'/.env', implode("\n", [
            'VIGIA_URL=https://alfamatriz.exemplo/api/vigia/erros',
            'VIGIA_TOKEN=7|segredo-do-teste',
            'VIGIA_AMBIENTE=producao',
            'VIGIA_ARQUIVOS="'.$this->dir.'/laravel*.log"',
            'VIGIA_ESTADO='.$this->dir.'/estado',
            'VIGIA_FUSO=-03:00',
            'VIGIA_ORIGEM=vps-teste',
        ])."\n");

        $this->criarCurlFalso();
        $this->criarDockerFalso();
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();

        parent::tearDown();
    }

    private function logDeExemplo(): string
    {
        return <<<'LOG'
[2026-10-03 13:58:01] production.INFO: Check-in recebido {"aluno":42}
[2026-10-03 14:00:01] production.ERROR: Falha ao validar check-in Wellhub {"exception":"[object] (App\\Exceptions\\WellhubException(code: 0): No EntityManager with actual transaction available at /var/www/alfagym/app/Services/WellhubService.php:87)
[stacktrace]
#0 /var/www/alfagym/app/Http/Controllers/CheckinController.php(45): App\\Services\\WellhubService->validar('8812')
#1 /var/www/alfagym/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\\Http\\Controllers\\CheckinController->store()
#2 {main}
"}
[2026-10-03 14:05:12] production.WARNING: Falha ao validar check-in Wellhub do aluno 77 {"exception":"[object] (RuntimeException(code: 0): Tempo esgotado at /var/www/alfagym/app/Services/WellhubService.php:90)
[stacktrace]
#0 {main}
"}
[2026-10-03 14:06:00] production.WARNING: Cache demorou 3s para responder
[2026-10-03 14:07:00] production.INFO: Fim

LOG;
    }

    private function rodar(array $argumentos = [], array $env = []): Process
    {
        $processo = new Process(
            array_merge(['bash', dirname(__DIR__, 3).'/deploy/vigia-logs/enviar-erros.sh', '--config', $this->dir.'/.env'], $argumentos),
            $this->dir,
            array_merge([
                'PATH' => $this->dir.'/bin:'.getenv('PATH'),
                'DIR_TESTE' => $this->dir,
            ], $env),
        );
        $processo->run();

        return $processo;
    }

    /** @return list<array<string, mixed>> os lotes impressos */
    private function lotes(Process $processo): array
    {
        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput().$processo->getOutput());

        return array_map(
            fn (string $linha) => json_decode($linha, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $processo->getOutput()))),
        );
    }

    /** @return list<array<string, mixed>> os corpos que o curl falso recebeu */
    private function enviados(): array
    {
        return array_map(
            fn (string $arquivo) => json_decode(file_get_contents($arquivo), true, 512, JSON_THROW_ON_ERROR),
            glob($this->dir.'/enviado-*.json') ?: [],
        );
    }

    public function test_imprimir_monta_o_json_com_o_error_e_o_warn_com_excecao(): void
    {
        $lotes = $this->lotes($this->rodar(['--imprimir', '--desde-o-inicio']));

        $this->assertCount(1, $lotes);
        $this->assertSame('producao', $lotes[0]['ambiente']);
        $this->assertSame('vps-teste:'.$this->dir.'/laravel.log', $lotes[0]['origem']);

        $erros = $lotes[0]['erros'];
        $this->assertCount(2, $erros, 'INFO e WARN sem exceção ficam de fora.');

        [$erro, $aviso] = $erros;

        $this->assertSame('2026-10-03T14:00:01-03:00', $erro['quando']);
        $this->assertSame('ERROR', $erro['nivel']);
        $this->assertSame('Falha ao validar check-in Wellhub', $erro['mensagem']);
        $this->assertSame('App\\Exceptions\\WellhubException', $erro['excecao']);

        $linhas = explode("\n", $erro['trecho']);
        $this->assertStringStartsWith('App\\Exceptions\\WellhubException(code: 0): No EntityManager', $linhas[0]);
        $this->assertSame("#0 /var/www/alfagym/app/Http/Controllers/CheckinController.php(45): App\\Services\\WellhubService->validar('8812')", $linhas[1]);
        $this->assertSame('#2 {main}', end($linhas));

        $this->assertSame('WARNING', $aviso['nivel']);
        $this->assertSame('Falha ao validar check-in Wellhub do aluno 77', $aviso['mensagem']);
        $this->assertSame('RuntimeException', $aviso['excecao']);

        $this->assertSame([], glob($this->dir.'/estado/arquivo*') ?: [], '--imprimir não anda o estado.');
    }

    public function test_docker_le_o_spring_boot_com_o_carimbo_do_docker(): void
    {
        file_put_contents($this->dir.'/.env', "VIGIA_CONTAINERS=alfagym-backend\nVIGIA_ESTADO={$this->dir}/estado\nVIGIA_ORIGEM=vps-teste\n");

        $lotes = $this->lotes($this->rodar(['--imprimir', '--desde-o-inicio']));

        $this->assertSame('vps-teste:docker:alfagym-backend', $lotes[0]['origem']);
        $erros = $lotes[0]['erros'];
        $this->assertCount(2, $erros);

        $this->assertSame('2026-10-03T17:00:01Z', $erros[0]['quando']);
        $this->assertSame('WARN', $erros[0]['nivel']);
        $this->assertSame('Falha ao validar check-in Wellhub', $erros[0]['mensagem']);
        $this->assertSame('org.springframework.dao.InvalidDataAccessApiUsageException', $erros[0]['excecao']);
        $this->assertStringContainsString('at br.com.alfa.alfagym.wellhub.WellhubService.validar(WellhubService.java:88)', $erros[0]['trecho']);

        $this->assertSame('ERROR', $erros[1]['nivel']);
        $this->assertNull($erros[1]['trecho']);
    }

    /**
     * O log do Spring em JSON (AlfaGym, achado na instalação da T-220): o
     * extrator só conhecia texto, e lia zero erros de um log com mais de mil.
     */
    public function test_docker_le_o_log_do_spring_em_json(): void
    {
        file_put_contents($this->dir.'/.env', "VIGIA_CONTAINERS=alfagym-json\nVIGIA_ESTADO={$this->dir}/estado\nVIGIA_ORIGEM=vps-teste\n");

        $erros = $this->lotes($this->rodar(['--imprimir', '--desde-o-inicio']))[0]['erros'];

        // INFO e WARN sem stack ficam de fora; ERROR sempre; WARN com stack_trace entra.
        $this->assertCount(2, $erros);

        $this->assertSame('ERROR', $erros[0]['nivel']);
        $this->assertSame('2026-10-02T14:10:26.854-03:00', $erros[0]['quando']);
        $this->assertSame('Falha ao validar check-in Wellhub 652 em background', $erros[0]['mensagem']);
        $this->assertSame('org.springframework.dao.InvalidDataAccessApiUsageException', $erros[0]['excecao']);
        $this->assertStringContainsString('SharedEntityManagerCreator.invoke', $erros[0]['trecho']);

        $this->assertSame('WARN', $erros[1]['nivel']);
        $this->assertSame('Aviso com "aspas" e stack', $erros[1]['mensagem']);
        $this->assertSame('java.lang.IllegalStateException', $erros[1]['excecao']);
    }

    /**
     * WARN sem stack só entra quando a MENSAGEM cita uma exceção. No Spring a
     * linha traz a classe que gravou, e a `GlobalExceptionHandler` do
     * AlfaControl (T-226) fazia todo aviso dela passar por exceção.
     */
    public function test_warn_nao_vira_excecao_pelo_nome_da_classe_que_grava(): void
    {
        file_put_contents($this->dir.'/.env', "VIGIA_CONTAINERS=alfacontrol-handler\nVIGIA_ESTADO={$this->dir}/estado\nVIGIA_ORIGEM=vps-teste\n");

        $erros = $this->lotes($this->rodar(['--imprimir', '--desde-o-inicio']))[0]['erros'];

        $this->assertCount(1, $erros);
        $this->assertSame('Falha ao ler: java.net.SocketTimeoutException: Read timed out', $erros[0]['mensagem']);
    }

    public function test_primeira_rodada_nao_manda_o_passado_e_depois_so_o_novo(): void
    {
        $primeira = $this->rodar();
        $this->assertSame(0, $primeira->getExitCode(), $primeira->getErrorOutput());
        $this->assertSame([], $this->enviados(), 'A primeira rodada só marca o ponto de partida.');

        file_put_contents($this->dir.'/laravel.log',
            "[2026-10-03 15:00:00] production.ERROR: Boleto 123 duplicado\n#0 /var/www/app/Services/Boleto.php(10): x()\n",
            FILE_APPEND);

        $segunda = $this->rodar();
        $this->assertSame(0, $segunda->getExitCode(), $segunda->getErrorOutput());

        $enviados = $this->enviados();
        $this->assertCount(1, $enviados);
        $this->assertCount(1, $enviados[0]['erros']);
        $this->assertSame('Boleto 123 duplicado', $enviados[0]['erros'][0]['mensagem']);

        // O token vai em arquivo de cabeçalho, não na linha de comando.
        $chamadas = file_get_contents($this->dir.'/curl.log');
        $this->assertStringNotContainsString('segredo-do-teste', $chamadas);
        $this->assertStringContainsString('Authorization: Bearer 7|segredo-do-teste', file_get_contents($this->dir.'/cabecalho-recebido'));

        // Nada novo: não chama o curl.
        $this->rodar();
        $this->assertCount(1, $this->enviados());
    }

    public function test_falha_no_envio_nao_anda_o_estado(): void
    {
        $this->rodar();
        file_put_contents($this->dir.'/laravel.log', "[2026-10-03 15:00:00] production.ERROR: Caiu\n", FILE_APPEND);

        $falha = $this->rodar([], ['CODIGO_CURL' => '503']);
        $this->assertNotSame(0, $falha->getExitCode());

        $this->rodar();
        $enviados = $this->enviados();
        $this->assertCount(1, $enviados, 'O mesmo trecho vai de novo na rodada seguinte.');
        $this->assertSame('Caiu', $enviados[0]['erros'][0]['mensagem']);
    }

    public function test_linha_pela_metade_fica_para_a_proxima_rodada(): void
    {
        $this->rodar();
        file_put_contents($this->dir.'/laravel.log', '[2026-10-03 15:00:00] production.ERROR: Escrevendo ag', FILE_APPEND);

        $this->rodar();
        $this->assertSame([], $this->enviados());

        file_put_contents($this->dir.'/laravel.log', "ora\n", FILE_APPEND);
        $this->rodar();

        $this->assertSame('Escrevendo agora', $this->enviados()[0]['erros'][0]['mensagem']);
    }

    private function criarCurlFalso(): void
    {
        $this->escreverExecutavel('curl', <<<'SH'
#!/usr/bin/env bash
echo "curl $*" >> "$DIR_TESTE/curl.log"
saida=""
while [ $# -gt 0 ]; do
    case "$1" in
        -o) shift; saida="$1" ;;
        -H) shift; case "$1" in @*) cp "${1#@}" "$DIR_TESTE/cabecalho-recebido" ;; esac ;;
        --data-binary) shift; n=$(ls "$DIR_TESTE"/enviado-*.json 2>/dev/null | wc -l | tr -d ' ')
            codigo="${CODIGO_CURL:-200}"
            if [ "$codigo" = "200" ]; then cp "${1#@}" "$DIR_TESTE/enviado-$((n + 1)).json"; fi ;;
    esac
    shift
done
[ -n "$saida" ] && echo '{"message":"ok"}' > "$saida"
printf '%s' "${CODIGO_CURL:-200}"
SH);
    }

    private function criarDockerFalso(): void
    {
        $this->escreverExecutavel('docker', <<<'SH'
#!/usr/bin/env bash
# Container com "json" no nome: o log do Spring em JSON, no formato real do
# backend do AlfaGym (T-220) — uma linha por evento, stack em texto em seguida.
case "$*" in *json*)
cat <<'LOG'
2026-10-02T17:10:20.000000000Z {"timestamp":"2026-10-02T14:10:20.000-03:00","level":"INFO","thread":"main","logger":"c.a.App","message":"Started"}
2026-10-02T17:10:26.855000000Z {"timestamp":"2026-10-02T14:10:26.854-03:00","level":"ERROR","thread":"task-80","logger":"c.a.p.service.WellhubAcessoService","message":"Falha ao validar check-in Wellhub 652 em background"}
2026-10-02T17:10:26.856000000Z org.springframework.dao.InvalidDataAccessApiUsageException: No EntityManager with actual transaction available for current thread
2026-10-02T17:10:26.857000000Z 	at org.springframework.orm.jpa.SharedEntityManagerCreator.invoke(SharedEntityManagerCreator.java:303)
2026-10-02T17:10:27.000000000Z {"timestamp":"2026-10-02T14:10:27.000-03:00","level":"WARN","thread":"main","logger":"c.a.Cache","message":"Lento"}
2026-10-02T17:10:28.000000000Z {"timestamp":"2026-10-02T14:10:28.000-03:00","level":"WARN","thread":"main","logger":"c.a.X","message":"Aviso com \"aspas\" e stack","stack_trace":"java.lang.IllegalStateException: estado ruim\n\tat c.a.X.y(X.java:10)"}
LOG
exit 0 ;;
*handler*)
# O AlfaControl (T-226): a classe que grava o log tem "Exception" no nome.
cat <<'LOG'
2026-10-05T16:56:58.708000000Z 2026-10-05T13:56:58.708-03:00  WARN 1 --- [nio-8080-exec-4] c.a.common.GlobalExceptionHandler        : Acesso negado: Access Denied
2026-10-05T16:56:59.000000000Z 2026-10-05T13:56:59.000-03:00  WARN 1 --- [nio-8080-exec-5] c.a.common.GlobalExceptionHandler        : ResponseStatusException [404]: Pessoa não encontrada
2026-10-05T16:57:00.000000000Z 2026-10-05T13:57:00.000-03:00  WARN 1 --- [nio-8080-exec-6] c.a.cliente.LeitorDeCliente              : Falha ao ler: java.net.SocketTimeoutException: Read timed out
LOG
exit 0 ;;
esac
cat <<'LOG'
2026-10-03T17:00:00.123456789Z 2026-10-03T14:00:00.120-03:00  INFO 1 --- [alfagym] [main] b.c.a.App : Started
2026-10-03T17:00:01.000000001Z 2026-10-03T14:00:01.000-03:00  WARN 1 --- [alfagym] [nio-8080-exec-1] b.c.a.wellhub.WellhubService : Falha ao validar check-in Wellhub
2026-10-03T17:00:01.000000002Z org.springframework.dao.InvalidDataAccessApiUsageException: No EntityManager with actual transaction available for current thread
2026-10-03T17:00:01.000000003Z 	at org.springframework.orm.jpa.SharedEntityManagerCreator.invoke(SharedEntityManagerCreator.java:303)
2026-10-03T17:00:01.000000004Z 	at br.com.alfa.alfagym.wellhub.WellhubService.validar(WellhubService.java:88)
2026-10-03T17:00:02.000000000Z 2026-10-03T14:00:02.000-03:00  WARN 1 --- [alfagym] [main] b.c.a.Cache : Lento
2026-10-03T17:00:03.000000000Z 2026-10-03T14:00:03.000-03:00 ERROR 1 --- [alfagym] [main] b.c.a.X : Deu ruim sem stack
LOG
SH);
    }

    private function escreverExecutavel(string $nome, string $conteudo): void
    {
        file_put_contents($this->dir.'/bin/'.$nome, $conteudo);
        chmod($this->dir.'/bin/'.$nome, 0755);
    }
}
