#!/bin/bash
# Reinicia o runner só quando ele está de fato ocioso: nenhum agente filho E
# nenhuma execução com a máquina (inclusive esperando o código do dono, que
# não tem processo). O arquivo "central-ia-ocupado" é escrito pelo runner.
#
# Serve ao systemd (Linux, WSL) e ao launchd (Mac) — T-374: era só systemd e
# lia /proc, que o Mac não tem.
set -eu
OCUPADO="$HOME/.local/share/alfa/central-ia-ocupado"
ROTULO="br.com.alfa.central-ia-runner"

if [ "$(uname -s)" = Darwin ]; then
  pid_do_runner() { launchctl print "gui/$(id -u)/$ROTULO" 2>/dev/null | awk '/^[[:space:]]*pid = / { print $3; exit }'; }
  reiniciar() { launchctl kickstart -k "gui/$(id -u)/$ROTULO"; }
else
  pid_do_runner() { systemctl --user show central-ia-runner -p MainPID --value; }
  reiniciar() { systemctl --user restart central-ia-runner; }
fi

idle=0
while [ "$idle" -lt 3 ]; do
  runner_pid="$(pid_do_runner || true)"
  children=""
  if [ -n "$runner_pid" ] && [ "$runner_pid" != 0 ]; then children="$(pgrep -P "$runner_pid" 2>/dev/null || true)"; fi
  ocupado=$(cat "$OCUPADO" 2>/dev/null || echo 0)
  if [ -z "$children" ] && [ "$ocupado" = "0" ]; then idle=$((idle+1)); else idle=0; fi
  sleep 5
done
reiniciar
