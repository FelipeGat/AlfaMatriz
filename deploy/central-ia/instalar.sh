#!/usr/bin/env bash
# Instalador do runner da Central de IA — Linux, WSL e Mac (T-374).
#
# Não se roda daqui: o AlfaMatriz o serve em /central-ia/instalar.sh com o
# endereço dele já dentro, e a tela "Conectar minha máquina" (Ajustes da
# Central) mostra o comando pronto:
#
#   curl -fsSL <AlfaMatriz>/central-ia/instalar.sh | CENTRAL_IA_CODIGO=<código> bash
#
# O código de confirmação dos pedidos sai por um bot do Telegram SÓ desta
# pessoa e SÓ para isso (o "bot do código"), diferente do bot em que ela
# conversa com a Central. O token dele é colado aqui, no terminal (ou vem em
# CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO), fica no arquivo 0600 da máquina e
# nunca passa pelo servidor: com ele no servidor, quem o invadisse mandaria o
# código a si mesmo e confirmaria o que quisesse (T-368, revisão da T-374).
#
# O código vem no AMBIENTE, e não como argumento: argumento aparece no `ps` de
# qualquer usuário da máquina; o ambiente, só para o dono do processo. O
# `bash -s -- <código>` dos comandos antigos continua aceito.
#
# Com o código (uso único, 15 min), conecta a máquina: troca o código pelo
# token, pergunta a pasta dos projetos, grava a configuração (0600) e liga o
# serviço. Sem o código, ATUALIZA: baixa o runner de novo e reinicia o serviço
# quando ele estiver ocioso, sem mexer na configuração.
#
# Opções: --projetos <pasta>   --maquina <nome>   --sem-anexos (não prepara a
# imagem que converte PDF e vídeo).
#
# Tudo dentro de `main`, chamada só na última linha: se o download pelo
# `curl | bash` cair no meio, o bash não executa a metade que chegou.
main() {
set -euo pipefail

URL="__ALFAMATRIZ_URL__"
ARQUIVOS="__ARQUIVOS__"
ROTULO="br.com.alfa.central-ia-runner"
IMAGEM="alfamatriz-central-media:local"

CODIGO="${CENTRAL_IA_CODIGO:-}" PROJETOS="" MAQUINA="" ANEXOS=1
while [ $# -gt 0 ]; do
  case "$1" in
    --projetos) PROJETOS="${2:-}"; shift 2 ;;
    --maquina) MAQUINA="${2:-}"; shift 2 ;;
    --sem-anexos) ANEXOS=0; shift ;;
    -*) echo "Opção desconhecida: $1" >&2; exit 2 ;;
    *) CODIGO="$1"; shift ;;
  esac
done

ok()    { printf '✓ %s\n' "$*"; }
aviso() { printf '! %s\n' "$*" >&2; }
erro()  { printf '✗ %s\n' "$*" >&2; exit 1; }

# A pergunta vem do teclado mesmo com o script chegando pelo `curl | bash`.
perguntar() { # $1 = pergunta, $2 = padrão
  local resposta=""
  if [ -r /dev/tty ] && { exec 3</dev/tty; } 2>/dev/null; then
    printf '%s [%s]: ' "$1" "$2" >/dev/tty
    IFS= read -r resposta <&3 || true
    exec 3<&-
  fi
  printf '%s' "${resposta:-$2}"
}

# Segredo: sem eco no terminal e sem padrão.
perguntar_segredo() { # $1 = pergunta
  local resposta=""
  if [ -r /dev/tty ] && { exec 3</dev/tty; } 2>/dev/null; then
    printf '%s: ' "$1" >/dev/tty
    IFS= read -rs resposta <&3 || true
    printf '\n' >/dev/tty
    exec 3<&-
  fi
  printf '%s' "$resposta"
}

