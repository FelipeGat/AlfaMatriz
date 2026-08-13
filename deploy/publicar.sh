#!/usr/bin/env bash
#
# Publica uma versão do AlfaMatriz pelo esquema AZUL/VERDE.
#
# Uso:
#   deploy/publicar.sh [--dir CAMINHO] [--ref REFERÊNCIA] [--portao COMANDO]
#                      [--url-ensaio URL] [--url-publica URL] [--sem-troca]
#
# --dir CAMINHO      raiz da instalação (padrão: /var/www/alfamatriz).
# --ref REFERÊNCIA   tag, branch ou commit a publicar (padrão: origin/main).
# --portao COMANDO   comando que precisa passar DENTRO da versão preparada
#                    antes de qualquer coisa ser aplicada (o staging usa
#                    `php artisan test`). Reprovou, nada é publicado.
# --url-ensaio URL   saúde da versão preparada, pela porta de ensaio
#                    (padrão: http://127.0.0.1:8081/healthz).
# --url-publica URL  saúde conferida DEPOIS da troca. Falhando, a troca é
#                    desfeita. Vazio desliga a conferência.
# --sem-troca        prepara e confere, mas não coloca no ar. Serve para
#                    ensaiar uma versão sem publicá-la.
#
# ------------------------------------------------------------------ o esquema
#
# A instalação tem DUAS cópias completas da aplicação — `versoes/azul` e
# `versoes/verde` — e dois symlinks:
#
#   atual   -> a versão que o nginx serve na porta 80
#   preparo -> a outra, servida só em 127.0.0.1:8081
#
# Publicar é preparar inteiramente a versão que NÃO está no ar (código,
# dependências, front-end compilado, caches), conferir a saúde dela pela porta
# de ensaio e só então apontar `atual` para ela. É o mesmo desenho do
# AlfaControl, trocando "container azul/verde + upstream do nginx" por "cópia
# azul/verde + symlink", que é a forma que serve a um PHP-FPM.
#
# O que isso resolve: até aqui o `git checkout` + `composer install` + `npm run
# build` aconteciam EM CIMA do diretório que estava no ar. Durante ~2 minutos o
# site servia metade do código velho com metade do novo, com o `vendor` sendo
# reescrito por baixo — e um build que falhasse no meio deixava a produção
# quebrada até alguém consertar à mão.
#
# O que isso NÃO resolve: as duas versões dividem o MESMO banco. A migração é
# aplicada antes da troca, então ela roda por alguns segundos sob o código
# antigo — e continua aplicada se a troca for desfeita. Vale aqui a mesma
# regra do AlfaControl: migração precisa ser compatível com a versão anterior
# (acrescentar antes, remover depois).
#
# Códigos de saída:
#   0  publicado (ou ensaiado com --sem-troca)
#   1  falhou ANTES da troca — o que estava no ar não foi tocado
#   2  trocou, a saúde pública reprovou e a troca foi DESFEITA

set -uo pipefail

DIR="${APP_DIR:-/var/www/alfamatriz}"
REF=""
PORTAO=""
URL_ENSAIO="${URL_ENSAIO:-http://127.0.0.1:8081/healthz}"
URL_PUBLICA="${URL_PUBLICA:-}"
TROCAR=1
# Quantas vezes perguntar a saúde, e com que intervalo. São variáveis de
# ambiente para que a suíte não precise esperar 20s a cada cenário de falha.
TENTATIVAS_SAUDE="${TENTATIVAS_SAUDE:-10}"
ESPERA_SAUDE="${ESPERA_SAUDE:-2}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir) DIR="$2"; shift 2 ;;
        --ref) REF="$2"; shift 2 ;;
        --portao) PORTAO="$2"; shift 2 ;;
        --url-ensaio) URL_ENSAIO="$2"; shift 2 ;;
        --url-publica) URL_PUBLICA="$2"; shift 2 ;;
        --sem-troca) TROCAR=0; shift ;;
        *) echo "publicar.sh: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

