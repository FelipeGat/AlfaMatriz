#!/usr/bin/env bash
#
# Atualiza o STAGING do AlfaMatriz a partir da main.
#
# Uso (no host Proxmox, chamado pelo deploy-staging ou pelo painel):
#   deploy-staging-alfamatriz.sh [--lxc 116] [--dir /var/www/alfamatriz]
#   deploy-staging-alfamatriz.sh --local          # já estou dentro do container
#
# Equivalente ao `deploy_alfahome` do /usr/local/bin/deploy-staging, com duas
# diferenças do sistema:
#   - sem Docker: os comandos rodam direto no LXC;
#   - sem GHCR: o AlfaMatriz compila no servidor, então o portão que segura o
#     deploy é a SUÍTE DE TESTES, não a existência de uma imagem do CI.
#
# O portão é o ponto principal deste script: nos outros sistemas, código
# quebrado nunca chega no staging porque o CI não publica a imagem. Aqui,
# se os testes falham, nada é aplicado e o staging fica na versão anterior.

set -uo pipefail

LXC=116
DIR=/var/www/alfamatriz
LOCAL=0
LOG=${LOG:-/var/log/deploy-staging-alfamatriz.log}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --lxc) LXC="$2"; shift 2 ;;
        --dir) DIR="$2"; shift 2 ;;
        --local) LOCAL=1; shift ;;
        *) echo "deploy-staging-alfamatriz.sh: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

log(){ echo "$(date '+%F %T') alfamatriz: $*" | tee -a "$LOG" 2>/dev/null || echo "alfamatriz: $*"; }

# Roda um comando dentro do container de staging (ou aqui mesmo, com --local).
#
# `pct exec` usa shell de LOGIN de propósito: é o que carrega o PATH com
# composer, php e npm dentro do container. Já no modo local o ambiente é o de
# quem chamou — e login shell aqui atrapalharia, porque em alguns sistemas
# (macOS) ele reescreve o PATH e descarta o que o chamador definiu.
no_container() {
    if [[ "$LOCAL" -eq 1 ]]; then
        bash -c "cd $DIR && $1"
    else
        pct exec "$LXC" -- bash -lc "cd $DIR && $1"
    fi
}

# ------------------------------------------------------- pausa e novidade

if no_container "test -f .deploy-paused" 2>/dev/null; then
    log "PAUSADO (.deploy-paused presente) — nada a fazer"
    exit 0
fi

no_container "git fetch --quiet origin main" || { log "pull FALHOU (fetch)"; exit 1; }

LOCAL_SHA=$(no_container "git rev-parse HEAD" 2>/dev/null | tr -d '\r\n')
REMOTO_SHA=$(no_container "git rev-parse origin/main" 2>/dev/null | tr -d '\r\n')

if [[ -z "$REMOTO_SHA" ]]; then
    log "pull FALHOU (não consegui ler origin/main)"
    exit 1
fi

if [[ "$LOCAL_SHA" == "$REMOTO_SHA" ]]; then
    log "UPTODATE (${LOCAL_SHA:0:7}) — nada a fazer"
    exit 0
fi

log "novidade na main: ${LOCAL_SHA:0:7} -> ${REMOTO_SHA:0:7}"

# --------------------------------------------------------------- o portão

# Traz o código ANTES de testar (é a versão nova que precisa ser aprovada),
# mas guarda a anterior para poder voltar se o portão reprovar.
no_container "git merge --ff-only origin/main" || { log "CONFLITO ff-only"; exit 1; }

log "portão: instalando dependências e rodando a suíte"
no_container "composer install --no-interaction --quiet" || {
    log "portão REPROVOU (composer falhou) — voltando para ${LOCAL_SHA:0:7}"
    no_container "git reset --hard $LOCAL_SHA"
    exit 1
}

if ! no_container "php artisan test"; then
    log "portão REPROVOU (teste falhando) — staging fica em ${LOCAL_SHA:0:7}"
    no_container "git reset --hard $LOCAL_SHA"
    exit 1
fi

log "portão aprovou — aplicando ${REMOTO_SHA:0:7}"

# ------------------------------------------------------------- aplicação

no_container "npm ci --silent" || { log "npm ci FALHOU"; exit 1; }
no_container "npm run build" || { log "build do front-end FALHOU"; exit 1; }
no_container "php artisan migrate --force" || { log "migração FALHOU"; exit 1; }
no_container "php artisan config:clear >/dev/null && php artisan config:cache >/dev/null"
no_container "php artisan route:cache >/dev/null && php artisan view:cache >/dev/null"

if [[ "$LOCAL" -eq 1 ]]; then
    systemctl reload php8.2-fpm >/dev/null 2>&1 || true
else
    pct exec "$LXC" -- systemctl reload php8.2-fpm >/dev/null 2>&1 || true
fi

log "staging atualizado para ${REMOTO_SHA:0:7}"
