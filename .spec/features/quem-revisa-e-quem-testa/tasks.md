# Tasks: Quem revisa e quem testa

> feature: quem-revisa-e-quem-testa

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

## T-127 — O motor: apontar ao mover, recomeçar ao entrar [concluida]
- Refs: AC-316, AC-317, AC-318, AC-319
- Arquivos: app/Services/FluxoTarefaService.php, app/Http/Controllers/TarefaController.php, tests/Feature/TarefasDesenvolvimento/QuemRevisaEQuemTestaTest.php
- Notas: `mover` aceita `interlocutor_id` opcional nos `$dados`. Entrada de
  tarefa de desenvolvimento em `em_revisao`/`em_staging`: `interlocutor_id` =
  apontado ?? null e `rodadas` = 0 (ASM-079 — cada portão recomeça). Apontar o
  próprio responsável VALE (AC-319/Q-039 — revisado no primeiro dia de uso: é
  o "dev valida" de sempre); apontado só vale nas entradas desses dois
  portões. Aviso ao apontado no padrão do sino (`Notificacao::avisar` já cala
  quando quem move aponta a si mesmo). AC-317 não pede código: `outroLadoDe`
  já prefere o interlocutor quando o responsável pergunta — pede prova.

## T-128 — O seletor no painel do movimento [concluida]
- Refs: AC-315
- Arquivos: resources/views/tarefas/_painel-motivo.blade.php, resources/views/tarefas/index.blade.php, tests/Feature/TarefasDesenvolvimento/QuemRevisaEQuemTestaTest.php
- Notas: o painel de confirmação das entradas em `em_revisao`/`em_staging`
  ganha o seletor opcional ("Quem revisa?" / "Quem testa?"), com a mesma
  lista de pessoas do seletor de responsável (ASM-080) e sem tornar nada
  obrigatório. Demais transições ficam como estão. Arrasto não abre painel
  (Q-038). Depende de T-127 (campo aceito pela rota).

## T-129 — A tarja do teste nomeia o testador [concluida]
- Refs: AC-320
- Arquivos: resources/views/tarefas/_avisos-da-tarefa.blade.php, tests/Feature/TarefasDesenvolvimento/QuemRevisaEQuemTestaTest.php
- Notas: em Em staging sem teste desta passagem e com interlocutor apontado, a
  tarja diz "aguardando o teste de {nome}"; sem apontado, o texto genérico de
  hoje. Depende de T-127 (o apontamento chegar ao interlocutor).