# O cron do root roda com PATH=/usr/bin:/bin, e composer, npm e node vivem em
# /usr/local/bin. Mesma correção do vigia e do executor de staging, e pelo
# mesmo motivo: sem ela o script funciona à mão e falha pelo agendador.
# Acrescentado ao FIM, para não tirar a prioridade de quem chamou.
export PATH="$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

VERSOES="$DIR/versoes"
COMPARTILHADO="$DIR/compartilhado"
ATUAL="$DIR/atual"
PREPARO="$DIR/preparo"

etapa() { echo "==> $*"; }
falhar() { echo "publicar.sh: falhou na etapa \"$1\" — nada foi publicado." >&2; exit 1; }

# ------------------------------------------------------------------ symlinks

# Troca para onde um symlink aponta.
#
# O caminho bom é `mv -T`, que é um rename(2): quem estiver lendo vê o link
# velho ou o novo, nunca a ausência dos dois. É o que torna a troca invisível
# para quem está usando o sistema — e é o que roda no servidor (Debian).
#
# O `ln -sfn` do fallback apaga e recria, com uma janela de microssegundos em
# que o link não existe. Ele está aqui porque `mv -T` é do GNU coreutils e não
# existe no macOS, onde esta suíte roda: um esquema de troca que só pode ser
# exercitado no servidor não é testável.
trocar_link() { # $1=destino  $2=caminho do link
    local destino="$1" link="$2" temporario="$2.trocando.$$"

    rm -f "$temporario"
    ln -sfn "$destino" "$temporario" || return 1

    if mv -Tf "$temporario" "$link" 2>/dev/null; then
        return 0
    fi

    rm -f "$temporario"
    ln -sfn "$destino" "$link"
}

# ------------------------------------------------------------------- layout

# Cria o que falta. Idempotente de propósito: é o que permite rodar isto sobre
# uma instalação recém-convertida, sobre uma instalação já em azul/verde e
# sobre um clone novo, sem três caminhos diferentes.
garantir_layout() {
    git -C "$DIR" rev-parse --git-dir >/dev/null 2>&1 || {
        echo "publicar.sh: $DIR não é um repositório git — rode o provisionamento antes." >&2
        return 1
    }

    # Instalação no formato antigo (a aplicação instalada na própria raiz,
    # servida de lá) precisa ser CONVERTIDA, não improvisada. Montar o layout
    # aqui deixaria o Nginx servindo a raiz enquanto a publicação trocaria
    # symlinks que ninguém lê: o deploy passaria, e a versão nova não estaria
    # no ar. O `vendor` na raiz é o sinal — instalação nova e instalação já
    # convertida não o têm.
    if [[ ! -d "$VERSOES" && -d "$DIR/vendor" ]]; then
        echo "publicar.sh: $DIR ainda está no formato antigo (há um vendor/ na raiz)." >&2
        echo "             Rode antes: deploy/converter-para-azul-verde.sh --dir $DIR" >&2
        return 1
    fi

    # Só depois das duas recusas acima: criar o diretório antes delas faria a
    # segunda nunca disparar, porque é justamente a ausência dele que
    # identifica a instalação por converter.
    mkdir -p "$VERSOES" "$COMPARTILHADO" || return 1

    # Cópia apagada à mão continua registrada no git, e o `worktree add`
    # seguinte falha com "already registered" — mensagem que não ajuda em nada
    # a quem só quer publicar. O `prune` esquece as que não existem mais.
    git -C "$DIR" worktree prune >/dev/null 2>&1 || true

    local cor
    for cor in azul verde; do
        [[ -d "$VERSOES/$cor" ]] && continue
        etapa "criando a cópia $cor"
        git -C "$DIR" worktree add --detach "$VERSOES/$cor" HEAD >/dev/null 2>&1 || return 1
    done

    [[ -L "$ATUAL" ]] || trocar_link "$VERSOES/azul" "$ATUAL" || return 1
    [[ -L "$PREPARO" ]] || trocar_link "$VERSOES/verde" "$PREPARO" || return 1

    return 0
}

