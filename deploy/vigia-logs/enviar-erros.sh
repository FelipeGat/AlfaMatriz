#!/usr/bin/env bash
#
# Vigia de logs (#219): manda ao AlfaMatriz os erros NOVOS do log deste
# servidor. Roda no cron, de hora em hora — ver README.md ao lado.
#
#   deploy/vigia-logs/enviar-erros.sh                    # lê o que é novo e envia
#   deploy/vigia-logs/enviar-erros.sh --imprimir         # mostra o JSON, não envia, não anda o estado
#   deploy/vigia-logs/enviar-erros.sh --desde-o-inicio   # relê os arquivos inteiros (e 24h de docker)
#   deploy/vigia-logs/enviar-erros.sh --config /caminho/.env
#
# POR QUE EXISTE
# --------------
# O erro do Wellhub no AlfaGym (#191) ficou semanas só no log: 36 de 36
# check-ins falharam, o `catch` registrava um WARN e seguia, e ninguém viu.
# Este script é o olho: ele não decide nada — junta as entradas de erro,
# manda, e o AlfaMatriz agrupa, abre o Bug e avisa no Telegram.
#
# O QUE ELE LÊ
# ------------
# Duas fontes, configuráveis no .env deste diretório:
#   (a) VIGIA_ARQUIVOS: logs do Laravel (`storage/logs/laravel*.log`), cujas
#       entradas começam por `[data] ambiente.NIVEL: mensagem` e continuam
#       nas linhas seguintes com o stack;
#   (b) VIGIA_CONTAINERS: `docker logs` de containers (o Spring Boot do
#       AlfaGym), com o carimbo do próprio docker.
# Pega ERROR (e CRITICAL/ALERT/EMERGENCY/FATAL/SEVERE) sempre, e WARN/WARNING
# SÓ QUANDO TRAZ EXCEÇÃO — linha com `Exception`/`Error:` ou stack `at ...`/
# `#N ...`. O WARN do Wellhub era exatamente isso; WARN sem exceção é aviso
# de rotina, e mandá-lo encheria o quadro de falso alarme.
#
# O QUE É "NOVO"
# --------------
# Arquivo: a posição (byte) e o inode lidos da última vez, em VIGIA_ESTADO.
# Inode diferente ou arquivo menor = rotacionou, e lê do começo. Só consome
# até a última quebra de linha: a entrada sendo escrita agora fica para a
# próxima rodada. Docker: o instante em que a última rodada começou.
# Na PRIMEIRA rodada não manda o passado — marca o fim de cada fonte e só
# vigia daí em diante. Instalar o vigia num servidor com meses de log não
# pode abrir cem tarefas de uma vez; para isso existe o --desde-o-inicio.
#
# O estado só anda quando o AlfaMatriz aceitou o lote. Fora do ar, a rodada
# seguinte manda o mesmo trecho de novo.
#
# Portável: bash 3.2 (o do macOS, onde os testes rodam) e awk POSIX (mawk,
# gawk, o do BSD) — nada de array associativo do bash nem de `{n}` em regex.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="${VIGIA_CONFIG:-$DIR/.env}"
IMPRIMIR=0
DESDE_O_INICIO=0

while [ $# -gt 0 ]; do
    case "$1" in
        --imprimir) IMPRIMIR=1 ;;
        --desde-o-inicio) DESDE_O_INICIO=1 ;;
        --config) shift; CONFIG="${1:-}" ;;
        -h|--help) sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Opção desconhecida: $1" >&2; exit 2 ;;
    esac
    shift
done

