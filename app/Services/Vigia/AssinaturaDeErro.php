<?php

namespace App\Services\Vigia;

/**
 * O que faz duas linhas de log serem o MESMO erro (#219).
 *
 * "Pedido 4812 não encontrado" e "Pedido 977 não encontrado" são um erro só
 * acontecendo com dados diferentes. Sem normalizar, cada número viraria uma
 * assinatura — e uma tarefa —, e o vigia afogaria o quadro no primeiro dia.
 * Normalizar demais tem o defeito oposto: "Coluna 'email' não existe" e
 * "Coluna 'cpf' não existe" são dois defeitos, e juntá-los esconderia um.
 *
 * Por isso só sai o que MUDA entre ocorrências do mesmo erro: números, UUIDs,
 * e-mails, hashes, datas e o que vem entre aspas QUANDO tem dígito (o id de
 * alguém, não o nome de uma coluna). A assinatura soma a classe da exceção e
 * o primeiro quadro do stack que é código DA APLICAÇÃO — a mesma frase vinda
 * de dois lugares do código são dois consertos.
 *
 * O número da linha sai do quadro de propósito: senão qualquer publicação que
 * empurrasse o arquivo uma linha para baixo faria o erro velho parecer novo.
 */
class AssinaturaDeErro
{
    /**
     * Prefixos de pacote que NÃO são código da aplicação, no stack Java (o
     * Spring Boot do AlfaGym). O primeiro quadro fora deles é o nosso.
     */
    private const PACOTES_DE_FORA = [
        'java.', 'javax.', 'jakarta.', 'jdk.', 'sun.', 'com.sun.',
        'org.springframework.', 'org.hibernate.', 'org.apache.', 'org.postgresql.',
        'com.fasterxml.', 'com.zaxxer.', 'io.netty.', 'reactor.', 'kotlin.',
        'org.jboss.', 'io.micrometer.', 'ch.qos.', 'org.slf4j.', 'com.mysql.',
        'org.eclipse.', 'feign.', 'okhttp3.', 'io.github.resilience4j.',
    ];

    public readonly string $padrao;

    public readonly ?string $excecao;

    public readonly ?string $quadro;

    public readonly string $hash;

    public function __construct(string $mensagem, ?string $excecao = null, ?string $trecho = null)
    {
        $this->padrao = mb_substr(self::normalizar($mensagem), 0, 500);
        $this->excecao = filled($excecao) ? ltrim(trim($excecao), '\\') : null;
        $this->quadro = self::primeiroQuadroDaAplicacao($trecho);

        $this->hash = hash('sha256', implode("\n", [$this->excecao ?? '', $this->padrao, $this->quadro ?? '']));
    }

    /** A mensagem com o que varia entre ocorrências trocado por marcadores. */
    public static function normalizar(string $texto): string
    {
        $trocas = [
            // A ordem importa: o UUID e a data têm dígitos, e o marcador de
            // número os picotaria em pedaços antes de serem reconhecidos.
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '{uuid}',
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '{email}',
            '/\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+\-]\d{2}:?\d{2})?)?/' => '{data}',
            '/\b\d{2}\/\d{2}\/\d{4}\b/' => '{data}',
            '/\b\d{2}:\d{2}:\d{2}(\.\d+)?\b/' => '{hora}',
            // Hash: hexadecimal longo COM pelo menos um dígito — "deadbeefcafe"
            // sem dígito é palavra, e palavra fica.
            '/\b(0x)?(?=[0-9a-f]*\d)[0-9a-f]{16,}\b/i' => '{hash}',
            // Entre aspas, só o que tem dígito: o id de alguém, não o nome de
            // uma coluna ou de uma rota.
            '/\'[^\'\s]*\d[^\'\s]*\'/' => "'{valor}'",
            '/"[^"\s]*\d[^"\s]*"/' => '"{valor}"',
            '/\d+/' => '{n}',
            '/\s+/' => ' ',
        ];

        return trim(preg_replace(array_keys($trocas), array_values($trocas), $texto) ?? $texto);
    }

    /**
     * O primeiro quadro do stack que pertence à aplicação, já sem número.
     *
     * PHP: o caminho que passa por `/app/` e não por `/vendor/`. Java: a linha
     * `at pacote.Classe.metodo(...)` fora dos pacotes de bibliotecas. Sem
     * nenhum dos dois, nulo — e a assinatura fica só com classe e mensagem.
     */
    public static function primeiroQuadroDaAplicacao(?string $trecho): ?string
    {
        foreach (preg_split('/\R/', (string) $trecho) ?: [] as $linha) {
            $linha = trim($linha);

            if (preg_match('#(/app/[^\s:()]+\.php)#', $linha, $m) && ! str_contains($linha, '/vendor/')) {
                return preg_replace('#^.*?/app/#', 'app/', $m[1]);
            }

            if (preg_match('/^at\s+([\w$.]+)/', $linha, $m) && ! self::pacoteDeFora($m[1])) {
                return self::normalizar($m[1]);
            }
        }

        return null;
    }

    private static function pacoteDeFora(string $quadro): bool
    {
        foreach (self::PACOTES_DE_FORA as $prefixo) {
            if (str_starts_with($quadro, $prefixo)) {
                return true;
            }
        }

        return false;
    }
}
