#!/usr/bin/env bash
#
# Restaura o banco do AlfaMatriz a partir de uma cópia gerada por backup.sh.
#
# Uso:
#   deploy/restaurar.sh --arquivo /var/backups/alfamatriz/alfamatriz-AAAA-MM-DD.sql.gz --confirmo
#
# Restaurar SOBRESCREVE o banco de produção — faturamento e financeiro reais.
# Por isso o script exige as duas coisas: um arquivo que existe e o
# --confirmo explícito. Faltando qualquer uma, ele recusa sem tocar no banco.

set -euo pipefail

ARQUIVO=""
CONFIRMADO=0
BANCO="${DB_DATABASE:-alfamatriz}"
USUARIO="${DB_USERNAME:-alfamatriz}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --arquivo) ARQUIVO="${2:-}"; shift 2 ;;
        --confirmo) CONFIRMADO=1; shift ;;
        *) echo "restaurar.sh: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

recusar() {
    echo "restaurar.sh: $* — o banco NÃO foi alterado." >&2
    exit 1
}

if [[ -z "$ARQUIVO" ]]; then
    recusar "informe a cópia a restaurar com --arquivo"
fi

if [[ ! -f "$ARQUIVO" ]]; then
    recusar "o arquivo \"$ARQUIVO\" não existe"
fi

if [[ ! -s "$ARQUIVO" ]]; then
    recusar "o arquivo \"$ARQUIVO\" está vazio"
fi

if [[ "$CONFIRMADO" -ne 1 ]]; then
    recusar "restaurar sobrescreve o banco de produção; repita com --confirmo se é isso mesmo"
fi

SENHA=""
if [[ -f /root/.alfamatriz-db-pass ]]; then
    SENHA="$(cat /root/.alfamatriz-db-pass)"
fi

echo "==> restaurando $ARQUIVO em $BANCO"

if [[ -n "$SENHA" ]]; then
    gunzip -c "$ARQUIVO" | mariadb -u "$USUARIO" -p"$SENHA" "$BANCO"
else
    gunzip -c "$ARQUIVO" | mariadb "$BANCO"
fi

echo "restaurar.sh: restauração concluída"
