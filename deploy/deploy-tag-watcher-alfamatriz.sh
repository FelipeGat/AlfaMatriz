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
# A aplicação é AZUL/VERDE (deploy/publicar.sh): a versão nova é montada na
# cópia que não está no ar e só entra depois de passar na porta de ensaio.
# Falhando antes da troca, a produção sequer é tocada; falhando a saúde DEPOIS
# da troca, a versão anterior volta ao ar sozinha, em ~1 segundo.
#
# Nos dois casos o vigia grava um marcador e PARA. Não tenta de novo sozinho:
# insistir em cima de um sistema quebrado só piora, e alguém precisa olhar.
# Para liberar, apague o marcador.

set -uo pipefail

DIR=/var/www/alfamatriz
# Endereço público. Quem pergunta a saúde é o publicar.sh, depois da troca —
# e é a resposta dele que decide se a versão fica ou volta.
HEALTH_URL="${HEALTH_URL:-https://matriz.alfasolucoes.cloud/healthz}"

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
# Fica na raiz da instalação, e não em public/: com azul/verde o public/ é da
# VERSÃO e troca a cada publicação — a telemetria descreve a instalação e
# precisa sobreviver à troca. O nginx a serve na mesma URL de sempre, por um
# `alias` (ver deploy/nginx-alfamatriz.conf).
STATUS_JSON="$DIR/deploy-status.json"
LOG="${LOG:-$DIR/storage/logs/deploy-tag.log}"

# O motor da publicação é o publicar.sh — o mesmo que o staging usa. Enquanto
# cada script tinha a sua cópia das etapas, elas derivaram: a carga de
# referência foi acrescentada num e faltou no outro, e recurso novo nasceu
# invisível em produção. Uma implementação só, dois chamadores.
#
# Vizinho primeiro: é assim que a suíte consegue exercitar o vigia contra um
# repositório descartável. No servidor o vigia roda de /usr/local/bin, onde não
# há vizinho, e cai no deploy/ do clone de controle.
PUBLICADOR="${PUBLICADOR:-$(dirname "$0")/publicar.sh}"
[[ -x "$PUBLICADOR" ]] || PUBLICADOR="$DIR/deploy/publicar.sh"

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

# `--force` e não o fetch comum: tag RECRIADA no remoto (apontando para outro
# commit) faz o fetch sem força sair 1 com "would clobber existing tag", e o
# vigia aborta AQUI, antes de olhar tag nenhuma. Em 2026-08-11 isso congelou a
# produção por 1h20: a v2026.08.11.3 foi recriada e a v2026.08.11.7, que nada
# tinha a ver com ela, simplesmente não chegou. Mover tag é fato da vida e não
# pode ter poder de veto sobre a esteira inteira.
#
# O erro do git vai para o LOG, não para /dev/null. "fetch de tags FALHOU"
# sozinho não diz nada: foi preciso entrar no container e rodar o fetch à mão
# para descobrir a causa, com a produção surda o tempo todo.
if ! ERRO_FETCH=$(git fetch --quiet --tags --force origin 2>&1); then
    log "fetch de tags FALHOU: ${ERRO_FETCH:-<git não disse nada>}"

    # Telemetria de falha, e por quê: o painel lê este arquivo e mostrava
    # VERDE, porque ele descrevia a última aplicação bem-sucedida e não a
    # tentativa de agora. Produção parada aparecendo como saudável é pior que
    # produção parada. A tag informada continua sendo a que está no ar.
    TAG=$(cat "$ESTADO" 2>/dev/null || echo "")
    escrever_status "falha"

    # Sem gravar o marcador de bloqueio, de propósito: ele existe para deploy
    # que passou e quebrou a saúde, onde insistir piora. Falha de fetch é
    # quase sempre transitória (rede, remoto fora do ar) e tentar de novo em 5
    # minutos é exatamente o certo — o marcador transformaria um soluço de
    # rede em parada que só sai com alguém apagando arquivo no servidor.
    exit 1
fi

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
#
# Quem faz o trabalho é o publicar.sh, no esquema azul/verde: a versão nova é
# montada inteira na cópia que NÃO está no ar, conferida por uma porta de
# ensaio que só o localhost alcança, e só então colocada no ar pela troca de um
# symlink. A produção que está atendendo não é tocada até esse instante.
#
# Antes disto, o `git checkout` e o `composer install` rodavam em cima do
# diretório servido: por ~2 minutos o site misturava código velho e novo, e um
# build que falhasse no meio deixava a produção quebrada.

log "aplicando $TAG (azul/verde) via $PUBLICADOR"

"$PUBLICADOR" --dir "$DIR" --ref "$TAG" --url-publica "$HEALTH_URL" >>"$LOG" 2>&1
CODIGO_PUBLICACAO=$?

case "$CODIGO_PUBLICACAO" in
    0)
        ;;
    2)
        # Trocou, a saúde reprovou e o publicar.sh já desfez a troca: a versão
        # anterior voltou ao ar em ~1 segundo, com dependências e caches
        # intactos. O vigia só registra e para.
        log "FALHA: $TAG subiu, a saúde reprovou e a troca foi DESFEITA — a versão anterior está no ar"
        log "       as migrações de $TAG continuam aplicadas no banco (a cópia de antes está em /var/backups/alfamatriz)"
        echo "saúde reprovou depois da troca; voltou sozinho para a versão anterior em $(date -Is)" > "$FALHOU"
        escrever_status "falha"
        exit 1
        ;;
    *)
        log "FALHA ao preparar $TAG — a versão anterior segue no ar, intacta (o banco já tem cópia desta execução)"
        echo "aplicação de $TAG falhou em $(date -Is)" > "$FALHOU"
        escrever_status "falha"
        exit 1
        ;;
esac

echo "$TAG" > "$ESTADO"
escrever_status "ok"
log "produção em $TAG — saúde ok"
