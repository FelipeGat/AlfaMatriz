#!/usr/bin/env bash
#
# Provisiona o container do AlfaMatriz no alfa-server (Proxmox).
#
# Uso:
#   deploy/provisionar.sh [--host alfa-server] [--vmid 115] [--ip 10.0.3.115]
#   deploy/provisionar.sh --local          # já estou dentro do host Proxmox
#
# Cria o LXC alfamatriz (Debian 12), instala PHP 8.2 + Nginx + MariaDB +
# Tailscale e publica o painel na internet via Tailscale Funnel (porta 443).
#
# IDEMPOTENTE de propósito: rodar de novo sobre um servidor já provisionado
# não recria o container nem apaga o banco — só confere que está tudo no
# lugar. É o que permite usar este script para consertar um ambiente torto
# sem medo de perder dados.

set -euo pipefail

HOST="alfa-server"
VMID="115"
IP="10.0.3.115"
GATEWAY="10.0.3.1"
NOME="alfamatriz"
BANCO="alfamatriz"
APP_DIR="/var/www/alfamatriz"
TEMPLATE="local:vztmpl/debian-12-standard_12.12-1_amd64.tar.zst"
ARMAZENAMENTO="dados"
LOCAL=0

AMBIENTE="producao"
MEMORIA=2048
SWAP=2048

while [[ $# -gt 0 ]]; do
    case "$1" in
        --host) HOST="$2"; shift 2 ;;
        --vmid) VMID="$2"; shift 2 ;;
        --ip) IP="$2"; shift 2 ;;
        --ambiente) AMBIENTE="$2"; shift 2 ;;
        --local) LOCAL=1; shift ;;
        *) echo "provisionar.sh: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

# O staging é o mesmo sistema com outro endereço e sem porta pública: vive só
# no tailnet, sem domínio da empresa e sem túnel Cloudflare. É o padrão dos
# stagings dos outros sistemas da casa.
case "$AMBIENTE" in
    producao) ;;
    staging)
        [[ "$VMID" == "115" ]] && VMID="116"
        [[ "$IP" == "10.0.3.115" ]] && IP="10.0.3.116"
        NOME="alfamatriz-staging"
        # O host tem 11 GB e já opera com ~8 GB em uso. A produção deste mesmo
        # software consome 331 MB, então 1 GB de teto é folga real — e evita
        # repetir o overcommit de 4 GB dos containers Java.
        MEMORIA=1024
        SWAP=1024
        ;;
    *) echo "provisionar.sh: ambiente inválido \"$AMBIENTE\" (use producao ou staging)" >&2; exit 1 ;;
esac

info() { echo "==> $*"; }
falhar() { echo "provisionar.sh: $*" >&2; exit 1; }

# Roda um comando no host Proxmox (por SSH, ou direto com --local).
no_host() {
    if [[ "$LOCAL" -eq 1 ]]; then
        bash -c "$1"
    else
        ssh -o BatchMode=yes "$HOST" "$1"
    fi
}

# Roda um comando dentro do container.
no_container() {
    no_host "pct exec $VMID -- bash -lc $(printf '%q' "$1")"
}

# ---------------------------------------------------------------- container

if no_host "pct config $VMID >/dev/null 2>&1"; then
    info "container $VMID já existe — mantendo (nada é recriado, banco intacto)"
else
    info "criando container $VMID ($NOME) em $IP"
    no_host "pct create $VMID $TEMPLATE \
        --hostname $NOME \
        --cores 2 --memory $MEMORIA --swap $SWAP \
        --rootfs $ARMAZENAMENTO:16 \
        --net0 name=eth0,bridge=vmbr0,gw=$GATEWAY,ip=$IP/24,type=veth \
        --nameserver '1.1.1.1 8.8.8.8' \
        --features nesting=1,keyctl=1 \
        --unprivileged 1 \
        --onboot 1 \
        --start 0"
fi

# O Tailscale precisa de /dev/net/tun dentro do container — mesmo padrão dos
# outros containers do alfa-server. Aplicado sempre: é barato conferir.
info "garantindo acesso ao /dev/net/tun (necessário para o Tailscale)"
no_host "grep -q 'dev/net/tun' /etc/pve/lxc/$VMID.conf || { \
    echo 'lxc.cgroup2.devices.allow: c 10:200 rwm' >> /etc/pve/lxc/$VMID.conf; \
    echo 'lxc.mount.entry: /dev/net/tun dev/net/tun none bind,create=file' >> /etc/pve/lxc/$VMID.conf; }"

if no_host "pct status $VMID | grep -q running"; then
    info "container já está rodando"
else
    info "iniciando container"
    no_host "pct start $VMID"
    sleep 10
fi

# ------------------------------------------------------------------- pilha

