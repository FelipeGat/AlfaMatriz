#!/usr/bin/env bash
#
# Confere, depois de publicar uma versão, que o AlfaMatriz está saudável na
# URL pública.
#
# Uso:
#   deploy/smoke.sh [--url URL]
#
# --url URL   endereço público a conferir (padrão: o domínio da empresa,
#              servido pelo túnel Cloudflare).
#              Existe para permitir testar o script contra um servidor falso,
#              sem tocar na URL real.
#
# Confere que a URL responde em HTTPS, que /healthz volta 200, que a tela de
# login abre (200) e que a tela de cadastro está fechada (404). Sai 0 só se
# as quatro checagens passarem; senão lista qual falhou e sai com erro.

set -uo pipefail

URL="${URL:-https://matriz.alfasolucoes.cloud}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --url)
            URL="$2"
            shift 2
            ;;
        *)
            echo "smoke.sh: argumento desconhecido: $1" >&2
            exit 1
            ;;
    esac
done

falhas=()

checar_https() {
    if [[ "$URL" != https://* ]]; then
        echo "[FALHOU] https — a URL não é https" >&2
        falhas+=("https")
        return
    fi

    if curl -s -o /dev/null "$URL"; then
        echo "[OK] https"
    else
        echo "[FALHOU] https — não foi possível conectar em $URL" >&2
        falhas+=("https")
    fi
}

checar_status() {
    local nome="$1" caminho="$2" esperado="$3"
    local alvo="${URL%/}${caminho}"
    local codigo

    codigo="$(curl -s -o /dev/null -w '%{http_code}' "$alvo")"

    if [[ "$codigo" == "$esperado" ]]; then
        echo "[OK] ${nome} (${codigo})"
    else
        echo "[FALHOU] ${nome} — esperado ${esperado}, recebeu ${codigo}" >&2
        falhas+=("$nome")
    fi
}

checar_https
checar_status "healthz" "/healthz" "200"
checar_status "login" "/login" "200"
checar_status "registro" "/register" "404"

if [[ ${#falhas[@]} -eq 0 ]]; then
    echo "smoke.sh: todas as checagens passaram"
    exit 0
fi

echo "smoke.sh: falharam: ${falhas[*]}" >&2
exit 1
