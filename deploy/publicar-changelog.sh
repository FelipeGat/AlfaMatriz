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
# O TOKEN NÃO MORA AQUI, e é de propósito: este arquivo é versionado. Ele é
# procurado em quatro lugares, na ordem, e o primeiro que responder ganha:
#
#   1. $ALFA_TELEGRAM_TOKEN          — para esteira, servidor e uso de uma vez
#   2. chaveiro do macOS             — o lugar recomendado nesta máquina
#   3. ~/.config/alfa/telegram.env   — o equivalente onde não há chaveiro
#   4. CLAUDE.md do AlfaControl      — LEGADO, e só para não quebrar hoje
#
# A quarta existia sozinha e era um ponto único de falha silencioso: aquele
# arquivo é ignorado pelo git e vive só no disco de uma máquina. Em 17/07/2026
# um `git rm` o levou junto, e a publicação parou de funcionar sem que nada
# avisasse — só se descobriu no dia em que alguém tentou publicar (21/08/2026).
# Ela continua na lista para quem ainda a tem, mas avisa que está de saída.
#
#   deploy/publicar-changelog.sh --fonte     # diz de onde sairia o token
#   deploy/publicar-changelog.sh --guardar   # move o token para o chaveiro
#
# O `--guardar` nunca imprime o token nem o passa por argumento de comando — o
# `security` o recebe pela entrada padrão, para ele não aparecer num `ps`.
set -euo pipefail

CHAT_ID="${ALFA_TELEGRAM_CHAT_ID:--5176787387}"
LIMITE=4096

SERVICO_CHAVEIRO="alfa-telegram-bot"
CONTA_CHAVEIRO="changelog"
ARQUIVO_DE_CONFIG="${XDG_CONFIG_HOME:-$HOME/.config}/alfa/telegram.env"
FONTE_LEGADA="${ALFA_TELEGRAM_FONTE:-$HOME/dev/AlfaControl/CLAUDE.md}"

TOKEN=""
FONTE_USADA=""

# O token do bot vem inteiro do primeiro lugar que responder. `FONTE_USADA` é
# dito em voz alta na publicação: saber de onde ele saiu é o que permite
# perceber que a máquina ainda depende do legado.
resolver_token() {
    TOKEN=""
    FONTE_USADA=""

    if [[ -n "${ALFA_TELEGRAM_TOKEN:-}" ]]; then
        TOKEN="$ALFA_TELEGRAM_TOKEN"
        FONTE_USADA="variável de ambiente ALFA_TELEGRAM_TOKEN"
        return 0
    fi

    if command -v security >/dev/null 2>&1; then
        local do_chaveiro
        do_chaveiro=$(security find-generic-password -w -s "$SERVICO_CHAVEIRO" -a "$CONTA_CHAVEIRO" 2>/dev/null || true)

        if [[ -n "$do_chaveiro" ]]; then
            TOKEN="$do_chaveiro"
            FONTE_USADA="chaveiro do macOS ($SERVICO_CHAVEIRO)"
            return 0
        fi
    fi

    if [[ -f "$ARQUIVO_DE_CONFIG" ]]; then
        local do_arquivo
        do_arquivo=$(sed -n 's/^ALFA_TELEGRAM_TOKEN=//p' "$ARQUIVO_DE_CONFIG" | head -1 | tr -d '"'"'"' ')

        if [[ -n "$do_arquivo" ]]; then
            TOKEN="$do_arquivo"
            FONTE_USADA="$ARQUIVO_DE_CONFIG"

            # Segredo em arquivo legível por outros é segredo pela metade.
            local modo
            modo=$(stat -f '%Lp' "$ARQUIVO_DE_CONFIG" 2>/dev/null || stat -c '%a' "$ARQUIVO_DE_CONFIG" 2>/dev/null || echo '')
            if [[ -n "$modo" && "$modo" != "600" ]]; then
                echo "  aviso: $ARQUIVO_DE_CONFIG está com permissão $modo — o certo é 600 (chmod 600)." >&2
            fi

            return 0
        fi
    fi

    if [[ -f "$FONTE_LEGADA" ]]; then
        local do_legado
        do_legado=$(grep -om1 'bot[0-9]\{6,\}:[A-Za-z0-9_-]\{30,\}' "$FONTE_LEGADA" | sed 's/^bot//' || true)

        if [[ -n "$do_legado" ]]; then
            TOKEN="$do_legado"
            FONTE_USADA="LEGADO — $FONTE_LEGADA"
            return 0
        fi
    fi

    return 1
}