# php8.2-sqlite3 não é opcional: a suíte roda em SQLite em memória
# (phpunit.xml), e é ela o portão que aprova o deploy do staging. Sem esse
# pacote, todo deploy é reprovado por "teste falhando" que não tem nada a ver
# com o código.
info "instalando a pilha (apt é idempotente: pacote já instalado não reinstala)"
no_container "export DEBIAN_FRONTEND=noninteractive && apt-get update -qq && \
    apt-get install -y -qq \
        nginx mariadb-server \
        php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml \
        php8.2-curl php8.2-zip php8.2-gd php8.2-bcmath php8.2-intl \
        php8.2-sqlite3 \
        git unzip curl ca-certificates gnupg cron"

info "instalando Node.js 20 (para compilar o front-end)"
no_container "command -v node >/dev/null 2>&1 || { \
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq nodejs; }"

info "instalando Composer"
no_container "command -v composer >/dev/null 2>&1 || { \
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php && \
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    rm -f /tmp/composer-setup.php; }"

info "instalando Tailscale"
no_container "command -v tailscale >/dev/null 2>&1 || { \
    curl -fsSL https://tailscale.com/install.sh | sh; }"

# -------------------------------------------------------------------- banco

info "garantindo banco e usuário do MariaDB (não recria nem apaga o que existe)"
no_container "systemctl enable --now mariadb"
no_container "mariadb -e \"CREATE DATABASE IF NOT EXISTS $BANCO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\""
no_container "test -f $APP_DIR/.env && grep -q '^DB_PASSWORD=' $APP_DIR/.env || true"

# A senha do banco vem do .env já existente quando houver; senão, é gerada uma
# vez e gravada. Rodar de novo NÃO troca a senha de um banco em uso.
no_container "if [ -f /root/.alfamatriz-db-pass ]; then \
        SENHA=\$(cat /root/.alfamatriz-db-pass); \
    else \
        SENHA=\$(head -c 32 /dev/urandom | base64 | tr -d '/+=' | head -c 24); \
        printf '%s' \"\$SENHA\" > /root/.alfamatriz-db-pass; chmod 600 /root/.alfamatriz-db-pass; \
    fi; \
    mariadb -e \"CREATE USER IF NOT EXISTS '$BANCO'@'localhost' IDENTIFIED BY '\$SENHA';\"; \
    mariadb -e \"ALTER USER '$BANCO'@'localhost' IDENTIFIED BY '\$SENHA';\"; \
    mariadb -e \"GRANT ALL PRIVILEGES ON $BANCO.* TO '$BANCO'@'localhost'; FLUSH PRIVILEGES;\""

# -------------------------------------------------------------------- nginx

info "instalando configuração do Nginx"
if [[ "$LOCAL" -eq 1 ]]; then
    cp "$(dirname "$0")/nginx-alfamatriz.conf" /tmp/nginx-alfamatriz.conf
    no_host "pct push $VMID /tmp/nginx-alfamatriz.conf /etc/nginx/sites-available/alfamatriz"
else
    scp -o BatchMode=yes "$(dirname "$0")/nginx-alfamatriz.conf" "$HOST:/tmp/nginx-alfamatriz.conf"
    no_host "pct push $VMID /tmp/nginx-alfamatriz.conf /etc/nginx/sites-available/alfamatriz"
fi

no_container "ln -sf /etc/nginx/sites-available/alfamatriz /etc/nginx/sites-enabled/alfamatriz && \
    rm -f /etc/nginx/sites-enabled/default && \
    mkdir -p $APP_DIR/public && \
    nginx -t && systemctl reload nginx"

# --------------------------------------------------------- painel AlfaDeploy

# O painel (LXC 110) descobre o estado de cada sistema por um único SSH como
# root, com a chave dedicada /root/.ssh/alfadeploy. Sem essa chave autorizada
# aqui, o probe falha e o painel mostra o sistema como desconectado — mesmo
# com o container de pé e o site respondendo. Foi exatamente o que aconteceu
# no primeiro provisionamento do staging, porque este passo não existia.
#
# Só no staging: o painel monitora o LXC 116 e nada mais. A produção guarda o
# financeiro da Alfa e não ganha uma chave de acesso que ninguém usa — mesmo
# critério que mantém o Funnel desligado logo abaixo.
if [[ "$AMBIENTE" == "staging" ]]; then
    CHAVE_PAINEL="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIvf2pPREFM9n1PO8/HLdyZtaSE0trZMnORRhG82xmZm alfadeploy@deploy"
    info "autorizando a chave do painel AlfaDeploy no root"
    no_container "mkdir -p /root/.ssh; chmod 700 /root/.ssh; \
        touch /root/.ssh/authorized_keys; chmod 600 /root/.ssh/authorized_keys; \
        grep -qF '$CHAVE_PAINEL' /root/.ssh/authorized_keys || \
            echo '$CHAVE_PAINEL' >> /root/.ssh/authorized_keys"
