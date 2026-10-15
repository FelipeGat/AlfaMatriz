#!/bin/bash
# Reinicia o runner só quando ele está de fato ocioso: nenhum agente filho E
# nenhuma execução com a máquina (inclusive esperando o código do dono, que
# não tem processo).
#
# Serve ao systemd (Linux, WSL) e ao launchd (Mac) — T-374: era só systemd e
# lia /proc, que o Mac não tem.
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
set -eu
DIR="$HOME/.local/share/alfa"
OCUPADO="$DIR/central-ia-ocupado"
REINICIAR="$DIR/central-ia-reiniciar"
CONHECE="$DIR/central-ia-conhece-reinicio"
PAUSADO="$DIR/central-ia-pausado"
ROTULO="br.com.alfa.central-ia-runner"
RECARREGAR=0
[ "$(uname -s)" = Darwin ] && [ "${CENTRAL_IA_RECARREGAR:-0}" = 1 ] && RECARREGAR=1

if [ "$(uname -s)" = Darwin ]; then
  PLIST="$HOME/Library/LaunchAgents/$ROTULO.plist"
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
        launchctl bootstrap "gui/$(id -u)" "$PLIST" 2>/dev/null && return 0
        launchctl print "gui/$(id -u)/$ROTULO" >/dev/null 2>&1 && return 0
        sleep 2
      done
      return 1
    }
  else
    reiniciar() { launchctl kickstart -k "gui/$(id -u)/$ROTULO"; }
  fi
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

antes="$(pid_do_runner || true)"
# Serviço parado: não há pedido a perder.
if [ -z "$antes" ] || [ "$antes" = 0 ]; then rm -f "$REINICIAR" "$PAUSADO"; reiniciar; exit 0; fi

rm -f "$PAUSADO"
pedir
idle=0
while :; do
  sleep 5
  agora="$(pid_do_runner || true)"
  # Saiu e ainda não voltou: espera o serviço religar.
  if [ -z "$agora" ] || [ "$agora" = 0 ]; then continue; fi
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
  # Runner que sabe sair (ou pausar) sozinho: só esperar.
  if conhece "$agora"; then continue; fi
  if ocioso "$agora"; then idle=$((idle+1)); else idle=0; fi
  if [ "$idle" -ge 4 ]; then
    # Última checagem colada no kill, sem espera entre as duas.
    if ocioso "$agora"; then rm -f "$REINICIAR"; reiniciar; exit 0; fi
    idle=0
  fi
done
