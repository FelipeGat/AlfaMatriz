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

### Publicar uma nova versão (azul/verde)

```bash
deploy/publicar.sh --ref v2026.08.12
```

A instalação tem **duas cópias completas** da aplicação e dois symlinks:

```
/var/www/alfamatriz/
├── versoes/azul/        cópia completa da aplicação
├── versoes/verde/       a outra
├── compartilhado/       .env e anexos — não pertencem a nenhuma versão
├── atual   -> versoes/…  o que o Nginx serve na porta 80
├── preparo -> versoes/…  a outra, servida só em 127.0.0.1:8081
└── .git, deploy/, marcadores do deploy
```

Publicar é montar a versão nova **inteira** na cópia que não está no ar
(código, dependências, front-end, caches), perguntar a saúde dela pela porta de
ensaio e só então apontar `atual` para ela — um `rename` de symlink, invisível
para quem está usando. É o mesmo desenho do AlfaControl, com "duas cópias +
symlink" no lugar de "dois containers + upstream do Nginx".

Falhando em qualquer etapa antes da troca, a produção não chega a ser tocada.
Falhando a saúde **depois** da troca, a versão anterior volta sozinha.

Duas coisas que o esquema não resolve, e é bom saber antes de marcar uma versão:

- **O banco é um só.** A migração roda antes da troca e continua aplicada se a
  troca for desfeita. Migração precisa ser compatível com a versão anterior:
  acrescentar numa versão, remover em outra.
- **A versão anterior é uma só.** Voltar duas vezes seguidas não existe.

Antes do primeiro deploy, preencher `/var/www/alfamatriz/compartilhado/.env`
com os segredos (em especial `ADMIN_PASSWORD`, exigido pela carga inicial) e
rodar `php artisan migrate --seed` uma vez.

### Voltar para a versão anterior

```bash
alfamatriz-voltar.sh          # no servidor; ou deploy/voltar.sh
```

Troca os symlinks de volta. A versão anterior está inteira no disco, com
dependências e caches quentes — a volta leva ~1 segundo, não os ~2 minutos de
reconstruir tudo. Depois de voltar, a esteira fica **bloqueada**
(`.deploy-tag-failed`): sem isso o vigia traria a mesma versão de volta em
cinco minutos. Para liberar, corrija, marque uma versão nova e apague o
marcador.

O banco não volta junto. Estrago no banco é `deploy/restaurar.sh`.

### Converter um servidor do formato antigo

```bash
deploy/converter-para-azul-verde.sh
```

Roda sozinho pelo `provisionar.sh` e é idempotente. Constrói tudo ao lado — o
segredo, os anexos e a versão que está no ar são preservados, e o site continua
respondendo durante a conversão inteira.

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
