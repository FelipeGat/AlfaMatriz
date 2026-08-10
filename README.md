# AlfaMatriz

Painel interno da Alfa Tecnologia. Controla o negócio de software house: revendas (ex.: Invest Soluções), clientes finais, os sistemas licenciados (AlfaGym, AlfaControl, AlfaHome, AlfaMed, AlfaJornada, AlfaSchool, Gestor), preço de atacado por tier, motor de faturamento mensal das revendas e o financeiro da própria Alfa (receitas, despesas, despesas fixas, caixa).

Não é um produto vendido — é a matriz que enxerga e cobra as revendas dos demais produtos Alfa.

## Stack

- Laravel 12 + Blade + Tailwind (tema dark, marca Alfa)
- MySQL via Docker (`docker-compose.yml`)
- Alpine.js pra interatividade leve (sem SPA)

### Cuidado ao trazer campo de integração

A atribuição em massa do Eloquent **descarta em silêncio** o que não está no
`$fillable` do modelo — sem erro, sem aviso. Foi assim que o e-mail e o telefone
que o AlfaGym manda sumiram por meses: o sincronizador entregava os dois para
`Cliente::create()` e a tabela `clientes` nem tem essas colunas (contato mora em
`cliente_emails` e `cliente_telefones`).

Ao ligar um campo novo vindo de integração, confira contra o `$fillable` e as
colunas de verdade antes de confiar que ele chegou. Ligar
`Model::preventSilentlyDiscardingAttributes()` no projeto inteiro resolveria a
classe do problema, mas mexe em todos os modelos e ainda não foi avaliado.

## Módulos

- **Painéis**: Financeiro (MRR, caixa, entradas/saídas) e Comercial (ranking de sistemas por clientes/valor)
- **Cadastros**: Revendas, Clientes (com endereço completo, e-mails/telefones múltiplos, busca de CNPJ/CEP), Sistemas + tiers de atacado
- **Faturamento**: gera 1 cobrança consolidada por revenda/mês, baseada nos clientes ativos de cada sistema
- **Financeiro**: Receitas, Despesas, Despesas Fixas (recorrentes), Caixa/Contas financeiras
- Fechamento mensal automatizado: `php artisan app:fechar-competencia-mensal` (agendado pro último dia do mês)

## Setup local

```bash
composer install
cp .env.example .env
php artisan key:generate
docker compose up -d
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Login inicial **do ambiente local**: `admin@alfatecnologia.com.br` / `AlfaTecnologia@2026`.

Essa senha vale só fora de produção. No ambiente publicado, a carga inicial
exige `ADMIN_PASSWORD` no `.env` e falha se ela não estiver definida — a senha
acima é pública neste README e nunca pode ser a senha do painel na internet.

## Deploy no alfa-server

URL pública: **https://matriz.alfasolucoes.cloud** (túnel Cloudflare)

Acesso de emergência, só de dentro do tailnet da empresa:
`https://alfamatriz.tail0939dd.ts.net` — serve para entrar se a Cloudflare
ficar indisponível. Não responde para quem está fora do Tailscale.

O sistema roda num container LXC dedicado (`alfamatriz`, VMID 115, Debian 12)
no alfa-server (Proxmox). Os scripts abaixo cobrem provisionar, publicar,
conferir e proteger os dados — cada um pensado pra rodar de novo sem quebrar
o que já existe.

### Provisionar o container (primeira vez, ou de novo se preciso)

```bash
deploy/provisionar.sh
```

Cria o LXC 115, instala PHP 8.2 + Nginx + MariaDB + Tailscale e publica o
Funnel na porta 443. Rodar de novo sobre um servidor já provisionado não
recria o container nem apaga o banco — só confere que está tudo no lugar.

### Publicar uma nova versão

```bash
deploy/publicar.sh
```

Busca o código, instala as dependências de produção, compila o front-end,
aplica as migrações e recarrega os caches. Para na primeira etapa que falhar,
avisando qual foi — não deixa o servidor pela metade.

Antes do primeiro deploy, copiar `deploy/.env.producao.exemplo` para `.env`
no servidor, preencher os segredos (em especial `ADMIN_PASSWORD`, exigido
pela carga inicial) e rodar `php artisan migrate --seed` uma vez.

Contas além do admin são criadas depois, no servidor, com:

```bash
php artisan alfa:criar-usuario "Nome da pessoa" email@alfatecnologia.com.br
```

### Conferir depois de publicar

```bash
deploy/smoke.sh
```

Confere que a URL pública responde em HTTPS, que `/healthz` volta 200, que a
tela de login abre e que a tela de cadastro está fechada (404). Sai com
sucesso só se as quatro checagens passarem; senão lista qual falhou.

### Backup e restauração

O cron do servidor roda `deploy/backup.sh` uma vez por dia: grava um dump
compactado e datado do banco em `/var/backups/alfamatriz` e mantém só as
sete cópias mais recentes.

Para restaurar um backup (com o servidor já provisionado):

```bash
deploy/restaurar.sh --arquivo /var/backups/alfamatriz/AAAA-MM-DD.sql.gz --confirmo
```

O script recusa rodar sem `--confirmo` ou apontando pra um arquivo que não
existe — não tem como sobrescrever o banco de produção por engano.