# ------------------------------------------------------------------- saúde

conferir_saude() { # $1=url  $2=tentativas
    local url="$1" tentativas="$2" codigo=""

    [[ -z "$url" ]] && return 0

    local i
    for (( i = 1; i <= tentativas; i++ )); do
        codigo=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$url" 2>/dev/null)
        [[ "$codigo" == "200" ]] && { echo "    saúde 200 em $url"; return 0; }
        [[ $i -lt $tentativas ]] && sleep "$ESPERA_SAUDE"
    done

    echo "    saúde respondeu \"${codigo:-<sem resposta>}\" em $url" >&2
    return 1
}

# ------------------------------------------------------------------ preparo

cd "$DIR" || { echo "publicar.sh: diretório $DIR não existe" >&2; exit 1; }

garantir_layout || falhar "preparar o layout azul/verde"

ATIVA=$(readlink "$ATUAL")
ALVO=$(readlink "$PREPARO")

if [[ -z "$ALVO" || "$ALVO" == "$ATIVA" ]]; then
    echo "publicar.sh: os symlinks atual/preparo apontam para a mesma cópia — conserte antes de publicar." >&2
    exit 1
fi

etapa "Buscando código"
# Falha de fetch não é fatal por si só: pode ser rede, e a referência pedida
# talvez já esteja aqui. Quem decide é o `rev-parse` logo abaixo.
if ! ERRO_FETCH=$(git -C "$DIR" fetch --quiet --tags --force origin 2>&1); then
    echo "    aviso: fetch não concluiu (${ERRO_FETCH:-<git não disse nada>})"
fi

[[ -n "$REF" ]] || REF="origin/main"

ALVO_SHA=$(git -C "$DIR" rev-parse --verify --quiet "${REF}^{commit}")
[[ -n "$ALVO_SHA" ]] || falhar "encontrar a referência \"$REF\""

echo "    no ar:    $ATIVA"
echo "    preparo:  $ALVO"
echo "    versão:   $REF (${ALVO_SHA:0:7})"

etapa "Levando o código para a cópia em preparo"
git -C "$ALVO" checkout --detach --quiet "$ALVO_SHA" || falhar "levar o código para a cópia em preparo"

# O .env é da INSTALAÇÃO, não da versão: mora em compartilhado/ e cada cópia
# só aponta para ele. Sem isto, publicar exigiria copiar segredo de uma pasta
# para outra a cada troca — e uma cópia ficaria para trás no dia em que uma
# senha mudasse.
if [[ -f "$COMPARTILHADO/.env" ]]; then
    ln -sfn "$COMPARTILHADO/.env" "$ALVO/.env" || falhar "apontar o .env compartilhado"
else
    echo "    aviso: $COMPARTILHADO/.env não existe — a cópia usará o .env que já tiver."
fi

# ------------------------------------------------------- dependências PHP
#
# Havendo portão, a instalação acontece DUAS vezes, e não é desperdício: o
# portão é a suíte de testes, e ela vive nas dependências de desenvolvimento —
# com `--no-dev` o `php artisan test` nem existe. A segunda instalação
# devolve a cópia ao estado de produção antes de ela ir para o ar, para que o
# que é publicado não carregue pacote de desenvolvimento junto.

if [[ -n "$PORTAO" ]]; then
    etapa "Instalando dependências PHP (com as de desenvolvimento, para o portão)"
    ( cd "$ALVO" && composer install --no-interaction ) || falhar "instalar dependências PHP"

    # ------------------------------------------------------------- portão
    #
    # Roda dentro da cópia em preparo. Nos outros sistemas da casa quem segura
    # código quebrado é o CI (a imagem só existe no GHCR se passou); aqui é
    # este comando. A diferença que o azul/verde traz: até então o portão
    # rodava depois de o código já ter sido mesclado no diretório que estava NO
    # AR, e reprovar exigia um `git reset --hard` em cima do site vivo.
    etapa "Portão: $PORTAO"

    # O route/view cache da versão anterior desta cópia não vale para o código
    # novo: a suíte carregaria rotas e views velhas e reprovaria código bom.
    # Aqui limpar é seguro — esta cópia não está atendendo ninguém.
    ( cd "$ALVO" && php artisan route:clear >/dev/null 2>&1; php artisan view:clear >/dev/null 2>&1 ) || true

    if ! ( cd "$ALVO" && eval "$PORTAO" ); then
        echo "publicar.sh: o portão REPROVOU — nada foi publicado, o que está no ar segue intacto." >&2
        exit 1
    fi
