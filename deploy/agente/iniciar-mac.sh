#!/bin/bash
#
# O que o launchd executa no Mac: carrega o ambiente do agente e inicia a ponte.
# Vive em ~agente/alfa-agente/iniciar.sh (cópia deste arquivo), fora do clone, para
# um `git pull` no meio de uma rodada não trocar o script que está em execução.
#
# `set -a` + `source` funciona aqui porque os valores do .env ficam entre aspas —
# o token do MCP tem um "|" que, sem aspas, o shell leria como pipe.

set -euo pipefail

set -a
# shellcheck disable=SC1091
. "$HOME/alfa-agente/agente.env"
set +a

# O virtualenv do Whisper na frente (é o python3 do transcrever.py), depois o
# Claude Code do usuário, depois o Homebrew, onde moram php, node, git e gh.
export PATH="$HOME/whisper/bin:$HOME/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin"

exec /opt/homebrew/bin/node "${AGENTE_REPO:-$HOME/AlfaMatriz}/deploy/agente/ponte-telegram.mjs"
