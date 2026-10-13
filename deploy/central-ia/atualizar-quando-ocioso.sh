#!/bin/bash
# Reinicia o runner só quando ele está de fato ocioso: nenhum agente filho E
# nenhuma execução com a máquina (inclusive esperando o código do dono, que
# não tem processo). O arquivo "central-ia-ocupado" é escrito pelo runner.
set -eu
OCUPADO="$HOME/.local/share/alfa/central-ia-ocupado"
runner_pid=$(systemctl --user show central-ia-runner -p MainPID --value)
idle=0
while [ "$idle" -lt 3 ]; do
  children=$(cat "/proc/$runner_pid/task/$runner_pid/children" 2>/dev/null || true)
  ocupado=$(cat "$OCUPADO" 2>/dev/null || echo 0)
  if [ -z "$children" ] && [ "$ocupado" = "0" ]; then idle=$((idle+1)); else idle=0; fi
  sleep 5
done
systemctl --user restart central-ia-runner
