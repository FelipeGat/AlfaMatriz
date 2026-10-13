<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaTarefa;
use Illuminate\Support\Str;

/**
 * O contexto de uma tarefa que sobrevive à troca de agente.
 *
 * A sessão interna do Claude não serve ao Codex, e a do Codex não serve ao
 * Copilot. O que passa de um para outro é ESTE registro: objetivo, pedidos,
 * decisões, estado real do git, testes, próximos passos, pendências e riscos.
 *
 * Duas fontes, separadas de propósito:
 *  - o que o RUNNER mediu (git: branch, HEAD, arquivos alterados) — fato;
 *  - o que o AGENTE relatou no bloco final da resposta — relato.
 * Quando o agente não manda o bloco, o dossiê registra a lacuna em vez de
 * fingir continuidade.
 */
class Dossie
{
    private const INICIO = '<<<CENTRAL-IA';

    private const FIM = 'CENTRAL-IA>>>';

    public static function inicial(string $objetivo): array
    {
        return [
            'objetivo' => $objetivo,
            'pedidos' => [],
            'decisoes' => [],
            'agentes' => [],
            'resumos' => [],
            'git' => null,
            'testes' => [],
            'proximos_passos' => [],
            'pendencias' => [],
            'riscos' => [],
            'duvida' => null,
            'lacunas' => [],
            'ultima_categoria' => null,
        ];
    }

    /**
     * Instrução que vai no fim de todo prompt: como o agente devolve o que o
     * dossiê precisa.
     */
    public static function instrucaoDoBloco(): string
    {
        return RespostaVisual::instrucao()."\nAo terminar, acrescente no FIM da resposta exatamente este bloco (JSON válido), que a Central usa para guardar o contexto da tarefa:\n"
            .self::INICIO."\n"
            .'{"resumo": "o que você fez/concluiu, em 1-3 frases", "decisoes": ["decisão tomada e por quê"], '
            .'"testes": [{"comando": "comando rodado", "resultado": "passou|falhou|não rodado"}], '
            .'"proximos_passos": ["..."], "pendencias": ["..."], "riscos": ["..."], '
            .'"duvida": null, "conseguiu": true, "tarefa_concluida": false, "pop": null, "delegar": null}'."\n"
            .self::FIM."\n"
            .'Quando precisar perguntar, escreva a pergunta em duvida e opções curtas em opcoes_duvida (lista de até 6 textos). Não esconda a pergunta no meio do relatório; na resposta diga apenas que aguarda esclarecimento. Opções não autorizam deploy, produção ou ações críticas. '
            .'Use "duvida" (texto) SÓ quando não puder seguir sem uma resposta da pessoa — ela será avisada. '
            .'"tarefa_concluida": true só quando o objetivo inteiro estiver atendido. '
            // Sinal da escalada automática (dono, 12/10/2026): quem não deu
            // conta passa o pedido a um nível ou a uma IA mais capaz.
            .'"conseguiu": false quando você NÃO conseguiu resolver o que foi pedido (faltou capacidade, o resultado ficou incompleto ou errado, ou você não achou a causa) — a Central passa o pedido a um modelo mais capaz. Não use false por falta de informação da pessoa (use "duvida") nem por falta de acesso (rede, permissão, credencial): aí responda com o que deu para verificar e diga a limitação — outro modelo bateria na mesma parede. '
            // POPs (dono, 12/10/2026): o que se aprendeu fica para o próximo pedido.
            .'"pop": quando você precisou DESCOBRIR como operar um sistema (tabelas, serviços, comandos, a via oficial de produção, cuidados) ou achou um POP recebido desatualizado, devolva {"sistema": "nome do projeto/sistema", "titulo": "o procedimento", "conteudo": "o POP COMPLETO e atualizado, em markdown: quando usar, passos, comandos testados, campos obrigatórios, armadilhas"}. Mesmo título substitui o anterior. Nunca ponha senha, token ou credencial. Sem nada novo, null. '
            // Delegação (dono, 13/10/2026, tarefa #14: o Claude parou esperando o
            // QA do Copilot e a pessoa recebeu um pedido que não é dela).
            .'"delegar": quando o próximo passo é de OUTRO agente (ex.: QA independente pelo Copilot, revisão pelo Codex, implementação pelo Claude), NÃO peça à pessoa para fazê-lo: devolva {"agente": "claude|codex|copilot", "modo": "analisar|implementar", "pedido": "o que ele deve fazer, com os arquivos e critérios"} e a Central encaminha na mesma tarefa. A pessoa só decide o que é dela (aprovar, commit, deploy, produção).';
    }