explicar_ausencia() {
    cat >&2 <<AJUDA
Não achei o token do bot em nenhum dos lugares conhecidos:

  1. variável ALFA_TELEGRAM_TOKEN     (não definida)
  2. chaveiro do macOS                ($SERVICO_CHAVEIRO / $CONTA_CHAVEIRO)
  3. $ARQUIVO_DE_CONFIG
  4. $FONTE_LEGADA  (legado)

Para resolver de uma vez, com o token em mãos:

  ALFA_TELEGRAM_TOKEN=<o-token> $0 --guardar

Isso o grava no chaveiro do macOS, e daí em diante nenhum arquivo de repositório
precisa existir para publicar.
AJUDA
}

conferir_apenas=false
so_a_fonte=false
guardar=false
arquivo=""

for argumento in "$@"; do
    case "$argumento" in
        --conferir|--dry-run) conferir_apenas=true ;;
        --fonte) so_a_fonte=true ;;
        --guardar) guardar=true ;;
        -*) echo "Opção desconhecida: $argumento" >&2; exit 2 ;;
        *) arquivo="$argumento" ;;
    esac
done

# As duas perguntas sobre o TOKEN vêm antes de qualquer coisa sobre a mensagem:
# elas não têm mensagem para publicar, e exigir um arquivo aqui obrigaria a
# inventar um só para descobrir onde está a credencial.
if [[ "$so_a_fonte" == true ]]; then
    if ! resolver_token; then
        explicar_ausencia
        exit 1
    fi

    echo "O token sairia de: $FONTE_USADA"

    # `getMe` e não um envio: ele responde quem é o bot sem publicar nada. É a
    # diferença entre "achei uma string" e "a credencial funciona" — e é essa
    # segunda que se quer saber ANTES de precisar publicar às pressas.
    quem=$(curl -s -m 10 "https://api.telegram.org/bot${TOKEN}/getMe" || true)

    if printf '%s' "$quem" | grep -q '"ok":true'; then
        apelido=$(printf '%s' "$quem" | sed -n 's/.*"username":"\([^"]*\)".*/\1/p')
        echo "O bot responde: @${apelido:-desconhecido}"
    else
        echo "Mas o Telegram RECUSOU essa credencial — ela não publica nada." >&2
        printf '%s\n' "$quem" >&2
        exit 1
    fi

    if [[ "$FONTE_USADA" == LEGADO* ]]; then
        echo
        echo "Essa fonte é um arquivo ignorado pelo git, que já sumiu uma vez."
        echo "Rode \`$0 --guardar\` para movê-lo para o chaveiro."
    fi

    exit 0
fi

if [[ "$guardar" == true ]]; then
    if ! resolver_token; then
        explicar_ausencia
        exit 1
    fi

    echo "Token encontrado em: $FONTE_USADA"

    if command -v security >/dev/null 2>&1; then
        # Pela ENTRADA PADRÃO, duas vezes: o `security` pede confirmação, e
        # passar o valor como argumento o deixaria visível num `ps` para
        # qualquer processo da máquina.
        if printf '%s\n%s\n' "$TOKEN" "$TOKEN" \
            | security add-generic-password -U -s "$SERVICO_CHAVEIRO" -a "$CONTA_CHAVEIRO" -w >/dev/null 2>&1; then
            echo "Guardado no chaveiro do macOS ($SERVICO_CHAVEIRO / $CONTA_CHAVEIRO)."
            echo "A partir de agora a publicação não depende de arquivo nenhum do repositório."
            exit 0
        fi

        echo "O chaveiro recusou a gravação; caindo para o arquivo de configuração." >&2
    fi

    mkdir -p "$(dirname "$ARQUIVO_DE_CONFIG")"
    umask 077
    printf 'ALFA_TELEGRAM_TOKEN=%s\n' "$TOKEN" > "$ARQUIVO_DE_CONFIG"
    chmod 600 "$ARQUIVO_DE_CONFIG"
    echo "Guardado em $ARQUIVO_DE_CONFIG (permissão 600)."
    exit 0
fi

if [[ -z "$arquivo" ]]; then
    echo "Uso: $0 [--conferir] <arquivo-com-a-mensagem>" >&2
    echo "     $0 --fonte      # diz de onde sairia o token" >&2
    echo "     $0 --guardar    # move o token para o chaveiro do macOS" >&2
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

if ! resolver_token; then
    explicar_ausencia
    exit 1
fi

echo "  token: $FONTE_USADA"

if [[ "$FONTE_USADA" == LEGADO* ]]; then
    echo "  aviso: essa fonte é um arquivo ignorado pelo git, que já sumiu uma vez." >&2
    echo "         Rode \`$0 --guardar\` para mover o token para o chaveiro." >&2
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