fi

etapa "Instalando dependências PHP de produção"
( cd "$ALVO" && composer install --no-dev --optimize-autoloader --no-interaction ) || falhar "instalar dependências PHP"

etapa "Instalando dependências do front-end"
( cd "$ALVO" && npm ci ) || falhar "instalar dependências do front-end"

etapa "Compilando front-end"
# `public/hot` é o bilhete que o Vite deixa quando o servidor de
# desenvolvimento está no ar: existindo o arquivo, o `@vite` do Blade para de
# apontar para os assets compilados e passa a apontar para o endereço que está
# lá dentro. Num servidor esse endereço não responde, e a tela inteira sobe sem
# CSS nem JS — sem erro no deploy, porque compilar deu certo.
#
# Ele é ignorado pelo git, e as cópias azul e verde são REUSADAS: o `checkout`
# não remove arquivo ignorado, então um `npm run dev` rodado uma vez dentro de
# uma versão ficaria lá para sempre. É a mesma armadilha do symlink de anexos,
# em outro arquivo.
#
# Removido ANTES do build, e não depois: se o build falhar, a cópia em preparo
# fica sem o bilhete em vez de ficar com um bilhete velho.
rm -f "$ALVO/public/hot"
( cd "$ALVO" && npm run build ) || falhar "compilar front-end"

# Os anexos de cobranças e contas a pagar vivem em compartilhado/anexos, fora
# das versões (ver config/filesystems.php). Este link é só a porta de entrada
# pública para eles, e precisa existir em cada cópia.
#
# `--force` porque sem ele o comando RECUSA um link existente, imprime "already
# exists" e **sai com sucesso** — o deploy segue e o link errado sobrevive. E
# ele sobrevive de verdade: as versões azul e verde se alternam, então um link
# criado numa publicação antiga continua na pasta quando ela é reusada, fora do
# git por ser ignorado.
#
# Foi exatamente o que aconteceu em produção: o link apontava para
# `versoes/azul/storage/app/public` enquanto o `FILESYSTEM_PUBLIC_ROOT` mandava
# gravar em `compartilhado/anexos`. A aplicação escrevia num lugar e o servidor
# lia noutro. Ficou invisível por meses porque NADA no sistema servia esse disco
# por URL — o download de anexo passa pela rota (`Storage::download`), do lado
# do servidor. A marca do sistema, num `<img src>`, foi a primeira a precisar.
#
# `--force` só remove SYMLINK (`isRemovableSymlink` checa `is_link`), então
# pasta de verdade com arquivo dentro nunca é apagada por aqui.
etapa "Ligando a pasta pública de anexos"
( cd "$ALVO" && php artisan storage:link --force ) || falhar "ligar a pasta pública de anexos"

etapa "Aplicando migrações"
( cd "$ALVO" && php artisan migrate --force ) || falhar "aplicar migrações"

# Permissão é dado semeado, não migrado: sem esta etapa, todo recurso novo
# nasce invisível em produção — some do menu e devolve 403, sem erro nenhum.
etapa "Aplicando cargas de referência"
( cd "$ALVO" && php artisan alfa:semear-referencia ) || falhar "aplicar cargas de referência"

