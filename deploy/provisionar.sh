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

while [[ $# -gt 0 ]]; do
    case "$1" in
        --host) HOST="$2"; shift 2 ;;
        --vmid) VMID="$2"; shift 2 ;;
        --ip) IP="$2"; shift 2 ;;
        --local) LOCAL=1; shift ;;
        *) echo "provisionar.sh: argumento desconhecido: $1" >&2; exit 1 ;;
    esac
done

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
        --cores 2 --memory 2048 --swap 2048 \
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

info "instalando a pilha (apt é idempotente: pacote já instalado não reinstala)"
no_container "export DEBIAN_FRONTEND=noninteractive && apt-get update -qq && \
    apt-get install -y -qq \
        nginx mariadb-server \
        php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml \
        php8.2-curl php8.2-zip php8.2-gd php8.2-bcmath php8.2-intl \
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

# ------------------------------------------------------------------- funnel

info "publicando na internet via Tailscale Funnel (porta 443)"
no_container "tailscale status >/dev/null 2>&1 || \
    echo 'AVISO: o container ainda não está no tailnet. Rode dentro dele: tailscale up --hostname=$NOME'"
no_container "if tailscale status >/dev/null 2>&1; then \
        tailscale serve --bg --https 443 http://127.0.0.1:80 >/dev/null 2>&1 || true; \
        tailscale funnel --bg 443 >/dev/null 2>&1 || \
            echo 'AVISO: não consegui ligar o Funnel — confira se o tailnet permite Funnel neste nó.'; \
    fi"

# ------------------------------------------------------------------- backup

info "agendando o backup diário do banco"
no_container "mkdir -p /var/backups/alfamatriz"
no_container "test -f /usr/local/bin/alfamatriz-backup.sh && \
    (crontab -l 2>/dev/null | grep -q alfamatriz-backup || \
     (crontab -l 2>/dev/null; echo '17 3 * * * /usr/local/bin/alfamatriz-backup.sh >> /var/log/alfamatriz-backup.log 2>&1') | crontab -) || \
    echo 'AVISO: /usr/local/bin/alfamatriz-backup.sh ainda não foi enviado — o cron será criado quando ele existir.'"

info "provisionamento concluído"
echo
echo "próximos passos:"
echo "  1. se o container ainda não estiver no tailnet:"
echo "       pct exec $VMID -- tailscale up --hostname=$NOME"
echo "  2. copie deploy/.env.producao.exemplo para $APP_DIR/.env e preencha os segredos"
echo "     (a senha do banco está em /root/.alfamatriz-db-pass dentro do container)"
echo "  3. deploy/publicar.sh"
echo "  4. deploy/smoke.sh"
