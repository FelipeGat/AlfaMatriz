# Tasks: Fluxo de deploy no padrão da casa (staging + produção por tag)

> feature: fluxo-deploy

## T-031 — Cadastro do AlfaMatriz no inventário do painel [concluida]
- Refs: US-016, AC-032, AC-033
- Arquivos: deploy/alfadeploy-systems-alfamatriz.toml, tests/Feature/FluxoDeploy/InventarioPainelTest.php
- Notas: bloco `[[systems]]` no formato do `config/systems.toml` do AlfaDeploy,
  versionado aqui para sobreviver a uma reinstalação (mesma ideia do `infra/`
  do painel). Traz name/key/lxc/ssh_host/dir/stack/health, e OMITE de propósito
  `mysql_container`, `user_table`, `email_col`, `pass_col`, `admin_id` e
  `seed_domain` — sem eles a re-anonimização não tem alvo.

## T-032 — Executor do staging com portão de testes [concluida]
- Refs: US-017, AC-034, AC-035
- Arquivos: deploy/deploy-staging-alfamatriz.sh, tests/Feature/FluxoDeploy/ExecutorStagingTest.php
- Notas: função equivalente ao `deploy_alfahome` do `/usr/local/bin/deploy-staging`,
  mas sem Docker e sem GHCR: roda direto no LXC do staging. A suíte de testes é
  o portão — falhou, não aplica nada e sai != 0. Idempotente: sem novidade na
  `main`, não faz nada.

## T-033 — Vigia de tag para produção [concluida]
- Refs: US-018, AC-036, AC-037
- Arquivos: deploy/deploy-tag-watcher-alfamatriz.sh, tests/Feature/FluxoDeploy/VigiaTagTest.php
- Notas: molde do `infra/prod-alfahome/deploy-tag-watcher.sh`. Só aplica tag
  `v*` mais recente; backup do banco antes das migrações (reaproveita o
  `backup.sh`), health-check depois, e marcador de falha para não repetir em
  cima de sistema quebrado. Publica o estado num JSON que o painel lê.

## T-034 — Provisionar o container de staging [concluida]
- Refs: US-017, AC-034
- Arquivos: deploy/provisionar.sh, tests/Feature/FluxoDeploy/ProvisionarStagingTest.php
- Notas: o `provisionar.sh` ganha `--ambiente staging|producao`, criando o LXC
  116 `alfamatriz-staging` (10.0.3.116) sem túnel Cloudflare e sem Funnel — o
  staging vive só no tailnet. Produção segue exatamente como está hoje.

## T-035 — Verificação automática no GitHub [concluida]
- Refs: US-017, AC-035
- Arquivos: .github/workflows/testes.yml, tests/Feature/FluxoDeploy/VerificacaoGithubTest.php
- Notas: roda a suíte a cada push e em cada tag. Não é o portão do staging (esse
  é local, T-032), mas dá o mesmo sinal verde/vermelho que os outros sistemas
  têm no GitHub antes de alguém marcar versão.

## T-036 — Cópia embaralhada da produção para o staging [concluida]
- Refs: US-019, AC-038
- Arquivos: deploy/preparar-staging.sh, tests/Feature/FluxoDeploy/CopiaEmbaralhadaTest.php
- Notas: pega o dump mais recente do `backup.sh`, restaura numa base de staging
  e troca nome, e-mail, telefone e CNPJ de clientes por dados falsos, com senha
  de teste conhecida. Valores de faturamento e financeiro NÃO são alterados —
  é o volume real que faz o staging valer. O script precisa ser testável contra
  um banco descartável, não só contra produção.

## T-037 — Instalar e conferir no servidor [concluida]
- Refs: US-016, US-017, US-018, AC-032, AC-034, AC-036
- Arquivos: README.md
- Notas: execução real — criar o LXC de staging, popular o banco com a cópia
  embaralhada, instalar o executor e o vigia no host, cadastrar no painel do
  AlfaDeploy e conferir que o AlfaMatriz aparece na tabela. Nunca em paralelo:
  depende de todas as anteriores.
