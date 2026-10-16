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
IMAGEM_OFFICE="alfamatriz-central-office:local"
IMAGEM_WHISPER="alfamatriz-central-whisper:local"

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

# ---------- Windows (WSL): o runner sem o systemd ----------
# No Windows o runner não depende do systemd (decisão do dono, 16/10/2026):
# - o WSL desliga a distro segundos depois de fechar o último terminal, e o
#   serviço do systemd vai junto; o linger não segura (teste de 16/10/2026,
#   máquina offline das 16:17 às 16:26). O que segura é um wsl.exe aberto;
# - no desktop do teste, o WSLg monta /run/user/1000 por cima do canal do
#   systemd do usuário, e o `systemctl --user` respondia "Failed to connect to
#   bus" em 5 de 5 religadas, com o serviço rodando: o instalador o dava como
#   desligado e parava.
# Então um script na pasta Inicialização do Windows (da pessoa, sem
# administrador; autorizado pelo dono em 16/10/2026) abre, escondido, um
# wsl.exe que roda o central-ia-wsl.sh — e é esse wsl.exe aberto que mantém o
# Ubuntu ligado. O central-ia-wsl.sh faz o papel do systemd: roda o runner e o
# religa 10 s depois de ele sair. O .vbs não abre um segundo se já houver um (a
# marca vai na linha de comando), e o central-ia-wsl.sh, pela trava, também não.
# O wsl.exe nasce pelo WMI (Win32_Process.Create), e não pelo WScript.Shell:
# aberto pelo Run, ele é filho de quem rodou o instalador e morre junto com o
# terminal ou a sessão SSH (no teste, morreu ao fechar a sessão); pelo WMI ele
# nasce fora dessa árvore.

# Supervisor no ar = a trava dele está presa.
supervisor_wsl_no_ar() { ! flock -n "$DEST/central-ia-wsl.trava" true 2>/dev/null; }

# Grava o .vbs na Inicialização e o roda; 1 quando o Windows não está à vista.
abrir_pelo_windows() {
  local appdata pasta vbs distro
  distro="${WSL_DISTRO_NAME:-}"
  # Programa do Windows lê o stdin: sem o </dev/null, o cmd.exe comeria o
  # resto de um script que chega pelo stdin.
  appdata="$( (cd /mnt/c 2>/dev/null; cmd.exe /c 'echo %APPDATA%' </dev/null 2>/dev/null) | tr -d '\r' || true)"
  if [ -z "$distro" ] || [ -z "$appdata" ] || ! command -v wslpath >/dev/null; then return 1; fi
  pasta="$(wslpath "$appdata")/Microsoft/Windows/Start Menu/Programs/Startup"
  vbs="$pasta/central-ia-wsl.vbs"
  mkdir -p "$pasta"
  # ASCII de propósito: o wscript lê o arquivo na página de código do Windows.
  # O caminho do supervisor vai relativo à casa (`--cd ~`): sem espaço nem
  # aspas para escapar na linha de comando do Windows.
  cat > "$vbs" <<VBS
' Central de IA (AlfaMatriz): mantem o runner rodando no $distro do WSL, com o
' terminal fechado. Gerado pelo instalador.
' Para desligar: apague este arquivo e rode  wsl --terminate $distro
Set wmi = GetObject("winmgmts:\\\\.\\root\\cimv2")
Set abertos = wmi.ExecQuery("SELECT ProcessId FROM Win32_Process WHERE Name = 'wsl.exe' AND CommandLine LIKE '%CENTRAL_IA_SUPERVISOR_WSL%'")
If abertos.Count = 0 Then
  Set inicio = wmi.Get("Win32_ProcessStartup").SpawnInstance_
  inicio.ShowWindow = 0
  wmi.Get("Win32_Process").Create "wsl.exe -d $distro -u $(id -un) --cd ~ --exec /usr/bin/env CENTRAL_IA_SUPERVISOR_WSL=1 /bin/bash .local/share/alfa/central-ia-wsl.sh", Null, inicio, pid
End If
VBS
  (cd /mnt/c 2>/dev/null; wscript.exe "$(wslpath -w "$vbs")" </dev/null)
}

