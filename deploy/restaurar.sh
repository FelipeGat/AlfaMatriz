#!/usr/bin/env bash
#
# Restaura o AlfaMatriz — banco, anexos, ou os dois — a partir das cópias
# geradas por backup.sh.
#
# Uso:
#   deploy/restaurar.sh --arquivo /var/backups/alfamatriz/alfamatriz-AAAA-MM-DD.sql.gz --confirmo
#   deploy/restaurar.sh --anexos  /var/backups/alfamatriz/alfamatriz-anexos-AAAA-MM-DD.tar.gz --confirmo
#
# --arquivo CAMINHO  a cópia do banco (.sql.gz)
# --anexos CAMINHO   a cópia dos anexos (.tar.gz)
# --destino CAMINHO  onde os anexos voltam
#                    (padrão: /var/www/alfamatriz/compartilhado/anexos)
# --confirmo         obrigatório em qualquer restauração
#
# Restaurar SOBRESCREVE produção — faturamento e financeiro reais. Por isso o
# script exige as duas coisas: um arquivo que existe e o --confirmo explícito.
# Faltando qualquer uma, ele recusa sem tocar em nada.
#
# As duas metades são independentes de propósito: o caso comum não é perder o
# servidor inteiro, é perder um lado só — um anexo apagado por engano volta sem
# que o banco de hoje seja jogado fora junto.

set -euo pipefail

ARQUIVO=""
ANEXOS=""
DESTINO="/var/www/alfamatriz/compartilhado/anexos"
CONFIRMADO=0
BANCO="${DB_DATABASE:-alfamatriz}"
USUARIO="${DB_USERNAME:-alfamatriz}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --arquivo) ARQUIVO="${2:-}"; shift 2 ;;
        --anexos) ANEXOS="${2:-}"; shift 2 ;;
        --destino) DESTINO="${2:-}"; shift 2 ;;
        --confirmo) CONFIRMADO=1; shift ;;
        *) echo "restaurar.sh: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

recusar() {
    echo "restaurar.sh: $* — nada foi alterado." >&2
    exit 1
}

# Conferência que vale para as duas cópias: existir e ter conteúdo. Um arquivo
# vazio passaria pela restauração sem erro nenhum e deixaria o destino zerado,
# que é o pior desfecho possível aqui.
conferir_copia() {
    local CAMINHO="$1"
    local QUAL="$2"

    if [[ ! -f "$CAMINHO" ]]; then
        recusar "o arquivo $QUAL \"$CAMINHO\" não existe"
    fi

    if [[ ! -s "$CAMINHO" ]]; then
        recusar "o arquivo $QUAL \"$CAMINHO\" está vazio"
    fi
}

if [[ -z "$ARQUIVO" && -z "$ANEXOS" ]]; then
    recusar "informe o que restaurar com --arquivo (banco) e/ou --anexos"
fi

# `if` em vez de `[[ ... ]] && ...`: sob `set -e`, uma lista cujo teste falha
# devolve 1 e derruba o script — aqui a condição ser falsa é o caso normal de
# quem restaura só uma das metades.
if [[ -n "$ARQUIVO" ]]; then
    conferir_copia "$ARQUIVO" "do banco"
fi

if [[ -n "$ANEXOS" ]]; then
    conferir_copia "$ANEXOS" "dos anexos"
fi

if [[ "$CONFIRMADO" -ne 1 ]]; then
    recusar "restaurar sobrescreve produção; repita com --confirmo se é isso mesmo"
fi

# Os anexos voltam ANTES do banco, invertendo a ordem do backup e pelo mesmo
# motivo: enquanto a restauração corre, é melhor ter arquivo a mais do que
# linha apontando para arquivo que ainda não chegou.
if [[ -n "$ANEXOS" ]]; then
    echo "==> restaurando anexos de $ANEXOS em $DESTINO"
    mkdir -p "$DESTINO"

    # Extrai POR CIMA, sem limpar o destino antes. Quem restaura um anexo
    # perdido não quer perder junto os que foram enviados depois da cópia —
    # e apagar aqui não teria volta.
    tar -xzf "$ANEXOS" -C "$DESTINO"

    # O tar preserva dono e permissão de quem foi copiado, mas só quando
    # extraído como root. Rodando com outro usuário os arquivos saem no dono
    # errado e o PHP-FPM deixa de conseguir lê-los — daí o aviso.
    if [[ "$(id -u)" -ne 0 ]]; then
        echo "    AVISO: fora do root o dono dos arquivos não é preservado — confira se o www-data ainda lê $DESTINO."
    fi
fi

if [[ -n "$ARQUIVO" ]]; then
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
fi

echo "restaurar.sh: restauração concluída"
