# Tasks: Desempenho do sistema

> feature: desempenho-do-sistema

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

## T-103 — Permissão resolvida uma vez por requisição [concluida]
- Refs: US-065, AC-236, AC-237, AC-238, AC-239
- Arquivos: app/Models/User.php, app/Http/Middleware/PermissoesDaRequisicao.php, bootstrap/app.php, tests/Feature/Desempenho/PermissaoEmCacheTest.php
- Esforço: medio
- Notas: `canPermissao()` vai ao banco a cada chamada — 17 fixas por página mais
  3 por card do quadro. Carregar as permissões do usuário uma vez e responder da
  memória. O cache vive pela REQUISIÇÃO (é uma propriedade da instância do
  modelo autenticado), não pelo cache da aplicação: permissão que sobrevive ao
  request vira permissão que não se revoga. AC-239 é o portão — nenhuma recusa
  pode afrouxar. **Vem antes de T-106 e T-107:** as 17 consultas de permissão
  entram na contagem dos painéis, e as metas de AC-244/AC-245 supõem esta tarefa
  já feita.

## T-104 — O sino consulta uma vez por requisição [concluida]
- Refs: US-067, AC-243
- Arquivos: app/Providers/AppServiceProvider.php, tests/Feature/Desempenho/SinoUmaVezTest.php
- Esforço: baixo
- Notas: o View composer está registrado para `layouts.navigation` e
  `layouts.notificacoes`, e as duas são desenhadas em toda tela — o closure roda
  duas vezes e repete as duas consultas. Resolver memoizando o resultado dentro
  da requisição, sem desfazer a razão de o composer existir (que é não repetir a
  linha em vinte controllers — ver o comentário no provider).

## T-105 — O quadro para de imprimir um modal por card [concluida]
- Refs: US-066, AC-240, AC-241, AC-242
- Arquivos: resources/views/tarefas/index.blade.php, resources/views/tarefas/_coluna.blade.php, app/Http/Controllers/TarefaController.php, routes/web.php, tests/Feature/Desempenho/QuadroLeveTest.php
- Esforço: alto
- Notas: hoje `_modais` imprime `_form` + comentários + checklist + anexos de
  TODAS as tarefas do quadro, ~45 KB por card. O modal passa a ser buscado no
  clique. Três contratos a preservar: (1) o `de_status` da concorrência continua
  indo em toda ação — ver `OrdemEConcorrenciaTest`; (2) `session('tarefa-aberta')`
  continua reabrindo a tarefa depois de comentar; (3) o bloco `data-modais`
  redesenhado por `respostaParcial` continua fechando o modal após Salvar. Com o
  modal fora do HTML, o eager load de `comentarios.autor`, `anexos.autor` e
  `itens` no quadro passa a servir só aos contadores do card — conferir o que
  ainda é necessário em vez de carregar a conversa inteira de cada tarefa.

## T-106 — Painéis: as séries saem de uma consulta agrupada [concluida]
- Refs: US-068, AC-244, AC-245, AC-246
- Arquivos: app/Services/IndicadoresService.php, app/Http/Controllers/PainelController.php, app/Http/Controllers/CentroControleController.php, tests/Feature/Desempenho/PaineisTest.php
- Esforço: alto
- Notas: `historicoSeisMeses()` refaz mês a mês exatamente o que
  `serieDeEntradas(6)`/`serieDeSaidas(6)` já calcularam — 26 `SUM(valor)`
  idênticos por carregamento —, e `competenciaFoiFaturada()` é perguntado 8
  vezes. Agrupar por mês numa consulta (o `SUBSTR(data, 1, 7)` que
  `serieDeEntrada()` já usa funciona nos dois bancos; `DATE_FORMAT` é só do
  MySQL e a suíte roda em SQLite) e memoizar por competência, como
  `previsaoMemo` já faz. AC-246 é o portão: nenhum número pode mudar. Depende de
  T-103 para a meta de 30 consultas fechar.

## T-107 — Ranking de sistemas sem uma consulta por sistema [concluida]
- Refs: US-068, AC-247
- Arquivos: app/Models/Sistema.php, app/Services/IndicadoresService.php, tests/Feature/Desempenho/RankingSistemasTest.php
- Esforço: medio
- Notas: `rankingSistemas()` chama `mrrEstimado()` e `mrrModulos()` por sistema,
  e cada um consulta os clientes daquele sistema — 2 consultas por produto.
  Carregar os vínculos de uma vez e deixar os dois métodos lerem a relação já
  carregada. `mrrPorRevenda()` também é a origem do painel de detalhe da tela de
  Produtos: o valor precisa continuar idêntico nos dois lugares. Compartilha
  `IndicadoresService.php` com T-106 — as duas rodam em sequência, nunca lado a
  lado.

## T-108 — A previsão de faturamento consulta em bloco [concluida]
- Refs: US-069, AC-248, AC-249
- Arquivos: app/Services/FaturamentoService.php, tests/Feature/Desempenho/PrevisaoEmBlocoTest.php
- Esforço: alto
- Notas: `previsaoDaCompetencia()` é laço aninhado — para cada revenda ativa, uma
  consulta de sistemas; para cada sistema, os clientes, o tier e os módulos.
  Levantar os vínculos ativos de uma vez e agrupar em memória. É cálculo de
  dinheiro: AC-249 exige que total e abertura por revenda saiam idênticos, e a
  regra de vigência de módulo (`ClienteModulo::vigentesNaCompetencia`) tem de
  continuar sendo a mesma que a fatura usa — se as duas divergirem, a prévia
  passa a mentir sobre o que vai ser cobrado.
