<?php

namespace App\Services\Executivo;

use Carbon\CarbonInterface;
use Throwable;

/**
 * O formato comum de todo bloco da API Executiva.
 *
 * Quem lê é um assessor que decide em cima do que recebe, então cada bloco diz
 * de onde veio (`fonte`), de que período é, quando foi consultado e quando a
 * fonte foi atualizada pela última vez, e em que situação está. Valor que não
 * existe vai como `null`, nunca como zero: zero é uma afirmação.
 *
 * Situações: `ok`, `desatualizado`, `falha`, `nao_configurado`.
 */
class Envelope
{
    public const SITUACOES = ['ok', 'desatualizado', 'falha', 'nao_configurado'];

    /** @return array<string, mixed> */
    public static function montar(
        string $fonte,
        ?string $periodo,
        ?CarbonInterface $atualizadoEm = null,
        string $situacao = 'ok',
        ?string $motivo = null,
        mixed $dados = null,
    ): array {
        return [
            'fonte' => $fonte,
            'periodo' => $periodo,
            'consultado_em' => now()->toIso8601String(),
            'atualizado_em' => $atualizadoEm?->toIso8601String(),
            'situacao' => in_array($situacao, self::SITUACOES, true) ? $situacao : 'falha',
            'motivo' => $motivo,
            'dados' => $dados,
        ];
    }

    /**
     * Roda a leitura de uma fonte sem deixar a falha dela virar 500: o bloco
     * volta com `situacao: falha` e um motivo curto. O erro completo vai para o
     * log, e a mensagem da exceção NÃO sai na resposta — ela pode trazer
     * consulta, caminho ou credencial.
     *
     * @param  callable(): array<string, mixed>  $leitura  devolve o envelope pronto
     * @return array<string, mixed>
     */
    public static function proteger(string $fonte, ?string $periodo, callable $leitura): array
    {
        try {
            return $leitura();
        } catch (Throwable $e) {
            report($e);

            return self::montar($fonte, $periodo, null, 'falha', 'A fonte não respondeu ('.class_basename($e).').');
        }
    }
}
