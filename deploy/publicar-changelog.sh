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
#
# REGISTRO NO ALFAMATRIZ (#225)
# -----------------------------
# Depois de o Telegram aceitar todas as partes, o mesmo texto é registrado na
# aba Atualizações da tela de Manutenção, com a versão e as tarefas (`T-N`)
# que entraram nela. O registro é do changelog, não do envio: se ele falhar, o
# grupo já recebeu a mensagem, e o script avisa como registrar de novo.
#
#   deploy/publicar-changelog.sh --versao=v2026.10.03.1 mensagem.txt
#   deploy/publicar-changelog.sh --so-registrar --versao=v2026.10.03.1 mensagem.txt
#   deploy/publicar-changelog.sh --importar deploy/changelog/*.txt
#   deploy/publicar-changelog.sh --sem-registro mensagem.txt
#
# Com `--versao`, as tarefas são os `T-N` das mensagens de commit entre a tag
# anterior e essa. Registrar duas vezes o mesmo texto não duplica: só acrescenta
# a versão e as tarefas que faltavam (o changelog costuma sair antes da tag).
#
# O token é PESSOAL e só registra changelog (`php artisan alfa:changelog-token
# <email>` no servidor). Ele mora no chaveiro do macOS, como o do Telegram:
#
#   ALFAMATRIZ_CHANGELOG_TOKEN=<token> deploy/publicar-changelog.sh --guardar-registro
#
# O endereço é o de produção pela tailnet; `ALFAMATRIZ_URL` troca (staging).
set -euo pipefail

CHAT_ID="${ALFA_TELEGRAM_CHAT_ID:--5176787387}"
LIMITE=4096

SERVICO_CHAVEIRO="alfa-telegram-bot"
CONTA_CHAVEIRO="changelog"
ARQUIVO_DE_CONFIG="${XDG_CONFIG_HOME:-$HOME/.config}/alfa/telegram.env"
FONTE_LEGADA="${ALFA_TELEGRAM_FONTE:-$HOME/dev/AlfaControl/CLAUDE.md}"

SERVICO_REGISTRO="alfamatriz-changelog"
CONTA_REGISTRO="producao"
URL_ALFAMATRIZ="${ALFAMATRIZ_URL:-https://alfamatriz.tail0939dd.ts.net}"
RAIZ_DO_REPO="$(cd "$(dirname "$0")/.." && pwd)"

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

# O token do registro no AlfaMatriz: variável de ambiente ou chaveiro. Sem
# arquivo de configuração de propósito — é um segredo novo, e nasce no lugar
# certo.
token_do_registro() {
    if [[ -n "${ALFAMATRIZ_CHANGELOG_TOKEN:-}" ]]; then
        printf '%s' "$ALFAMATRIZ_CHANGELOG_TOKEN"
        return 0
    fi

    if command -v security >/dev/null 2>&1; then
        security find-generic-password -w -s "$SERVICO_REGISTRO" -a "$CONTA_REGISTRO" 2>/dev/null && return 0
    fi

    return 1
}

# As tarefas da versão: os `T-N` das mensagens de commit entre a tag anterior
# e a da versão. Sem a tag no clone local, nenhuma — e o aviso diz por quê,
# porque "registrou sem tarefas" calado parece que a versão não tinha nenhuma.
tarefas_da_versao() {
    local versao="$1" anterior

    if ! git -C "$RAIZ_DO_REPO" rev-parse -q --verify "refs/tags/$versao" >/dev/null; then
        git -C "$RAIZ_DO_REPO" fetch -q --tags origin 2>/dev/null || true
    fi

    if ! git -C "$RAIZ_DO_REPO" rev-parse -q --verify "refs/tags/$versao" >/dev/null; then
        echo "  aviso: a tag $versao não existe aqui; registrando sem as tarefas dos commits." >&2
        return 0
    fi

    anterior=$(git -C "$RAIZ_DO_REPO" describe --tags --abbrev=0 --match 'v*' "$versao^" 2>/dev/null || true)
    local faixa="$versao"
    [[ -n "$anterior" ]] && faixa="$anterior..$versao"

    # `|| true`: versão sem nenhum `T-N` é resposta válida (vazia), e o grep
    # sem achar nada derrubaria o script pelo `pipefail`.
    { git -C "$RAIZ_DO_REPO" log --format=%B "$faixa" \
        | grep -oE '(^|[^A-Za-z0-9])[Tt]-[0-9]+' | grep -oE '[0-9]+' | sort -un | tr '\n' ' '; } || true
}

