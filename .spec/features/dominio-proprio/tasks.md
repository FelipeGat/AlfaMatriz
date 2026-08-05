# Tasks: Domínio próprio da empresa

> feature: dominio-proprio

## T-023 — Apontar o ambiente para o domínio da empresa [concluida]
- Refs: US-011, AC-025
- Arquivos: deploy/.env.producao.exemplo, tests/Feature/Dominio/EnderecoProprioTest.php
- Notas: `APP_URL=https://matriz.alfasolucoes.cloud` no modelo e no `.env` do
  servidor, com `config:cache` refeito. O teste prova que o modelo aponta para
  o domínio da empresa e que os links gerados seguem o `APP_URL`.

## T-024 — Túnel Cloudflare no container [concluida]
- Refs: US-011, AC-024
- Arquivos: deploy/cloudflared-alfamatriz.yml, deploy/provisionar.sh, tests/Feature/Dominio/ConfigTunelTest.php
- Notas: mesmo padrão do `alfahome-prod` — `cloudflared` no container 115, túnel
  próprio, ingress `matriz.alfasolucoes.cloud → http://localhost:80` e um
  `http_status:404` no fim. O provisionamento passa a instalar e habilitar o
  serviço. A autenticação na Cloudflare é passo manual do dono.

## T-025 — Conferência apontando para o domínio [concluida]
- Refs: US-011, AC-024
- Arquivos: deploy/smoke.sh, tests/Feature/Deploy/ScriptSmokeTest.php
- Notas: a URL padrão do `smoke.sh` passa a ser `https://matriz.alfasolucoes.cloud`.
  As quatro checagens continuam as mesmas (HTTPS, saúde 200, login 200,
  cadastro 404), já que o Access ficou fora desta entrega.

## T-026 — Tirar o Funnel da internet, mantendo o tailnet [concluida]
- Refs: US-013, AC-028
- Arquivos: deploy/provisionar.sh, tests/Feature/Dominio/ExposicaoUnicaTest.php
- Notas: `tailscale funnel off` mantendo o `serve` — o `.ts.net` continua
  abrindo de dentro do tailnet. O provisionamento não pode voltar a ligar o
  Funnel numa próxima execução. Só rodar depois de AC-024 confirmado ao vivo.

## T-027 — Aplicar no servidor e conferir de fora [pendente]

- Refs: US-011, US-013, AC-024, AC-025, AC-028
- Arquivos: README.md
- Notas: execução real — criar o túnel, publicar o DNS, rodar a conferência e
  confirmar de fora do tailnet. Registrar o novo endereço no README. Nunca em
  paralelo: depende de todas as anteriores.
