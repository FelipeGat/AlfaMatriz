# Tasks: Tarefas de desenvolvimento

> feature: tarefas-desenvolvimento

<!--
  A ordem aqui é a ordem de execução: esta feature é uma fatia vertical e as
  partes se empilham (o serviço de fluxo precisa do model; as rotas precisam
  do controller; o card e o formulário são incluídos pela tela do quadro).
  Por isso as tarefas compartilham `app/Http/Controllers/TarefaController.php`
  e `resources/views/tarefas/index.blade.php` de propósito — é o que impede o
  planejador de mandá-las para faixas paralelas que quebrariam entre si.
-->

## T-058 — Estrutura de dados: tarefa, evento de etapa e relatório de teste [concluida]
- Refs: US-036, AC-083, AC-084, AC-091
- Arquivos: database/migrations/2026_08_10_090000_criar_tarefas_desenvolvimento.php, app/Models/Tarefa.php, app/Models/TarefaEvento.php, app/Models/TarefaRelatorioTeste.php, database/factories/TarefaFactory.php, tests/Feature/TarefasDesenvolvimento/EstruturaTarefaTest.php
- Notas: três tabelas — `tarefas` (título, resumo, detalhes, sistema_id nulo,
  responsavel_id nulo, criado_por_id, prioridade, status, iniciada_em, soft
  delete), `tarefa_eventos` (de_status, para_status, motivo, entrou_em,
  saiu_em, duracao_segundos) e `tarefa_relatorios_teste` (aprovado, notas).
  Constantes `Tarefa::STATUS` e `Tarefa::PRIORIDADES` no padrão de
  `Lead::ESTAGIOS`. Status inicial decidido no model: sem responsável nasce
  `aberta`, com responsável nasce `backlog`. Base de tudo.
- Esforço: alto

## T-059 — Motor do fluxo: transições permitidas, exigências e tempo por etapa [pendente]

- Refs: US-037, US-038, AC-085, AC-086, AC-087, AC-088, AC-089, AC-090, AC-091
- Arquivos: app/Services/FluxoTarefaService.php, tests/Feature/TarefasDesenvolvimento/FluxoTarefaTest.php
- Notas: porta `assertTransitionAllowed` + `applyStatusChange` do
  `lib/task-store.ts` do alfadev. Mapa de transições permitidas; recusa com
  exceção de domínio quando falta responsável (aberta→backlog), motivo
  (→ajustes, →cancelada) ou relatório de teste aprovado (→concluída);
  cancelada não sai de lugar nenhum. Cada mudança fecha o evento aberto
  (saiu_em + duracao_segundos) e abre o próximo. Depende de T-058.
- Esforço: alto

## T-060 — Quadro no ar: permissão `tarefas`, rotas, controller e a tela com as colunas [pendente]

- Refs: US-036, US-039, AC-081, AC-082, AC-095
- Arquivos: database/seeders/PerfilPermissaoSeeder.php, routes/web.php, app/Http/Controllers/TarefaController.php, resources/views/tarefas/index.blade.php, tests/Feature/TarefasDesenvolvimento/QuadroTest.php, tests/Feature/TarefasDesenvolvimento/AcessoTarefasTest.php
- Notas: recurso `tarefas` novo no seeder de permissões (só perfis da matriz
  recebem); rotas `tarefas.index`, `tarefas.store`, `tarefas.update`,
  `tarefas.mover` e `tarefas.historico` sob `permissao:tarefas`, com 403 para
  quem tem `temEscopoDeRevenda()` mesmo tendo a permissão no perfil. A tela
  monta as sete colunas do ciclo na ordem, cada uma com a contagem;
  o layout espelha `resources/views/leads/index.blade.php` (altura da janela,
  colunas de largura fixa, sombras de borda) e o Alpine do quadro vai inline
  na própria view, como o `funil`. Permissão, rota, controller e tela andam
  juntos porque nenhum deles se sustenta (nem se testa) sem os outros.
  Depende de T-058 e T-059.
- Esforço: alto

## T-061 — Grupo "Desenvolvimento" no menu lateral [pendente]

- Refs: US-036, US-039, AC-081, AC-094
- Arquivos: resources/views/layouts/navigation.blade.php, tests/Feature/TarefasDesenvolvimento/MenuDesenvolvimentoTest.php
- Notas: grupo novo entre "Financeiro" e "Sistema", com o item Tarefas
  (`route: tarefas.index`, `pattern: tarefas.*`, ícone `view-grid`) marcado
  `'matriz' => true` — é a flag que já tira o item do menu de quem tem escopo
  de revenda. Depende de T-060 (a rota precisa existir).

## T-062 — Card da tarefa: sistema, prioridade, tempo na etapa e destaque de esquecida [pendente]

- Refs: US-036, US-038, AC-084, AC-092, AC-093
- Arquivos: resources/views/tarefas/_card.blade.php, resources/views/tarefas/index.blade.php, tests/Feature/TarefasDesenvolvimento/CardTarefaTest.php
- Notas: porta `timeInCurrentColumn` e `ticketAgingLevel` do `board-utils.ts`
  para métodos do model — tempo curto ("agora", "3h", "2d") e destaque de
  atenção a partir de 24h / crítico a partir de 48h, só em Aberta e Em testes.
  Prioridade em `x-badge` com os tons já existentes. Depende de T-060.

## T-063 — Criar e editar tarefa: sistema, responsável e prioridade [pendente]

- Refs: US-036, AC-083, AC-084
- Arquivos: resources/views/tarefas/_form.blade.php, resources/views/tarefas/index.blade.php, app/Http/Controllers/TarefaController.php, tests/Feature/TarefasDesenvolvimento/FormularioTarefaTest.php
- Notas: modal no padrão do `x-modal` já usado em Leads. Select de sistema
  oferece só sistemas ativos e aceita vazio; select de responsável lista
  usuários da matriz (sem escopo de revenda). Salvar sem responsável deixa a
  tarefa em Aberta. Depende de T-060.

## T-064 — Mover card: arrastar, menu "Mover" e confirmação com motivo ou notas de teste [pendente]

- Refs: US-037, AC-085, AC-086, AC-087, AC-088, AC-089, AC-090
- Arquivos: resources/views/tarefas/_mover.blade.php, resources/views/tarefas/index.blade.php, app/Http/Controllers/TarefaController.php, tests/Feature/TarefasDesenvolvimento/MoverTarefaTest.php
- Notas: arrastar como no Funil de Vendas, e o menu "Mover ▾" no card como
  caminho acessível — obrigatório nas transições que pedem texto (ajustes,
  cancelamento, conclusão com relatório de teste). O erro devolvido pelo motor
  do fluxo aparece como aviso na tela e o card não sai do lugar. Depende de
  T-059 e T-062.
- Esforço: alto

## T-065 — Quadro enxuto e histórico inteiro: recorte de 30 dias e listagem sem filtro [pendente]

- Refs: US-040, AC-096, AC-097
- Arquivos: resources/views/tarefas/historico.blade.php, app/Http/Controllers/TarefaController.php, tests/Feature/TarefasDesenvolvimento/HistoricoTarefasTest.php
- Notas: as colunas Concluída e Cancelada do quadro passam a trazer só os
  últimos 30 dias, avisando quantas ficaram fora; o link no cabeçalho leva ao
  histórico completo, em `x-tabela`, com sistema, responsável, etapa final e
  data, sem nenhum recorte de período — é o caminho de auditoria. Depende de
  T-060.