# O .env é LIDO, e não executado com `source`: o token tem `|` (e o shell o
# tomaria por um cano), e um arquivo de configuração não deveria poder rodar
# comando. Só entram chaves VIGIA_*; aspas em volta do valor são tiradas.
if [ -f "$CONFIG" ]; then
    while IFS= read -r linha || [ -n "$linha" ]; do
        case "$linha" in
            VIGIA_*=*) ;;
            *) continue ;;
        esac
        chave="${linha%%=*}"
        valor="${linha#*=}"
        case "$chave" in *[!A-Z0-9_]*) continue ;; esac
        case "$valor" in
            \"*\") valor="${valor#\"}"; valor="${valor%\"}" ;;
            \'*\') valor="${valor#\'}"; valor="${valor%\'}" ;;
        esac
        export "$chave=$valor"
    done < "$CONFIG"
fi

VIGIA_URL="${VIGIA_URL:-}"
VIGIA_TOKEN="${VIGIA_TOKEN:-}"
VIGIA_AMBIENTE="${VIGIA_AMBIENTE:-producao}"
VIGIA_ARQUIVOS="${VIGIA_ARQUIVOS:-}"
VIGIA_CONTAINERS="${VIGIA_CONTAINERS:-}"
VIGIA_ESTADO="${VIGIA_ESTADO:-$DIR/.estado}"
VIGIA_LINHAS_TRECHO="${VIGIA_LINHAS_TRECHO:-15}"
VIGIA_LOTE="${VIGIA_LOTE:-200}"
VIGIA_ORIGEM="${VIGIA_ORIGEM:-$(hostname 2>/dev/null || echo servidor)}"

# O fuso das datas do log do Laravel, que não o escreve: o Laravel carimba no
# fuso da APLICAÇÃO (config/app.php), que pode não ser o do servidor. Sem
# VIGIA_FUSO, vale o do servidor.
if [ -z "${VIGIA_FUSO:-}" ]; then
    z="$(date +%z)"
    VIGIA_FUSO="${z:0:3}:${z:3:2}"
fi

dizer() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] vigia: $*" >&2; }

if [ "$IMPRIMIR" -eq 0 ] && { [ -z "$VIGIA_URL" ] || [ -z "$VIGIA_TOKEN" ]; }; then
    dizer "falta VIGIA_URL ou VIGIA_TOKEN em $CONFIG"
    exit 2
fi

if [ -z "$VIGIA_ARQUIVOS" ] && [ -z "$VIGIA_CONTAINERS" ]; then
    dizer "nenhuma fonte: defina VIGIA_ARQUIVOS e/ou VIGIA_CONTAINERS em $CONFIG"
    exit 2
fi

case "$VIGIA_AMBIENTE" in
    producao|staging) ;;
    *) dizer "VIGIA_AMBIENTE deve ser producao ou staging (veio '$VIGIA_AMBIENTE')"; exit 2 ;;
esac

mkdir -p "$VIGIA_ESTADO"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/vigia.XXXXXX")"
TRAVA="$VIGIA_ESTADO/.trava"

limpar() {
    rm -rf "$TMP"
    [ "${TRAVOU:-0}" -eq 1 ] && rmdir "$TRAVA" 2>/dev/null || true
}
trap limpar EXIT

# Uma rodada por vez: se a anterior travou num envio lento, a nova não lê o
# mesmo trecho em paralelo. Trava com mais de 2h é de uma rodada que morreu.
if [ "$IMPRIMIR" -eq 0 ]; then
    if [ -d "$TRAVA" ] && [ -n "$(find "$TRAVA" -maxdepth 0 -mmin +120 2>/dev/null)" ]; then
        rmdir "$TRAVA" 2>/dev/null || true
    fi
    if ! mkdir "$TRAVA" 2>/dev/null; then
        dizer "outra rodada em andamento; saindo"
        exit 0
    fi
    TRAVOU=1
fi

PRIMEIRA_RODADA=0
if [ ! -f "$VIGIA_ESTADO/.iniciado" ] && [ "$DESDE_O_INICIO" -eq 0 ]; then
    PRIMEIRA_RODADA=1
fi