# A instalação anterior (até 16/10/2026) ligava o runner pelo systemd também
# no WSL. Sai, para não rodarem dois. Sem o canal do systemd (o WSLg o cobre),
# a unidade é trocada por uma que não religa e o systemd do usuário a relê pelo
# SIGHUP — conferido no desktop do teste. Pedido em andamento não é cortado: o
# runner antigo sai sozinho quando fica livre (o mesmo pedido do
# atualizar-quando-ocioso.sh), e só leva o TERM depois de 10 min.
desligar_systemd_antigo() {
  local u="$HOME/.config/systemd/user/central-ia-runner.service" canal=0 sd p i
  [ -f "$u" ] || return 0
  systemctl --user show-environment >/dev/null 2>&1 && canal=1
  sd="$(pgrep -u "$(id -u)" -x systemd | head -1 || true)"
  if [ "$canal" = 0 ] && [ -n "$sd" ]; then
    printf '[Service]\nExecStart=/bin/true\nRestart=no\n' > "$u"
    kill -HUP "$sd" 2>/dev/null || true; sleep 2
  fi
  p=""; [ -n "$sd" ] && p="$(pgrep -P "$sd" -f central-ia-runner.mjs | head -1 || true)"
  # Runner de antes do reinício pedido (não grava o pid em
  # central-ia-conhece-reinicio) não sairia sozinho: leva o TERM direto.
  if [ -n "$p" ] && [ "$(cat "$DEST/central-ia-conhece-reinicio" 2>/dev/null || true)" != "$p" ]; then
    kill -TERM "$p" 2>/dev/null || true
  elif [ -n "$p" ]; then
    : > "$DEST/central-ia-reiniciar"
    echo "Parando o runner antigo (do systemd) assim que ele terminar o que está fazendo…"
    for i in $(seq 1 120); do kill -0 "$p" 2>/dev/null || break; sleep 5; done
    kill -TERM "$p" 2>/dev/null || true
    rm -f "$DEST/central-ia-reiniciar"
  fi
  # Com o canal, quem para (e desfaz o religar do Restart=always) é ele.
  [ "$canal" = 1 ] && systemctl --user disable --now central-ia-runner >/dev/null 2>&1 || true
  rm -f "$u" "$HOME/.config/systemd/user/default.target.wants/central-ia-runner.service"
  ok "O serviço antigo do systemd saiu: no Windows, quem mantém o runner é o script da Inicialização."
}

# ---------- sistema e pré-requisitos ----------
case "$(uname -s)" in
  Darwin) SISTEMA=mac ;;
  # O kernel do WSL se identifica no `uname -r` (…-microsoft-standard-WSL2).
  Linux) if uname -r | grep -qi microsoft || grep -qi microsoft /proc/version 2>/dev/null; then SISTEMA=wsl; else SISTEMA=linux; fi ;;
  *) erro "No Windows, rode este comando dentro do WSL (Ubuntu). O runner roda no Linux, no WSL e no Mac." ;;
esac

command -v curl >/dev/null || erro "Falta o curl."
command -v git >/dev/null || erro "Falta o git. Mac: xcode-select --install. Linux/WSL: sudo apt install git."
NODE="$(command -v node || true)"
[ -n "$NODE" ] || erro "Falta o Node 18 ou mais novo. Mac: brew install node. Linux/WSL: https://nodejs.org (ou nvm)."
"$NODE" -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 18 ? 0 : 1)' \
  || erro "O Node desta máquina é antigo ($("$NODE" -v)). A Central precisa do 18 ou mais novo."

