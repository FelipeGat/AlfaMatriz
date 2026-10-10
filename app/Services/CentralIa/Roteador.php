<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaTarefa;
use Illuminate\Support\Str;

/**
 * Escolhe o agente e o modo quando a pessoa deixa em "automático".
 *
 * Regras fixas, e não uma chamada a modelo: perguntar ao GPT a cada pedido
 * custaria mais uma execução da cota do ChatGPT e 10–15 s de espera antes de
 * o trabalho começar (dono informado em 10/10/2026). O que sai daqui é
 * sempre o agente E o motivo — o motivo aparece na tela, e a troca manual
 * vence qualquer regra.
 *
 * Preferências do dono: GPT/Codex planeja, investiga, arquiteta e revisa;
 * Claude implementa; Copilot dá apoio técnico rápido.
 */
class Roteador
{
    /**
     * Ordem importa: a primeira categoria que casa decide. Implementar vem
     * antes de revisar porque "corrija o que a revisão achou" é implementação.
     */
    private const CATEGORIAS = [
        'implementacao' => ['implemente', 'implementar', 'corrija', 'corrigir', 'conserte', 'crie', 'criar', 'adicione', 'adicionar',
            'altere', 'alterar', 'ajuste', 'ajustar', 'refatore', 'refatorar', 'remova', 'mude', 'mudar', 'troque', 'escreva', 'faça', 'faca',
            'aplique', 'migre', 'atualize', 'inclua', 'resolva'],
        'revisao' => ['revise', 'revisar', 'revisão', 'revisao', 'review', 'audite', 'auditar', 'confira', 'valide', 'validar', 'avalie o código'],
        'planejamento' => ['planeje', 'planejar', 'plano', 'arquitetura', 'arquitete', 'desenhe', 'proponha', 'estratégia', 'estrategia', 'roadmap'],
        'investigacao' => ['analise', 'analisar', 'análise', 'investigue', 'investigar', 'descubra', 'por que', 'porque', 'entenda', 'diagnostique',
            'mapeie', 'liste', 'resuma', 'explique o projeto'],
        'apoio' => ['como faço', 'como faco', 'qual comando', 'sintaxe', 'me lembre', 'exemplo de'],
    ];

    public function decidir(string $instrucao, array $disponiveis, ?CentralIaTarefa $tarefa = null): array
    {
        $texto = Str::lower(Str::squish($instrucao));
        $categoria = 'investigacao';
        $palavra = null;

        foreach (self::CATEGORIAS as $nome => $termos) {
            foreach ($termos as $termo) {
                if (preg_match('/(^|[^\pL])'.preg_quote($termo, '/').'($|[^\pL])/u', $texto)) {
                    [$categoria, $palavra] = [$nome, $termo];
                    break 2;
                }
            }
        }

        $modo = $categoria === 'implementacao' ? 'implementar' : 'analisar';
        $preferidos = config('central_ia.roteamento.'.$categoria, ['codex', 'claude', 'copilot']);

        // Continuidade só no MESMO tipo de pedido: implementar depois de
        // implementar fica com quem já está (trocar à toa custa um handoff);
        // revisar depois de implementar vai para outro agente de propósito —
        // quem escreveu não é o melhor revisor do próprio código.
        $anterior = $tarefa?->dossie['ultima_categoria'] ?? null;
        if ($tarefa?->agente_atual && $anterior === $categoria && in_array($tarefa->agente_atual, $disponiveis, true)
            && $tarefa->agente_atual !== $preferidos[0]) {
            return $this->resultado($tarefa->agente_atual, $modo, $categoria, $palavra, 'mantido o agente que já conduz a tarefa');
        }

        foreach ($preferidos as $i => $agente) {
            if (in_array($agente, $disponiveis, true)) {
                return $this->resultado($agente, $modo, $categoria, $palavra, $i > 0 ? "{$preferidos[0]} não está disponível na máquina" : null);
            }
        }

        return $this->resultado(null, $modo, $categoria, $palavra, 'nenhum agente disponível na máquina');
    }

    private function resultado(?string $agente, string $modo, string $categoria, ?string $palavra, ?string $observacao): array
    {
        $rotulos = [
            'implementacao' => 'implementação', 'revisao' => 'revisão', 'planejamento' => 'planejamento',
            'investigacao' => 'investigação', 'apoio' => 'apoio técnico',
        ];
        $motivo = 'Automático: '.$rotulos[$categoria].($palavra ? " (\"{$palavra}\")" : ' (padrão)');

        return [
            'agente' => $agente,
            'modo' => $modo,
            'categoria' => $categoria,
            'manual' => false,
            'motivo' => $motivo.($observacao ? " — {$observacao}" : ''),
        ];
    }
}
