# Conexão da API Executiva ao ChatGPT — procedimento

Sem tokens neste arquivo. O token aparece UMA vez e vai direto para a configuração da Action.

## Emitir o token (produção)
No servidor da Matriz (LXC alfamatriz), como `www-data`, na pasta do app:

    php artisan alfa:executivo-token <e-mail-do-felipe>

Revogar: `php artisan alfa:executivo-token <e-mail-do-felipe> --revogar`.
Validade 30 dias (`EXECUTIVO_TOKEN_DIAS`). Cola-se direto no campo Bearer da Action; nunca em chat, arquivo ou log.

## GPT com Actions
1. Importar `openapi.yaml` (servidor `https://matriz.alfasolucoes.cloud`).
2. Autenticação: API Key → Bearer.
3. Teste: "estado das fontes" e "o que espera por mim hoje?".
4. Financeiro só depois de homologado; 4.4 segue pendente.

## Restringir o `/mcp` fora da tailnet (15/11/2026) — NÃO aplicado

**Quem usa o `/mcp` hoje** (lido do repositório e da configuração desta máquina, sem abrir tokens):
- `.mcp.json`: `alfamatriz-producao` e `alfamatriz-staging`, pelos hostnames da tailnet (`*.tail0939dd.ts.net`).
- Central de IA: o runner monta `ALFAMATRIZ_URL + /mcp` a cada pedido (`deploy/central-ia/politica.mjs`).
  **O runner desta máquina aponta para `https://matriz.alfasolucoes.cloud`, o hostname público.**
- Ponte do Telegram (`deploy/agente`): `http://10.0.3.115/mcp`, rede interna do Proxmox.
- Não dá para enumerar daqui as máquinas do Rossini e do Alexandre: o instalador grava a URL de onde foi baixado.

**Por que não bloqueei:** bloquear `/mcp` no hostname público derruba o quadro (MCP) dentro da Central de IA
nas máquinas que usam esse hostname, inclusive a do Felipe. Ordem segura:
1. Trocar `ALFAMATRIZ_URL` do runner para o hostname da tailnet (esta máquina o alcança: respondeu 401 a token inválido) e
   reiniciar o runner quando ocioso. Repetir nas máquinas dos outros.
2. Criar a regra abaixo em **Log** (ou observar `/mcp` nos logs) por alguns dias e ver se sobra consumidor no público.
3. Só então mudar a ação para **Block**.

**Regra Cloudflare (WAF > Custom rules)**, a mais simples por não depender de acesso ao servidor:
- Expressão: `(http.host eq "matriz.alfasolucoes.cloud" and starts_with(http.request.uri.path, "/mcp"))`
- Ação: Block. Não alcança `/api/executivo`, que não começa por `/mcp`.

**Alternativa no nginx do container (não testada):** o túnel entrega a `Host` e adiciona `CF-Connecting-IP`; tailnet e rede
interna não. Num `conf.d`: `map $http_cf_connecting_ip $mcp_externo { default 1; "" 0; }` e, no server `:80`,
`location ^~ /mcp { if ($mcp_externo) { return 404; } ... mesmo fastcgi do index.php ... }`. Precisa de `nginx -t` e ensaio antes.

**Como validar depois:** de fora, `POST /mcp` com JSON e token inválido deve dar 404 (hoje dá **401**, o que prova que a
autenticação pega, mas também que a rota está alcançável); pela tailnet continua 401. Depois, um `tools/list` com token válido
pela tailnet, e um pedido da Central de IA que use o quadro.

## Conferência do OpenAPI (15/11/2026)
- `@redocly/cli lint`: válido, 1 aviso cosmético (`info-license-strict`).
- Conferido contra as respostas reais (teste temporário, apagado): o envelope tem exatamente os 7 campos; `situacao` bate com o enum;
  `periodo` e `atualizado_em` podem ser `null`. **Divergência achada e corrigida no contrato:** `dados` vazio sai como `[]` (não `{}`)
  quando a fonte não está configurada (ex.: `emails` sem caixa). Também faltavam 401/403/429 em todas as operações.
- Não conferido: respostas com dados reais e o bloco `agenda` neste teste (coberto pelos testes do repositório).