# O Node tenta IPv4 e IPv6 juntos e desiste de cada um em 250 ms. No WSL o
# IPv6 não tem rota e o IPv4 até o Telegram passa disso (~335 ms no teste de
# 16/10/2026): todo fetch daqui falhava, e o token bom do bot do código era
# dado como "recusado pelo Telegram". O runner já tinha o mesmo ajuste
# (runner.mjs, 13/10/2026). Vai na frente de todo `node -e` que usa a rede.
REDE='require("node:net").setDefaultAutoSelectFamilyAttemptTimeout?.(2500);'

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
# Entre chaves: sem terminal, o próprio `</dev/tty` falha antes de o stty
# rodar, e o erro dele escapava do `2>&1` e saía na tela.
trap 'rm -rf "$TMP"; { stty echo </dev/tty; } >/dev/null 2>&1 || true' EXIT
trap 'exit 130' INT TERM
for f in $ARQUIVOS; do
  # `.php` vai com `.txt` no fim: endereço terminado em .php o nginx entrega ao PHP-FPM, não ao AlfaMatriz.
  u="$f"; case "$f" in *.php) u="$f.txt" ;; esac
  curl -fsS --max-time 60 "$URL/central-ia/runner/arquivos/$u" -o "$TMP/$f" || erro "Não consegui baixar $f do AlfaMatriz."
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
  TG_TOKEN="$1" TG_API="$TG_API" "$NODE" -e "$REDE"'
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
# O @ do bot do código gravado, quando o Telegram o confirma; vazio = não
# confirmado (recusado ou Telegram fora do ar).
NOME_ATUAL=""
[ -n "$ATUAL" ] && NOME_ATUAL="$(bot_do_token "$ATUAL")"
if [ -n "$ATUAL" ] && [ -n "$CONVERSA" ] && [ "$NOME_ATUAL" = "$CONVERSA" ]; then
  # Instalação anterior aos dois bots: o código saía pelo bot da conversa,
  # cujo token o servidor tem. Sai daqui, e a máquina recusa modificar
  # arquivos até ganhar o bot do código.
  gravar_conf CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO ""
  ATUAL="" NOME_ATUAL=""
  aviso "O código saía pelo @$CONVERSA, o bot em que você conversa com a Central. Agora ele sai por um bot só para isso."
fi

# Grava o token colado (do ambiente ou da pergunta) como bot do código, se o
# Telegram o confirmar e ele não for o bot da conversa.
usar_novo() {
  local nome
  nome="$(bot_do_token "$NOVO")"
  if [ -z "$CONVERSA" ]; then
    # Sem saber qual é o bot da conversa não há como recusá-lo aqui. O que
    # fazer (cadastrar o bot ou gerar um comando novo) vem no aviso do fim.
    aviso "Não sei qual é o seu bot da conversa, então não gravei esse token."
  elif [ -z "$nome" ]; then
    aviso "O Telegram recusou esse token. Confira no @BotFather e rode o comando de novo."
  elif [ "$nome" = "$CONVERSA" ]; then
    aviso "Esse é o @$nome, o bot em que você conversa com a Central. O código precisa de um bot só dele: crie outro no @BotFather."
  else
    gravar_conf CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO "$NOVO"
    ATUAL="$NOVO" NOME_ATUAL="$nome"
    ok "Bot do código: @$nome (o token fica só nesta máquina)."
  fi
}
[ -n "$NOVO" ] && usar_novo

