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

# O cron do root roda com PATH=/usr/bin:/bin, onde `pct` (em /usr/sbin) não
# existe. Sem esta linha o script funciona quando chamado à mão e falha
# silenciosamente a cada 5 minutos pelo agendador — foi exatamente o que
# aconteceu, e só apareceu ao ler o log.
# Acrescentado ao FIM, não ao início: garante os diretórios do sistema sem
# tirar a prioridade de quem chamou. Prefixar quebraria qualquer ambiente que
# aponte para binários próprios — inclusive o teste deste script.
export PATH="$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

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

# ------------------------------------------------------------------ trava
#
# Uma execução por vez. São DOIS gatilhos apontando para o mesmo diretório: o
# cron de 5 em 5 minutos e o botão do painel (que chega aqui pelo
# `deploy-staging alfamatriz` do host). Juntos, fariam `git merge` e
# `composer install` um em cima do outro — e o segundo esbarraria no
# index.lock do git com uma mensagem que ninguém relaciona à causa.
#
# A trava fica AQUI e não em quem chama: é o único ponto por onde os dois
# caminhos passam. Enquanto ela viveu só do lado do painel, protegia clique
# contra clique e deixava passar o encontro com o cron, que é o provável.
#
# `mkdir` e não `flock`, mesma escolha do vigia de tags e pelo mesmo motivo:
# criar diretório é atômico nos dois sistemas, e `flock` é do util-linux — não
# existe no macOS, onde esta suíte roda. Trava que só funciona no servidor não
# é testável.
#
# Fica no /run e não em "$DIR/.deploy-staging.lock" como a do vigia: o vigia
# roda DENTRO do container, mas este script roda no host e o $DIR dele é um
# caminho do LXC. Um mkdir daqui criaria a trava no /var/www do host, que não
# é o mesmo diretório que ele protege.
#
# Quem chega depois desiste em silêncio e sai 0: para o cron, encontrar o
# vizinho rodando é o dia normal, não incidente — sair 1 pintaria a unit de
# vermelho a cada cinco minutos.
TRAVA=${TRAVA:-/run/deploy-staging-alfamatriz.lock.d}

# Trava esquecida por queda no meio do caminho bloquearia todo deploy seguinte
# — o mesmo modo de falha do marcador de erro do vigia. Um deploy leva ~2
# minutos; uma hora é folga suficiente para não competir com execução legítima.
if [[ -d "$TRAVA" ]] && [[ -z "$(find "$TRAVA" -maxdepth 0 -mmin -60 2>/dev/null)" ]]; then
    log "trava antiga (>60min) — assumindo que ficou para trás e seguindo"
    rmdir "$TRAVA" 2>/dev/null || true
fi

if mkdir "$TRAVA" 2>/dev/null; then
    # Liberada em qualquer saída, inclusive nas que reprovam no portão.
    trap 'rmdir "$TRAVA" 2>/dev/null || true' EXIT
elif [[ -d "$TRAVA" ]]; then
    log "já há um deploy em curso — nada a fazer"
    exit 0
else
    # mkdir falhou e a trava NÃO existe: é problema de ambiente (diretório pai
    # ausente ou sem permissão), não vizinho rodando. Tratar os dois casos como
    # o mesmo faria o deploy parar para sempre em silêncio, que é justamente o
    # que esta trava não pode causar.
    log "não consegui criar a trava em $TRAVA — seguindo sem ela"
fi

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

# Registra o veredito do portão DENTRO do container, onde o painel enxerga.
# Sem isto, "por que o staging não anda?" só se responde lendo este log no
# host — e o painel fala com o container, não com o Proxmox.
veredito() { # $1=resultado (ok|reprovado) $2=sha $3=motivo
    no_container "cat > $DIR/.deploy-gate <<'FIM'
{\"resultado\":\"$1\",\"sha\":\"$2\",\"motivo\":\"$3\",\"quando\":\"$(date -u +%FT%TZ)\"}
FIM" 2>/dev/null || true
}

# ------------------------------------------------ portão e aplicação
#
# As duas coisas acontecem numa chamada só, dentro do publicar.sh — o mesmo
# motor que a produção usa. O portão (a suíte) roda como `--portao`, DENTRO da
# cópia em preparo, depois das dependências e antes de qualquer outra etapa.
#
# É o que muda de verdade em relação a antes: o código novo era mesclado no
# diretório QUE ESTAVA NO AR e testado ali; reprovando, o conserto era um
# `git reset --hard` em cima do site vivo, que ficava servindo código não
# verificado durante toda a rodada da suíte. Agora, reprovando, o staging nem
# soube que houve tentativa.
#
# O `--url-publica` fica de fora aqui de propósito: o publicar.sh já confere a
# saúde pela porta de ensaio ANTES de trocar, e o staging não tem endereço
# público para conferir depois.

log "portão: rodando a suíte na cópia em preparo e publicando se ela passar"

if ! no_container "deploy/publicar.sh --dir $DIR --ref origin/main --portao 'php artisan test'"; then
    log "portão REPROVOU ou a publicação falhou — staging fica em ${LOCAL_SHA:0:7}"
    veredito reprovado "$REMOTO_SHA" "suite de testes reprovou ou a publicacao falhou"
    exit 1
fi

veredito ok "$REMOTO_SHA" "suite aprovou"

log "staging atualizado para ${REMOTO_SHA:0:7}"
