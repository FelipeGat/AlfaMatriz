<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Integração com os sistemas da casa
    |--------------------------------------------------------------------------
    |
    | Nenhum segredo mora aqui. O endereço e a chave de cada sistema ficam no
    | próprio cadastro do sistema (a chave, cifrada). Isto aqui é só o
    | comportamento do conector, igual para todos.
    |
    */

    // Tempo, em segundos, para o sistema responder. Curto de propósito: uma
    // tela do painel não pode ficar pendurada esperando um sistema doente.
    'timeout' => (int) env('INTEGRACAO_TIMEOUT', 15),

    // Tempo para apenas abrir a conexão. Sistema fora do ar precisa falhar
    // rápido, não consumir o tempo inteiro de resposta.
    'timeout_conexao' => (int) env('INTEGRACAO_TIMEOUT_CONEXAO', 5),

    // Novas tentativas em falha de conexão, excesso de pedidos e erro do
    // servidor. Nunca em recusa: repetir não muda um "não".
    'tentativas' => (int) env('INTEGRACAO_TENTATIVAS', 2),
    'espera_entre_tentativas' => (int) env('INTEGRACAO_ESPERA_MS', 300),

    // Paginação pedida ao sistema. O contrato limita o tamanho a 500.
    'tamanho_pagina' => (int) env('INTEGRACAO_TAMANHO_PAGINA', 200),

    // Teto de páginas por escopo numa sincronização disparada da tela, para
    // ela não estourar o tempo do servidor web. A varredura completa fica com
    // a execução agendada, que roda pela linha de comando e não tem esse teto.
    'max_paginas_sob_demanda' => (int) env('INTEGRACAO_MAX_PAGINAS', 10),

    // A partir de quantas horas o retrato local é considerado velho demais
    // para se confiar, e a tela passa a avisar.
    'horas_para_retrato_velho' => (int) env('INTEGRACAO_HORAS_RETRATO_VELHO', 24),

    // Janela, em dias, do que a tela de licenças considera "vencendo".
    'dias_para_licenca_vencendo' => (int) env('INTEGRACAO_DIAS_VENCENDO', 30),

    // Versão principal do contrato que este painel entende. Resposta com
    // versão principal diferente é recusada, em vez de virar retrato torto.
    'contrato_major' => 1,

];