# A chave antiga das máquinas instaladas à mão. Nela costumava estar o bot da
# conversa, cujo token o servidor tem, e o runner já não a aceita. Decide-se
# DEPOIS do bot gravado e do token novo (3ª revisão da T-374): antes, ela era
# julgada contra um ATUAL que ainda ia cair (bot da conversa, token recusado)
# e o instalador mandava apagar o único token bom da máquina.
# Vira bot do código quando o getMe diz que é OUTRO bot que não o da conversa
# e não há bot do código confirmado. Sai do arquivo só quando se sabe o que
# ela era: convertida, igual ao bot do código gravado, ou confirmada como o
# bot da conversa. Apagar sem saber (Telegram fora do ar, bot da conversa
# desconhecido) perdia o único token do bot do código daquela máquina; e na
# linha ela não faz mal, porque o runner a ignora.
LEGADO="$(ler_conf CENTRAL_IA_TELEGRAM_TOKEN)"
if [ -n "$LEGADO" ]; then
  NOME_LEGADO=""
  [ -n "$CONVERSA" ] && NOME_LEGADO="$(bot_do_token "$LEGADO")"
  if [ -n "$ATUAL" ] && [ "$LEGADO" = "$ATUAL" ]; then
    gravar_conf CENTRAL_IA_TELEGRAM_TOKEN ""
  elif [ -n "$NOME_LEGADO" ] && [ "$NOME_LEGADO" = "$CONVERSA" ]; then
    gravar_conf CENTRAL_IA_TELEGRAM_TOKEN ""
  elif [ -n "$NOME_LEGADO" ] && [ -z "$NOME_ATUAL" ]; then
    # Sem bot do código, ou com um que o Telegram acabou de recusar enquanto
    # confirmava a chave antiga: ela é o bot do código que vale.
    gravar_conf CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO "$LEGADO"
    gravar_conf CENTRAL_IA_TELEGRAM_TOKEN ""
    ATUAL="$LEGADO" NOME_ATUAL="$NOME_LEGADO"
    ok "Bot do código: @$NOME_LEGADO (veio da configuração antiga desta máquina)."
  elif [ -n "$NOME_LEGADO" ]; then
    # Só aqui há um bot do código gravado E confirmado: a antiga sobra.
    aviso "A linha CENTRAL_IA_TELEGRAM_TOKEN (@$NOME_LEGADO) de $CONF não é usada: o bot do código é o @$NOME_ATUAL. Pode apagá-la."
  elif [ -n "$CONVERSA" ]; then
    # Sem o bot da conversa, o aviso do fim já manda gerar um comando novo;
    # "rode de novo mais tarde" o contradizia.
    aviso "Não consegui conferir a linha antiga CENTRAL_IA_TELEGRAM_TOKEN de $CONF; ela ficou lá, sem uso. Rode o comando de novo mais tarde."
  fi
fi

# Sem saber qual é o bot da conversa, não há como recusá-lo no lugar do bot do
# código: não pergunta (o aviso no fim diz o que fazer).
if [ -z "$NOVO" ] && [ -z "$ATUAL" ] && [ -n "$TG_CHAT" ] && [ -n "$CONVERSA" ]; then
  echo
  echo "O código de confirmação dos seus pedidos chega por um bot do Telegram só para isso, diferente do bot da conversa."
  echo "No @BotFather: /newbot, um nome (ex.: Código do seu nome) e um @ terminado em bot. Depois abra o bot novo e toque em Começar."
  NOVO="$(perguntar_segredo "Cole aqui o token do bot do código (Enter para deixar para depois)")"
  [ -n "$NOVO" ] && usar_novo
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
elif [ "$SISTEMA" = wsl ]; then
  desligar_systemd_antigo
  SUPERVISOR="$DEST/central-ia-wsl.sh"
  # O ambiente fica num arquivo à parte, relido a cada vez que o runner sobe:
  # o supervisor roda por dias, e trocar o node (nvm) ou o PATH vale no
  # próximo reinício, sem ter de derrubá-lo. O PATH é o do terminal, sem as
  # pastas do Windows que o WSL acrescenta: o runner acharia o `claude` do npm
  # do Windows antes do da distro. O ~/.local/bin (onde o instalador do
  # Claude Code põe o `claude`) entra mesmo que o terminal não o tenha: rodado
  # sem shell de login (pelo SSH, no teste de 16/10/2026), o PATH vinha sem ele
  # e a máquina subia sem nenhum agente.
  PATH_WSL="$(printf '%s' "$PATH" | tr ':' '\n' | grep -v '^/mnt/' | paste -sd: -)"
  case ":$PATH_WSL:" in *":$HOME/.local/bin:"*) ;; *) [ -d "$HOME/.local/bin" ] && PATH_WSL="$HOME/.local/bin:$PATH_WSL" ;; esac
  { printf 'HOME=%q\n' "$HOME"
    printf 'NODE=%q\n' "$NODE"
    printf 'PATH=%q\n' "$PATH_WSL"
    # O runner gira o próprio log (limpeza.mjs) quando sabe onde ele está.
    printf 'CENTRAL_IA_LOG=%q\n' "$DEST/central-ia-runner.log"
  } > "$DEST/central-ia-wsl.env.novo"
  mv -f "$DEST/central-ia-wsl.env.novo" "$DEST/central-ia-wsl.env"
  # Trocado pelo `mv`, nunca reescrito no lugar: o bash lê o script aos
  # pedaços enquanto roda, e o supervisor no ar leria o arquivo novo pela
  # metade. O que está no ar segue com o texto antigo até o Ubuntu religar;
  # por isso o que muda (node, PATH) mora no .env, relido a cada subida.
  cat > "$SUPERVISOR.novo" <<'SUPERVISOR'