# Registra UM arquivo no AlfaMatriz. Devolve 0 se registrou (ou já estava).
registrar() {
    local arquivo="$1" versao="$2" origem="$3" token tarefas resposta codigo corpo

    if ! token=$(token_do_registro); then
        echo "  ✗ sem o token do AlfaMatriz (ALFAMATRIZ_CHANGELOG_TOKEN ou chaveiro $SERVICO_REGISTRO)." >&2
        echo "    No servidor: php artisan alfa:changelog-token <seu-email>; depois $0 --guardar-registro" >&2
        return 1
    fi

    tarefas=""
    [[ -n "$versao" ]] && tarefas=$(tarefas_da_versao "$versao")

    # 429 é o freio da rota (120 por minuto): uma importação grande passa
    # dele. Esperar e tentar de novo é o que a pessoa faria à mão — três vezes,
    # e depois desiste com a frase.
    local tentativa
    for tentativa in 1 2 3 4; do
        resposta=$(curl -s -m 30 -w $'\n%{http_code}' -X POST "$URL_ALFAMATRIZ/api/atualizacoes" \
            -H "Authorization: Bearer $token" \
            -H "Accept: application/json" \
            --data-urlencode "texto@$arquivo" \
            --data-urlencode "versao=$versao" \
            --data-urlencode "tarefas=$tarefas" \
            --data-urlencode "origem=$origem" \
            --data-urlencode "arquivo=$(basename "$arquivo")" || true)

        codigo="${resposta##*$'\n'}"
        corpo="${resposta%$'\n'*}"

        [[ "$codigo" == "429" && "$tentativa" -lt 4 ]] || break
        echo "  … limite de registros por minuto; esperando 30 s para continuar" >&2
        sleep 30
    done

    if [[ "$codigo" == "201" || "$codigo" == "200" ]]; then
        echo "  ✓ $(basename "$arquivo"): $(printf '%s' "$corpo" | sed -n 's/.*"message":"\([^"]*\)".*/\1/p') no AlfaMatriz${versao:+ ($versao)}${tarefas:+ · tarefas: $tarefas}"
        return 0
    fi

    # Só a frase: com o modo de depuração ligado (local, staging) o corpo
    # traz o rastro inteiro da exceção, e cem linhas de pilha escondem o motivo.
    local motivo
    motivo=$(printf '%s' "$corpo" | sed -n 's/.*"message": *"\([^"]*\)".*/\1/p' | head -1)
    echo "  ✗ $(basename "$arquivo"): o AlfaMatriz respondeu ${codigo:-sem resposta}: ${motivo:-$(printf '%s' "$corpo" | head -c 300)}" >&2

    return 1
}

conferir_apenas=false
so_a_fonte=false
guardar=false
guardar_registro=false
so_registrar=false
importar=false
sem_registro=false
versao=""
arquivo=""
arquivos=()

for argumento in "$@"; do
    case "$argumento" in
        --conferir|--dry-run) conferir_apenas=true ;;
        --fonte) so_a_fonte=true ;;
        --guardar) guardar=true ;;
        --guardar-registro) guardar_registro=true ;;
        --so-registrar) so_registrar=true ;;
        --importar) importar=true ;;
        --sem-registro) sem_registro=true ;;
        --versao=*) versao="${argumento#--versao=}" ;;
        -*) echo "Opção desconhecida: $argumento" >&2; exit 2 ;;
        *) arquivo="$argumento"; arquivos+=("$argumento") ;;
    esac
done

if [[ "$guardar_registro" == true ]]; then
    if [[ -z "${ALFAMATRIZ_CHANGELOG_TOKEN:-}" ]]; then
        echo "Passe o token na variável: ALFAMATRIZ_CHANGELOG_TOKEN=<token> $0 --guardar-registro" >&2
        exit 2
    fi

    # Pela entrada padrão, como o do Telegram: argumento apareceria num `ps`.
    printf '%s\n%s\n' "$ALFAMATRIZ_CHANGELOG_TOKEN" "$ALFAMATRIZ_CHANGELOG_TOKEN" \
        | security add-generic-password -U -s "$SERVICO_REGISTRO" -a "$CONTA_REGISTRO" -w >/dev/null
    echo "Token do AlfaMatriz guardado no chaveiro ($SERVICO_REGISTRO / $CONTA_REGISTRO)."
    exit 0
fi

# Só registrar: o reenvio de um registro que falhou (`--so-registrar`) ou a
# importação dos changelogs de antes da tela (`--importar`, vários arquivos).
# Nenhum dos dois manda nada ao Telegram.
if [[ "$so_registrar" == true || "$importar" == true ]]; then
    if [[ ${#arquivos[@]} -eq 0 ]]; then
        echo "Diga o(s) arquivo(s) a registrar." >&2
        exit 2
    fi

    falhas=0
    for um in "${arquivos[@]}"; do
        registrar "$um" "$versao" "$([[ "$importar" == true ]] && echo importado || echo script)" || falhas=$((falhas + 1))
    done

    [[ "$falhas" -eq 0 ]] || { echo "$falhas arquivo(s) não registrado(s)." >&2; exit 1; }
    exit 0
fi

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
    echo "Uso: $0 [--conferir] [--versao=vX] [--sem-registro] <arquivo-com-a-mensagem>" >&2
    echo "     $0 --so-registrar [--versao=vX] <arquivo>   # só registra no AlfaMatriz" >&2
    echo "     $0 --importar deploy/changelog/*.txt        # registra os antigos, sem enviar" >&2
    echo "     $0 --fonte      # diz de onde sairia o token" >&2
    echo "     $0 --guardar    # move o token para o chaveiro do macOS" >&2
    echo "     $0 --guardar-registro   # guarda o token do AlfaMatriz no chaveiro" >&2
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

if [[ "$sem_registro" == true ]]; then
    exit 0
fi

echo "→ registrando no AlfaMatriz…"
if ! registrar "$arquivo" "$versao" script; then
    # O Telegram já recebeu: falhar aqui com erro faria parecer que a
    # publicação não saiu, e alguém a mandaria de novo ao grupo.
    echo "  O grupo JÁ recebeu o changelog. Para registrar depois, sem reenviar:" >&2
    echo "    $0 --so-registrar${versao:+ --versao=$versao} $arquivo" >&2
fi
