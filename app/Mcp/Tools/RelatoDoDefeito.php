<?php

namespace App\Mcp\Tools;

use App\Services\TarefaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * O relato do Defeito (tarefa #204) nas portas do agente — `criar_tarefa` e
 * `editar_tarefa`.
 *
 * Os argumentos têm nome curto (`quem`, `quando`, `esperado`, `ocorrido`)
 * porque é assim que quem dita fala; as colunas são `defeito_*`. A tradução
 * mora aqui para as duas ferramentas dizerem o mesmo, e a exigência de quem e
 * quando NÃO mora aqui: é do `TarefaService`, que recusa com a frase da tela.
 */
trait RelatoDoDefeito
{
    /** Argumento da ferramenta => coluna da tarefa. */
    private const CAMPOS_DO_RELATO = [
        'quem' => 'defeito_quem',
        'quando' => 'defeito_quando',
        'esperado' => 'defeito_esperado',
        'ocorrido' => 'defeito_ocorrido',
    ];

    /**
     * @return array<string, mixed>
     */
    private static function esquemaDoRelato(JsonSchema $schema): array
    {
        return [
            'quem' => $schema->string()->max(255)
                ->description('Só para defeito (obrigatório nele): quem foi afetado — o cliente, o aluno ou a academia, pelo nome que se procura no sistema.'),
            'quando' => $schema->string()
                ->description('Só para defeito (obrigatório nele): quando aconteceu, AAAA-MM-DD HH:MM.'),
            'esperado' => $schema->string()->max(2000)
                ->description('Só para defeito: o que deveria ter acontecido.'),
            'ocorrido' => $schema->string()->max(2000)
                ->description('Só para defeito: o que aconteceu de fato, com a mensagem de erro se houve. O print vai depois, como anexo pela tela.'),
        ];
    }

    /**
     * As mesmas regras de formato da tela (`TarefaService::REGRAS_DO_RELATO`),
     * com o nome do argumento.
     *
     * @return array<string, string>
     */
    private static function regrasDoRelato(): array
    {
        $regras = [];

        foreach (self::CAMPOS_DO_RELATO as $argumento => $coluna) {
            $regras[$argumento] = 'sometimes|'.TarefaService::REGRAS_DO_RELATO[$coluna];
        }

        return $regras;
    }

    /**
     * Só o que veio, já com o nome da coluna — a edição é parcial.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private static function relatoDoEnvio(array $entrada): array
    {
        $relato = [];

        foreach (self::CAMPOS_DO_RELATO as $argumento => $coluna) {
            if (array_key_exists($argumento, $entrada)) {
                $relato[$coluna] = $entrada[$argumento];
            }
        }

        return $relato;
    }

    /**
     * O relato mandado para tarefa que não é Defeito — ou null, se está certo.
     *
     * A tela esconde os campos fora do tipo e o serviço os descarta em
     * silêncio; o agente não tem tela, e sem a frase acharia que gravou o
     * relato numa tarefa que não o guarda.
     *
     * @param  array<string, mixed>  $entrada
     */
    private static function relatoForaDoDefeito(array $entrada, ?string $tipo): ?string
    {
        if ($tipo === 'defeito' || self::relatoDoEnvio($entrada) === []) {
            return null;
        }

        return 'Quem, quando, esperado e ocorrido são o relato de um defeito. Passe tipo "defeito" para abrir a tarefa como defeito, ou tire esses campos.';
    }
}