#!/bin/bash
# Runner da Central de IA no Windows (WSL) — gerado pelo instalador do
# AlfaMatriz (T-374). Faz o papel do systemd: roda o runner e o religa 10 s
# depois de ele sair (Restart=always). Quem o abre é o central-ia-wsl.vbs da
# pasta Inicialização do Windows, e o wsl.exe dele é que mantém o Ubuntu ligado.
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
# Um só: a trava fica presa enquanto este script roda.
exec 9>"$DIR/central-ia-wsl.trava"
flock -n 9 || exit 0
LOG="$DIR/central-ia-runner.log"
PID="$DIR/central-ia-runner.pid"
filho=""
# Parado (wsl --terminate, desligar o Windows): para o runner junto e espera
# ele encerrar os agentes, como o KillMode=mixed do systemd.
trap '[ -n "$filho" ] && { kill -TERM "$filho" 2>/dev/null; wait "$filho" 2>/dev/null; }; rm -f "$PID"; exit 0' TERM INT HUP
while :; do
  . "$DIR/central-ia-wsl.env"
  # Sem journal, o limite do log é o do próprio runner (CENTRAL_IA_LOG: passou
  # de 10 MB, ele copia e esvazia no lugar; o >> abaixo acompanha).
  export HOME PATH CENTRAL_IA_LOG
  # 9>&-: o runner não herda a trava, senão ela seguiria presa sem supervisor.
  "$NODE" "$DIR/central-ia-runner.mjs" >>"$LOG" 2>&1 9>&- &
  filho=$!
  echo "$filho" > "$PID"
  wait "$filho"; saiu=$?
  printf '%s supervisor: o runner saiu (%s); religando em 10 s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$saiu" >>"$LOG"
  filho=""
  rm -f "$PID"
  sleep 10 & wait $!
