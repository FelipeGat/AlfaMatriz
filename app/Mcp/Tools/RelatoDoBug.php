<?php

namespace App\Mcp\Tools;

use App\Services\TarefaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * O relato do Bug (tarefa #204) nas portas do agente — `criar_tarefa` e
 * `editar_tarefa`.
 *
 * Os argumentos têm nome curto (`quem`, `quando`) porque é assim que quem dita
 * fala; as colunas são `defeito_*`, do nome que o tipo teve no primeiro dia. A
 * tradução mora aqui para as duas ferramentas dizerem o mesmo, e a exigência
 * de quem e quando NÃO mora aqui: é do `TarefaService`, que recusa com a frase
 * da tela.
 *
 * "Esperado" e "ocorrido" saíram no segundo ajuste da #204: o que aconteceu vai
 * no `resumo`, como na tela.
 */
trait RelatoDoBug
{
    /** Argumento da ferramenta => coluna da tarefa. */
    private const CAMPOS_DO_RELATO = [
        'quem' => 'defeito_quem',
        'quando' => 'defeito_quando',
    ];

    /**
     * @return array<string, mixed>
     */
    private static function esquemaDoRelato(JsonSchema $schema): array
    {
        return [
            'quem' => $schema->string()->max(255)
                ->description('Só para bug (obrigatório nele): quem foi afetado — o cliente, o aluno ou a academia, pelo nome que se procura no sistema.'),
            'quando' => $schema->string()
                ->description('Só para bug (obrigatório nele): quando aconteceu, AAAA-MM-DD HH:MM.'),
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
     * O relato mandado para tarefa que não é Bug — ou null, se está certo.
     *
     * A tela esconde os campos fora do tipo e o serviço os descarta em
     * silêncio; o agente não tem tela, e sem a frase acharia que gravou o
     * relato numa tarefa que não o guarda.
     *
     * @param  array<string, mixed>  $entrada
     */
    private static function relatoForaDoBug(array $entrada, ?string $tipo): ?string
    {
        if ($tipo === 'bug' || self::relatoDoEnvio($entrada) === []) {
            return null;
        }

        return 'Quem e quando são o relato de um bug. Passe tipo "bug" para abrir a tarefa como bug, ou tire esses campos.';
    }
}
