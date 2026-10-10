<?php

return [
    // Teto de uma mensagem. O Telegram corta em 4096; abaixo disso, a mesma
    // mensagem cabe nos dois canais sem ser partida.
    'max_texto' => 3000,

    'agentes' => [
        // Pedidos por pessoa por dia, contando todos os agentes. Cada pedido
        // gasta a assinatura de IA de quem pediu.
        'limite_diario' => (int) env('CENTRAL_IA_LIMITE_DIARIO', 20),
        // Teto de uma execução no runner. Quem passa disso é encerrado lá; aqui,
        // a execução sem notícia depois do teto + 5 min vira `expirada`.
        'timeout_minutos' => (int) env('CENTRAL_IA_TIMEOUT_MINUTOS', 15),
        // Execuções ao mesmo tempo por pessoa. A máquina do Felipe tem 6 CPUs
        // e ~7 GB na WSL: cinco projetos À VISTA, dois rodando (dono, 10/10).
        // Uma mesma tarefa nunca roda duas vezes ao mesmo tempo.
        'paralelo' => (int) env('CENTRAL_IA_PARALELO', 2),
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
        // Quem segura a trava do bot. Padrão: o hostname (estável num restart
        // do container). Defina só se dois processos legítimos dividirem o host.
        'dono' => env('CENTRAL_IA_TELEGRAM_DONO'),
        'codigo_minutos' => 10,
        // Quanto o getUpdates espera por novidade. É também o atraso máximo
        // de uma mensagem da tela até o celular, porque a entrega sai entre
        // uma leitura e outra.
        'espera_segundos' => 10,
    ],
];
