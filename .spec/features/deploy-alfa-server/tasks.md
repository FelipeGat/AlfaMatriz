# Tasks: Deploy no alfa-server com acesso externo

> feature: deploy-alfa-server

## T-001 — Desativar o cadastro público [concluida]
- Refs: US-001, AC-001
- Arquivos: routes/auth.php, tests/Feature/Deploy/RegistroDesativadoTest.php
- Notas: remover as rotas `GET`/`POST /register` e o link "Registrar" das telas
  de convidado, se houver. O controller e as views ficam no repositório (não é
  limpeza de código, é fechar a porta). Teste confirma 404 nas duas rotas.

## T-002 — Comando administrativo de criação de usuário [pendente]
- Refs: US-001, AC-002
- Arquivos: app/Console/Commands/CriarUsuario.php, tests/Feature/Deploy/CriarUsuarioCommandTest.php
- Notas: `php artisan alfa:criar-usuario {nome} {email} {--senha=}`. Recusa
  e-mail já existente com mensagem clara e saída != 0, sem sobrescrever senha.
  Marca o e-mail como verificado (o painel usa middleware `verified`).

## T-003 — Bloqueio por tentativas repetidas de login [pendente]
- Refs: US-001, AC-003
- Arquivos: tests/Feature/Deploy/LoginThrottleTest.php
- Notas: o Breeze já traz o limitador em `LoginRequest`; esta tarefa prova que
  ele vale na configuração publicada (6ª tentativa é recusada com aviso de
  espera). Se o teste mostrar que não vale, aí sim ajustar a regra.

## T-004 — HTTPS correto atrás do Funnel [pendente]
- Refs: US-002, AC-004
- Arquivos: bootstrap/app.php, app/Providers/AppServiceProvider.php, tests/Feature/Deploy/HttpsAtrasDoProxyTest.php
- Notas: confiar no proxy local (o Funnel entrega em 127.0.0.1) e forçar
  esquema `https` quando `APP_ENV=production`. Sem isso, links e cookie de
  sessão saem em `http` e o login quebra na URL pública.

## T-005 — Checagem de saúde sem login [pendente]
- Refs: US-002, AC-005
- Arquivos: routes/web.php, app/Http/Controllers/SaudeController.php, tests/Feature/Deploy/SaudeTest.php
- Notas: `/healthz` fora do grupo autenticado, responde JSON com estado do app
  e do banco. Não expõe versão, caminho de arquivo nem dado de negócio.

## T-006 — Senha do admin vinda do ambiente [pendente]
- Refs: US-003, AC-006
- Arquivos: database/seeders/DadosIniciaisSeeder.php, tests/Feature/Deploy/SeederSenhaAdminTest.php
- Notas: a senha `AlfaTecnologia@2026` está publicada no README — não pode
  virar a senha de produção. Em `production`, sem `ADMIN_PASSWORD` definida a
  carga falha; fora de produção mantém o padrão atual para não quebrar o setup
  local. Atualizar o README junto.

## T-007 — Modelo de ambiente de produção endurecido [pendente]
- Refs: US-003, AC-007
- Arquivos: deploy/.env.producao.exemplo, tests/Feature/Deploy/AmbienteProducaoTest.php
- Notas: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`,
  `SESSION_SECURE_COOKIE=true`, `LOG_LEVEL=warning`, banco apontando para o
  MariaDB local do container. Sem segredo real no arquivo — é modelo.

## T-008 — Script de provisionamento do container [pendente]
- Refs: US-004, AC-008
- Arquivos: deploy/provisionar.sh, deploy/nginx-alfamatriz.conf, tests/Feature/Deploy/ScriptProvisionarTest.php
- Notas: roda no host Proxmox. Cria o LXC 115 `alfamatriz` (Debian 12,
  10.0.3.115, com `/dev/net/tun` para o Tailscale, igual ao padrão dos outros
  containers), instala PHP 8.2 + Nginx + MariaDB + Tailscale, e publica o
  Funnel na porta 443. Idempotente: se o container já existe, segue sem
  recriar nem apagar o banco.

## T-009 — Script de publicação da aplicação [concluida]
- Refs: US-004, AC-009
- Arquivos: deploy/publicar.sh, tests/Feature/Deploy/ScriptPublicarTest.php
- Notas: `set -euo pipefail`; composer sem dev, `npm ci && npm run build`,
  `migrate --force`, `config/route/view:cache`, reload do PHP-FPM. Para na
  primeira etapa que falhar, com mensagem dizendo qual.

## T-010 — Script de conferência pós-deploy [concluida]
- Refs: US-004, AC-010
- Arquivos: deploy/smoke.sh, tests/Feature/Deploy/ScriptSmokeTest.php
- Notas: confere HTTPS na URL pública, `/healthz` = 200, tela de login = 200 e
  cadastro = 404. Sai 0 só com as quatro passando; senão lista qual falhou.

## T-011 — Backup diário com retenção de sete dias [pendente]
- Refs: US-005, AC-011
- Arquivos: deploy/backup.sh, tests/Feature/Deploy/ScriptBackupTest.php
- Notas: dump compactado e datado, agendado por cron no container. A lógica de
  retenção precisa ser testável de verdade — aceitar `--dir` para o teste rodar
  o script num diretório temporário com arquivos falsos e conferir que sobram
  as sete cópias mais recentes.

## T-012 — Restauração protegida [pendente]
- Refs: US-005, AC-012
- Arquivos: deploy/restaurar.sh, tests/Feature/Deploy/ScriptRestaurarTest.php
- Notas: exige `--confirmo` e um arquivo existente. Sem os dois, recusa com
  saída != 0 e não toca no banco.

## T-013 — Provisionar, publicar e conferir no alfa-server [pendente]
- Refs: US-004, AC-008, AC-009, AC-010, AC-011
- Arquivos: README.md
- Notas: execução real — rodar T-008, T-009 e T-010 contra o alfa-server, criar
  o admin com senha forte, ativar o cron do backup e registrar no README a URL
  pública e o procedimento. Depende de todas as anteriores; nunca em paralelo.