    /**
     * Separa a resposta visível do bloco estruturado.
     *
     * @return array{0: string, 1: array|null}
     */
    public static function extrair(string $resposta): array
    {
        $ini = strrpos($resposta, self::INICIO);
        $fim = $ini === false ? false : strpos($resposta, self::FIM, $ini);

        if ($ini === false || $fim === false) {
            return [trim($resposta), null];
        }

        $json = trim(substr($resposta, $ini + strlen(self::INICIO), $fim - $ini - strlen(self::INICIO)));
        $json = preg_replace('/^```(?:json)?|```$/m', '', $json);
        $bloco = json_decode(trim($json), true);
        // Tira a cerca de código que alguns agentes põem em volta do bloco: a
        // abertura no fim do texto de antes, o fechamento no começo do depois.
        $antes = preg_replace('/```(?:json)?\s*$/', '', substr($resposta, 0, $ini));
        $depois = preg_replace('/^\s*```/', '', substr($resposta, $fim + strlen(self::FIM)));
        $texto = trim(trim($antes).$depois);

        return [$texto, is_array($bloco) ? $bloco : null];
    }

    public static function registrarPedido(array $d, string $texto): array
    {
        $d['pedidos'][] = ['texto' => Str::limit($texto, 1500), 'em' => now()->toIso8601String()];
        $d['pedidos'] = array_slice($d['pedidos'], -15);
        $d['duvida'] = null;
        $d['opcoes_duvida'] = [];

        return $d;
    }

    /**
     * O que uma execução deixou: o relato do agente e a medida do runner.
     */
    public static function aplicarConclusao(array $d, CentralIaExecucao $e, ?array $bloco, ?array $checkpoint): array
    {
        $agora = now()->toIso8601String();
        $agente = $e->nomeDoAgente();

        if (! in_array($e->agente, array_column($d['agentes'], 'agente'), true)) {
            $d['agentes'][] = ['agente' => $e->agente, 'nome' => $agente, 'desde' => $agora];
        }
        $d['ultima_categoria'] = $e->roteamento['categoria'] ?? $d['ultima_categoria'];

        if ($checkpoint) {
            $d['git'] = $checkpoint + ['medido_em' => $agora];
        }

        if (! $bloco) {
            $d['lacunas'][] = "{$agente} (execução #{$e->id}) não devolveu o bloco de contexto; o resumo desta etapa está só no texto da resposta.";
            $d['lacunas'] = array_slice($d['lacunas'], -5);

            return $d;
        }

        $lista = fn ($v) => array_values(array_filter(array_map(fn ($x) => is_string($x) ? Str::limit(trim($x), 500) : null, (array) $v)));

        if (filled($bloco['resumo'] ?? null) && is_string($bloco['resumo'])) {
            $d['resumos'][] = ['agente' => $agente, 'execucao' => $e->id, 'texto' => Str::limit($bloco['resumo'], 800), 'em' => $agora];
            $d['resumos'] = array_slice($d['resumos'], -12);
        }
        foreach ($lista($bloco['decisoes'] ?? []) as $decisao) {
            $d['decisoes'][] = ['texto' => $decisao, 'agente' => $agente, 'em' => $agora];
        }
        $d['decisoes'] = array_slice($d['decisoes'], -30);

        foreach ((array) ($bloco['testes'] ?? []) as $t) {
            if (is_array($t) && filled($t['comando'] ?? null)) {
                $d['testes'][] = ['comando' => Str::limit((string) $t['comando'], 300), 'resultado' => Str::limit((string) ($t['resultado'] ?? ''), 60), 'agente' => $agente, 'em' => $agora];
            }
        }
        $d['testes'] = array_slice($d['testes'], -20);

        // Próximos passos, pendências e riscos SUBSTITUEM: são o retrato de
        // agora, não um acumulado — o que foi resolvido sai da lista.
        foreach (['proximos_passos', 'pendencias', 'riscos'] as $campo) {
            if (array_key_exists($campo, $bloco)) {
                $d[$campo] = array_slice($lista($bloco[$campo]), 0, 15);
            }
        }
        $d['duvida'] = is_string($bloco['duvida'] ?? null) && trim($bloco['duvida']) !== '' ? Str::limit($bloco['duvida'], 1500) : null;
        $d['opcoes_duvida'] = $d['duvida'] ? array_slice(array_values(array_filter(array_map(fn ($o) => is_string($o) ? Str::limit(trim($o), 250) : '', is_array($bloco['opcoes_duvida'] ?? null) ? $bloco['opcoes_duvida'] : []))), 0, 6) : [];

        return $d;
    }