fi

# ------------------------------------------------------- exposição pública

# Quem publica o painel na internet é o túnel Cloudflare, em
# matriz.alfasolucoes.cloud. O Tailscale fica só como acesso de emergência
# DENTRO do tailnet — o Funnel (que expõe o .ts.net na internet) permanece
# desligado de propósito: duas portas públicas para os mesmos dados
# financeiros é uma a mais do que o necessário.
info "mantendo o Tailscale como acesso interno (sem Funnel)"
no_container "tailscale status >/dev/null 2>&1 || \
    echo 'AVISO: o container ainda não está no tailnet. Rode dentro dele: tailscale up --hostname=$NOME'"
# ATENÇÃO à ordem: `tailscale funnel off` apaga a configuração INTEIRA, não só
# a parte pública — inclusive o `serve`. Por isso o serve é (re)criado DEPOIS
# do funnel off, senão o acesso de emergência pelo tailnet some junto.
no_container "if tailscale status >/dev/null 2>&1; then \
        tailscale funnel --https=443 off >/dev/null 2>&1 || true; \
        tailscale serve --bg --https=443 http://127.0.0.1:80 >/dev/null 2>&1 || true; \
    fi"

if [[ "$AMBIENTE" == "staging" ]]; then
    info "staging: sem túnel Cloudflare e sem porta pública (vive só no tailnet)"
else

info "instalando o túnel Cloudflare (domínio da empresa)"
no_container "command -v cloudflared >/dev/null 2>&1 || { \
    curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg -o /usr/share/keyrings/cloudflare-main.gpg && \
    echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared bookworm main' > /etc/apt/sources.list.d/cloudflared.list && \
    DEBIAN_FRONTEND=noninteractive apt-get update -qq && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq cloudflared; }"

# O túnel em si depende de credencial da Cloudflare (passo manual do dono):
# `cloudflared tunnel login` e `cloudflared tunnel create alfamatriz`.
# Havendo credencial, o serviço é garantido aqui — sem ela, o script avisa e
# segue, em vez de falhar um provisionamento que no resto está correto.
# O pacote do cloudflared NÃO cria a unidade do systemd: quem cria é
# `cloudflared service install`, a partir do /etc/cloudflared/config.yml.
no_container "if [ -f /etc/cloudflared/config.yml ]; then \
        systemctl list-unit-files cloudflared.service >/dev/null 2>&1 || cloudflared service install >/dev/null 2>&1 || true; \
        systemctl enable --now cloudflared >/dev/null 2>&1 || true; \
        systemctl is-active cloudflared >/dev/null 2>&1 && echo '    túnel ativo' || echo '    AVISO: cloudflared instalado mas não ativo'; \
    else \
        echo '    AVISO: /etc/cloudflared/config.yml ainda não existe — crie o túnel antes (cloudflared tunnel login).'; \
    fi"

fi  # fim do bloco exclusivo de produção

# ------------------------------------------------------------------- backup

info "agendando o backup diário do banco"
no_container "mkdir -p /var/backups/alfamatriz"
no_container "test -f /usr/local/bin/alfamatriz-backup.sh && \
    (crontab -l 2>/dev/null | grep -q alfamatriz-backup || \
     (crontab -l 2>/dev/null; echo '17 3 * * * /usr/local/bin/alfamatriz-backup.sh >> /var/log/alfamatriz-backup.log 2>&1') | crontab -) || \
    echo 'AVISO: /usr/local/bin/alfamatriz-backup.sh ainda não foi enviado — o cron será criado quando ele existir.'"

# ------------------------------------------------------- agendador do Laravel

# Sem esta linha o `Schedule::` de routes/console.php é decoração: nem o retrato
# horário dos sistemas integrados nem o fechamento mensal de competência rodam.
# O executor é chamado a cada minuto e é o próprio Laravel quem decide o que
# está na hora — por isso um único cron cobre todos os agendamentos.
info "agendando o executor de tarefas do Laravel (schedule:run)"
no_container "(crontab -l 2>/dev/null | grep -q 'alfamatriz.*schedule:run' || \
    (crontab -l 2>/dev/null; echo '* * * * * cd $APP_DIR && php artisan schedule:run >> /var/log/alfamatriz-schedule.log 2>&1') | crontab -)"

info "provisionamento de $AMBIENTE concluído (LXC $VMID em $IP)"
echo
echo "próximos passos:"
echo "  1. se o container ainda não estiver no tailnet:"
echo "       pct exec $VMID -- tailscale up --hostname=$NOME"
echo "  2. copie deploy/.env.producao.exemplo para $APP_DIR/.env e preencha os segredos"
echo "     (a senha do banco está em /root/.alfamatriz-db-pass dentro do container)"
echo "  3. deploy/publicar.sh"
echo "  4. deploy/smoke.sh"
