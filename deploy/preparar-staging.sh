#!/usr/bin/env bash
#
# Prepara a base do STAGING a partir de uma cópia da produção.
#
# Uso (no container de staging):
#   deploy/preparar-staging.sh --dump /caminho/alfamatriz-AAAA-MM-DD.sql.gz
#
# Restaura o dump numa base de staging e roda o embaralhamento, que troca
# nome, documento e contato de cliente por dados falsos. Valores de faturamento
# e financeiro ficam intactos: é o volume real que faz o staging valer.
#
# A ordem é a segurança: primeiro restaura, DEPOIS embaralha, e só então o
# staging é utilizável. Se o embaralhamento falhar, o script derruba a base em
# vez de deixar dado real acessível num ambiente de teste.

set -euo pipefail

DUMP=""
DIR="${DIR:-/var/www/alfamatriz}"
BANCO="${BANCO:-alfamatriz}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dump) DUMP="$2"; shift 2 ;;
        --dir) DIR="$2"; shift 2 ;;
        *) echo "preparar-staging.sh: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

falhar(){ echo "preparar-staging.sh: $*" >&2; exit 1; }

[[ -n "$DUMP" ]] || falhar "informe a cópia da produção com --dump"
[[ -f "$DUMP" ]] || falhar "o arquivo \"$DUMP\" não existe"

# Com azul/verde, a aplicação que roda é a da versão publicada — a raiz é só o
# clone de controle e não tem vendor, então `php artisan` dali não funciona. O
# `atual` é o endereço estável da versão no ar; sem ele (instalação ainda não
# convertida, ou diretório de teste), vale a própria raiz.
[[ -d "$DIR/atual" ]] && DIR="$DIR/atual"

cd "$DIR" || falhar "diretório $DIR não existe"

# Trava dupla: este script existe para o staging. Rodá-lo com o .env de
# produção apontado sobrescreveria a base real com um dump antigo.
AMBIENTE=$(grep -E '^APP_ENV=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' || echo "")
if [[ "$AMBIENTE" == "production" ]]; then
    falhar "o .env deste diretório é de production — este script é do staging"
fi

echo "==> restaurando a cópia em $BANCO"
gunzip -c "$DUMP" | mariadb "$BANCO" || falhar "restauração do dump falhou"

echo "==> embaralhando os dados pessoais"
if ! php artisan alfa:embaralhar-dados; then
    echo "preparar-staging.sh: o embaralhamento FALHOU — derrubando a base para não" >&2
    echo "                     deixar dado real de cliente num ambiente de teste." >&2
    mariadb -e "DROP DATABASE IF EXISTS \`$BANCO\`; CREATE DATABASE \`$BANCO\`;" || true
    exit 1
fi

echo "preparar-staging.sh: staging pronto (dados pessoais falsos, valores reais)"
