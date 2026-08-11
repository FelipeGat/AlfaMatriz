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

# O cron do root roda com PATH=/usr/bin:/bin, e o composer vive em
# /usr/local/bin. Sem esta linha o script funciona quando chamado à mão e
# falha a cada 5 minutos pelo agendador, sempre no mesmo ponto: "composer:
# command not found", antes mesmo de chegar ao health check.
#
# É o MESMO defeito que o deploy-staging já tinha e corrigiu; este script
# nasceu depois e não herdou a correção. Acrescentado ao FIM, não ao início:
# garante os diretórios do sistema sem tirar a prioridade de quem chamou.
export PATH="$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

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

# Uma execução por vez. O `deploy-staging` já tem esta trava; este script
# nasceu depois e não a herdou — a mesma história do PATH acima.
#
# Sem ela, uma execução manual e o timer das :05 rodam juntas e as duas chamam
# `migrate --force` ao mesmo tempo: a segunda estoura em "Table already exists",
# grava o marcador de falha e BLOQUEIA todo deploy seguinte, enquanto a
# primeira termina bem. Aconteceu ao publicar a v2026.08.10: produção ficou
# correta e travada ao mesmo tempo, que é o pior dos dois mundos para
# diagnosticar.
#
# `mkdir` e não `flock`: a criação de diretório é atômica nos dois sistemas, e
# `flock` é do util-linux — não existe no macOS, onde a suíte deste script
# roda. Um lock que só funciona no servidor não é testável, e este script já
# provou que precisa de teste.
#
# Quem chega depois desiste em silêncio em vez de enfileirar: não faz sentido
# aplicar a mesma tag duas vezes.
TRAVA="$DIR/.deploy-tag.lock"

# Trava esquecida por queda no meio do caminho bloquearia todo deploy seguinte
# — o mesmo modo de falha do marcador de erro. Um deploy leva ~2 minutos; uma
# hora é folga suficiente para não competir com execução legítima.
if [[ -d "$TRAVA" ]] && [[ -z "$(find "$TRAVA" -maxdepth 0 -mmin -60 2>/dev/null)" ]]; then
    log "trava antiga (>60min) — assumindo que ficou para trás e seguindo"
    rmdir "$TRAVA" 2>/dev/null || true
fi

if ! mkdir "$TRAVA" 2>/dev/null; then
    log "outra execução em andamento — saindo"
    exit 0
fi

# Liberada em qualquer saída, inclusive nas que gravam marcador de falha.
trap 'rmdir "$TRAVA" 2>/dev/null || true' EXIT

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
    # Depois do migrate e antes dos caches: permissão é dado semeado, não
    # migrado, e sem isto todo recurso novo nasce invisível em produção.
    php artisan alfa:semear-referencia || return 1
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