# ---------- sistema e pré-requisitos ----------
case "$(uname -s)" in
  Darwin) SISTEMA=mac ;;
  Linux) if grep -qi microsoft /proc/version 2>/dev/null; then SISTEMA=wsl; else SISTEMA=linux; fi ;;
  *) erro "No Windows, rode este comando dentro do WSL (Ubuntu). O runner roda no Linux, no WSL e no Mac." ;;
esac

command -v curl >/dev/null || erro "Falta o curl."
command -v git >/dev/null || erro "Falta o git. Mac: xcode-select --install. Linux/WSL: sudo apt install git."
NODE="$(command -v node || true)"
[ -n "$NODE" ] || erro "Falta o Node 18 ou mais novo. Mac: brew install node. Linux/WSL: https://nodejs.org (ou nvm)."
"$NODE" -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 18 ? 0 : 1)' \
  || erro "O Node desta máquina é antigo ($("$NODE" -v)). A Central precisa do 18 ou mais novo."

# O serviço guarda o caminho deste node. No nvm o caminho tem a versão, e
# trocar de versão (ou apagar a antiga) para o serviço até rodar o comando de
# atualizar de novo.
case "$NODE" in
  */.nvm/*|*/.fnm/*|*/.volta/*|*/.asdf/*) aviso "O serviço vai usar o node em $NODE. Se trocar a versão do node, rode o comando de atualizar de novo." ;;
esac

# A tailnet: o AlfaMatriz só atende por ela.
if ! curl -fsS --max-time 15 -o /dev/null "$URL/healthz"; then
  erro "Esta máquina não alcança o AlfaMatriz ($URL). Ligue o Tailscale nela (entrar na tailnet da Alfa) e rode o comando de novo."
fi
ok "AlfaMatriz alcançado ($URL) · sistema: $SISTEMA"

umask 077
CONF_DIR="$HOME/.config/alfa"
CONF="$CONF_DIR/central-ia-runner.env"
DEST="$HOME/.local/share/alfa"
mkdir -p "$CONF_DIR" "$DEST"

ler_conf() { [ -f "$CONF" ] && sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" "$CONF" | tail -1 | sed "s/^[\"']//; s/[\"']\$//" || true; }

if [ -z "$CODIGO" ] && [ -z "$(ler_conf ALFAMATRIZ_RUNNER_TOKEN)" ]; then
  erro "Esta máquina ainda não está conectada. Gere o comando em Central de IA › Ajustes › Conectar minha máquina."
fi

# ---------- arquivos do runner ----------
TMP="$(mktemp -d)"
# Ctrl-C na pergunta do token (lida sem eco) deixava o terminal sem eco.
trap 'rm -rf "$TMP"; stty echo </dev/tty >/dev/null 2>&1 || true' EXIT
trap 'exit 130' INT TERM
for f in $ARQUIVOS; do
  curl -fsS --max-time 60 "$URL/central-ia/runner/arquivos/$f" -o "$TMP/$f" || erro "Não consegui baixar $f do AlfaMatriz."
  case "$f" in *.mjs) "$NODE" --check "$TMP/$f" || erro "O arquivo $f chegou corrompido." ;; esac
done
# O runner fica fora de qualquer repositório: trocar de branch num checkout
# não troca o programa que está rodando.
for f in $ARQUIVOS; do
  alvo="$DEST/$f"; [ "$f" = runner.mjs ] && alvo="$DEST/central-ia-runner.mjs"
  mv -f "$TMP/$f" "$alvo"
done
chmod 700 "$DEST/atualizar-quando-ocioso.sh"
ok "Runner baixado em $DEST"

# ---------- conectar (com código) ----------
if [ -n "$CODIGO" ]; then
  # A troca vem ANTES de qualquer pergunta: o código vale até ser trocado, e
  # a pergunta da pasta pode ficar minutos esperando (revisão da T-374).
  NOVA=false; [ -n "$(ler_conf ALFAMATRIZ_RUNNER_TOKEN)" ] || NOVA=true
  [ -n "$MAQUINA" ] || MAQUINA="$(ler_conf CENTRAL_IA_MAQUINA)"
  [ -n "$MAQUINA" ] || MAQUINA="$(hostname -s 2>/dev/null || hostname)"
  MAQUINA="$(printf '%s' "$MAQUINA" | tr -c 'A-Za-z0-9._-' '-' | cut -c1-64)"
  # O corpo é montado pelo node (escapa o que vier) e vai pelo stdin do curl,
  # fora da linha de comando.
  RESPOSTA="$(CODIGO="$CODIGO" MAQUINA="$MAQUINA" NOVA="$NOVA" "$NODE" -e \
      'process.stdout.write(JSON.stringify({ codigo: process.env.CODIGO, maquina: process.env.MAQUINA, nova: process.env.NOVA === "true" }))' \
    | curl -sS --max-time 30 -w '\n%{http_code}' -X POST \
      -H 'Accept: application/json' -H 'Content-Type: application/json' \
      --data-binary @- "$URL/api/central-ia/instalacao")" \
    || erro "Não alcancei o AlfaMatriz para trocar o código. Confira o Tailscale e rode o comando de novo."
  STATUS="${RESPOSTA##*$'\n'}"; CORPO="${RESPOSTA%$'\n'*}"
  [ "$STATUS" = 200 ] || erro "Código de instalação inválido, já usado ou vencido. Gere outro em Ajustes › Conectar minha máquina."
  # Outra máquina desta pessoa já tinha o nome: o servidor deu um sufixo.
  MAQUINA="$(CORPO="$CORPO" "$NODE" -e 'process.stdout.write(String(JSON.parse(process.env.CORPO).maquina || ""))')"

  padrao="$(ler_conf CENTRAL_IA_PROJETOS)"
  if [ -z "$padrao" ]; then
    padrao="$HOME/projetos"
    for d in "$HOME/projetos" "$HOME/dev" "$HOME/Projects" "$HOME/code" "$HOME/src"; do
      [ -d "$d" ] && { padrao="$d"; break; }
    done
  fi
  [ -n "$PROJETOS" ] || PROJETOS="$(perguntar "Pasta dos seus projetos (cada subpasta vira um projeto na Central)" "$padrao")"
  PROJETOS="${PROJETOS/#\~/$HOME}"
  [ -d "$PROJETOS" ] || { mkdir -p "$PROJETOS"; aviso "Criei a pasta $PROJETOS (estava vazia)."; }
  PROJETOS="$(cd "$PROJETOS" && pwd -P)"

  # Grava a configuração mantendo o que a pessoa já tinha (teto de acesso,
  # pastas ignoradas, agenda…). O segredo da confirmação nasce AQUI e nunca
  # sai da máquina; se já existe, fica o mesmo.
  CORPO="$CORPO" CONF="$CONF" URL="$URL" PROJETOS="$PROJETOS" MAQUINA="$MAQUINA" "$NODE" --input-type=module -e '
    import { readFileSync, writeFileSync, chmodSync, existsSync } from "node:fs";
    import { randomBytes } from "node:crypto";
    const r = JSON.parse(process.env.CORPO);
    const linhas = existsSync(process.env.CONF) ? readFileSync(process.env.CONF, "utf8").split("\n") : ["# Central de IA — configuração desta máquina (só a pessoa lê)."];
    const atual = k => { for (const l of linhas) { const m = l.match(new RegExp(`^\\s*${k}\\s*=\\s*(.*?)\\s*$`)); if (m) return m[1].replace(/^["\x27]|["\x27]$/g, ""); } return null; };
    // Chave repetida (arquivo editado à mão): o runner lê a ÚLTIMA. Grava no
    // lugar da primeira e apaga as outras, senão o valor novo não valia.
    const definir = (k, v) => {
      if (v === null || v === undefined || v === "") return;
      const re = new RegExp(`^\\s*${k}\\s*=`);
      const i = linhas.findIndex(l => re.test(l));
      if (i < 0) { linhas.push(`${k}=${v}`); return; }
      linhas[i] = `${k}=${v}`;
      for (let j = linhas.length - 1; j > i; j--) if (re.test(linhas[j])) linhas.splice(j, 1);
    };
    definir("ALFAMATRIZ_URL", process.env.URL);
    definir("ALFAMATRIZ_RUNNER_TOKEN", r.token);
    definir("CENTRAL_IA_PROJETOS", process.env.PROJETOS);
    definir("CENTRAL_IA_MAQUINA", process.env.MAQUINA);
    if (!/^[0-9a-f]{64}$/i.test(atual("CENTRAL_IA_SEGREDO_CONFIRMACAO") || "")) definir("CENTRAL_IA_SEGREDO_CONFIRMACAO", randomBytes(32).toString("hex"));
    definir("CENTRAL_IA_TELEGRAM_CHAT_ID", r.telegram?.chat_id);
    // Só o @ do bot da CONVERSA (nunca o token): o comando de atualizar, sem
    // código, também precisa saber qual bot recusar como bot do código.
    definir("CENTRAL_IA_TELEGRAM_BOT_CONVERSA", r.telegram?.bot);
    writeFileSync(process.env.CONF, linhas.join("\n").replace(/\n*$/, "\n"), { mode: 0o600 });
    chmodSync(process.env.CONF, 0o600);
    console.log(JSON.stringify({ conta: r.conta, bot: r.telegram?.bot || null, chat: !!r.telegram?.chat_id }));
  ' > "$TMP/conectada.json"
  ok "Máquina \"$MAQUINA\" conectada à conta $(sed -n 's/.*"conta":"\([^"]*\)".*/\1/p' "$TMP/conectada.json") · projetos em $PROJETOS"
  ok "Configuração em $CONF (só você lê)"
