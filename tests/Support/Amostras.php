<?php

namespace Tests\Support;

use App\Services\Integracao\ConectorFalso;

/**
 * Carrega as amostras de resposta e monta um sistema de mentira com elas.
 *
 * Carregar arquivo é responsabilidade do teste, não do dublê: código que vive
 * em `app/` não deve saber que existe uma pasta de amostras.
 */
class Amostras
{
    private const ESCOPOS = ['revendas', 'clientes', 'planos', 'usuarios', 'licencas', 'financeiro', 'contadores'];

    /** Um dublê carregado com todas as amostras de um conjunto. */
    public static function conector(string $conjunto = 'v1', string $sistema = 'alfagym'): ConectorFalso
    {
        $dados = [];

        foreach (self::ESCOPOS as $escopo) {
            $dados[$escopo] = self::ler($conjunto, $escopo);
        }

        return new ConectorFalso($dados, sistema: $sistema);
    }

    /** @return array o conteúdo cru de uma amostra */
    public static function ler(string $conjunto, string $escopo): array
    {
        $caminho = base_path("tests/Fixtures/Integracao/{$conjunto}/{$escopo}.json");

        if (! is_file($caminho)) {
            return [];
        }

        return json_decode(file_get_contents($caminho), true) ?? [];
    }

    /** Uma amostra com um item alterado — para simular o que mudou na origem. */
    public static function comAlteracao(array $itens, string $idExterno, array $mudancas): array
    {
        foreach ($itens as $indice => $item) {
            if ((string) ($item['id_externo'] ?? '') === $idExterno) {
                $itens[$indice] = array_merge($item, $mudancas);
            }
        }

        return $itens;
    }

    /** Uma amostra sem um item — para simular quem sumiu da origem. */
    public static function sem(array $itens, string $idExterno): array
    {
        return array_values(array_filter(
            $itens,
            fn ($item) => (string) ($item['id_externo'] ?? '') !== $idExterno
        ));
    }
}
