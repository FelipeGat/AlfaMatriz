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

## No Mac (onde ele roda desde 01/10/2026)

O disco do Proxmox saturou (RAID1 com um disco só, 14 contêineres num HD): um pedido mínimo levava
6 s e a suíte até 325 s. No Mac mini (M1, SSD) são 3,4 s e ~110 s, e a tailnet alcança a produção
direto. O agente roda num **usuário separado**, `agente`, sem a chave SSH do Proxmox nem o chaveiro
de quem trabalha na máquina — o isolamento que o LXC dava, sem o disco do LXC.

1. `sudo bash deploy/agente/preparar-mac.sh` — cria o usuário, a regra de sudoers e o serviço
   (`br.com.alfa.agente`, um LaunchDaemon com `UserName=agente`). É o único passo com senha.
2. Como `agente` (`sudo -u agente -H …`): Claude Code, `gh auth`, clone em `~/AlfaMatriz`,
   `composer install`, `npm ci && npm run build`, virtualenv do Whisper em `~/whisper`.
3. `~/alfa-agente/agente.env` (chmod 600) com as mesmas variáveis do exemplo, mais
   `WHISPER_MODELOS=/Users/agente/whisper/modelos`; e `~/alfa-agente/iniciar.sh` copiado de
   `iniciar-mac.sh`.
4. Login do Claude Code: `bash deploy/agente/guardar-token-claude.sh`, rodado pelo dono no usuário
   dele. O `agente` não tem chaveiro (nunca entrou pela tela), então o `/login` comum não fica
   gravado para um serviço; o script gera o token de longa duração (`claude setup-token`), confere
   com a Anthropic e o guarda no chaveiro do dono, de onde vai para `CLAUDE_CODE_OAUTH_TOKEN` no
   `agente.env`. Copiar o token à mão perdeu um caractere na quebra de linha do Terminal.
5. `sudo launchctl bootstrap system /Library/LaunchDaemons/br.com.alfa.agente.plist`. Reiniciar:
   `sudo launchctl kickstart -k system/br.com.alfa.agente`. Log em `~agente/alfa-agente/ponte.log`.

O Telegram aceita **um** ouvinte por bot: com o Mac ligado, o serviço do LXC fica desligado
(`systemctl disable --now alfa-agente`).

## Usar

Texto livre (ou áudio) é um pedido ao Claude, que continua a conversa entre mensagens.

**Duas faixas.** Pedido de quadro e agenda ("abre uma tarefa", "o que está travado") roda num agente
que só tem as ferramentas do MCP e responde em segundos — mesmo com uma tarefa de código em
andamento. Pedido de código (editar, suíte, commit) roda no clone, um por vez. Ninguém escolhe a
faixa: o agente do quadro recebe primeiro e, se o pedido não é dele, devolve um marcador e a ponte
passa adiante. A exceção é a conversa de código em curso com a faixa livre, que segue direto.

**Andamento.** Uma mensagem só, editada a cada passo ("rodando a suíte de testes", "editando
TarefaService.php"), em vez de silêncio até o fim ou de uma notificação por passo.

Comandos:

| Comando | O que faz |
|---|---|
| `/status` | branch, últimos commits, se há algo rodando |
| `/publicar v2026.09.30.1` | cria a tag na `main` remota e envia; o vigia publica em até 5 min |
| `/agendar 22:00 pedido` | roda o pedido hoje às 22h (ou `AAAA-MM-DD HH:MM pedido`) |
| `/agendados`, `/cancelar N` | lista e desmarca |
| `/parar` | interrompe o que estiver rodando nas duas faixas e esvazia as filas |
| `/novo` | conversa nova com o Claude |

## Áudio

Mensagem de voz vira texto na própria máquina, com o Whisper (`faster-whisper`, modelo `small`
em int8, português), e a ponte mostra "Entendi: …" antes de mandar ao Claude — quem ditou vê o que
ele vai ler. Nada sai da infra e não há conta em serviço externo. Instalação, uma vez:

```
apt-get install -y python3-venv && python3 -m venv /opt/whisper && /opt/whisper/bin/pip install --upgrade pip
/opt/whisper/bin/pip install --only-binary=:all: faster-whisper
/opt/whisper/bin/python -c 'from faster_whisper import WhisperModel; WhisperModel("small", device="cpu", compute_type="int8", download_root="/opt/whisper/modelos")'
```

e `AGENTE_TRANSCRITOR=/opt/dev/AlfaMatriz/deploy/agente/transcrever.py` no `/etc/alfa-agente.env`.
Conta uns 30 a 60 segundos por minuto de áudio nos 4 núcleos do LXC; no Mac, poucos segundos. O
`transcrever.py` decodifica o áudio por conta própria (ver o comentário nele): o decodificador do
`faster-whisper` 1.2 quebra com o PyAV 15+, que é o que o `pip` instala. `--only-binary` porque sem
pacote pronto o `pip` tenta compilar o PyAV.

## Atualizar

`git -C /opt/dev/AlfaMatriz pull && systemctl restart alfa-agente`. O estado (offset do Telegram,
sessões por chat, agendamentos) fica em `/var/lib/alfa-agente/estado.json` e sobrevive ao restart.
