# Tasks: Pesquisa no historico

> feature: pesquisa-no-historico

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

## T-143 — Ampliar a busca das abas de Tarefas para todo texto da tarefa [concluida]
- Refs: US-096, AC-343, AC-344, AC-345, AC-346, AC-347, AC-348, AC-349, AC-350
- Arquivos: app/Http/Controllers/TarefaController.php, resources/views/tarefas/_filtros.blade.php, tests/Feature/TarefasDesenvolvimento/FiltrosTarefasTest.php
- Notas: as condições novas entram DENTRO do `where` aninhado de `aplicarFiltros()` — soltas, o `orWhere` escapa do `whereIn` de status (é a armadilha que o próprio comentário do método documenta, e o AC-349 vigia). Uma tarefa só: é um método, um placeholder e os testes — dividir criaria dependência artificial.
