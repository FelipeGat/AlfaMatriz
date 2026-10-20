#!/bin/bash
# Reinicia o runner só quando ele está de fato ocioso: nenhum agente filho E
# nenhuma execução com a máquina (inclusive esperando o código do dono, que
# não tem processo).
#
# Serve ao systemd (Linux), ao launchd (Mac) e ao central-ia-wsl.sh (Windows
# com WSL, desde 16/10/2026) — T-374: era só systemd e lia /proc, que o Mac
# não tem.
#
# Desde 15/10/2026 (T-374) quem decide a hora é o próprio runner: este script
# deixa o arquivo "central-ia-reiniciar", e o laço do runner sai antes de pedir
# trabalho quando não há nada rodando; o serviço o religa com a versão nova.
# Matar o processo de fora deixava uma janela entre a última checagem e o kill:
# o #17 chegou nela, no staging, e morreu como "expirada".
#
# O runner que sabe fazer isso grava o próprio pid em "central-ia-conhece-reinicio"
# ao subir, e esse nunca é morto daqui: espera-se que ele saia, por mais que
# demore (acesso recusado ou fora de alcance espaçam o laço até 10 min, e aí
# nenhum pedido chega). Antes, ~20 s sem sair já contavam como "runner antigo",
# e um `proxima` lento (até 20 s) ainda podia trazer pedido no kill (revisão da
# T-374). O runner de antes desta versão não grava o arquivo e nunca sai
# sozinho: para ele fica o caminho antigo — checar ocioso e matar, sem espera
# entre a última checagem e o kill.
#
# CENTRAL_IA_RECARREGAR=1 (o instalador põe quando o plist do Mac mudou): o
# launchd só relê o plist descarregando o serviço, e o runner religado sozinho
# voltaria com o plist velho. Então o pedido é "pausar": o runner para de pedir
# trabalho e avisa em "central-ia-pausado", e daqui se descarrega e carrega o
# serviço sem pedido na mão.
#
# A saída vai para o log do runner (o instalador a manda para lá): uma recarga
# que falhasse em silêncio deixava o serviço descarregado sem rastro nenhum
# (revisão da T-374).
set -eu
DIR="$HOME/.local/share/alfa"
log() { printf '%s atualizar-quando-ocioso: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
OCUPADO="$DIR/central-ia-ocupado"
REINICIAR="$DIR/central-ia-reiniciar"
CONHECE="$DIR/central-ia-conhece-reinicio"
PAUSADO="$DIR/central-ia-pausado"
ROTULO="br.com.alfa.central-ia-runner"
RECARREGAR=0
[ "$(uname -s)" = Darwin ] && [ "${CENTRAL_IA_RECARREGAR:-0}" = 1 ] && RECARREGAR=1

if [ "$(uname -s)" = Darwin ]; then
  PLIST="$HOME/Library/LaunchAgents/$ROTULO.plist"
  # Os dois: descarregado, só o `bootstrap` liga; carregado sem processo
  # (o launchd desistiu de religar), o `bootstrap` falha e quem liga é o
  # `kickstart` (3ª revisão da T-374).
  ligar_a_mao() {
    log "  launchctl bootstrap gui/$(id -u) $PLIST"
    log "  ou, se ele disser que o serviço já está carregado: launchctl kickstart -k gui/$(id -u)/$ROTULO"
  }
  pid_do_runner() { launchctl print "gui/$(id -u)/$ROTULO" 2>/dev/null | awk '/^[[:space:]]*pid = / { print $3; exit }'; }
  if [ "$RECARREGAR" = 1 ]; then
    # O `bootout` volta antes de o launchd terminar de descarregar: o
    # `bootstrap` colado nele falhava com "5: Input/output error" e o serviço
    # ficava DESCARREGADO, sem runner até alguém notar (teste no Mac mini,
    # 15/10/2026). Espera o serviço sumir (o ExitTimeOut do plist é 60 s) e
    # tenta carregar de novo até conseguir.
    reiniciar() {
      local i
      launchctl bootout "gui/$(id -u)/$ROTULO" 2>/dev/null || true
      for i in $(seq 1 75); do launchctl print "gui/$(id -u)/$ROTULO" >/dev/null 2>&1 || break; sleep 1; done
      for i in $(seq 1 30); do
        launchctl bootstrap "gui/$(id -u)" "$PLIST" 2>/dev/null && { log "serviço recarregado"; return 0; }
        launchctl print "gui/$(id -u)/$ROTULO" >/dev/null 2>&1 && { log "serviço recarregado"; return 0; }
        sleep 2
      done
      # Última tentativa, com o erro do launchctl no log, e o comando para
      # quem abrir o log: descarregado, o KeepAlive não religa nada.
      if launchctl bootstrap "gui/$(id -u)" "$PLIST" && launchctl kickstart "gui/$(id -u)/$ROTULO"; then
        log "serviço recarregado na última tentativa"; return 0
      fi
      log "ERRO: o macOS não recarregou o serviço; o runner está DESLIGADO. Para ligar à mão:"
      ligar_a_mao
      return 1
    }
  else
    reiniciar() { launchctl kickstart -k "gui/$(id -u)/$ROTULO"; }
  fi
elif [ -f "$DIR/central-ia-wsl.sh" ]; then
  # Windows (WSL), sem systemd desde 16/10/2026: quem religa o runner é o
  # central-ia-wsl.sh, que deixa o pid dele em central-ia-runner.pid. Reiniciar
  # é parar o runner; o supervisor o sobe de novo em 10 s.
  pid_do_runner() {
    local p
    p="$(cat "$DIR/central-ia-runner.pid" 2>/dev/null || true)"
    [ -n "$p" ] && kill -0 "$p" 2>/dev/null && echo "$p"
  }
  reiniciar() {
    local p
    p="$(pid_do_runner || true)"
    if [ -n "$p" ]; then kill -TERM "$p"; return 0; fi
    # Sem runner, com o supervisor no ar: ele mesmo religa.
    flock -n "$DIR/central-ia-wsl.trava" true 2>/dev/null || return 0
    log "ERRO: o supervisor do runner não está rodando; o runner está DESLIGADO. Para ligar, rode o comando de instalar de novo"
    log "  (ou reinicie o Windows: o script da pasta Inicialização o liga)."
    return 1
  }
else
  pid_do_runner() { systemctl --user show central-ia-runner -p MainPID --value; }
  reiniciar() { systemctl --user restart central-ia-runner; }
fi
ocioso() { # $1 = pid do runner
  local filhos
  filhos="$(pgrep -P "$1" 2>/dev/null || true)"
  [ -z "$filhos" ] && [ "$(cat "$OCUPADO" 2>/dev/null || echo 0)" = 0 ]
}
conhece() { [ "$(cat "$CONHECE" 2>/dev/null || true)" = "$1" ]; }
pedir() { if [ "$RECARREGAR" = 1 ]; then echo pausar > "$REINICIAR"; else : > "$REINICIAR"; fi; }

# Um de cada vez: dois instaladores rodados juntos davam dois scripts, e com
# RECARREGAR os dois descarregavam e carregavam o serviço ao mesmo tempo
# (revisão da T-374). O segundo espera o primeiro terminar — e não desiste,
# porque pode trazer o plist novo que o primeiro não viu. Trava largada por
# um script que morreu (pid que não existe mais) é desfeita.
#
# Tetos (revisão da T-374), contados em passos de 5 s: um script esquecido
# esperando para sempre ficava pendurado no sistema sem ninguém saber. A
# trava espera até 2 h — mais que o pedido mais longo (60 min) —, e o serviço
# fora do ar, até 30 min. Trocáveis só para o teste (test-reinicio.mjs).
TETO_TRAVA="${CENTRAL_IA_ATUALIZAR_TETO_TRAVA:-7200}"
TETO_SEM_RUNNER="${CENTRAL_IA_ATUALIZAR_TETO_SEM_RUNNER:-1800}"
# O runner que sabe sair, ocupado: o runner deixa de pedir trabalho novo com
# o reinício pedido, então esvazia; o teto é a rede de segurança (3ª revisão
# da T-374) — 2 h, mais que o pedido mais longo somado à espera do código.
TETO_OCUPADO="${CENTRAL_IA_ATUALIZAR_TETO_OCUPADO:-7200}"
TRAVA="$DIR/central-ia-atualizar.trava"
esperou=0
until mkdir "$TRAVA" 2>/dev/null; do
  dono="$(cat "$TRAVA/pid" 2>/dev/null || true)"
  if [ -n "$dono" ] && ! kill -0 "$dono" 2>/dev/null; then rm -rf "$TRAVA"; continue; fi
  # Sem pid há mais de 1 min: morreu entre o mkdir e gravar o pid.
  if [ -z "$dono" ] && [ -n "$(find "$TRAVA" -maxdepth 0 -mmin +1 2>/dev/null)" ]; then rm -rf "$TRAVA"; continue; fi
  if [ "$esperou" -ge "$TETO_TRAVA" ]; then
    log "ERRO: outra atualização (pid $dono) segura a trava há $((esperou / 60)) min; desisti. Rode o comando de instalar de novo quando ela terminar."
    exit 1
  fi
  sleep 5; esperou=$((esperou + 5))
done
echo $$ > "$TRAVA/pid"
trap 'rm -rf "$TRAVA"' EXIT

antes="$(pid_do_runner || true)"
# Serviço parado: não há pedido a perder.
if [ -z "$antes" ] || [ "$antes" = 0 ]; then rm -f "$REINICIAR" "$PAUSADO"; reiniciar; exit 0; fi

rm -f "$PAUSADO"
pedir
idle=0
sem_runner=0
ocupado=0
while :; do
  sleep 5
  agora="$(pid_do_runner || true)"
  # Saiu e ainda não voltou: espera o serviço religar — até o teto. Desistindo,
  # apaga o pedido: um "pausar" largado deixaria o runner que voltasse depois
  # parado, sem ninguém para recarregar o serviço.
  if [ -z "$agora" ] || [ "$agora" = 0 ]; then
    sem_runner=$((sem_runner + 5))
    if [ "$sem_runner" -ge "$TETO_SEM_RUNNER" ]; then
      rm -f "$REINICIAR" "$PAUSADO"
      log "ERRO: o serviço do runner não voltou em $((sem_runner / 60)) min; desisti. Para ligar à mão:"
      if [ "$(uname -s)" = Darwin ]; then ligar_a_mao
      elif [ -f "$DIR/central-ia-wsl.sh" ]; then log "  rode o comando de instalar de novo (ou reinicie o Windows)"
      else log "  systemctl --user restart central-ia-runner"; fi
      exit 1
    fi
    continue
  fi
  sem_runner=0
  if [ "$agora" != "$antes" ]; then
    # Saiu sozinho e o serviço já o religou: pronto (ele mesmo apagou o arquivo).
    [ "$RECARREGAR" = 1 ] || exit 0
    # Precisava recarregar o plist, mas o runner saiu em vez de pausar: o
    # launchd o religou com o plist velho. Pede de novo, a este.
    antes="$agora"; idle=0; pedir; continue
  fi
  # Pausado, sem pedido na mão: recarrega o serviço.
  if [ "$(cat "$PAUSADO" 2>/dev/null || true)" = "$agora" ]; then
    rm -f "$PAUSADO"; reiniciar; exit 0
  fi
  # Runner que sabe sair (ou pausar) sozinho: só esperar — até o teto. Desistindo,
  # apaga o pedido, para o runner voltar a pegar trabalho com a versão que tem.
  if conhece "$agora"; then
    ocupado=$((ocupado + 5))
    if [ "$ocupado" -ge "$TETO_OCUPADO" ]; then
      rm -f "$REINICIAR"
      log "ERRO: o runner não ficou livre em $((ocupado / 60)) min; desisti, e ele segue com a versão antiga. Rode o comando de instalar de novo mais tarde."
      exit 1
    fi
    continue
  fi
  if ocioso "$agora"; then idle=$((idle+1)); else idle=0; fi
  if [ "$idle" -ge 4 ]; then
    # Última checagem colada no kill, sem espera entre as duas.
    if ocioso "$agora"; then rm -f "$REINICIAR"; reiniciar; exit 0; fi
    idle=0
  fi
done
