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
# O runner de antes desta versão não conhece o arquivo e nunca sai sozinho:
# ocioso por 20 s seguidos sem ter saído (o laço novo dá a volta em até 10 s),
# é ele, e cai no caminho antigo — checar e matar, já sem a espera entre a
# última checagem e o kill. Na mesma situação fica o runner novo com o acesso
# recusado ou fora de alcance, que espaça o laço; aí nenhum pedido chega, e
# matar não derruba nada.
set -eu
DIR="$HOME/.local/share/alfa"
OCUPADO="$DIR/central-ia-ocupado"
REINICIAR="$DIR/central-ia-reiniciar"
ROTULO="br.com.alfa.central-ia-runner"

if [ "$(uname -s)" = Darwin ]; then
  pid_do_runner() { launchctl print "gui/$(id -u)/$ROTULO" 2>/dev/null | awk '/^[[:space:]]*pid = / { print $3; exit }'; }
  reiniciar() { launchctl kickstart -k "gui/$(id -u)/$ROTULO"; }
else
  pid_do_runner() { systemctl --user show central-ia-runner -p MainPID --value; }
  reiniciar() { systemctl --user restart central-ia-runner; }
fi
ocioso() { # $1 = pid do runner
  local filhos
  filhos="$(pgrep -P "$1" 2>/dev/null || true)"
  [ -z "$filhos" ] && [ "$(cat "$OCUPADO" 2>/dev/null || echo 0)" = 0 ]
}

antes="$(pid_do_runner || true)"
# Serviço parado: não há pedido a perder.
if [ -z "$antes" ] || [ "$antes" = 0 ]; then rm -f "$REINICIAR"; reiniciar; exit 0; fi

touch "$REINICIAR"
idle=0
while :; do
  sleep 5
  agora="$(pid_do_runner || true)"
  # Saiu sozinho e o serviço já o religou: pronto (ele mesmo apagou o arquivo).
  if [ -n "$agora" ] && [ "$agora" != 0 ] && [ "$agora" != "$antes" ]; then exit 0; fi
  # Saiu e ainda não voltou: espera o serviço religar.
  if [ -z "$agora" ] || [ "$agora" = 0 ]; then continue; fi
  if ocioso "$agora"; then idle=$((idle+1)); else idle=0; fi
  if [ "$idle" -ge 4 ]; then
    # Última checagem colada no kill, sem espera entre as duas.
    if ocioso "$agora"; then rm -f "$REINICIAR"; reiniciar; exit 0; fi
    idle=0
  fi
done