fi

# ---------- Telegram: o bot do código ----------
# ANTES de ligar o serviço: o runner que sobe já precisa achar o token no
# arquivo. Depois do serviço, a máquina recém-instalada ficava sem o bot do
# código até reiniciar por outro motivo, e recusava todo pedido que modifica
# arquivos (revisão da T-374). O runner também relê o arquivo antes de cada
# confirmação, para o caso de alguém editá-lo à mão.
# Trocável só para o teste do instalador (tools/central-ia/test-instalador.mjs).
TG_API="${CENTRAL_IA_TELEGRAM_API:-https://api.telegram.org}"
# O @ do bot de um token, pelo getMe; vazio se o Telegram recusar.
bot_do_token() {
  TG_TOKEN="$1" TG_API="$TG_API" "$NODE" -e '
    fetch(`${process.env.TG_API}/bot${process.env.TG_TOKEN}/getMe`, { signal: AbortSignal.timeout(15000) })
      .then(r => r.json()).then(j => { if (!j.ok) process.exit(1); process.stdout.write(String(j.result.username || "")); })
      .catch(() => process.exit(1));' 2>/dev/null || true
}
# Grava (ou, com valor vazio, apaga) uma chave da configuração.
gravar_conf() {
  CONF="$CONF" K="$1" V="$2" "$NODE" -e '
    const fs = require("node:fs");
    const re = new RegExp(`^\\s*${process.env.K}\\s*=`);
    const linhas = (fs.existsSync(process.env.CONF) ? fs.readFileSync(process.env.CONF, "utf8") : "").split("\n").filter(l => !re.test(l));
    if (process.env.V) linhas.push(`${process.env.K}=${process.env.V}`);
    fs.writeFileSync(process.env.CONF, linhas.join("\n").replace(/^\n+/, "").replace(/\n*$/, "\n"), { mode: 0o600 });
    fs.chmodSync(process.env.CONF, 0o600);'
}

