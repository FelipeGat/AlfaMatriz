#!/usr/bin/env bash
#
# Volta o AlfaMatriz para a versão anterior — a que ficou de reserva na outra
# cópia do esquema azul/verde.
#
# Uso:
#   deploy/voltar.sh [--dir CAMINHO] [--url-publica URL] [--sim]
#
# --dir CAMINHO      raiz da instalação (padrão: /var/www/alfamatriz).
# --url-publica URL  saúde conferida depois de voltar.
# --sim              não pergunta nada. É o que o vigia e o painel usam.
#
# A volta é a troca de um symlink: a versão anterior continua inteira no disco,
# com dependências instaladas, front-end compilado e caches quentes. Por isso
# ela leva ~1 segundo, e não os ~2 minutos de reconstruir tudo.
#
# O que NÃO volta é o banco. As migrações da versão que saiu continuam
# aplicadas — e é por isso que a regra de migração compatível com a versão
# anterior não é recomendação, é o que sustenta este script. Havendo estrago
# no banco, o caminho é deploy/restaurar.sh, não este.

set -uo pipefail

DIR="${APP_DIR:-/var/www/alfamatriz}"
URL_PUBLICA="${URL_PUBLICA:-}"
PERGUNTAR=1
TENTATIVAS_SAUDE="${TENTATIVAS_SAUDE:-10}"
ESPERA_SAUDE="${ESPERA_SAUDE:-2}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir) DIR="$2"; shift 2 ;;
        --url-publica) URL_PUBLICA="$2"; shift 2 ;;
        --sim) PERGUNTAR=0; shift ;;
        *) echo "voltar.sh: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

export PATH="$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

ATUAL="$DIR/atual"
PREPARO="$DIR/preparo"

falhar() { echo "voltar.sh: $*" >&2; exit 1; }

trocar_link() { # $1=destino  $2=caminho do link
    local destino="$1" link="$2" temporario="$2.trocando.$$"

    rm -f "$temporario"
    ln -sfn "$destino" "$temporario" || return 1

    if mv -Tf "$temporario" "$link" 2>/dev/null; then
        return 0
    fi

    rm -f "$temporario"
    ln -sfn "$destino" "$link"
}

[[ -L "$ATUAL" && -L "$PREPARO" ]] || falhar "esta instalação ainda não usa azul/verde (não há atual/preparo em $DIR)"

NO_AR=$(readlink "$ATUAL")
RESERVA=$(readlink "$PREPARO")

[[ -n "$RESERVA" && "$RESERVA" != "$NO_AR" ]] || falhar "não há versão de reserva para onde voltar"

# Cópia de reserva sem dependências instaladas não é versão anterior: é uma
# pasta vazia. Voltar para ela trocaria uma falha por um site fora do ar.
[[ -d "$RESERVA/vendor" ]] || falhar "a cópia de reserva ($RESERVA) não tem dependências instaladas — não dá para voltar para ela"

VERSAO_NO_AR=$(git -C "$NO_AR" describe --tags --always 2>/dev/null || echo "?")
VERSAO_RESERVA=$(git -C "$RESERVA" describe --tags --always 2>/dev/null || echo "?")

echo "no ar:    $NO_AR ($VERSAO_NO_AR)"
echo "voltando: $RESERVA ($VERSAO_RESERVA)"

if [[ "$PERGUNTAR" -eq 1 ]]; then
    read -r -p "confirma voltar para $VERSAO_RESERVA? [s/N] " RESPOSTA
    # `tr` e não `${RESPOSTA,,}`: a minúscula por expansão é do bash 4, e o
    # macOS ainda entrega o 3.2.
    [[ "$(echo "$RESPOSTA" | tr '[:upper:]' '[:lower:]')" == "s" ]] || { echo "voltar.sh: cancelado"; exit 0; }
fi

trocar_link "$RESERVA" "$ATUAL" || falhar "não consegui trocar o symlink do que está no ar"
trocar_link "$NO_AR" "$PREPARO" || echo "voltar.sh: aviso — o symlink de preparo não foi atualizado"

if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    systemctl reload php8.2-fpm >/dev/null 2>&1 || true
else
    sudo systemctl reload php8.2-fpm >/dev/null 2>&1 || true
fi

# O clone de controle acompanha o que está no ar — é dele que o painel lê a
# versão. Sem isto, voltar deixaria o painel anunciando a versão quebrada.
SHA_RESERVA=$(git -C "$RESERVA" rev-parse HEAD 2>/dev/null)
[[ -n "$SHA_RESERVA" ]] && git -C "$DIR" checkout --detach --quiet "$SHA_RESERVA" 2>/dev/null

# O vigia precisa ficar BLOQUEADO depois de uma volta. Sem isto, a tag da qual
# se acabou de voltar continua sendo a mais recente do repositório: em cinco
# minutos o vigia a aplicaria de novo, e o sistema voltaria a quebrar sozinho
# — com quem voltou a versão jurando ter resolvido.
#
# Para liberar: corrija, marque uma versão nova e apague o marcador.
echo "voltou de $VERSAO_NO_AR para $VERSAO_RESERVA em $(date -u +%FT%TZ)" > "$DIR/.deploy-tag-failed"

# Telemetria do painel. A tag informada é a que FALHOU, não a que ficou no ar:
# é ela que o painel está acompanhando, e é dela que se espera notícia.
printf '{"sistema":"alfamatriz","tag":"%s","estado":"falha","quando":"%s"}\n' \
    "$VERSAO_NO_AR" "$(date -u +%FT%TZ)" > "$DIR/deploy-status.json" 2>/dev/null || true

if [[ -n "$URL_PUBLICA" ]]; then
    CODIGO=""
    for (( i = 1; i <= TENTATIVAS_SAUDE; i++ )); do
        CODIGO=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$URL_PUBLICA" 2>/dev/null)
        [[ "$CODIGO" == "200" ]] && break
        [[ $i -lt $TENTATIVAS_SAUDE ]] && sleep "$ESPERA_SAUDE"
    done

    if [[ "$CODIGO" != "200" ]]; then
        echo "voltar.sh: voltou para $VERSAO_RESERVA, mas a saúde respondeu \"${CODIGO:-<sem resposta>}\" — o problema não era só a versão." >&2
        exit 1
    fi
fi

echo "voltar.sh: no ar em $RESERVA ($VERSAO_RESERVA)"