done
SUPERVISOR
  chmod 700 "$SUPERVISOR.novo"
  mv -f "$SUPERVISOR.novo" "$SUPERVISOR"
  if supervisor_wsl_no_ar; then RODANDO=1; fi
  # Grava (ou regrava) o .vbs sempre: o nome da distro ou do usuário pode ter
  # mudado. Rodá-lo com o supervisor no ar não faz nada.
  if abrir_pelo_windows; then
    NO_WINDOWS=1
  else
    NO_WINDOWS=0
  fi
  if [ "$RODANDO" = 0 ]; then
    for i in $(seq 1 20); do supervisor_wsl_no_ar && break; sleep 1; done
    if ! supervisor_wsl_no_ar; then
      # Sem o Windows à vista (interop desligado) ou o .vbs não abriu: roda
      # daqui, e vale enquanto o Ubuntu estiver aberto.
      setsid nohup /bin/bash "$SUPERVISOR" </dev/null >/dev/null 2>&1 &
      for i in $(seq 1 10); do supervisor_wsl_no_ar && break; sleep 1; done
      supervisor_wsl_no_ar || erro "O runner não ligou: confira  $DEST/central-ia-runner.log"
      NO_WINDOWS=0
    fi
  fi
  if [ "$NO_WINDOWS" = 1 ]; then
    ok "Windows: o runner fica rodando com o terminal fechado (e liga sozinho ao entrar no Windows)."
  else
    aviso "Não consegui ligar o runner pelo Windows: ele para quando o Ubuntu fechar. Deixe um terminal do Ubuntu aberto."
  fi
  LOG="tail -f $DEST/central-ia-runner.log"
  LOG_ATUALIZAR="$DEST/central-ia-atualizar.log"
else
  if ! systemctl --user show-environment >/dev/null 2>&1; then
    aviso "O systemd não está ligado nesta máquina, e é ele que mantém o runner rodando."
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
  # Word, Excel e PowerPoint (17/10/2026): imagem à parte, para não refazer a
  # de PDF e vídeo de quem já a tem. Mesmo conteúdo de deploy/central-ia/office.
  if ! docker image inspect "$IMAGEM_OFFICE" >/dev/null 2>&1; then
    echo "Preparando a conversão de Word, Excel e PowerPoint (Docker; leva alguns minutos na primeira vez)…"
    printf 'FROM debian:bookworm-slim\nRUN apt-get update && apt-get install -y --no-install-recommends libreoffice-writer-nogui libreoffice-calc-nogui libreoffice-impress-nogui poppler-utils fonts-dejavu-core fonts-liberation2 && rm -rf /var/lib/apt/lists/*\n' \
      | docker build -q -t "$IMAGEM_OFFICE" - >/dev/null && ok "Conversão de Word, Excel e PowerPoint pronta." \
      || aviso "Não consegui preparar a conversão de documentos; o agente recebe o original e texto puro funciona."
  fi
  # Reuniões do Secretário (docs/specs/secretario-reunioes): transcrição na
  # máquina, sem rede na hora de transcrever — o modelo vem dentro da imagem.
  # Mesmo conteúdo de deploy/central-ia/whisper/Dockerfile.
  # Só em quem pede (CENTRAL_IA_TRANSCRICAO=sim no ambiente ou no .env do
  # runner): são ~1,7 GB, e o Secretário é de uma pessoa, não de toda máquina.
  TRANSCRICAO="${CENTRAL_IA_TRANSCRICAO:-$(ler_conf CENTRAL_IA_TRANSCRICAO)}"
  if [ "$TRANSCRICAO" != sim ] && ! docker image inspect "$IMAGEM_WHISPER" >/dev/null 2>&1; then
    echo "Transcrição de reuniões: desligada nesta máquina (para ligar, rode o instalador com CENTRAL_IA_TRANSCRICAO=sim)."
  elif ! docker image inspect "$IMAGEM_WHISPER" >/dev/null 2>&1 && [ -f "$DEST/transcrever_reuniao.py" ]; then
    echo "Preparando a transcrição de reuniões (Docker; baixa ~1,7 GB na primeira vez)…"
    CTX="$(mktemp -d)"
    cp "$DEST/transcrever_reuniao.py" "$CTX/"
    printf 'FROM python:3.11-slim\nRUN pip install --no-cache-dir "faster-whisper==1.1.1" requests && python -c "from faster_whisper import WhisperModel; WhisperModel(\x27small\x27, device=\x27cpu\x27, compute_type=\x27int8\x27, download_root=\x27/modelos\x27)" && chmod -R a+rX /modelos\nENV HF_HUB_OFFLINE=1 WHISPER_MODELOS=/modelos\nCOPY transcrever_reuniao.py /app/transcrever_reuniao.py\n' > "$CTX/Dockerfile"
    docker build -q -t "$IMAGEM_WHISPER" "$CTX" >/dev/null && ok "Transcrição de reuniões pronta." \
      || aviso "Não consegui preparar a transcrição de reuniões; o resto do runner funciona."
    rm -rf "$CTX"
  elif [ -f "$DEST/transcrever_reuniao.py" ] && [ "$(docker run --rm --pull=never --network=none --entrypoint sha256sum "$IMAGEM_WHISPER" /app/transcrever_reuniao.py 2>/dev/null | cut -d' ' -f1)" != "$(sha256sum "$DEST/transcrever_reuniao.py" | cut -d' ' -f1)" ]; then
    # Script novo (ex.: o progresso de 19/10/2026): só troca o arquivo por cima
    # da imagem que já existe — sem rede e sem baixar o modelo de novo.
    CTX="$(mktemp -d)"
    cp "$DEST/transcrever_reuniao.py" "$CTX/"
    printf 'FROM %s\nCOPY transcrever_reuniao.py /app/transcrever_reuniao.py\n' "$IMAGEM_WHISPER" > "$CTX/Dockerfile"
    docker build -q -t "$IMAGEM_WHISPER" "$CTX" >/dev/null && ok "Transcrição de reuniões atualizada." \
      || aviso "Não consegui atualizar o transcritor; a versão anterior continua valendo."
    rm -rf "$CTX"
  fi
