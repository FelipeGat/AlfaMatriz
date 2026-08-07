# Tasks: Infra agendamento

> feature: infra-agendamento

<!--
  Como ler este arquivo (o formato é verificado por `onp-spec audit`):
  - T-xxx = tarefa (código de rastreio, único no projeto inteiro).
  - Toda tarefa referencia em `Refs:` pelo menos uma história de usuário
    (US-xxx) ou critério de aceite (AC-xxx).
  - Toda tarefa lista os arquivos que cria/altera em `Arquivos:` — capriche:
    é o que decide o que `onp-spec plano` roda em PARALELO (arquivos
    disjuntos) e o que roda em sequência.
  - Campos opcionais por tarefa, usados pelo plano de execução:
    `- Modelo: claude-sonnet-5` e `- Esforço: alto` (baixo|medio|alto|xalto|max).
  - Uma tarefa só pode virar [concluida] quando os critérios de aceite dela
    tiverem prova PASS registrada por `onp-spec verify`.
  Status: pendente | em-andamento | concluida
    (atalho: `onp-spec tarefa <feature> <T-xxx> <status>`)
-->

## Contrato compartilhado

Vale para todas as tarefas desta feature:

- Os testes de infraestrutura **leem o conteúdo dos scripts**, no mesmo molde de
  `tests/Feature/Deploy/ScriptProvisionarTest.php` e `ScriptPublicarTest.php`. Eles executam o
  script com falsos no `PATH` e conferem o que teria sido feito. Nunca tocam no servidor real.
- **Nenhum binário novo pode ser exigido do host.** Os falsos dos testes existentes só cobrem
  `cp`, `scp`, `pct`, `grep`, `ssh`. Nada de `install`, `tee`, `envsubst`.
- **Nenhuma mensagem nova pode conter a frase "instalando o túnel Cloudflare"** —
  `tests/Feature/FluxoDeploy/ProvisionarStagingTest.php` assere a ausência dela no staging.
- Tudo que o provisionamento faz precisa continuar **idempotente**: rodar duas vezes não pode
  quebrar nada (`tests/Feature/Deploy/ScriptProvisionarTest.php` já cobre isso).
- Nenhum segredo literal em código (princípio P-002). Valores sensíveis só como campo a
  preencher no modelo de ambiente.

## T-053 — Conferir o fechamento mensal antes de ligar o agendamento [pendente]

- Refs: US-032
- Arquivos: README.md
- Notas: Fecha ASM-027. O fechamento mensal está agendado desde sempre e nunca teve quem o
  disparasse; ligar o agendamento na T-054 faria a primeira execução acontecer sozinha, sobre
  uma competência que ninguém conferiu. Executar `php artisan app:fechar-competencia-mensal`
  à mão no servidor, conferir o que foi gerado, e só então seguir. Os serviços são idempotentes,
  então depois disso o agendamento é inócuo. Documentar o procedimento na seção de operação do
  README. **Esta tarefa precede a T-054 obrigatoriamente.**

## T-054 — Agendamento de sistema chamando as rotinas do painel [pendente]

- Refs: AC-071
- Arquivos: deploy/provisionar.sh, tests/Feature/Infra/AgendamentoTest.php
- Notas: Criar `/etc/cron.d/alfamatriz-schedule` sobrescrevendo o arquivo — é idempotente por
  construção, ao contrário do `crontab -l | ... | crontab -` usado no bloco de backup. Nome sem
  ponto e modo 0644 são exigência do cron. A linha `PATH=` completa e o interpretador pelo
  caminho absoluto vêm do aprendizado já registrado em `deploy/deploy-staging-alfamatriz.sh`:
  o cron roda com caminho mínimo e falha em silêncio. Roda como o usuário do aplicativo, não
  como administrador — ver T-055.

## T-055 — Configuração legível pelo aplicativo e pastas de trabalho no dono certo [pendente]

- Refs: AC-072
- Arquivos: deploy/provisionar.sh, tests/Feature/Infra/PermissoesDoAplicativoTest.php
- Notas: Fecha ASM-029. Hoje o arquivo de configuração é exclusivo do administrador; as rotinas
  de fundo só funcionariam por causa do cache de configuração e quebrariam na primeira limpeza.
  Rodá-las como administrador também não serve: deixaria arquivos nas pastas de trabalho que o
  servidor web não consegue reabrir. Passar para leitura pelo grupo do usuário do aplicativo, e
  garantir o dono das pastas de trabalho e de cache. Tudo idempotente.

## T-056 — Serviço permanente que consome a fila [pendente]

- Refs: AC-073
- Arquivos: deploy/alfamatriz-queue.service, deploy/provisionar.sh, tests/Feature/Infra/ExecutorDeFilaTest.php
- Notas: Instalar pela mesma mecânica já usada para a configuração do servidor web em
  `deploy/provisionar.sh` (cópia para área temporária + envio para dentro do container) — é o
  que mantém os falsos dos testes existentes válidos sem exigir binário novo. O serviço se
  reergue ao cair e se recicla de tempos em tempos, para código velho não ficar rodando
  indefinidamente. Depende da T-055: sem a permissão de leitura, o serviço não sobe.

## T-057 — Publicar avisa o executor da fila a pegar o código novo [pendente]

- Refs: AC-074
- Arquivos: deploy/publicar.sh, tests/Feature/Deploy/ScriptPublicarTest.php
- Notas: Entra entre a regravação dos caches e o recarregamento do servidor web. O aviso é
  gravado no cache e é seguro mesmo quando nenhum executor está rodando. O teste existente
  assere ordem relativa entre etapas nomeadas — a etapa nova precisa manter a ordem já provada.

## T-058 — Envio de e-mail real no ambiente publicado e comando de conferência [pendente]

- Refs: AC-075, AC-076, AC-077
- Arquivos: deploy/.env.producao.exemplo, app/Console/Commands/TestarEmail.php, tests/Feature/Deploy/AmbienteProducaoTest.php, tests/Feature/Infra/TesteDeEmailTest.php
- Notas: Fecha ASM-028 e responde Q-011. O modelo de ambiente troca o destino de arquivo de log
  para serviço de envio real (Google Workspace, porta 587), e ganha os campos a preencher da
  senha do e-mail e do token do provedor de hospedagem — este último já entra aqui para a
  feature `hospedagens-avisos` encontrar o terreno pronto. O comando de conferência imprime meio,
  servidor e remetente **antes** de tentar enviar, para um envio engavetado por engano aparecer
  na hora. O teste do ambiente publicado ganha os dois segredos novos na lista do que não pode
  vir preenchido. Se a porta 587 estiver bloqueada, o plano B é a porta 465 — registrar no README.