    /**
     * O contexto que vai no prompt de quem pega a tarefa.
     */
    public static function paraPrompt(CentralIaTarefa $t, ?string $vindoDe = null): string
    {
        $d = $t->dossie ?? self::inicial($t->objetivo);
        $l = ["## Contexto da tarefa (registrado pela Central de IA)", "Tarefa #{$t->id}: {$t->titulo}", "Objetivo original: {$d['objetivo']}"];

        $secao = function (string $titulo, array $itens) use (&$l) {
            if ($itens) {
                $l[] = "\n### {$titulo}";
                foreach ($itens as $i) {
                    $l[] = "- {$i}";
                }
            }
        };

        if ($p = $d['ponto'] ?? null) {
            $l[] = "\n### PONTO SALVO em {$p['em']} por {$p['agente']} — é daqui que você continua";
            $l[] = $p['resumo'];
        }

        $secao('Pedidos da pessoa (mais recentes por último)', array_map(fn ($p) => $p['texto'], array_slice($d['pedidos'], -8)));
        $secao('Decisões já tomadas', array_map(fn ($x) => "{$x['texto']} ({$x['agente']})", array_slice($d['decisoes'], -12)));
        $secao('O que cada agente fez', array_map(fn ($x) => "{$x['agente']}, execução #{$x['execucao']}: {$x['texto']}", array_slice($d['resumos'], -6)));
        $secao('Testes rodados', array_map(fn ($x) => "{$x['comando']} → {$x['resultado']} ({$x['agente']})", array_slice($d['testes'], -8)));
        $secao('Próximos passos registrados', $d['proximos_passos']);
        $secao('Pendências', $d['pendencias']);
        $secao('Riscos', $d['riscos']);

        if ($g = $d['git']) {
            $arquivos = implode(', ', array_slice($g['arquivos'] ?? [], 0, 30)) ?: 'nenhum';
            $l[] = "\n### Estado do git medido no fim da última execução";
            $l[] = 'Branch: '.($g['branch'] ?? '?').' · HEAD: '.($g['head'] ?? '?').' · base: '.($t->commit_base ?? '?');
            $l[] = "Arquivos alterados sem commit: {$arquivos}";
        }
        $secao('Lacunas conhecidas neste contexto', $d['lacunas']);

        if ($vindoDe) {
            $l[] = "\n### Você está ASSUMINDO esta tarefa, que estava com {$vindoDe}";
            $l[] = 'Antes de alterar qualquer coisa: confira o estado real (git status, git diff --stat, os arquivos citados) contra o registro acima.';
            $l[] = 'Se bater, siga com o pedido. Se não bater, ou faltar contexto para continuar com segurança, NÃO altere nada: explique a divergência e use o campo "duvida" do bloco final.';
        }

        return Str::limit(implode("\n", $l), 24000);
    }
}