# ---------------------------------------------------------------------------
# O extrator: lê linhas de log e escreve um erro por linha, em JSON.
#   modo=laravel|docker  fuso=-03:00  max_trecho=15
# ---------------------------------------------------------------------------
read -r -d '' EXTRATOR <<'AWK' || true
function json(s,    r, i, c) {
    r = ""
    for (i = 1; i <= length(s); i++) {
        c = substr(s, i, 1)
        if (c == "\\") r = r "\\\\"
        else if (c == "\"") r = r "\\\""
        else if (c == "\t") r = r "\\t"
        else if (c == "\n") r = r "\\n"
        else r = r c
    }
    return "\"" r "\""
}
function desescapar(s,    r, i, c) {
    # O contexto do Laravel é JSON: "App\\Models\\X" vira "App\Models\X", e \" vira ".
    r = ""
    while ((i = index(s, "\\")) > 0) {
        c = substr(s, i + 1, 1)
        if (c == "\\" || c == "\"" || c == "/") {
            r = r substr(s, 1, i - 1) c
            s = substr(s, i + 2)
        } else {
            r = r substr(s, 1, i)
            s = substr(s, i + 1)
        }
    }
    return r s
}
function aparar(s) {
    sub(/^[ \t]+/, "", s)
    sub(/[ \t]+$/, "", s)
    return s
}
function eh_inicio(l) {
    return (l ~ /^\[?[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9][ T][0-9][0-9]:[0-9][0-9]/)
}
function tem_fuso(s) {
    return (s ~ /Z$/ || s ~ /[+-][0-9][0-9]:?[0-9][0-9]$/)
}
function quando_da_linha(l,    s, t) {
    s = l
    sub(/^\[/, "", s)
    if (l ~ /^\[/) {
        s = substr(s, 1, index(s, "]") - 1)
    } else {
        # "2026-10-03 14:00:00.123  WARN ..." ou "2026-10-03T14:00:00.123-03:00 WARN ..."
        split(s, t, /[ \t]+/)
        s = t[1]
        if (s !~ /T/ && t[2] ~ /^[0-9][0-9]:/) s = s "T" t[2]
    }
    sub(/ /, "T", s)
    sub(/,/, ".", s)
    if (!tem_fuso(s)) s = s fuso
    return s
}
function fechar(    linha, i) {
    if (!aberto) return
    aberto = 0
    if (nivel == "") return
    if (nivel ~ /^WARN/ && !tem_excecao) return
    if (length(mensagem) > 2000) mensagem = substr(mensagem, 1, 1999) "…"
    linha = "{\"quando\":" json(quando) ",\"nivel\":" json(nivel) ",\"mensagem\":" json(mensagem)
    linha = linha ",\"excecao\":" (excecao == "" ? "null" : json(excecao))
    linha = linha ",\"trecho\":" (trecho == "" ? "null" : json(trecho)) "}"
    print linha
}
function abrir(l, carimbo,    p, resto, k, ctx) {
    aberto = 1
    nivel = ""; mensagem = ""; excecao = ""; trecho = ""; linhas_trecho = 0; tem_excecao = 0; laravel = 0
    quando = (carimbo != "") ? carimbo : quando_da_linha(l)

    if (match(l, /\.(ERROR|WARNING|CRITICAL|ALERT|EMERGENCY):/)) {
        # Laravel: [data] ambiente.NIVEL: mensagem {"exception":"[object] (Classe(code: 0): ... at /arq.php:12)
        nivel = substr(l, RSTART + 1, RLENGTH - 2)
        resto = substr(l, RSTART + RLENGTH)
        laravel = 1
    } else if (match(l, /[ \t\[](ERROR|WARN|WARNING|FATAL|SEVERE)[ \t\]]/)) {
        # Spring Boot / logback: data  NIVEL pid --- [app] [thread] classe : mensagem
        nivel = substr(l, RSTART + 1, RLENGTH - 2)
        resto = substr(l, RSTART + RLENGTH)
        p = index(resto, " : ")
        if (p > 0) resto = substr(resto, p + 3)
    } else {
        return
    }

    resto = aparar(resto)
    k = index(resto, "{\"exception\":\"[object] (")
    if (k > 0) {
        ctx = substr(resto, k + length("{\"exception\":\"[object] ("))
        resto = aparar(substr(resto, 1, k - 1))
        tem_excecao = 1
        p = index(ctx, "(code:")
        if (p > 0) excecao = desescapar(substr(ctx, 1, p - 1))
        ctx = desescapar(ctx)
        sub(/\)$/, "", ctx)
        juntar_trecho(ctx)
    }
    mensagem = resto
    if (mensagem == "") mensagem = (excecao != "" ? excecao : "(sem mensagem)")
    # Só a MENSAGEM, e não a linha inteira: no Spring a linha traz a classe que
    # gravou o log, e a `GlobalExceptionHandler` do AlfaControl (T-226) fazia
    # todo WARN dela — "Acesso negado", 404 — parecer exceção. E com os dois
    # pontos: "XException: motivo" é exceção citada; "XException [404]" do
    # handler é só o nome de quem já a tratou.
    if (resto ~ /(Exception|Error):/) tem_excecao = 1
}
# Spring com log em JSON, uma linha por evento (o AlfaGym, achado na
# instalação da T-220): {"timestamp":"…","level":"ERROR","logger":"…","message":"…"}.
# O stack vem nas linhas de texto seguintes — que o `continuar` já junta — ou,
# em alguns encoders, num campo "stack_trace" do próprio JSON.
function campo(l, nome,    k, s, r, i, c) {
    k = index(l, "\"" nome "\":\"")
    if (k == 0) return ""
    s = substr(l, k + length(nome) + 4)
    r = ""
    for (i = 1; i <= length(s); i++) {
        c = substr(s, i, 1)
        if (c == "\\") { r = r substr(s, i, 2); i++; continue }
        if (c == "\"") break
        r = r c
    }
    return desescapar(r)
}
function abrir_json(l, carimbo,    st, partes, n, i) {
    aberto = 1
    nivel = ""; mensagem = ""; excecao = ""; trecho = ""; linhas_trecho = 0; tem_excecao = 0; laravel = 0
    nivel = toupper(campo(l, "level"))
    if (nivel !~ /^(ERROR|WARN|WARNING|FATAL|SEVERE|CRITICAL)$/) { nivel = ""; return }
    quando = campo(l, "timestamp")
    if (quando == "") quando = carimbo
    if (quando != "" && !tem_fuso(quando)) quando = quando fuso
    mensagem = campo(l, "message")
    if (mensagem == "") mensagem = "(sem mensagem)"
    st = campo(l, "stack_trace")
    if (st != "") {
        n = split(st, partes, /\\n/)
        for (i = 1; i <= n; i++) continuar(partes[i])
    }
}
function juntar_trecho(s) {
    if (linhas_trecho >= max_trecho) return
    if (length(s) > 300) s = substr(s, 1, 299) "…"
    trecho = (trecho == "" ? s : trecho "\n" s)
    linhas_trecho++
}
function continuar(l,    t, c) {
    if (!aberto || nivel == "") return
    t = aparar(l)
    if (t == "" || t == "[stacktrace]" || t ~ /^"\}/) return
    if (laravel) t = desescapar(t)
    if (t ~ /Exception|Error:/ || l ~ /^[ \t]+at / || t ~ /^#[0-9]+ /) tem_excecao = 1
    # Java: a primeira linha "pacote.ClasseException: mensagem" diz a classe.
    if (excecao == "" && t ~ /^[A-Za-z_$][A-Za-z0-9_$.]*(Exception|Error)(:|$)/) {
        c = t
        sub(/:.*/, "", c)
        excecao = c
    }
    juntar_trecho(t)
}
{
    l = $0
    carimbo = ""
    if (modo == "docker" && match(l, /^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9:.]+Z /)) {
        carimbo = substr(l, 1, RLENGTH - 1)
        l = substr(l, RLENGTH + 1)
        # Nanossegundos: o PHP lê até micro, e o segundo basta para a hora.
        sub(/\.[0-9]+Z$/, "Z", carimbo)
    }
    if (l ~ /^\{/ && index(l, "\"level\":") > 0) {
        fechar()
        abrir_json(l, carimbo)
    } else if (eh_inicio(l)) {
        fechar()
        abrir(l, carimbo)
    } else {
        continuar(l)
    }
}
END { fechar() }
AWK

# Tira byte de controle (menos tab e quebra de linha) e, se der, byte que não
# é UTF-8: o JSON tem de sair válido mesmo de um log sujo.
limpar_texto() {
    if command -v iconv >/dev/null 2>&1; then
        LC_ALL=C tr -d '\000-\010\013-\037' | iconv -c -f UTF-8 -t UTF-8 2>/dev/null || true
    else
        LC_ALL=C tr -d '\000-\010\013-\037'
    fi
}

extrair() { # $1 = modo; lê o log na entrada, escreve NDJSON na saída
    limpar_texto | awk -v modo="$1" -v fuso="$VIGIA_FUSO" -v max_trecho="$VIGIA_LINHAS_TRECHO" "$EXTRATOR"
}

json_texto() { printf '%s' "$1" | awk 'BEGIN { RS = "\001" } { gsub(/\\/, "\\\\"); gsub(/"/, "\\\""); gsub(/\n/, " "); printf "\"%s\"", $0 }'; }

FALHOU=0

# Manda o NDJSON de uma fonte, em lotes. Devolve 0 se pode andar o estado.
enviar() { # $1 = arquivo NDJSON, $2 = origem
    local ndjson="$1" origem="$2" lote corpo codigo resposta andar=0
    [ -s "$ndjson" ] || return 0

    rm -f "$TMP"/lote.*
    split -l "$VIGIA_LOTE" "$ndjson" "$TMP/lote."

    for lote in "$TMP"/lote.*; do
        corpo="$TMP/corpo.json"
        {
            printf '{"ambiente":%s,"origem":%s,"erros":[' "$(json_texto "$VIGIA_AMBIENTE")" "$(json_texto "$origem")"
            paste -s -d, "$lote" | tr -d '\n'
            printf ']}'
        } > "$corpo"

        if [ "$IMPRIMIR" -eq 1 ]; then
            cat "$corpo"
            echo
            continue
        fi

        # O token vai num arquivo de cabeçalho, e não na linha de comando:
        # argumento de comando aparece no `ps` de qualquer usuário.
        ( umask 077; printf 'Authorization: Bearer %s\n' "$VIGIA_TOKEN" > "$TMP/cabecalho" )
        resposta="$TMP/resposta"
        codigo="$(curl -sS -m 60 -o "$resposta" -w '%{http_code}' \
            -H @"$TMP/cabecalho" -H 'Content-Type: application/json' -H 'Accept: application/json' \
            --data-binary @"$corpo" "$VIGIA_URL" 2>"$TMP/curl.err" || true)"

        case "$codigo" in
            2??)
                dizer "$origem: $(wc -l < "$lote" | tr -d ' ') erro(s) enviados — $(head -c 300 "$resposta" 2>/dev/null)"
                ;;
            413|422)
                # Reenviar não resolve: o AlfaMatriz recusou o CONTEÚDO. Anda o
                # estado para não travar a fonte para sempre, e diz em voz alta.
                dizer "$origem: lote recusado ($codigo), descartado — $(head -c 300 "$resposta" 2>/dev/null)"
                ;;
            401)
                dizer "$origem: token recusado (401) — confira VIGIA_TOKEN (alfa:vigia-token no AlfaMatriz)"
                andar=1
                ;;
            *)
                dizer "$origem: envio falhou (HTTP ${codigo:-sem resposta}) $(cat "$TMP/curl.err" 2>/dev/null) — tento de novo na próxima rodada"
                andar=1
                ;;
        esac

        [ "$andar" -eq 0 ] || break
    done

    [ "$andar" -eq 0 ] || FALHOU=1
    return "$andar"
}

