<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // O segredo do webhook do GitHub (#211): o mesmo valor configurado em cada
    // repositório. Vazio, a rota recusa tudo — ver `GitHubWebhookController`.
    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
    ],

    // O grupo de alertas do Telegram, onde o vigia de logs avisa (#219). Sem
    // os dois, o aviso não sai e fica registrado no log — ver `AvisoNoTelegram`.
    // O AlfaMonitor (#394). `url` é a web dele: abre o `link` relativo de
    // cada alerta e é a base da API de leitura (`/api/v1/…`) que as telas do
    // Monitor consultam. `api_token` é o token de MÁQUINA só de leitura que o
    // monitor emite em Config → "API de leitura" — outro, e no sentido
    // contrário, do `alfa:monitor-token` do webhook. Sem os dois, as telas
    // dizem que o monitor não está ligado em vez de quebrar.
    'alfamonitor' => [
        'url' => env('ALFAMONITOR_URL'),
        'api_token' => env('ALFAMONITOR_API_TOKEN'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id_alertas' => env('TELEGRAM_CHAT_ID_ALERTAS'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
