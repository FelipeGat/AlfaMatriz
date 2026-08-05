#!/usr/bin/env bash
#
# Cópia diária do banco do AlfaMatriz, rodada pelo cron do container.
#
# Uso:
#   deploy/backup.sh [--dir CAMINHO] [--reter N] [--sem-dump]
#
# --dir CAMINHO   onde guardar as cópias (padrão: /var/backups/alfamatriz)
# --reter N       quantas cópias manter (padrão: 7)
# --sem-dump      só aplica a retenção, sem falar com o banco. Existe para o
#                 teste conferir a retenção de verdade, num diretório
#                 temporário, sem precisar de um MariaDB.
#
# O painel guarda faturamento e financeiro reais: sem isto, uma falha de
# disco leva o negócio junto.

set -euo pipefail

DIR="/var/backups/alfamatriz"
RETER=7
BANCO="${DB_DATABASE:-alfamatriz}"
USUARIO="${DB_USERNAME:-alfamatriz}"
COM_DUMP=1

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir) DIR="$2"; shift 2 ;;
        --reter) RETER="$2"; shift 2 ;;
        --sem-dump) COM_DUMP=0; shift ;;
        *) echo "backup.sh: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

mkdir -p "$DIR"

if [[ "$COM_DUMP" -eq 1 ]]; then
    SENHA=""
    if [[ -f /root/.alfamatriz-db-pass ]]; then
        SENHA="$(cat /root/.alfamatriz-db-pass)"
    fi

    ARQUIVO="$DIR/alfamatriz-$(date +%Y-%m-%d).sql.gz"

    echo "==> gerando $ARQUIVO"
    if [[ -n "$SENHA" ]]; then
        mariadb-dump --single-transaction --quick -u "$USUARIO" -p"$SENHA" "$BANCO" | gzip -9 > "$ARQUIVO"
    else
        mariadb-dump --single-transaction --quick "$BANCO" | gzip -9 > "$ARQUIVO"
    fi

    # Dump vazio é pior que dump nenhum: dá falsa sensação de proteção.
    if [[ ! -s "$ARQUIVO" ]]; then
        rm -f "$ARQUIVO"
        echo "backup.sh: o dump saiu vazio — cópia descartada." >&2
        exit 1
    fi
fi

# Retenção: mantém as N cópias mais recentes e apaga o resto.
# Sem `mapfile`: ele não existe no bash 3.x, e este script também roda no
# macOS durante os testes.
echo "==> aplicando retenção (mantendo $RETER cópias)"
COPIAS=()
while IFS= read -r COPIA; do
    [[ -n "$COPIA" ]] && COPIAS+=("$COPIA")
done < <(ls -1t "$DIR"/alfamatriz-*.sql.gz 2>/dev/null || true)

if [[ "${#COPIAS[@]}" -gt "$RETER" ]]; then
    for (( i=RETER; i<${#COPIAS[@]}; i++ )); do
        echo "    removendo ${COPIAS[$i]}"
        rm -f "${COPIAS[$i]}"
    done
fi

echo "backup.sh: concluído — $(ls -1 "$DIR"/alfamatriz-*.sql.gz 2>/dev/null | wc -l | tr -d ' ') cópia(s) em $DIR"
