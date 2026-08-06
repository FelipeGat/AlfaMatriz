#!/usr/bin/env bash
#
# Vigia de versão da PRODUÇÃO do AlfaMatriz.
#
# Uso (dentro do container de produção, por timer systemd):
#   deploy-tag-watcher-alfamatriz.sh [--dir /var/www/alfamatriz]
#
# Molde: infra/prod-alfahome/deploy-tag-watcher.sh do AlfaDeploy, com as
# diferenças do sistema: sem Docker (roda direto no LXC) e sem assets do
# GHCR (o front-end é compilado aqui).
#
# A produção só muda quando existe uma TAG v* nova. Alteração na main não
# chega aqui — é o que separa "estou mexendo no código" de "isto vale para o
# faturamento da empresa".
#
# Falhou o health-check? O vigia grava um marcador e PARA. Não tenta de novo
# sozinho: insistir em cima de um sistema quebrado só piora, e alguém precisa
# olhar. Para liberar, apague o marcador.

set -uo pipefail

DIR=/var/www/alfamatriz
HEALTH_URL="${HEALTH_URL:-https://matriz.alfasolucoes.cloud/healthz}"
HEALTH_TIMEOUT=90

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir) DIR="$2"; shift 2 ;;
        *) echo "deploy-tag-watcher: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

ESTADO="$DIR/.deploy-tag-state"
FALHOU="$DIR/.deploy-tag-failed"
PAUSADO="$DIR/.deploy-paused"
STATUS_JSON="$DIR/public/deploy-status.json"
LOG="${LOG:-$DIR/storage/logs/deploy-tag.log}"

mkdir -p "$(dirname "$LOG")" 2>/dev/null || true
log(){ echo "$(date '+%F %T') $*" | tee -a "$LOG" 2>/dev/null || echo "$*"; }

# Telemetria lida pelo painel AlfaDeploy.
escrever_status(){
    printf '{"sistema":"alfamatriz","tag":"%s","estado":"%s","quando":"%s"}\n' \
        "${TAG:-}" "$1" "$(date -Is)" > "$STATUS_JSON" 2>/dev/null || true
}

cd "$DIR" || { log "diretório $DIR não existe"; exit 1; }

if [[ -f "$PAUSADO" ]]; then
    log "PAUSADO (.deploy-paused) — nada a fazer"
    exit 0
fi

if [[ -f "$FALHOU" ]]; then
    log "BLOQUEADO: a última tentativa falhou ($(cat "$FALHOU" 2>/dev/null)). Apague $FALHOU depois de investigar."
    exit 0
fi

git fetch --quiet --tags origin 2>/dev/null || { log "fetch de tags FALHOU"; exit 1; }

TAG=$(git tag -l 'v[0-9]*' --sort=-v:refname | head -1)

if [[ -z "$TAG" ]]; then
    log "nenhuma tag v* no repositório — produção segue como está"
    exit 0
fi

ATUAL=$(cat "$ESTADO" 2>/dev/null || echo "")

if [[ "$TAG" == "$ATUAL" ]]; then
    log "UPTODATE ($TAG) — nada a fazer"
    exit 0
fi

log "versão nova: ${ATUAL:-<nenhuma>} -> $TAG"
escrever_status "deployando"

# ------------------------------------------------------- backup primeiro

# Migração ruim em cima de faturamento real precisa ter volta. O backup
# diário não basta: ele é de madrugada, e o estrago seria de agora.
log "gerando cópia do banco antes de migrar"
if ! bash "$DIR/deploy/backup.sh" >>"$LOG" 2>&1; then
    log "FALHA: backup não concluiu — nada foi aplicado"
    echo "backup falhou em $(date -Is)" > "$FALHOU"
    escrever_status "falha"
    exit 1
fi

# ------------------------------------------------------------ aplicação

aplicar(){
    git checkout --quiet "$TAG" || return 1
    composer install --no-dev --optimize-autoloader --no-interaction || return 1
    npm ci --silent || return 1
    npm run build || return 1
    php artisan migrate --force || return 1
    # Sem `config:clear`: ele deixaria a produção sem configuração por alguns
    # segundos, devolvendo 500 a quem estivesse usando. O `config:cache`
    # reescreve o arquivo de uma vez só.
    php artisan config:cache >/dev/null 2>&1 || return 1
    php artisan route:cache >/dev/null 2>&1 || return 1
    php artisan view:cache >/dev/null 2>&1 || return 1
    systemctl reload php8.2-fpm >/dev/null 2>&1 || true
    return 0
}

if ! aplicar >>"$LOG" 2>&1; then
    log "FALHA ao aplicar $TAG — o banco já tem cópia desta execução"
    echo "aplicação de $TAG falhou em $(date -Is)" > "$FALHOU"
    escrever_status "falha"
    exit 1
fi

# --------------------------------------------------------- saúde depois

log "conferindo saúde em $HEALTH_URL"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' --max-time "$HEALTH_TIMEOUT" "$HEALTH_URL" 2>/dev/null)

if [[ "$CODIGO" != "200" ]]; then
    log "FALHA: saúde respondeu $CODIGO depois de aplicar $TAG"
    echo "health-check falhou ($CODIGO) em $(date -Is)" > "$FALHOU"
    escrever_status "falha"
    exit 1
fi

echo "$TAG" > "$ESTADO"
escrever_status "ok"
log "produção em $TAG — saúde ok"
