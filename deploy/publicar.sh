#!/usr/bin/env bash
#
# Publica uma nova versão do AlfaMatriz no container já provisionado.
#
# Uso:
#   deploy/publicar.sh [--dir CAMINHO]
#
# --dir CAMINHO   raiz da aplicação (padrão: /var/www/alfamatriz).
#                  Existe para permitir testar o script contra um diretório
#                  temporário, sem tocar na instalação real.
#
# Traz o código, instala as dependências de produção, compila o front-end,
# aplica as migrações e recarrega os caches. Para na primeira etapa que
# falhar, avisando qual foi.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/alfamatriz}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir)
            APP_DIR="$2"
            shift 2
            ;;
        *)
            echo "publicar.sh: argumento desconhecido: $1" >&2
            exit 1
            ;;
    esac
done

cd "$APP_DIR"

etapa() {
    echo "==> $1"
}

falhar() {
    echo "publicar.sh: falhou na etapa \"$1\" — publicação interrompida." >&2
    exit 1
}

etapa "Buscando código"
git pull --ff-only || falhar "buscar código"

etapa "Instalando dependências PHP de produção"
composer install --no-dev --optimize-autoloader --no-interaction || falhar "instalar dependências PHP"

etapa "Instalando dependências do front-end"
npm ci || falhar "instalar dependências do front-end"

etapa "Compilando front-end"
npm run build || falhar "compilar front-end"

etapa "Aplicando migrações"
php artisan migrate --force || falhar "aplicar migrações"

etapa "Recarregando cache de configuração"
php artisan config:cache || falhar "recarregar cache de configuração"

etapa "Recarregando cache de rotas"
php artisan route:cache || falhar "recarregar cache de rotas"

etapa "Recarregando cache de views"
php artisan view:cache || falhar "recarregar cache de views"

etapa "Recarregando PHP-FPM"
sudo systemctl reload php8.2-fpm || falhar "recarregar PHP-FPM"

echo "publicar.sh: publicação concluída com sucesso"
