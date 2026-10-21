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
    private array $esgotados = [];
    private array $preferencias = [];

    /**
     * Ordem importa: a primeira categoria que casa decide. Implementar vem
     * antes de revisar porque "corrija o que a revisão achou" é implementação.
     */
    private const CATEGORIAS = [
        'implementacao' => ['implemente', 'implementar', 'inplementar', 'inplemente', 'implante', 'implantar', 'corrija', 'corrigir', 'conserte', 'crie', 'criar', 'adicione', 'adicionar',
            'altere', 'alterar', 'ajuste', 'ajustar', 'refatore', 'refatorar', 'remova', 'mude', 'mudar', 'troque', 'escreva', 'faça', 'faca',
            'aplique', 'migre', 'atualize', 'inclua', 'resolva', 'gere', 'monte', 'commit', 'commite', 'push', 'deploy', 'publique'],
        'revisao' => ['revise', 'revisar', 'revisão', 'revisao', 'review', 'audite', 'auditar', 'confira', 'valide', 'validar', 'avalie o código'],
        'planejamento' => ['planeje', 'planejar', 'plano', 'arquitetura', 'arquitete', 'desenhe', 'proponha', 'estratégia', 'estrategia', 'roadmap'],
        'investigacao' => ['investigue', 'investigar', 'descubra', 'por que', 'porque', 'entenda', 'diagnostique', 'mapeie'],
        'apoio' => ['como faço', 'como faco', 'qual comando', 'sintaxe', 'me lembre', 'exemplo de'],
    ];

    /**
     * O que pede uma RESPOSTA, e não uma mudança no código: resumo, análise,
     * relatório… Vence os verbos genéricos — "me faça um resumo da agenda"
     * caía em implementação pelo "faça" e ia para o Claude (dono, 10/10/2026:
     * resumo, análise e relatório são do Codex, com a mesma qualidade).
     */
    private const ANALITICO = ['resumo', 'resuma', 'resumir', 'relatório', 'relatorio', 'análise', 'analise', 'analisar', 'levantamento',
        'panorama', 'status', 'situação', 'situacao', 'agenda', 'compromisso', 'compromissos', 'reunião', 'reuniao', 'reuniões', 'reunioes',
        'diagnóstico', 'diagnostico', 'explique', 'explicação', 'explicacao', 'compare', 'comparação', 'comparacao', 'lista', 'liste',
        'quais', 'quantos', 'quantas', 'o que', 'como está', 'como esta', 'balanço', 'balanco', 'visão geral', 'visao geral', 'sugestões', 'sugestoes'];

    /**
     * Só estes fazem implementação mesmo com um termo analítico no texto.
     * Os verbos de alteração entram aqui: "adicione um filtro de status na
     * lista" ia para análise pelo "status" e pela "lista", e nada mudava.
     */
    private const IMPLEMENTA_DE_VERDADE = ['implemente', 'implementar', 'inplementar', 'inplemente', 'implante', 'implantar', 'corrija', 'corrigir', 'conserte', 'refatore', 'refatorar', 'migre',
        'adicione', 'altere', 'remova', 'ajuste', 'inclua', 'troque', 'mude', 'atualize', 'aplique', 'resolva',
        // Commit e deploy são execução (13/10/2026: "pode fazer commit e deploy"
        // caía em análise). Deploy pede o código da máquina sempre.
        // Push também (T-368): "pode fazer o push", resposta a uma pergunta
        // do agente, ficava em análise e o push era negado.
        'commit', 'commite', 'push', 'deploy', 'publique', 'publicar'];

    /** Genéricos: só contam como implementação quando nada analítico aparece. */
    private const VERBOS_GENERICOS = ['faça', 'faca', 'crie', 'criar', 'escreva', 'gere', 'monte'];

    /**
     * A mensagem pede uma RESPOSTA? Na conversa Geral é o que separa um
     * pedido ("resumo da minha agenda", "quais tarefas estão atrasadas?") de
     * uma anotação ("ideia para depois") — anotação não gasta cota de IA.
     */
    public function pedeResposta(string $texto): bool
    {
        $t = Str::lower(Str::squish($texto));

        return str_ends_with($t, '?')
            || collect(self::ANALITICO)->contains(fn ($p) => preg_match('/(^|[^\pL])'.preg_quote($p, '/').'($|[^\pL])/u', $t));
    }

    /**
     * Operar um sistema: gravar um dado pedido pela pessoa (agendar alguém,
     * lançar, dar baixa) — não é código nem relatório. Dono, 12/10/2026:
     * "agende o Vitor amanhã no cliente X" — o agente confere, propõe e só
     * grava depois de a pessoa confirmar na conversa.
     */
    private const OPERACAO = ['agende', 'agendar', 'marque', 'marcar', 'remarque', 'remarcar', 'desmarque', 'desmarcar', 'cancele', 'cancelar',
        'cadastre', 'cadastrar', 'lance', 'lançar', 'lancar', 'registre', 'registrar', 'dê baixa', 'de baixa', 'dar baixa', 'baixe', 'emita', 'emitir', 'reagende', 'reagendar',
        // Mudar o estado de um registro é operação no sistema, não código.
        'mude o status', 'mudar o status', 'altere o status', 'alterar o status', 'troque o status', 'trocar o status'];

    /**
     * O pedido fala de CÓDIGO: só com algum destes um verbo de alteração ao
     * lado de uma operação vira implementação (Felipe, 16/10/2026: "mude o
     * status para Financeiro, depois dê baixa" é operação no Gestor, do Codex;
     * o Claude fica só com desenvolvimento).
     */
    private const FALA_DE_CODIGO = ['código', 'codigo', 'arquivo', 'arquivos', 'tela', 'telas', 'layout', 'componente', 'função', 'funcao',
        'classe', 'controller', 'migration', 'migração', 'migracao', 'endpoint', 'rota', 'backend', 'frontend', 'css', 'html', 'javascript',
        'php', 'refatoração', 'refatoracao', 'branch', 'teste', 'testes', 'bug'];

    /** Publicar é sempre execução, com o código no Telegram. */
    private const PUBLICA = ['commit', 'commite', 'push', 'deploy', 'publique', 'publicar'];

    /** Ler, resumir, relatar: o nível leve resolve. */
    private const LEVE = ['resumo', 'resuma', 'resumir', 'relatório', 'relatorio', 'agenda', 'compromisso', 'compromissos', 'reunião', 'reuniao',
        'reuniões', 'reunioes', 'status', 'situação', 'situacao', 'lista', 'liste', 'quais', 'quantos', 'quantas', 'balanço', 'balanco',
        'panorama', 'visão geral', 'visao geral', 'como faço', 'como faco', 'qual comando', 'sintaxe', 'exemplo de'];

    /** Pedido sobre a agenda que pede raciocínio, e não só a lista. */
    private const PENSAR_SOBRE = ['resum', 'analis', 'anális', 'prepar', 'sugir', 'sugest', 'compar', 'prioriz', 'organiz', 'relat', 'conflit', 'explique', 'por que', 'planej'];

    /**
     * O nível de custo do pedido (ver `config/central_ia.php`, `niveis`).
     *
     * - `sem_ia`: só a lista da agenda ("quais meus compromissos hoje?") — os
     *   dados oficiais já são a resposta, nenhuma IA precisa ler;
     * - `forte`: implementação;
     * - `medio`: análise, revisão, planejamento, investigação;
     * - `leve`: o resto que só lê e relata.
     */
    public function nivel(string $instrucao, string $categoria, string $modo, array $fontes = []): string
    {
        if ($modo === 'implementar') return 'forte';
        if ($categoria === 'operacao') return 'medio';
        $t = Str::lower(Str::squish($instrucao));
        $tem = fn (array $termos) => collect($termos)->contains(fn ($p) => preg_match('/(^|[^\pL])'.preg_quote($p, '/').'/u', $t));
        // "Sem IA" só para LER a agenda: com um verbo de ação ("agende",
        // "marque") a lista crua não responde nada.
        if (collect($fontes)->contains('tipo', 'agenda') && ! $tem(self::PENSAR_SOBRE) && ! $tem(self::OPERACAO)) return 'sem_ia';
        if (in_array($categoria, ['revisao', 'planejamento', 'codigo'], true)) return 'medio';
        if (in_array($categoria, ['apoio', 'relatorio'], true)) return 'leve';
        // Investigação: "quais/status/resumo" é leitura; "por que/diagnostique" pede raciocínio.
        return $tem(self::LEVE) && ! $tem(['por que', 'porque', 'diagnóstico', 'diagnostico', 'diagnostique', 'investigue', 'entenda', 'análise', 'analise', 'compare'])
            ? 'leve' : 'medio';
    }

    public function decidir(string $instrucao, array $disponiveis, ?CentralIaTarefa $tarefa = null, array $esgotados = [], array $preferencias = []): array
    {
        // Quem está no limite da assinatura sai da escolha automática, e o
        // motivo vai junto (ver AgentesService::esgotados).
        $this->esgotados = $esgotados;
        $this->preferencias = $preferencias;
        $disponiveis = array_values(array_diff($disponiveis, array_keys($esgotados)));
        $texto = Str::lower(Str::squish($instrucao));
        // “Análise da implantação” continua só leitura. O substantivo indica
        // escrita apenas quando acompanha autorização explícita do dono.
        $semAcento = Str::ascii($texto);
        if (preg_match('/\b(?:pode|siga|vamos|autorizo)\b.*\b(?:implementacao|implantacao)\b/u', $semAcento)
            || preg_match('/\banalise\s+com\s+implementacao\b/u', $semAcento)) {
            $texto .= ' implementar';
        }

        $categoria = 'investigacao';
        $palavra = null;
        $tem = fn (array $termos) => collect($termos)->first(fn ($t) => preg_match('/(^|[^\pL])'.preg_quote($t, '/').'($|[^\pL])/u', $texto));

        // "Me traga um modelo para meu ok e depois implemente": primeiro a
        // proposta; a implementação vem com o "Sim, implementar" (dono,
        // 13/10/2026 — caía direto em implementação e pedia o código).
        if ($p = $tem(['para meu ok', 'para o meu ok', 'para minha aprovação', 'para minha aprovacao', 'para eu aprovar', 'modelo conceito',
            'conceito', 'protótipo', 'prototipo', 'mockup', 'antes de implementar', 'me traga uma proposta', 'me traga um modelo',
            // "Analise e me traga um cenário" é proposta, mesmo citando "implantar" (16/10/2026).
            'me traga um cenário', 'me traga um cenario', 'traga um cenário', 'traga um cenario', 'monte um cenário', 'monte um cenario'])) {
            return $this->escolher('planejamento', $p, $disponiveis, $tarefa);
        }

        if (($op = $tem(self::OPERACAO)) && (! $tem(self::IMPLEMENTA_DE_VERDADE) || (! $tem(self::FALA_DE_CODIGO) && ! $tem(self::PUBLICA)))) {
            return $this->escolher('operacao', $op, $disponiveis, $tarefa);
        }

        $analitico = $tem(self::ANALITICO);
        if ($analitico && $tem(['código', 'codigo', 'backend', 'frontend', 'controller', 'migration', 'refatoração', 'refatoracao']) && ! $tem(self::IMPLEMENTA_DE_VERDADE)) {
            // Consulta de código é análise: segue a preferência de código
            // (padrão Codex), e não a de implementação (16/10/2026).
            $rota = $this->escolher('codigo', 'análise de código', $disponiveis, $tarefa);
            $rota['modo'] = 'analisar';
            $rota['categoria'] = 'codigo';
            return $rota;
        }
        if ($analitico && $tem(['financeiro', 'financeira', 'pagar', 'receber', 'receitas', 'despesas', 'dados']) && ! $tem(self::IMPLEMENTA_DE_VERDADE)) {
            return $this->escolher('investigacao', 'análise de dados do sistema', $disponiveis, $tarefa);
        }
        if ($preferencias && $tem(['relatório', 'relatorio', 'balanço', 'balanco']) && ! $tem(self::IMPLEMENTA_DE_VERDADE)) {
            return $this->escolher('relatorio', $analitico, $disponiveis, $tarefa);
        }
        if ($analitico && ! $tem(self::IMPLEMENTA_DE_VERDADE)) {
            $revisao = $tem(self::CATEGORIAS['revisao']);
            $planejamento = $tem(self::CATEGORIAS['planejamento']);
            [$categoria, $palavra] = $revisao ? ['revisao', $revisao] : ($planejamento ? ['planejamento', $planejamento] : ['investigacao', $analitico]);

            return $this->escolher($categoria, $palavra, $disponiveis, $tarefa);
        }

        foreach (self::CATEGORIAS as $nome => $termos) {
            foreach ($termos as $termo) {
                if (preg_match('/(^|[^\pL])'.preg_quote($termo, '/').'($|[^\pL])/u', $texto)) {
                    [$categoria, $palavra] = [$nome, $termo];
                    break 2;
                }
            }
        }

        return $this->escolher($categoria, $palavra, $disponiveis, $tarefa);
    }

    private function escolher(string $categoria, ?string $palavra, array $disponiveis, ?CentralIaTarefa $tarefa): array
    {
        $modo = $categoria === 'implementacao' ? 'implementar' : 'analisar';
        $preferidos = $this->preferencias[$categoria] ?? config('central_ia.roteamento.'.$categoria, ['codex', 'claude', 'copilot']);
        if (! $preferidos) return $this->resultado(null, $modo, $categoria, $palavra, 'nenhum agente autorizado');

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
                $obs = $i > 0 ? ($this->esgotados[$preferidos[0]] ?? "{$preferidos[0]} não está disponível na máquina") : null;

                return $this->resultado($agente, $modo, $categoria, $palavra, $obs);
            }
        }

        return $this->resultado(null, $modo, $categoria, $palavra, 'nenhum agente disponível na máquina');
    }

    private function resultado(?string $agente, string $modo, string $categoria, ?string $palavra, ?string $observacao): array
    {
        $rotulos = [
            'implementacao' => 'implementação', 'revisao' => 'revisão', 'planejamento' => 'planejamento',
            'investigacao' => 'investigação', 'apoio' => 'apoio técnico', 'relatorio' => 'relatório', 'operacao' => 'operação no sistema', 'codigo' => 'análise de código',
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
