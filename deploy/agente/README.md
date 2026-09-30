# O agente do AlfaMatriz

Um serviço no LXC `dev` (108) do Proxmox que ouve o bot do Telegram e, a cada mensagem de quem pode
comandar, roda o Claude Code em modo headless dentro do clone deste repositório. É a fase 2 da
integração pedida pelo dono do produto em 28/09/2026 (a fase 1 é o servidor MCP, em `app/Mcp/`).

O desenho, e o porquê, está no cabeçalho de `ponte-telegram.mjs`. Em uma linha: ele só age quando
alguém manda, só de quem está na lista, um pedido por vez, e nunca cria tag — a tag é o `/publicar`,
digitado pela pessoa.

## Instalar (uma vez, no LXC 108)

O LXC já tem PHP 8.3, Composer, Node, Git autenticado no GitHub, o clone em `/opt/dev/AlfaMatriz`
com a suíte verde, e o Claude Code em `/root/.local/bin/claude`.

1. **Login do Claude Code** — só o dono, porque é a conta dele:
   `ssh -t alfa-server pct exec 108 -- claude login`
2. **Ambiente**: copie `alfa-agente.env.example` para `/etc/alfa-agente.env` (chmod 600) e preencha
   `TELEGRAM_TOKEN` (chaveiro do macOS, `alfa-telegram-bot`) e `ALFAMATRIZ_MCP_TOKEN` (emitido em
   produção com `php artisan alfa:mcp-token <email> --nome=lxc-dev`). Deixe `TELEGRAM_PERMITIDOS`
   vazio na primeira subida.
   O LXC não alcança a produção pela tailnet (o Tailscale nega o par), só pela rede interna: por isso
   `AGENTE_MCP_CONFIG` aponta para um `/etc/alfa-agente-mcp.json` com `http://10.0.3.115/mcp` e o
   token, que substitui o `.mcp.json` do repositório nas rodadas do agente. Não faça `source` do
   `.env`: o token tem `|` e vira pipe no shell — o systemd lê o arquivo sem shell.
3. **Serviço**: `cp deploy/agente/alfa-agente.service /etc/systemd/system/ && systemctl daemon-reload
   && systemctl enable --now alfa-agente`.
4. Mande qualquer mensagem ao bot: ele responde "Não conheço você. Seu id é N." Ponha o N em
   `TELEGRAM_PERMITIDOS` e `systemctl restart alfa-agente`.

## Usar

Texto livre é um pedido ao Claude, que continua a conversa entre mensagens. Comandos:

| Comando | O que faz |
|---|---|
| `/status` | branch, últimos commits, se há algo rodando |
| `/publicar v2026.09.30.1` | cria a tag na `main` remota e envia; o vigia publica em até 5 min |
| `/agendar 22:00 pedido` | roda o pedido hoje às 22h (ou `AAAA-MM-DD HH:MM pedido`) |
| `/agendados`, `/cancelar N` | lista e desmarca |
| `/parar` | interrompe o que estiver rodando |
| `/novo` | conversa nova com o Claude |

## Atualizar

`git -C /opt/dev/AlfaMatriz pull && systemctl restart alfa-agente`. O estado (offset do Telegram,
sessões por chat, agendamentos) fica em `/var/lib/alfa-agente/estado.json` e sobrevive ao restart.
