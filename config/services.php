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
    // O AlfaMonitor (#394). `url` é a web dele, para o NAVEGADOR de quem
    // olha: abre o `link` relativo de cada alerta. `api_url` é por onde o
    // SERVIDOR do Matriz chega à API de leitura (`/api/v1/…`) — separada
    // porque os dois caminhos não são o mesmo: a ACL do Tailscale não deixa o
    // container do Matriz chegar ao monitor, e a API vai pela rede interna do
    // alfa-server (`http://10.0.3.146:8080`, combinado com a Central
    // AlfaMonitor em 17/10/2026; o tráfego não sai do host). Sem `api_url`,
    // vale a `url`. `api_token` é o token de MÁQUINA só de leitura que o
    // monitor emite em Config → "API de leitura" — outro, e no sentido
    // contrário, do `alfa:monitor-token` do webhook. Sem eles, as telas
    // dizem que o monitor não está ligado em vez de quebrar.
    // Orçamentos VixCard (docs/specs/orcamentos-vixcard): o PDF sai pelo Chromium do sistema.
    'orcamentos' => [
        // Caminho absoluto: o PHP-FPM roda sem PATH (clear_env).
        'chromium' => env('ORCAMENTOS_CHROMIUM', '/usr/bin/chromium'),
    ],

    'alfamonitor' => [
        'url' => env('ALFAMONITOR_URL'),
        'api_url' => env('ALFAMONITOR_API_URL', env('ALFAMONITOR_URL')),
        'api_token' => env('ALFAMONITOR_API_TOKEN'),
    ],

    // VixOrganize (docs/specs/secretario-vixorganize): endereço e chave são da
    // conta, em Secretário → Configurações. Isto só libera http e IP privado no
    // teste local ponta a ponta — em produção, fica desligado.
    'vixorganize' => [
        'permite_local' => env('VIXORGANIZE_PERMITE_LOCAL') === 'sim',
    ],

    // Boleto da revenda (cobranca-revendas-boleto): a mesma conta do Mercado Pago
    // do Gestor (Felipe, 18/10/2026). Só no .env do servidor; sem ele, nada emite.
    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id_alertas' => env('TELEGRAM_CHAT_ID_ALERTAS'),
    ],

    // Google Agenda do Secretário (docs/specs/secretario-agenda): cliente OAuth
    // "Aplicativo da Web" da PRÓPRIA conta do dono. Sem os dois, a tela diz
    // que a integração não está configurada e nada mais muda.
    'google_secretario' => [
        'client_id' => env('GOOGLE_SECRETARIO_CLIENT_ID'),
        'client_secret' => env('GOOGLE_SECRETARIO_CLIENT_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
