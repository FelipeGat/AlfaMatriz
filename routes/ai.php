<?php

use App\Mcp\Servers\AlfaMatrizServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * O servidor MCP do AlfaMatriz, por duas portas.
 *
 * `Mcp::local` é o processo na própria máquina: quem o inicia já está nela, e a
 * identidade vem do ambiente (`MCP_USUARIO`). É a porta do desenvolvimento.
 *
 * `Mcp::web` é a porta de quem fala de FORA — o agente no LXC e o Claude Code
 * de outra máquina — com o sistema que está no ar. Autorizada pelo dono do
 * produto em 30/09/2026. É o padrão de mercado para MCP remoto: HTTP com token
 * portador, e não SSH até o servidor. O token é pessoal (Sanctum,
 * `alfa:mcp-token`), guardado como hash, com a capacidade `mcp` e mais
 * nenhuma: quem o tiver fala com o quadro e a agenda em nome de quem o emitiu,
 * e com nada além disso. A rota não passa pelo grupo `web` (sem sessão, sem
 * CSRF), e o `throttle` é o freio para um agente em loop.
 *
 * Exposta só onde o app já é exposto: em produção e no staging o nginx atende
 * pela tailnet, então `/mcp` também só existe lá dentro.
 */
Mcp::local('alfamatriz', AlfaMatrizServer::class);

Mcp::web('/mcp', AlfaMatrizServer::class)
    ->middleware(['auth:sanctum', 'abilities:mcp', 'throttle:60,1']);
