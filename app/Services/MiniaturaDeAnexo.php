<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A versão pequena da figura anexada, para a grade de miniaturas.
 *
 * A grade da seção de anexos desenha caixas de ~140×105 (quatro colunas num
 * modal de 620px, ver `_anexos.blade.php`) e apontava o `<img>` para o arquivo
 * ORIGINAL — até 12 MB. Abrir uma tarefa com doze prints baixava os doze
 * inteiros para pintar doze selos de correio, e tudo de uma vez: as figuras só
 * são pedidas quando o modal abre, porque `loading="lazy"` debaixo de um
 * `display:none` não pede nada. A espera acontecia exatamente ao olhar.
 *
 * Nenhuma camada de fora resolve isto. A rota do anexo responde `private`
 * (está atrás de `auth`), então a borda da Cloudflare não guarda imagem
 * nenhuma; e PNG e JPEG já são comprimidos, então `gzip` no caminho não tira
 * um byte. O que sobra é não mandar o arquivo grande.
 *
 * O ORIGINAL CONTINUA INTACTO, byte a byte. Ele é a prova do defeito e é o que
 * abre em aba nova ao clicar; a miniatura é só o que a grade pinta. Recodificar
 * o original borraria o texto que o print foi anexado para mostrar — é a mesma
 * razão pela qual o `reduzir()` do navegador só age no que não caberia no POST.
 */
class MiniaturaDeAnexo
{
    /**
     * 320 no maior lado.
     *
     * A caixa da grade tem ~140px de largura, e 320 cobre tela de densidade
     * dobrada com folga. Não é medida de legibilidade: aos 140px não se lê o
     * texto de print nenhum, e quem quer ler clica e abre o original.
     */
    public const LADO = 320;

    /**
     * A figura que já é pequena não ganha derivada.
     *
     * Reduzir 300px para 320px produziria um arquivo do mesmo tamanho com uma
     * volta de recodificação no meio — e uma segunda cópia no disco para não
     * economizar nada. Sem derivada, a grade cai no original, que é o certo.
     */
    private const MENOR_QUE_VALE_REDUZIR = self::LADO;

    /** GD guarda cada pixel de imagem truecolor em quatro bytes. */
    private const BYTES_POR_PIXEL = 4;

    /**
     * A decodificação pede mais memória do que o bitmap final ocupa: o arquivo
     * lido, as estruturas do GD e a cópia redimensionada convivem por um
     * instante. A folga é grosseira de propósito — errar para cima aqui custa
     * uma miniatura que não nasce, e errar para baixo custa a requisição.
     */
    private const FOLGA_DE_MEMORIA = 1.6;

    /**
     * Gera a miniatura de um arquivo JÁ GRAVADO no disco `public`.
     *
     * Devolve o caminho da miniatura, ou nulo quando ela não deve existir — o
     * que NÃO é erro: quem chama guarda o nulo e a tela cai no original. Os
     * casos de nulo são todos legítimos: o arquivo não é figura, a figura já é
     * pequena, o formato não é dos que o GD lê, ou a imagem é grande demais
     * para caber na memória desta requisição.
     */
    public static function gerar(string $caminho): ?string
    {
        $disco = Storage::disk('public');

        // `getimagesize` lê só o CABEÇALHO do arquivo — largura, altura e tipo
        // — sem decodificar pixel nenhum. É de propósito que a decisão venha
        // daqui: estourar `memory_limit` dentro do GD é erro fatal, e erro
        // fatal não se pega com `try`. A pergunta tem de ser feita ANTES.
        $medidas = @getimagesize($disco->path($caminho));

        if (! $medidas || ! self::valeReduzir($medidas[0], $medidas[1])) {
            return null;
        }

        if (! self::cabeNaMemoria($medidas[0], $medidas[1])) {
            return null;
        }

        $bytes = self::redesenhar($disco->get($caminho), $medidas[0], $medidas[1]);

        if ($bytes === null) {
            return null;
        }

        // Ao lado do original e derivado do mesmo nome: quem olha a pasta vê o
        // par junto, e apagar o anexo não depende de adivinhar um segundo
        // endereço. O `.jpg` é sempre honesto — a miniatura sai em JPEG venha
        // o original de que formato vier.
        $destino = preg_replace('/\.[^.]+$/', '', $caminho).'-min.jpg';

        return $disco->put($destino, $bytes) ? $destino : null;
    }