# Sem `config:clear`: ele deixaria a cópia sem configuração por alguns
# segundos. Aqui isso não derrubaria ninguém (esta cópia ainda não atende), mas
# a etapa é a mesma nos três scripts e uma delas roda sobre o que está no ar —
# manter o hábito é o que impede a versão errada de virar costume.
etapa "Recarregando caches"
( cd "$ALVO" && php artisan config:cache >/dev/null ) || falhar "recarregar cache de configuração"
( cd "$ALVO" && php artisan route:cache >/dev/null ) || falhar "recarregar cache de rotas"
( cd "$ALVO" && php artisan view:cache >/dev/null ) || falhar "recarregar cache de views"

# ------------------------------------------------------------------- ensaio

etapa "Conferindo a saúde da versão preparada (porta de ensaio)"
if ! conferir_saude "$URL_ENSAIO" "$TENTATIVAS_SAUDE"; then
    echo "publicar.sh: a versão preparada não passou no ensaio — NÃO foi colocada no ar." >&2
    echo "             O que está publicado continua sendo $ATIVA, intacto." >&2
    exit 1
fi

if [[ "$TROCAR" -eq 0 ]]; then
    echo "publicar.sh: versão preparada e aprovada em $ALVO (--sem-troca: não foi colocada no ar)"
    exit 0
fi

# -------------------------------------------------------------------- troca

etapa "Colocando no ar"
trocar_link "$ALVO" "$ATUAL" || falhar "trocar a versão no ar"
trocar_link "$ATIVA" "$PREPARO" || echo "    aviso: o symlink de preparo não foi atualizado"

# O opcache é indexado pelo caminho REAL do arquivo (é para isso que o nginx
# manda $realpath_root), então a versão nova entra com cache limpo sozinha.
# O reload continua aqui por causa da cópia que ACABOU de sair: ela vai ser
# reescrita na próxima publicação, e deixar o opcache dela pendurado é gastar
# memória com código que ninguém mais serve. Recarregar o PHP-FPM é gracioso —
# as requisições em andamento terminam.
etapa "Recarregando PHP-FPM"
if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    systemctl reload php8.2-fpm >/dev/null 2>&1 || true
else
    sudo systemctl reload php8.2-fpm >/dev/null 2>&1 || true
fi

# ------------------------------------------------------------ saúde pública

if [[ -n "$URL_PUBLICA" ]]; then
    etapa "Conferindo a saúde no endereço público"
    if ! conferir_saude "$URL_PUBLICA" "$TENTATIVAS_SAUDE"; then
        echo "publicar.sh: a saúde reprovou DEPOIS da troca — desfazendo." >&2

        trocar_link "$ATIVA" "$ATUAL"
        trocar_link "$ALVO" "$PREPARO"
        if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
            systemctl reload php8.2-fpm >/dev/null 2>&1 || true
        else
            sudo systemctl reload php8.2-fpm >/dev/null 2>&1 || true
        fi

        if conferir_saude "$URL_PUBLICA" "$TENTATIVAS_SAUDE"; then
            echo "publicar.sh: voltou para $ATIVA e o sistema está saudável de novo." >&2
        else
            echo "publicar.sh: voltou para $ATIVA e a saúde AINDA reprova — o problema não é a versão." >&2
        fi

        # A migração aplicada NÃO é desfeita: o banco é um só e não há como
        # saber, daqui, o que uma migração de volta faria com os dados.
        echo "publicar.sh: atenção — as migrações de $REF continuam aplicadas no banco." >&2
        exit 2
    fi
fi

# O clone de controle acompanha o que está no ar. É dele que o painel
# AlfaDeploy lê a versão do staging (`git rev-parse HEAD`), e é dele que sai o
# `deploy/` instalado em /usr/local/bin. Só se move DEPOIS da troca dar certo:
# controle apontando para uma versão que não subiu faria o painel mostrar como
# publicado algo que não está no ar.
git -C "$DIR" checkout --detach --quiet "$ALVO_SHA" 2>/dev/null || \
    echo "    aviso: o clone de controle não acompanhou (a versão no ar é a de $ALVO)"

echo "publicar.sh: no ar em $ALVO ($REF) — a anterior continua pronta em $ATIVA"