TG_CHAT="$(ler_conf CENTRAL_IA_TELEGRAM_CHAT_ID)"
# O bot da CONVERSA (só o @): vem do servidor na conexão e fica gravado para o
# comando de atualizar, que não tem código. Não serve de bot do código.
CONVERSA="$(ler_conf CENTRAL_IA_TELEGRAM_BOT_CONVERSA)"
NOVO="${CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO:-}"
ATUAL="$(ler_conf CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO)"
# A chave antiga das máquinas instaladas à mão. Nela costumava estar o bot da
# conversa, cujo token o servidor tem, e o runner já não a aceita: vira bot do
# código só se o getMe disser que é OUTRO bot que não o da conversa (revisão da
# T-374). Em qualquer caso sai do arquivo.
LEGADO="$(ler_conf CENTRAL_IA_TELEGRAM_TOKEN)"
if [ -n "$LEGADO" ]; then
  if [ -z "$ATUAL" ] && [ -z "$NOVO" ] && [ -n "$CONVERSA" ]; then
    NOME_LEGADO="$(bot_do_token "$LEGADO")"
    if [ -n "$NOME_LEGADO" ] && [ "$NOME_LEGADO" != "$CONVERSA" ]; then
      gravar_conf CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO "$LEGADO"
      ATUAL="$LEGADO"
      ok "Bot do código: @$NOME_LEGADO (veio da configuração antiga desta máquina)."
    fi
  fi
  gravar_conf CENTRAL_IA_TELEGRAM_TOKEN ""