    /**
     * Gera as que faltam, para os anexos que já estavam no disco.
     *
     * Aqui e não dentro da migração — que é quem chama — para que a suíte
     * alcance: a migração roda uma vez, com a tabela vazia no ambiente de
     * teste, e o que ela faz nunca seria exercitado. Devolve quantas nasceram.
     *
     * Cada arquivo é uma tentativa ISOLADA, e nenhuma falha derruba quem
     * chamou. A miniatura é cortesia: o pior caso de não gerar é a grade
     * continuar fazendo o que já faz. Interromper a publicação de uma versão
     * inteira por causa de um PNG corrompido de 2026 seria trocar um incômodo
     * por uma parada.
     */
    public static function gerarAsQueFaltam(int $porVez = 500): int
    {
        $geradas = 0;

        // `eachById` e não `each`: o `each` pagina por DESLOCAMENTO, e o filtro
        // muda debaixo dele — cada linha que ganha miniatura sai do
        // `whereNull`, as seguintes escorregam para trás e a segunda página
        // pula tantas quantas a primeira preencheu. Paginando pelo id, o que já
        // passou não desloca o que falta.
        //
        // `$porVez` existe para o teste poder forçar mais de uma página com
        // poucas linhas: no tamanho de verdade, todo acervo plausível cabe numa
        // página só, e o erro de deslocamento não apareceria em teste nenhum.
        DB::table('tarefa_anexos')
            ->whereNull('caminho_miniatura')
            ->where('mime', 'like', 'image/%')
            ->eachById(function (object $anexo) use (&$geradas): void {
                try {
                    $miniatura = self::gerar($anexo->caminho);
                } catch (\Throwable) {
                    return;
                }

                if ($miniatura === null) {
                    return;
                }

                DB::table('tarefa_anexos')
                    ->where('id', $anexo->id)
                    ->update(['caminho_miniatura' => $miniatura]);

                $geradas++;
            }, $porVez);

        return $geradas;
    }

    private static function valeReduzir(int $largura, int $altura): bool
    {
        return max($largura, $altura) > self::MENOR_QUE_VALE_REDUZIR;
    }

    /**
     * A imagem cabe na memória que ainda resta nesta requisição?
     *
     * O teto é lido do `memory_limit` em vez de fixado num número: um print de
     * tela 5K são ~15 megapixels, que passam com folga nos 128M do FPM, e um
     * PNG de 12 MB pode trazer 48 megapixels, que não passariam de jeito
     * nenhum. Fixar um número aqui escolheria um dos dois para sempre — e o
     * escolheria de novo, errado, no dia em que o limite do servidor mudasse.
     */
    private static function cabeNaMemoria(int $largura, int $altura): bool
    {
        $limite = self::limiteDeMemoria();

        if ($limite === null) {
            return true;
        }

        $preciso = $largura * $altura * self::BYTES_POR_PIXEL * self::FOLGA_DE_MEMORIA;

        return $preciso < ($limite - memory_get_usage(true));
    }

    /** O `memory_limit` em bytes; nulo quando não há limite (`-1`, o CLI). */
    private static function limiteDeMemoria(): ?int
    {
        $limite = trim((string) ini_get('memory_limit'));

        if ($limite === '' || $limite === '-1') {
            return null;
        }

        $unidade = strtolower(substr($limite, -1));
        $numero = (int) $limite;

        return match ($unidade) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => $numero,
        };
    }

    /**
     * Redesenha em JPEG, no tamanho da grade. Nulo se o GD não der conta.
     *
     * `imagecreatefromstring` em vez de uma função por formato: ela deduz o
     * tipo do conteúdo e devolve `false` para o que não sabe ler, que é
     * exatamente a resposta que interessa aqui — um formato a mais na lista de
     * aceites não exige uma linha a mais aqui dentro.
     *
     * JPEG e não PNG porque uma figura de tela redesenhada por PNG costuma sair
     * MAIOR que o original: o PNG do gerador vem otimizado, e o redesenho perde
     * essa otimização. É a mesma conclusão a que o `reduzir()` do navegador
     * chegou.
     */
    private static function redesenhar(string $conteudo, int $largura, int $altura): ?string
    {
        $origem = @imagecreatefromstring($conteudo);

        if ($origem === false) {
            return null;
        }

        $escala = self::LADO / max($largura, $altura);
        $destino = imagecreatetruecolor((int) round($largura * $escala), (int) round($altura * $escala));

        // Fundo branco antes de desenhar: JPEG não tem transparência, e sem
        // isto todo PNG com fundo transparente ganharia um fundo PRETO — que
        // não é o que a pessoa viu ao anexar.
        imagefilledrectangle(
            $destino, 0, 0, imagesx($destino), imagesy($destino),
            imagecolorallocate($destino, 255, 255, 255)
        );

        imagecopyresampled(
            $destino, $origem,
            0, 0, 0, 0,
            imagesx($destino), imagesy($destino), $largura, $altura
        );

        ob_start();
        imagejpeg($destino, null, 78);
        $bytes = (string) ob_get_clean();

        // Sem `imagedestroy`: desde o PHP 8.0 a imagem é um objeto, liberado
        // pelo coletor quando a função retorna — a chamada não faz nada, e o
        // PHP 8.5 a marcou como depreciada. As duas saem de escopo na linha
        // abaixo, que é exatamente o que ela pretendia fazer.
        return $bytes !== '' ? $bytes : null;
    }
}
