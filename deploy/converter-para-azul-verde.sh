#!/usr/bin/env bash
#
# Converte uma instalação do AlfaMatriz do formato antigo (um diretório só,
# publicado por cima de si mesmo) para o esquema AZUL/VERDE.
#
# Uso:
#   deploy/converter-para-azul-verde.sh [--dir /var/www/alfamatriz]
#
# IDEMPOTENTE: rodar sobre uma instalação já convertida não faz nada e sai 0.
# É o que permite ao provisionamento chamá-lo sempre, sem condição por fora.
#
# ------------------------------------------------------------------ o que faz
#
#   antes                          depois
#   /var/www/alfamatriz/           /var/www/alfamatriz/          (clone de controle)
#     .git, app/, public/            .git, app/, deploy/          — git, marcadores, scripts
#     .env                           compartilhado/.env           — segredo, uma cópia só
#     storage/app/public/            compartilhado/anexos/        — anexos, fora das versões
#     vendor/, node_modules/         versoes/azul/  (cópia completa, servida)
#                                    versoes/verde/ (cópia completa, de reserva)
#                                    atual   -> versoes/azul
#                                    preparo -> versoes/verde
#
# O diretório de origem NÃO é apagado: ele vira o clone de controle. É dele que
# o painel AlfaDeploy lê a versão (`git rev-parse HEAD`), dele que saem os
# scripts instalados em /usr/local/bin, e nele que continuam morando os
# marcadores (.deploy-paused, .deploy-tag-state, .deploy-gate). Nada do que
# aponta para /var/www/alfamatriz precisa mudar de endereço.
#
# ------------------------------------------------------- ordem e interrupção
#
# A ordem é escolhida para o site continuar no ar do começo ao fim: tudo é
# construído ao lado, e o que estava servindo só sai de cena quando o nginx
# passa a apontar para a cópia azul — o que é feito pelo provisionamento,
# depois deste script. Por isso as dependências são COPIADAS (cp) e não
# movidas: interromper este script no meio deixa a instalação antiga inteira.

set -uo pipefail

DIR="${APP_DIR:-/var/www/alfamatriz}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir) DIR="$2"; shift 2 ;;
        *) echo "converter-para-azul-verde.sh: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

export PATH="$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

VERSOES="$DIR/versoes"
COMPARTILHADO="$DIR/compartilhado"
ANEXOS="$COMPARTILHADO/anexos"
AZUL="$VERSOES/azul"
VERDE="$VERSOES/verde"

info() { echo "==> $*"; }
falhar() { echo "converter-para-azul-verde.sh: $*" >&2; exit 1; }

[[ -d "$DIR" ]] || falhar "o diretório $DIR não existe"
git -C "$DIR" rev-parse --git-dir >/dev/null 2>&1 || falhar "$DIR não é um repositório git"

if [[ -L "$DIR/atual" && -d "$VERSOES" ]]; then
    info "instalação já está em azul/verde — nada a fazer"
    exit 0
fi

info "convertendo $DIR para azul/verde"

mkdir -p "$VERSOES" "$COMPARTILHADO" "$ANEXOS" || falhar "não consegui criar o layout"

# ------------------------------------------------------------------ segredo

# O .env passa a ser da instalação, não da versão: as duas cópias apontam para
# o mesmo arquivo. Trocar uma senha continua sendo editar um arquivo só.
if [[ -f "$DIR/.env" && ! -L "$DIR/.env" ]]; then
    info "movendo o .env para compartilhado/"
    mv "$DIR/.env" "$COMPARTILHADO/.env" || falhar "não consegui mover o .env"
    ln -sfn "$COMPARTILHADO/.env" "$DIR/.env" || falhar "não consegui apontar o .env do controle"
elif [[ ! -f "$COMPARTILHADO/.env" ]]; then
    echo "    AVISO: não há .env nem em $DIR nem em compartilhado/ — preencha antes de publicar."
fi

# ------------------------------------------------------------------- anexos

# Anexos de cobranças e contas a pagar são dado real, gravado pelo usuário.
# Vivendo dentro da versão, some da vista na troca seguinte — e o `storage:link`
# da versão nova apontaria para uma pasta vazia. Vão para compartilhado/, e o
# app passa a gravar lá pelo FILESYSTEM_PUBLIC_ROOT.
if [[ -d "$DIR/storage/app/public" ]]; then
    if [[ -n "$(ls -A "$DIR/storage/app/public" 2>/dev/null)" ]]; then
        info "copiando os anexos existentes para compartilhado/anexos"
        cp -a "$DIR/storage/app/public/." "$ANEXOS/" || falhar "não consegui copiar os anexos"
    fi