fi
if [ -n "$ATUAL" ] && [ -n "$CONVERSA" ] && [ "$(bot_do_token "$ATUAL")" = "$CONVERSA" ]; then
  # Instalação anterior aos dois bots: o código saía pelo bot da conversa,
  # cujo token o servidor tem. Sai daqui, e a máquina recusa modificar
  # arquivos até ganhar o bot do código.
  gravar_conf CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO ""
  ATUAL=""
  aviso "O código saía pelo @$CONVERSA, o bot em que você conversa com a Central. Agora ele sai por um bot só para isso."
fi
if [ -z "$NOVO" ] && [ -z "$ATUAL" ] && [ -n "$TG_CHAT" ]; then
  echo
  echo "O código de confirmação dos seus pedidos chega por um bot do Telegram só para isso, diferente do bot da conversa."
  echo "No @BotFather: /newbot, um nome (ex.: Código do seu nome) e um @ terminado em bot. Depois abra o bot novo e toque em Começar."
  NOVO="$(perguntar_segredo "Cole aqui o token do bot do código (Enter para deixar para depois)")"
fi
if [ -n "$NOVO" ]; then
  NOME="$(bot_do_token "$NOVO")"
  if [ -z "$CONVERSA" ]; then
    # Sem saber qual é o bot da conversa não há como recusá-lo aqui. Só
    # acontece em máquina conectada antes desta versão do instalador.
    aviso "Não sei qual é o seu bot da conversa, então não posso conferir esse token."
    aviso "Gere um comando novo em Central de IA › Ajustes › Minhas máquinas e rode-o aqui."
  elif [ -z "$NOME" ]; then
    aviso "O Telegram recusou esse token. Confira no @BotFather e rode o comando de novo."
  elif [ -n "$CONVERSA" ] && [ "$NOME" = "$CONVERSA" ]; then
    aviso "Esse é o @$NOME, o bot em que você conversa com a Central. O código precisa de um bot só dele: crie outro no @BotFather."
  else
    gravar_conf CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO "$NOVO"
    ATUAL="$NOVO"
    ok "Bot do código: @$NOME (o token fica só nesta máquina)."
  fi
fi

