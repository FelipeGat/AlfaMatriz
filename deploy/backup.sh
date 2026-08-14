#!/usr/bin/env bash
#
# Cópia diária do AlfaMatriz — banco e anexos —, rodada pelo cron do container.
#
# Uso:
#   deploy/backup.sh [--dir CAMINHO] [--anexos CAMINHO] [--reter N] [--sem-dump]
#
# --dir CAMINHO     onde guardar as cópias (padrão: /var/backups/alfamatriz)
# --anexos CAMINHO  a pasta de anexos a copiar
#                   (padrão: /var/www/alfamatriz/compartilhado/anexos)
# --reter N         quantas cópias manter, de cada tipo (padrão: 7)
# --sem-dump        só aplica a retenção, sem falar com o banco. Existe para o
#                   teste conferir a retenção de verdade, num diretório
#                   temporário, sem precisar de um MariaDB.
#
# O painel guarda faturamento e financeiro reais: sem isto, uma falha de
# disco leva o negócio junto.
#
# Os anexos vêm junto porque o banco guarda só o CAMINHO do arquivo, nunca o
# conteúdo: restaurar apenas o dump devolve a linha da cobrança e não o PDF, e
# a tela passa a responder "arquivo não encontrado" para um anexo que o sistema
# jura existir. O vzdump do Proxmox cobre o container inteiro todo dia, mas
# recuperar um anexo por ele significa restaurar a máquina toda — caro demais
# para o caso comum, que é um arquivo apagado por engano.

set -euo pipefail

DIR="/var/backups/alfamatriz"
ANEXOS="/var/www/alfamatriz/compartilhado/anexos"
RETER=7
BANCO="${DB_DATABASE:-alfamatriz}"
USUARIO="${DB_USERNAME:-alfamatriz}"
COM_DUMP=1

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir) DIR="$2"; shift 2 ;;
        --anexos) ANEXOS="$2"; shift 2 ;;
        --reter) RETER="$2"; shift 2 ;;
        --sem-dump) COM_DUMP=0; shift ;;
        *) echo "backup.sh: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

mkdir -p "$DIR"

HOJE="$(date +%Y-%m-%d)"

if [[ "$COM_DUMP" -eq 1 ]]; then
    SENHA=""
    if [[ -f /root/.alfamatriz-db-pass ]]; then
        SENHA="$(cat /root/.alfamatriz-db-pass)"
    fi

    ARQUIVO="$DIR/alfamatriz-$HOJE.sql.gz"

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

# Os anexos saem DEPOIS do banco, de propósito. Entre uma cópia e outra alguém
# pode anexar um arquivo, e uma das pontas vai ficar mais nova que a outra:
# nesta ordem sobra arquivo sem linha no banco, que não incomoda ninguém; na
# ordem inversa faltaria arquivo para uma linha que existe — que é justamente o
# "arquivo não encontrado" que esta cópia veio evitar.
#
# Pasta ausente avisa mas não derruba a rodada: o dump do banco já está pronto
# neste ponto, e perdê-lo por causa dos anexos seria trocar a proteção maior
# pela menor. Em compensação o aviso vai para o log do cron, onde é visível.
if [[ -d "$ANEXOS" ]]; then
    COPIA_ANEXOS="$DIR/alfamatriz-anexos-$HOJE.tar.gz"

    echo "==> gerando $COPIA_ANEXOS"
    # `-C "$ANEXOS" .` guarda os caminhos relativos à própria pasta de anexos,
    # para a restauração poder apontar o destino sem depender de onde a cópia
    # foi feita. O `.` também leva os arquivos ocultos, como o .gitignore que
    # mora lá.
    tar -czf "$COPIA_ANEXOS" -C "$ANEXOS" .

    if [[ ! -s "$COPIA_ANEXOS" ]]; then
        rm -f "$COPIA_ANEXOS"
        echo "backup.sh: a cópia dos anexos saiu vazia — descartada." >&2
        exit 1
    fi
else
    echo "    AVISO: $ANEXOS não existe — os anexos NÃO foram copiados."
fi

# Retenção: mantém as N cópias mais recentes de cada tipo e apaga o resto.
# Sem `mapfile`: ele não existe no bash 3.x, e este script também roda no
# macOS durante os testes.
#
# Os dois tipos são podados pela mesma régua e no mesmo dia, para que cada
# dump encontre os anexos da mesma data ao ser restaurado. Contar os dois
# juntos deixaria a série mais curta sumindo antes da outra.
aplicar_retencao() {
    local PADRAO="$1"
    local COPIAS=()
    local COPIA

    while IFS= read -r COPIA; do
        [[ -n "$COPIA" ]] && COPIAS+=("$COPIA")
    done < <(ls -1t $PADRAO 2>/dev/null || true)

    if [[ "${#COPIAS[@]}" -gt "$RETER" ]]; then
        for (( i=RETER; i<${#COPIAS[@]}; i++ )); do
            echo "    removendo ${COPIAS[$i]}"
            rm -f "${COPIAS[$i]}"
        done
    fi
}

echo "==> aplicando retenção (mantendo $RETER cópias de cada tipo)"
aplicar_retencao "$DIR/alfamatriz-*.sql.gz"
aplicar_retencao "$DIR/alfamatriz-anexos-*.tar.gz"

contar() {
    ls -1 $1 2>/dev/null | wc -l | tr -d ' '
}

echo "backup.sh: concluído — $(contar "$DIR/alfamatriz-*.sql.gz") cópia(s) do banco e $(contar "$DIR/alfamatriz-anexos-*.tar.gz") dos anexos em $DIR"
