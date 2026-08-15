# Tasks: Teste do staging e aviso de bloqueio

> feature: teste-do-staging-e-aviso-de-bloqueio

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

## T-121 — Quem testou entra no relatório de teste [concluida]
- Refs: AC-304
- Arquivos: database/migrations/2026_08_15_150000_quem_testou_no_relatorio_de_teste.php, app/Models/TarefaRelatorioTeste.php
- Notas: coluna `user_id` nullable com FK em `tarefa_relatorios_teste` +
  relação no modelo. Relatórios antigos ficam sem autor de propósito —
  inventar autoria para dado histórico seria mentira. O carimbo do painel de
  mover (`TarefaController::mover`) também passa a gravar quem carimbou.

## T-122 — Registrar o teste do staging sem mover o card [concluida]
- Refs: AC-303, AC-305, AC-306, AC-307, AC-308
- Arquivos: app/Services/FluxoTarefaService.php, app/Http/Controllers/TarefaController.php, routes/web.php, tests/Feature/TarefasDesenvolvimento/RegistrarTesteDoStagingTest.php
- Notas: método no `FluxoTarefaService` (o motor valida: só desenvolvimento em
  `em_staging`; reprovado exige notas) + rota POST própria atrás de
  `permissao:tarefas,editar`, SEM passar por `motivoParaNaoMover` — registrar
  teste é como perguntar/bloquear: restringir impediria de REGISTRAR, não de
  trabalhar. Notifica o responsável com o veredito (padrão das notificações de
  retorno/pergunta). Depende de T-121 (autor do relatório). O portão
  `aprovadaNestaPassagem` já lê o relatório do evento aberto — AC-305 não pede
  código novo, pede prova.

## T-123 — O bloqueio avisa no sino [concluida]
- Refs: AC-309, AC-310
- Arquivos: app/Services/FluxoTarefaService.php, app/Models/User.php, tests/Feature/TarefasDesenvolvimento/AvisoDeBloqueioTest.php
- Notas: `FluxoTarefaService::bloquear` passa a notificar responsável + quem
  triaga, menos quem bloqueou, sem duplicar (responsável que também triaga
  recebe um aviso só). Escopo/consulta "quem triaga" no `User` (capacidade
  `tarefas_triagem`, seguindo o padrão de `podeTriarTarefas`). Mesmo arquivo de
  serviço que T-122 — rodar em sequência.

## T-124 — Comando do portão do staging [concluida]
- Refs: AC-311, AC-312, AC-313
- Arquivos: app/Console/Commands/PortaoDoStaging.php, tests/Feature/TarefasDesenvolvimento/PortaoDoStagingTest.php
- Notas: `php artisan alfa:portao-staging {reprovou|passou}` (prefixo `alfa:`
  como os demais commands do repo). Motivo padrão
  do portão vive numa constante (é a assinatura que o `passou` procura para
  destravar — ASM-078). `reprovou` bloqueia via `FluxoTarefaService::bloquear`
  (para o aviso de T-123 sair de graça), pulando as já bloqueadas (AC-312);
  `passou` destrava só quem tem o motivo padrão. Depende de T-123.

## T-125 — O deploy chama o portão nos dois vereditos [concluida]
- Refs: AC-314
- Arquivos: deploy/deploy-staging-alfamatriz.sh, tests/Feature/TarefasDesenvolvimento/GanchoDoDeployTest.php
- Notas: chamada best-effort (`|| log`, nunca derruba o deploy) nos dois
  ramos do veredito, apontando para o quadro de PRODUÇÃO — `pct exec 115` na
  cópia `atual/` (ASM-076/ASM-077; conferir o caminho antes de fechar). A
  cópia do script para o host é passo manual de deploy, fora desta tarefa. O
  teste confere o contrato: o nome do comando aparece nos dois ramos do
  script.

## T-126 — A ação na tela e o autor no histórico [concluida]
- Refs: AC-303, AC-304
- Arquivos: resources/views/tarefas/_avisos-da-tarefa.blade.php, resources/views/tarefas/_checklist-envios.blade.php, resources/views/tarefas/historico.blade.php, app/Models/Tarefa.php, app/Services/FluxoTarefaService.php, app/Http/Controllers/TarefaController.php
- Notas: banner do teste nos avisos do modal (padrões visuais existentes —
  nenhum valor novo), com DOIS envios ocultos em `_checklist-envios`, um por
  veredito: o caminho parcial monta `FormData(form)` sem o submitter e o value
  do botão que enviou se perderia. O recorte "desta passagem" virou
  `Tarefa::testeDestaPassagem()` — o portão do serviço e a tarja fazem a mesma
  pergunta, e duas cópias divergiriam. O histórico mostra "por Fulano" ao lado
  do veredito (eager load `relatoriosTeste.autor`). Depende de T-122 (rota).
