#!/usr/bin/env bash
#
# Publica um changelog no grupo "Alfa Solucoes Alertas" do Telegram.
#
#   deploy/publicar-changelog.sh --conferir mensagem.txt   # não envia: mostra
#   deploy/publicar-changelog.sh mensagem.txt              # envia
#
# O ARQUIVO é a mensagem já pronta em HTML do Telegram (<b>, <i>, <code>, <a>).
# Partes se separam por uma linha contendo apenas `---`: o Telegram trunca acima
# de 4096 caracteres, e cada parte tem de ser autocontida — com cabeçalho
# próprio, para quem lê a segunda sem ter visto a primeira.
#
# POR QUE ESTE ARQUIVO EXISTE
# ---------------------------
# O procedimento vivia só em prosa no CLAUDE.md, e a cada publicação alguém
# remontava o mesmo `curl` num arquivo temporário. Remontar significa reescolher
# o chat, reescrever a checagem de erro e redescobrir o limite de caracteres —
# três coisas que só se erra uma vez em produção. Aqui elas estão escritas.
#
# SÓ TELEGRAM. O CLAUDE.md do AlfaControl manda enviar ao Discord em seguida;
# no AlfaMatriz, não — decisão do dono do produto em 12/08/2026.
#
# O TOKEN NÃO MORA AQUI, e é de propósito: este arquivo é versionado. Ele sai do
# CLAUDE.md do AlfaControl na hora de rodar, que é o único lugar onde ele vive.
set -euo pipefail

FONTE_DO_TOKEN="${ALFA_TELEGRAM_FONTE:-$HOME/dev/AlfaControl/CLAUDE.md}"
CHAT_ID="${ALFA_TELEGRAM_CHAT_ID:--5176787387}"
LIMITE=4096

conferir_apenas=false
arquivo=""

for argumento in "$@"; do
    case "$argumento" in
        --conferir|--dry-run) conferir_apenas=true ;;
        -*) echo "Opção desconhecida: $argumento" >&2; exit 2 ;;
        *) arquivo="$argumento" ;;
    esac
done

if [[ -z "$arquivo" ]]; then
    echo "Uso: $0 [--conferir] <arquivo-com-a-mensagem>" >&2
    exit 2
fi

if [[ ! -f "$arquivo" ]]; then
    echo "Não achei o arquivo da mensagem: $arquivo" >&2
    exit 1
fi

# Divide em partes pela linha `---`, preservando as linhas em branco de dentro.
partes=()
atual=""
while IFS= read -r linha || [[ -n "$linha" ]]; do
    if [[ "$linha" == "---" ]]; then
        partes+=("$atual")
        atual=""
    else
        atual+="$linha"$'\n'
    fi
done < "$arquivo"
partes+=("$atual")

# Tira a quebra final de cada parte e descarta as vazias (arquivo terminando
# em `---`, por exemplo).
limpas=()
for parte in "${partes[@]}"; do
    parte="${parte%$'\n'}"
    [[ -n "${parte// /}" ]] && limpas+=("$parte")
done
partes=("${limpas[@]}")

if [[ ${#partes[@]} -eq 0 ]]; then
    echo "O arquivo não tem mensagem nenhuma." >&2
    exit 1
fi

# Confere o tamanho ANTES de mandar qualquer coisa: descobrir que a parte 3 não
# cabe depois de as duas primeiras terem chegado ao grupo deixa meia publicação
# lá, e não há como recolher.
excedeu=false
for indice in "${!partes[@]}"; do
    caracteres=$(printf '%s' "${partes[$indice]}" | wc -m | tr -d ' ')
    numero=$((indice + 1))

    if [[ "$caracteres" -gt "$LIMITE" ]]; then
        echo "✗ parte $numero: $caracteres caracteres — acima do limite de $LIMITE." >&2
        excedeu=true
    else
        echo "  parte $numero: $caracteres caracteres (limite $LIMITE)"
    fi
done

if [[ "$excedeu" == true ]]; then
    echo "Nada foi enviado. Divida as partes com uma linha \`---\`." >&2
    exit 1
fi

if [[ "$conferir_apenas" == true ]]; then
    echo
    echo "== conferência, nada foi enviado =="
    for indice in "${!partes[@]}"; do
        echo
        echo "---------- parte $((indice + 1)) ----------"
        printf '%s\n' "${partes[$indice]}"
    done
    exit 0
fi

if [[ ! -f "$FONTE_DO_TOKEN" ]]; then
    echo "Não achei $FONTE_DO_TOKEN — é de lá que sai o token do bot." >&2
    exit 1
fi

TOKEN=$(grep -om1 'bot[0-9]\{6,\}:[A-Za-z0-9_-]\{30,\}' "$FONTE_DO_TOKEN" | sed 's/^bot//')

if [[ -z "${TOKEN:-}" ]]; then
    echo "Não consegui extrair o token de $FONTE_DO_TOKEN." >&2
    exit 1
fi

for indice in "${!partes[@]}"; do
    numero=$((indice + 1))
    echo "→ enviando parte $numero de ${#partes[@]}…"

    resposta=$(curl -s -X POST "https://api.telegram.org/bot${TOKEN}/sendMessage" \
        --data-urlencode "chat_id=${CHAT_ID}" \
        --data-urlencode "parse_mode=HTML" \
        --data-urlencode "disable_web_page_preview=true" \
        --data-urlencode "text=${partes[$indice]}")

    if ! printf '%s' "$resposta" | grep -q '"ok":true'; then
        echo "  ✗ parte $numero FALHOU — as anteriores JÁ FORAM para o grupo:" >&2
        printf '%s\n' "$resposta" >&2
        exit 1
    fi

    echo "  ✓ parte $numero publicada"

    # Espaço entre as mensagens para elas chegarem na ordem em que foram
    # escritas: sem isso o grupo às vezes recebe a 2 antes da 1.
    [[ "$numero" -lt "${#partes[@]}" ]] && sleep 2
done

echo
echo "Changelog publicado no grupo Alfa Solucoes Alertas."