# ---------- serviço ----------
RODANDO=0 RECARREGAR=0
if [ "$SISTEMA" = mac ]; then
  PLIST="$HOME/Library/LaunchAgents/$ROTULO.plist"
  mkdir -p "$HOME/Library/LaunchAgents" "$HOME/Library/Logs"
  xml() { printf '%s' "$1" | sed 's/&/\&amp;/g; s/</\&lt;/g; s/>/\&gt;/g'; }
  PLIST_ANTES="$(cat "$PLIST" 2>/dev/null || true)"
  cat > "$PLIST" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$ROTULO</string>
  <key>ProgramArguments</key>
  <array><string>$(xml "$NODE")</string><string>$(xml "$DEST/central-ia-runner.mjs")</string></array>
  <key>EnvironmentVariables</key>
  <dict><key>PATH</key><string>$(xml "$PATH")</string><key>HOME</key><string>$(xml "$HOME")</string></dict>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>ThrottleInterval</key><integer>10</integer>
  <key>ExitTimeOut</key><integer>60</integer>
  <key>StandardOutPath</key><string>$(xml "$HOME/Library/Logs/central-ia-runner.log")</string>
  <key>StandardErrorPath</key><string>$(xml "$HOME/Library/Logs/central-ia-runner.log")</string>
</dict>
</plist>
EOF
  if launchctl print "gui/$(id -u)/$ROTULO" >/dev/null 2>&1; then
    RODANDO=1
    # O `kickstart` não relê o plist: mudou (o caminho do node, o PATH), o
    # serviço precisa ser descarregado e carregado (revisão da T-374).
    [ "$PLIST_ANTES" = "$(cat "$PLIST")" ] || RECARREGAR=1
  else
    launchctl bootstrap "gui/$(id -u)" "$PLIST" || erro "O macOS não aceitou o serviço ($PLIST)."
  fi
  LOG="tail -f ~/Library/Logs/central-ia-runner.log"
  LOG_ATUALIZAR="$HOME/Library/Logs/central-ia-runner.log"
else
  if ! systemctl --user show-environment >/dev/null 2>&1; then
    aviso "O systemd não está ligado nesta máquina, e é ele que mantém o runner rodando."
    if [ "$SISTEMA" = wsl ]; then
      aviso "No WSL: rode  printf '[boot]\\nsystemd=true\\n' | sudo tee -a /etc/wsl.conf"
      aviso "depois, no PowerShell do Windows,  wsl --shutdown  — abra o Ubuntu de novo e rode:"
      aviso "  curl -fsSL $URL/central-ia/instalar.sh | bash"
    fi
    erro "A configuração foi gravada; falta só o serviço."
  fi
  UNIDADE="$HOME/.config/systemd/user/central-ia-runner.service"
  # O PATH do terminal, sem as pastas do Windows que o WSL acrescenta: o
  # serviço acharia o `claude` do npm do Windows antes do da distro.
  PATH_SERVICO="$(printf '%s' "$PATH" | tr ':' '\n' | grep -v '^/mnt/' | paste -sd: - | sed 's/%/%%/g')"
  # `%` é especificador na unidade do systemd: escrito dobrado, vale literal.
  NODE_SERVICO="$(printf '%s' "$NODE" | sed 's/%/%%/g')"
  mkdir -p "$(dirname "$UNIDADE")"
  cat > "$UNIDADE" <<EOF
# Runner da Central de IA — gerado pelo instalador do AlfaMatriz (T-374).
[Unit]
Description=Runner da Central de IA (AlfaMatriz)
After=network-online.target

[Service]
ExecStart="$NODE_SERVICO" %h/.local/share/alfa/central-ia-runner.mjs
Restart=always
RestartSec=10
TimeoutStopSec=60
KillMode=mixed
Environment="PATH=$PATH_SERVICO"

[Install]
WantedBy=default.target
EOF
  systemctl --user daemon-reload || erro "O systemd não releu o serviço ($UNIDADE)."
  systemctl --user enable central-ia-runner >/dev/null 2>&1 || erro "O systemd não aceitou o serviço ($UNIDADE): confira com  systemctl --user status central-ia-runner"
  if systemctl --user is-active --quiet central-ia-runner; then RODANDO=1; else systemctl --user start central-ia-runner || erro "O serviço não ligou: confira com  journalctl --user -u central-ia-runner"; fi
  # Continua rodando sem sessão aberta.
  EU="$(id -un)"
  loginctl enable-linger "$EU" >/dev/null 2>&1 || aviso "Não consegui ligar o linger: o runner para quando você sair da sessão (sudo loginctl enable-linger $EU resolve)."
  LOG="journalctl --user -u central-ia-runner -f"
  LOG_ATUALIZAR="$DEST/central-ia-atualizar.log"
