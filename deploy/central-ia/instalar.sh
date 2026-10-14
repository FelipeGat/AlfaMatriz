#!/usr/bin/env bash
# Instalador do runner da Central de IA — Linux, WSL e Mac (T-374).
#
# Não se roda daqui: o AlfaMatriz o serve em /central-ia/instalar.sh com o
# endereço dele já dentro, e a tela "Conectar minha máquina" (Ajustes da
# Central) mostra o comando pronto:
#
#   curl -fsSL <AlfaMatriz>/central-ia/instalar.sh | bash -s -- <código>
#
# Com o código (uso único, 15 min), conecta a máquina: troca o código pelo
# token, pergunta a pasta dos projetos, grava a configuração (0600) e liga o
# serviço. Sem o código, ATUALIZA: baixa o runner de novo e reinicia o serviço
# quando ele estiver ocioso, sem mexer na configuração.
#
# Opções: --projetos <pasta>   --maquina <nome>   --sem-anexos (não prepara a
# imagem que converte PDF e vídeo).
set -euo pipefail

URL="__ALFAMATRIZ_URL__"
ARQUIVOS="__ARQUIVOS__"
ROTULO="br.com.alfa.central-ia-runner"
IMAGEM="alfamatriz-central-media:local"

CODIGO="" PROJETOS="" MAQUINA="" ANEXOS=1
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
trap 'rm -rf "$TMP"' EXIT
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

  [ -n "$MAQUINA" ] || MAQUINA="$(ler_conf CENTRAL_IA_MAQUINA)"
  [ -n "$MAQUINA" ] || MAQUINA="$(hostname -s 2>/dev/null || hostname)"
  MAQUINA="$(printf '%s' "$MAQUINA" | tr -c 'A-Za-z0-9._-' '-' | cut -c1-64)"

  RESPOSTA="$(curl -sS --max-time 30 -w '\n%{http_code}' -X POST \
      -H 'Accept: application/json' -H 'Content-Type: application/json' \
      -d "{\"codigo\":\"$CODIGO\",\"maquina\":\"$MAQUINA\"}" "$URL/api/central-ia/instalacao")" \
    || erro "Não alcancei o AlfaMatriz para trocar o código. Confira o Tailscale e rode o comando de novo."
  STATUS="${RESPOSTA##*$'\n'}"; CORPO="${RESPOSTA%$'\n'*}"
  [ "$STATUS" = 200 ] || erro "Código de instalação inválido, já usado ou vencido. Gere outro em Ajustes › Conectar minha máquina."

  # Grava a configuração mantendo o que a pessoa já tinha (teto de acesso,
  # pastas ignoradas, agenda…). O segredo da confirmação nasce AQUI e nunca
  # sai da máquina; se já existe, fica o mesmo.
  CORPO="$CORPO" CONF="$CONF" URL="$URL" PROJETOS="$PROJETOS" MAQUINA="$MAQUINA" "$NODE" --input-type=module -e '
    import { readFileSync, writeFileSync, chmodSync, existsSync } from "node:fs";
    import { randomBytes } from "node:crypto";
    const r = JSON.parse(process.env.CORPO);
    const linhas = existsSync(process.env.CONF) ? readFileSync(process.env.CONF, "utf8").split("\n") : ["# Central de IA — configuração desta máquina (só a pessoa lê)."];
    const atual = k => { for (const l of linhas) { const m = l.match(new RegExp(`^\\s*${k}\\s*=\\s*(.*?)\\s*$`)); if (m) return m[1].replace(/^["\x27]|["\x27]$/g, ""); } return null; };
    const definir = (k, v) => {
      if (v === null || v === undefined || v === "") return;
      const i = linhas.findIndex(l => new RegExp(`^\\s*${k}\\s*=`).test(l));
      if (i >= 0) linhas[i] = `${k}=${v}`; else linhas.push(`${k}=${v}`);
    };
    definir("ALFAMATRIZ_URL", process.env.URL);
    definir("ALFAMATRIZ_RUNNER_TOKEN", r.token);
    definir("CENTRAL_IA_PROJETOS", process.env.PROJETOS);
    definir("CENTRAL_IA_MAQUINA", process.env.MAQUINA);
    if (!/^[0-9a-f]{64}$/i.test(atual("CENTRAL_IA_SEGREDO_CONFIRMACAO") || "")) definir("CENTRAL_IA_SEGREDO_CONFIRMACAO", randomBytes(32).toString("hex"));
    definir("CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO", r.telegram?.token);
    definir("CENTRAL_IA_TELEGRAM_CHAT_ID", r.telegram?.chat_id);
    writeFileSync(process.env.CONF, linhas.join("\n").replace(/\n*$/, "\n"), { mode: 0o600 });
    chmodSync(process.env.CONF, 0o600);
    console.log(JSON.stringify({ conta: r.conta, bot: r.telegram?.bot || null, telegram: !!(r.telegram?.token && r.telegram?.chat_id) }));
  ' > "$TMP/conectada.json"
  ok "Máquina \"$MAQUINA\" conectada à conta $(sed -n 's/.*"conta":"\([^"]*\)".*/\1/p' "$TMP/conectada.json") · projetos em $PROJETOS"
  ok "Configuração em $CONF (só você lê)"
fi

# ---------- serviço ----------
RODANDO=0
if [ "$SISTEMA" = mac ]; then
  PLIST="$HOME/Library/LaunchAgents/$ROTULO.plist"
  mkdir -p "$HOME/Library/LaunchAgents" "$HOME/Library/Logs"
  xml() { printf '%s' "$1" | sed 's/&/\&amp;/g; s/</\&lt;/g; s/>/\&gt;/g'; }
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
  else
    launchctl bootstrap "gui/$(id -u)" "$PLIST" || erro "O macOS não aceitou o serviço ($PLIST)."
  fi
  LOG="tail -f ~/Library/Logs/central-ia-runner.log"
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
  mkdir -p "$(dirname "$UNIDADE")"
  cat > "$UNIDADE" <<EOF
# Runner da Central de IA — gerado pelo instalador do AlfaMatriz (T-374).
[Unit]
Description=Runner da Central de IA (AlfaMatriz)
After=network-online.target

[Service]
ExecStart="$NODE" %h/.local/share/alfa/central-ia-runner.mjs
Restart=always
RestartSec=10
TimeoutStopSec=60
KillMode=mixed
Environment="PATH=$PATH"

[Install]
WantedBy=default.target
EOF
  systemctl --user daemon-reload
  systemctl --user enable central-ia-runner >/dev/null 2>&1
  if systemctl --user is-active --quiet central-ia-runner; then RODANDO=1; else systemctl --user start central-ia-runner; fi
  # Continua rodando sem sessão aberta.
  loginctl enable-linger "$USER" >/dev/null 2>&1 || aviso "Não consegui ligar o linger: o runner para quando você sair da sessão (sudo loginctl enable-linger $USER resolve)."
  LOG="journalctl --user -u central-ia-runner -f"
fi

if [ "$RODANDO" = 1 ]; then
  # Já rodava: reinicia só quando não houver pedido em andamento.
  nohup "$DEST/atualizar-quando-ocioso.sh" >/dev/null 2>&1 &
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

# ---------- Telegram ----------
TG_TOKEN="$(ler_conf CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO)"; [ -n "$TG_TOKEN" ] || TG_TOKEN="$(ler_conf CENTRAL_IA_TELEGRAM_TOKEN)"
TG_CHAT="$(ler_conf CENTRAL_IA_TELEGRAM_CHAT_ID)"
BOT="$(sed -n 's/.*"bot":"\([^"]*\)".*/\1/p' "$TMP/conectada.json" 2>/dev/null || true)"
if [ -n "$TG_TOKEN" ] && [ -n "$TG_CHAT" ]; then
  if TG_TOKEN="$TG_TOKEN" TG_CHAT="$TG_CHAT" MAQUINA="$(ler_conf CENTRAL_IA_MAQUINA)" "$NODE" -e '
      fetch(`https://api.telegram.org/bot${process.env.TG_TOKEN}/sendMessage`, { method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ chat_id: process.env.TG_CHAT, text: `✅ A máquina ${process.env.MAQUINA || ""} está conectada à Central de IA. Os códigos de confirmação dos seus pedidos chegam aqui.` }),
        signal: AbortSignal.timeout(15000) }).then(r => r.json()).then(j => process.exit(j.ok ? 0 : 1)).catch(() => process.exit(1));'; then
    ok "Telegram: mandei uma mensagem de teste — os códigos de confirmação chegam lá."
  else
    aviso "O seu bot não conseguiu falar com você no Telegram.${BOT:+ Abra @$BOT, toque em Começar} e rode o comando de novo."
  fi
else
  aviso "Sem o seu bot do Telegram: pedidos que modificam arquivos serão recusados por esta máquina."
  aviso "Cadastre o seu bot e vincule o Telegram em Ajustes da Central, gere um comando novo em Minhas máquinas e rode-o aqui."
fi

echo
ok "Pronto. Em até 30 segundos a máquina aparece em Central de IA › Ajustes › Minhas máquinas."
echo "  Acompanhar: $LOG"
echo "  Atualizar depois: curl -fsSL $URL/central-ia/instalar.sh | bash"