fi

if [[ -f "$COMPARTILHADO/.env" ]] && ! grep -q '^FILESYSTEM_PUBLIC_ROOT=' "$COMPARTILHADO/.env"; then
    info "apontando o disco de anexos para compartilhado/anexos"
    printf '\n# Anexos vivem FORA das versões: com azul/verde a pasta da\n# aplicação troca a cada publicação (deploy/publicar.sh).\nFILESYSTEM_PUBLIC_ROOT=%s\n' \
        "$ANEXOS" >> "$COMPARTILHADO/.env" || falhar "não consegui escrever no .env"
fi

# ------------------------------------------------------------------ cópias

SHA=$(git -C "$DIR" rev-parse HEAD 2>/dev/null)
[[ -n "$SHA" ]] || falhar "não consegui descobrir o commit publicado hoje em $DIR"

# Conversão interrompida no meio pode ter deixado uma cópia registrada no git
# sem o diretório correspondente; sem isto, a segunda tentativa falharia em
# "already registered".
git -C "$DIR" worktree prune >/dev/null 2>&1 || true

for COR in azul verde; do
    if [[ -d "$VERSOES/$COR" ]]; then
        info "cópia $COR já existe"
        continue
    fi
    info "criando a cópia $COR no commit que está no ar (${SHA:0:7})"
    git -C "$DIR" worktree add --detach "$VERSOES/$COR" "$SHA" >/dev/null 2>&1 \
        || falhar "não consegui criar a cópia $COR"
done

# A cópia azul recebe o que a instalação antiga já tinha pronto. Copiar em vez
# de reinstalar poupa os ~2 minutos de composer + npm e, mais importante, faz a
# conversão publicar EXATAMENTE o que já estava no ar — e não uma reconstrução
# que pode diferir por um pacote atualizado no caminho.
info "levando dependências e front-end compilado para a cópia azul"
for ITEM in vendor node_modules public/build; do
    [[ -e "$DIR/$ITEM" ]] || continue
    [[ -e "$AZUL/$ITEM" ]] && continue
    mkdir -p "$(dirname "$AZUL/$ITEM")"
    cp -a "$DIR/$ITEM" "$AZUL/$ITEM" || falhar "não consegui copiar $ITEM"
done

ln -sfn "$COMPARTILHADO/.env" "$AZUL/.env" || falhar "não consegui apontar o .env da cópia azul"

info "recarregando os caches da cópia azul"
( cd "$AZUL" && php artisan storage:link ) || falhar "não consegui ligar a pasta pública de anexos"
( cd "$AZUL" && php artisan config:cache >/dev/null ) || falhar "não consegui gerar o cache de configuração"
( cd "$AZUL" && php artisan route:cache >/dev/null ) || falhar "não consegui gerar o cache de rotas"
( cd "$AZUL" && php artisan view:cache >/dev/null ) || falhar "não consegui gerar o cache de views"

# ---------------------------------------------------------------- symlinks

info "apontando atual -> azul e preparo -> verde"
ln -sfn "$AZUL" "$DIR/atual" || falhar "não consegui criar o symlink atual"
ln -sfn "$VERDE" "$DIR/preparo" || falhar "não consegui criar o symlink preparo"

# --------------------------------------------------------------- telemetria

# A telemetria que o painel lê muda de endereço no disco (de public/ para a
# raiz) e continua na mesma URL, servida por um `alias` do nginx. Sem trazer o
# arquivo junto, o painel ficaria sem notícia até a próxima publicação.
if [[ -f "$DIR/public/deploy-status.json" && ! -f "$DIR/deploy-status.json" ]]; then
    info "trazendo a telemetria do painel para a raiz da instalação"
    mv "$DIR/public/deploy-status.json" "$DIR/deploy-status.json" || true
fi

echo
echo "converter-para-azul-verde.sh: conversão concluída."
echo "  no ar (depois do nginx recarregar): $AZUL"
echo "  reserva:                            $VERDE"
echo
echo "o vendor/ e o node_modules/ da raiz de controle não são mais usados por"
echo "ninguém — pode apagá-los quando a primeira publicação azul/verde passar."
