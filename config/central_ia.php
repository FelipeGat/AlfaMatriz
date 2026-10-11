<?php

return [
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
        // Execuções ao mesmo tempo por pessoa. A máquina do Felipe tem 6 CPUs
        // e ~7 GB na WSL: cinco projetos À VISTA, dois rodando (dono, 10/10).
        // Uma mesma tarefa nunca roda duas vezes ao mesmo tempo.
        'paralelo' => (int) env('CENTRAL_IA_PARALELO', 2),
        // Acesso dos agentes. `total` (dono, 10/10/2026: "permita tudo"): sem
        // caixa de areia nem comandos negados — igual ao VS Code. `restrito`
        // volta às regras de antes (só leitura ao analisar, worktree ao
        // implementar, sem docker/ssh/push).
        'acesso' => env('CENTRAL_IA_ACESSO', 'total'),
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
    ],

    'telegram' => [
        // Só o processo do bot (`central-ia:telegram`) recebe o token. A
        // aplicação web não chama o Telegram: ela grava a entrega e o processo
        // do bot é quem a leva.
        'token' => env('CENTRAL_IA_TELEGRAM_TOKEN'),
        'bot' => env('CENTRAL_IA_TELEGRAM_BOT', 'GrupoSolucoesAgentBot'),
        'codigo_minutos' => 10,
        // Quanto o getUpdates espera por novidade. É também o atraso máximo
        // de uma mensagem da tela até o celular, porque a entrega sai entre
        // uma leitura e outra.
        'espera_segundos' => 10,
    ],
];