chave_do_arquivo() { printf '%s' "$1" | tr '/ ' '__'; }

# --- (a) arquivos do Laravel ----------------------------------------------
for padrao in $VIGIA_ARQUIVOS; do
    for arquivo in $padrao; do
        [ -f "$arquivo" ] || continue

        estado="$VIGIA_ESTADO/arquivo$(chave_do_arquivo "$arquivo")"
        inode="$(ls -i "$arquivo" | awk '{print $1}')"
        tamanho="$(wc -c < "$arquivo" | tr -d ' ')"
        posicao=0

        if [ "$DESDE_O_INICIO" -eq 1 ]; then
            posicao=0
        elif [ -f "$estado" ]; then
            read -r inode_antes posicao_antes < "$estado" || true
            if [ "${inode_antes:-}" = "$inode" ] && [ "${posicao_antes:-0}" -le "$tamanho" ]; then
                posicao="${posicao_antes:-0}"
            fi
        elif [ "$PRIMEIRA_RODADA" -eq 1 ]; then
            posicao="$tamanho"
        fi

        novo="$TMP/novo.log"
        # `|| true`: se o log cresceu desde o `wc`, o `head` fecha o cano antes
        # de o `tail` terminar, e o SIGPIPE derrubaria a rodada no `pipefail`.
        { tail -c +"$((posicao + 1))" "$arquivo" | head -c "$((tamanho - posicao))"; } > "$novo" || true

        # Só até a última quebra de linha: a entrada ainda sendo escrita fica
        # para a próxima rodada, inteira.
        lidos="$(wc -c < "$novo" | tr -d ' ')"
        if [ "$lidos" -gt 0 ] && [ "$(tail -c 1 "$novo" | od -An -c | tr -d ' ')" != '\n' ]; then
            parcial="$(tail -n 1 "$novo" | wc -c | tr -d ' ')"
            lidos=$((lidos - parcial))
            # `head -c 0` é erro no BSD: sem linha inteira, o trecho fica vazio.
            if [ "$lidos" -gt 0 ]; then
                head -c "$lidos" "$novo" > "$novo.inteiro" && mv "$novo.inteiro" "$novo"
            else
                : > "$novo"
            fi
        fi

        extrair laravel < "$novo" > "$TMP/erros.ndjson"

        if enviar "$TMP/erros.ndjson" "$VIGIA_ORIGEM:$arquivo" && [ "$IMPRIMIR" -eq 0 ]; then
            echo "$inode $((posicao + lidos))" > "$estado"
        fi
    done
done

# --- (b) containers --------------------------------------------------------
agora="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
for container in $VIGIA_CONTAINERS; do
    estado="$VIGIA_ESTADO/docker_$container"

    if [ "$DESDE_O_INICIO" -eq 1 ]; then
        desde="24h"
    elif [ -f "$estado" ]; then
        desde="$(cat "$estado")"
    else
        desde="$agora"
    fi

    if ! docker logs --timestamps --since "$desde" --until "$agora" "$container" > "$TMP/docker.log" 2>&1; then
        dizer "docker:$container: não consegui ler o log ($(head -c 200 "$TMP/docker.log"))"
        FALHOU=1
        continue
    fi

    extrair docker < "$TMP/docker.log" > "$TMP/erros.ndjson"

    if enviar "$TMP/erros.ndjson" "$VIGIA_ORIGEM:docker:$container" && [ "$IMPRIMIR" -eq 0 ]; then
        echo "$agora" > "$estado"
    fi
done

if [ "$IMPRIMIR" -eq 0 ]; then
    touch "$VIGIA_ESTADO/.iniciado"
fi

exit "$FALHOU"
