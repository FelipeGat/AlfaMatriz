<?php

return [
    // Teto de uma mensagem. O Telegram corta em 4096; abaixo disso, a mesma
    // mensagem cabe nos dois canais sem ser partida.
    'max_texto' => 3000,

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
