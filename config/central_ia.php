<?php

return [
    // Quem administra os limites das outras contas é quem tem o perfil
    // Administrador e usa a Central (T-374) — ver App\Services\CentralIa\Governanca.
    // O e-mail de um "dono" no .env, que vinha antes, saiu: a Central é de um time.

    // Teto de uma mensagem. O Telegram corta em 4096; abaixo disso, a mesma
    // mensagem cabe nos dois canais sem ser partida.
    'max_texto' => 3000,

    'agentes' => [
        // Pedidos por pessoa por dia, contando todos os agentes. Cada pedido
        // gasta a assinatura de IA de quem pediu.
        'limite_diario' => (int) env('CENTRAL_IA_LIMITE_DIARIO', 20),
        // A franquia real é do provedor; o teto administrativo só bloqueia
        // quando explicitamente ligado. Fallback não conta como novo pedido.
        'limite_diario_ativo' => (bool) env('CENTRAL_IA_LIMITE_DIARIO_ATIVO', false),
        'fallback_automatico' => true,
        'fallback_pausa_limite_minutos' => 360,
        'fallback_pausa_indisponivel_minutos' => 5,
        // Teto de uma execução no runner. Quem passa disso é encerrado lá; aqui,
        // a execução sem notícia depois do teto + 5 min vira `expirada`.
        'timeout_minutos' => (int) env('CENTRAL_IA_TIMEOUT_MINUTOS', 15),
        // Implementar leva mais: a rodada de QA do gestor (10/10/2026) não
        // coube em 15 min e foi cortada no meio.
        'timeout_implementar_minutos' => (int) env('CENTRAL_IA_TIMEOUT_IMPLEMENTAR_MINUTOS', 60),
        // Execuções ao mesmo tempo por pessoa (teto do servidor; cada máquina
        // tem o seu, CENTRAL_IA_PARALELO no runner). Dois cabem numa máquina
        // de 6 CPUs e ~7 GB. Uma mesma tarefa nunca roda duas vezes ao mesmo tempo.
        'paralelo' => (int) env('CENTRAL_IA_PARALELO', 2),
        // Acesso dos agentes. `restrito` é o padrão (dono do produto,
        // 14/10/2026, T-368: "precisa de aprovação onde for necessário"): só
        // leitura ao analisar e, ao implementar, sem push, commit, docker, ssh
        // nem sudo. `total` tira a caixa de areia, como no VS Code, mas só vale
        // se a máquina também permitir, e mesmo nele push, ssh e publicação
        // só saem em pedido de deploy, que sempre pede o código (politica.mjs).
        'acesso' => env('CENTRAL_IA_ACESSO', 'restrito'),
        // Implementar direto na pasta do projeto (como no VS Code), e não numa
        // worktree. Com isso, um projeto só tem uma implementação por vez.
        'implementar_na_pasta' => (bool) env('CENTRAL_IA_IMPLEMENTAR_NA_PASTA', true),
        // Franquia mensal de requisições premium do plano do Copilot (Pro: 300,
        // Pro+: 1500). Sem ela, a tela mostra só o usado — não há como ler o
        // plano da máquina.
        'copilot_premium_mes' => env('CENTRAL_IA_COPILOT_PREMIUM_MES') !== null ? (int) env('CENTRAL_IA_COPILOT_PREMIUM_MES') : null,
    ],

    // Preferência por tipo de pedido no modo automático, na ordem: o primeiro
    // disponível na máquina leva. Ver App\Services\CentralIa\Roteador.
    'roteamento' => [
        'implementacao' => ['claude', 'codex', 'copilot'],
        'revisao' => ['codex', 'claude', 'copilot'],
        'planejamento' => ['codex', 'claude', 'copilot'],
        'investigacao' => ['codex', 'claude', 'copilot'],
        'apoio' => ['copilot', 'codex', 'claude'],
        // Operar um sistema (agendar, lançar, dar baixa): o Codex faz os dados.
        'operacao' => ['codex', 'claude', 'copilot'],
    ],

    /*
     * Níveis de custo (dono, 12/10/2026: "relatório da agenda é só para ler,
     * não preciso usar o fable"). Cada pedido vai ao MENOR nível que resolve:
     *  - sem_ia: dados oficiais mostrados direto (a lista de compromissos);
     *  - leve:   ler, resumir, relatar;
     *  - medio:  analisar, revisar, planejar, investigar;
     *  - forte:  implementar.
     * `modelo` nulo = o padrão da CLI. Modelos do catálogo local de 12/10
     * (`~/.codex/models_cache.json`, `claude --help`). O custo premium de cada
     * modelo do Copilot não está em nenhum arquivo local: fica `auto` até
     * medir (Constitution VIII — não presumir). Ajustável por conta.
     */
    'niveis' => [
        'leve' => [
            'claude' => ['modelo' => 'sonnet', 'esforco' => 'low'],
            'codex' => ['modelo' => 'gpt-5.6-luna', 'esforco' => 'low'],
            'copilot' => ['modelo' => 'auto', 'esforco' => 'low'],
        ],
        'medio' => [
            'claude' => ['modelo' => 'sonnet', 'esforco' => 'medium'],
            'codex' => ['modelo' => 'gpt-6-sol', 'esforco' => 'medium'],
            'copilot' => ['modelo' => 'auto', 'esforco' => 'medium'],
        ],
        'forte' => [
            'claude' => ['modelo' => null, 'esforco' => null],
            'codex' => ['modelo' => null, 'esforco' => null],
            'copilot' => ['modelo' => null, 'esforco' => null],
        ],
    ],

    'telegram' => [
        // Não há bot no servidor (T-374, decisão do dono em 14/10/2026): cada
        // pessoa cadastra o PRÓPRIO bot nos Ajustes da Central, e o token mora
        // cifrado em `central_ia_telegram_bots`. Os antigos
        // CENTRAL_IA_TELEGRAM_TOKEN/_BOT/_CONFIRMACAO saíram — o primeiro bot
        // era da conta pessoal de quem criou a Central.
        'codigo_minutos' => 10,
        // Quanto o getUpdates espera por novidade. É também o atraso máximo
        // de uma mensagem da tela até o celular, porque a entrega sai entre
        // uma leitura e outra.
        'espera_segundos' => 10,
    ],
];
