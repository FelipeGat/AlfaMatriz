<?php

return [

    /*
     * Protótipo da tela Conversas (T-602): o chat interno do time, só desenho,
     * com dados fixos e nada gravado. Serve para o dono do produto ver a ideia
     * no staging ANTES de decidir se ela vira funcionalidade.
     *
     * Ligado por padrão só quando APP_ENV=staging, para não depender de mexer
     * no .env do servidor; `ALFA_CONVERSAS_PROTOTIPO` vence nos dois sentidos.
     * Em produção (APP_ENV=production) fica desligado: a rota responde 404 e o
     * menu não mostra o item — protótipo visto pelo cliente vira promessa.
     */
    'conversas_prototipo' => (bool) env('ALFA_CONVERSAS_PROTOTIPO', env('APP_ENV') === 'staging'),

];