fi

if [ "$RODANDO" = 1 ]; then
  # Já rodava: reinicia só quando não houver pedido em andamento.
  # A saída vai para um log: recarga que falha não pode sumir em silêncio.
  CENTRAL_IA_RECARREGAR="$RECARREGAR" nohup "$DEST/atualizar-quando-ocioso.sh" >>"$LOG_ATUALIZAR" 2>&1 &
  ok "Serviço atualizado: o runner reinicia sozinho assim que terminar o que está fazendo."
else
  ok "Serviço ligado."
fi

# ---------- conversão de PDF e vídeo (opcional) ----------
if [ "$ANEXOS" = 1 ] && command -v docker >/dev/null && docker info >/dev/null 2>&1; then
  if ! docker image inspect "$IMAGEM" >/dev/null 2>&1; then
    echo "Preparando a conversão de PDF e vídeo (Docker; leva alguns minutos na primeira vez)…"
    printf 'FROM debian:bookworm-slim\nRUN apt-get update && apt-get install -y --no-install-recommends ffmpeg poppler-utils && rm -rf /var/lib/apt/lists/*\n' \
      | docker build -q -t "$IMAGEM" - >/dev/null && ok "Conversão de PDF e vídeo pronta." \
      || aviso "Não consegui preparar a conversão de PDF e vídeo; imagens continuam funcionando."
  fi
else
  [ "$ANEXOS" = 1 ] && aviso "Sem Docker: PDF e vídeo anexados não são convertidos (imagens funcionam)."
fi

# ---------- Telegram: mensagem de teste pelo bot do código ----------
TG_TOKEN="$ATUAL"
if [ -n "$TG_TOKEN" ] && [ -n "$TG_CHAT" ]; then
  if TG_TOKEN="$TG_TOKEN" TG_CHAT="$TG_CHAT" TG_API="$TG_API" MAQUINA="$(ler_conf CENTRAL_IA_MAQUINA)" "$NODE" -e '
      fetch(`${process.env.TG_API}/bot${process.env.TG_TOKEN}/sendMessage`, { method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ chat_id: process.env.TG_CHAT, text: `✅ A máquina ${process.env.MAQUINA || ""} está conectada à Central de IA. Os códigos de confirmação dos seus pedidos chegam aqui.` }),
        signal: AbortSignal.timeout(15000) }).then(r => r.json()).then(j => process.exit(j.ok ? 0 : 1)).catch(() => process.exit(1));'; then
    ok "Telegram: mandei uma mensagem de teste pelo bot do código — os códigos de confirmação chegam lá."
  else
    aviso "O bot do código não conseguiu falar com você. Abra o bot do código no Telegram, toque em Começar e rode:"
    aviso "  curl -fsSL $URL/central-ia/instalar.sh | bash"
  fi
elif [ -z "$TG_CHAT" ]; then
  aviso "Sem o Telegram vinculado: pedidos que modificam arquivos serão recusados por esta máquina."
  aviso "Cadastre o seu bot e vincule o Telegram em Ajustes da Central, gere um comando novo em Minhas máquinas e rode-o aqui."
else
  aviso "Sem o bot do código: pedidos que modificam arquivos serão recusados por esta máquina."
  aviso "Crie o bot no @BotFather, toque em Começar nele e rode de novo, para colar o token:"
  aviso "  curl -fsSL $URL/central-ia/instalar.sh | bash"
fi

echo
ok "Pronto. Em até 30 segundos a máquina aparece em Central de IA › Ajustes › Minhas máquinas."
echo "  Acompanhar: $LOG"
echo "  Atualizar depois: curl -fsSL $URL/central-ia/instalar.sh | bash"
}

main "$@"
