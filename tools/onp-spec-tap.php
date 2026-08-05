<?php

/**
 * Ponte TAP do PHPUnit para o motor onp-spec-driven.
 *
 * O motor (`.claude/skills/onp-spec-driven`) decide quais critérios de aceite
 * passaram lendo linhas TAP (`ok N - título` / `not ok N - título`). Quem
 * julga é o PHPUnit, nunca o agente — este script só traduz o resultado.
 *
 * Convenção: a tag `@spec:AC-xxx` (ou `@principle:P-xxx`) vai no **docblock**
 * do método de teste (ou da classe). Assim ela existe literalmente no arquivo
 * — o `audit` varre os arquivos de teste procurando a tag — e também entra no
 * título TAP daqui.
 *
 *     /** @spec:AC-004 Revenda sem contrato ativo não entra no faturamento. *\/
 *     public function test_revenda_sem_contrato_fica_de_fora(): void
 *
 * Uso:
 *     php tools/onp-spec-tap.php [args extras do phpunit]
 *
 * O PHPUnit roda com --log-junit num arquivo temporário; a saída normal dele
 * continua aparecendo no terminal, e as linhas TAP saem depois. O exit code é
 * o do próprio PHPUnit.
 */

$root = dirname(__DIR__);

$autoload = $root.'/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "onp-spec-tap: vendor/autoload.php não existe — rode `composer install` primeiro.\n");
    exit(1);
}
require $autoload;

$phpunit = $root.'/vendor/bin/phpunit';
if (! is_file($phpunit)) {
    fwrite(STDERR, "onp-spec-tap: vendor/bin/phpunit não existe — rode `composer install` primeiro.\n");
    exit(1);
}

$junit = tempnam(sys_get_temp_dir(), 'onp-junit-').'.xml';

$args = array_slice($argv, 1);
$cmd = array_map('escapeshellarg', array_merge(
    [PHP_BINARY, $phpunit, '--log-junit', $junit],
    $args
));

passthru(implode(' ', $cmd), $exitCode);

if (! is_file($junit) || filesize($junit) === 0) {
    fwrite(STDERR, "\nonp-spec-tap: PHPUnit não gerou o log JUnit — nenhuma linha TAP emitida.\n");
    @unlink($junit);
    exit($exitCode === 0 ? 1 : $exitCode);
}

$previous = libxml_use_internal_errors(true);
$xml = simplexml_load_file($junit);
libxml_use_internal_errors($previous);
@unlink($junit);

if ($xml === false) {
    fwrite(STDERR, "\nonp-spec-tap: log JUnit inválido — nenhuma linha TAP emitida.\n");
    exit($exitCode === 0 ? 1 : $exitCode);
}

/** @var list<array{title: string, status: string}> $results */
$results = [];

collectTestCases($xml, $results);

echo "\n# TAP (onp-spec-driven)\n";
$n = 0;
foreach ($results as $result) {
    $n++;
    $line = $result['status'] === 'failed' ? 'not ok' : 'ok';
    $suffix = $result['status'] === 'skipped' ? ' # SKIP' : '';
    echo $line.' '.$n.' - '.$result['title'].$suffix."\n";
}
echo '1..'.$n."\n";

exit($exitCode);

/**
 * Percorre <testsuite> recursivamente e acumula cada <testcase>.
 *
 * @param  list<array{title: string, status: string}>  $results
 */
function collectTestCases(SimpleXMLElement $node, array &$results): void
{
    foreach ($node->children() as $name => $child) {
        if ($name === 'testsuite') {
            collectTestCases($child, $results);

            continue;
        }

        if ($name !== 'testcase') {
            continue;
        }

        $class = (string) ($child['class'] ?? '');
        $method = (string) ($child['name'] ?? '');

        $results[] = [
            'title' => testTitle($class, $method),
            'status' => testStatus($child),
        ];
    }
}

/**
 * failed domina; skipped/incomplete não é prova; o resto passou.
 */
function testStatus(SimpleXMLElement $testcase): string
{
    foreach (['failure', 'error'] as $tag) {
        if (isset($testcase->{$tag})) {
            return 'failed';
        }
    }

    foreach (['skipped', 'warning'] as $tag) {
        if (isset($testcase->{$tag})) {
            return 'skipped';
        }
    }

    return 'passed';
}

/**
 * Título TAP: identificação do teste + as tags @spec/@principle do docblock.
 */
function testTitle(string $class, string $method): string
{
    $id = $class !== '' ? $class.'::'.$method : $method;
    $tags = tagsFor($class, $method);

    return $tags === '' ? $id : $id.' '.$tags;
}

/**
 * Lê as tags do docblock do método e, como fallback, do docblock da classe.
 * Data providers viram "test_foo with data set #0" — o sufixo é descartado.
 */
function tagsFor(string $class, string $method): string
{
    if ($class === '' || ! class_exists($class)) {
        return '';
    }

    $method = preg_replace('/\s+with data set .*$/', '', $method) ?? $method;

    $docs = [];

    try {
        $reflection = new ReflectionClass($class);
        if ($method !== '' && $reflection->hasMethod($method)) {
            $docs[] = $reflection->getMethod($method)->getDocComment();
        }
        $docs[] = $reflection->getDocComment();
    } catch (ReflectionException) {
        return '';
    }

    $tags = [];
    foreach ($docs as $doc) {
        if (! is_string($doc)) {
            continue;
        }
        preg_match_all('/@(?:spec:AC-\d{3,}|principle:P-\d{3,})/', $doc, $matches);
        foreach ($matches[0] as $tag) {
            if (! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }
    }

    return implode(' ', $tags);
}