else
  [ "$ANEXOS" = 1 ] && aviso "Sem Docker: PDF, vídeo e documentos anexados não são convertidos (imagens e texto puro funcionam)."
fi

# ---------- Telegram: mensagem de teste pelo bot do código ----------
TG_TOKEN="$ATUAL"
if [ -n "$TG_TOKEN" ] && [ -n "$TG_CHAT" ]; then
  if TG_TOKEN="$TG_TOKEN" TG_CHAT="$TG_CHAT" TG_API="$TG_API" MAQUINA="$(ler_conf CENTRAL_IA_MAQUINA)" "$NODE" -e "$REDE"'
      fetch(`${process.env.TG_API}/bot${process.env.TG_TOKEN}/sendMessage`, { method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ chat_id: process.env.TG_CHAT, text: `✅ A máquina ${process.env.MAQUINA || ""} está conectada à Central de IA. Os códigos de confirmação dos seus pedidos chegam aqui.` }),
        signal: AbortSignal.timeout(15000) }).then(r => r.json()).then(j => process.exit(j.ok ? 0 : 1)).catch(() => process.exit(1));'; then
    ok "Telegram: mandei uma mensagem de teste pelo bot do código — os códigos de confirmação chegam lá."
  else
    aviso "O bot do código não conseguiu falar com você. Abra o bot do código no Telegram, toque em Começar e rode:"
    aviso "  curl -fsSL $URL/central-ia/instalar.sh | bash"
  fi
elif [ -z "$TG_CHAT" ]; then
  # O servidor só manda o chat quando a pessoa tem o bot da conversa cadastrado
  # E o Telegram vinculado: faltando um dos dois, gerar outro comando sozinho
  # não resolve.
  aviso "Sem o Telegram vinculado: pedidos que modificam arquivos serão recusados por esta máquina."
  aviso "Primeiro cadastre o seu bot da conversa e vincule o Telegram em Central de IA › Ajustes; depois gere um comando novo em Minhas máquinas e rode-o aqui."
elif [ -z "$CONVERSA" ]; then
  # Conectada antes de o servidor mandar o @ do bot da conversa.
  aviso "Sem o bot do código: pedidos que modificam arquivos serão recusados por esta máquina."
  aviso "Esta máquina foi conectada por um instalador antigo. Gere um comando novo em Central de IA › Ajustes › Minhas máquinas e rode-o aqui."
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
