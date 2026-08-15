# Tasks: Modal historico da tarefa

> feature: modal-historico-da-tarefa

<!--
  Ordem de dependência (o plano de execução respeita isto):
  T-118 (banco + serviço) e T-119 (partial da linha do tempo) são
  paralelizáveis entre si — arquivos disjuntos.
  T-120 (página do histórico) vem DEPOIS das duas: monta o modal usando a
  partial de T-119 e exibe o autor que T-118 passa a gravar.
  Status: pendente | em-andamento | concluida
-->

## T-118 — Autor do evento: banco, serviço e modelo [concluida]
- Refs: US-083, AC-301
- Arquivos: database/migrations/2026_08_15_100000_autor_do_evento_da_tarefa.php, app/Services/FluxoTarefaService.php, app/Models/TarefaEvento.php, tests/Feature/TarefasDesenvolvimento/AutorDoEventoTest.php
- Esforço: medio
- Notas: coluna `user_id` nullable em `tarefa_eventos`, FK `nullOnDelete`
  (padrão de `responsavel_id`, ver ASM-073 — NÃO congela o nome como
  `auditorias`). Sem backfill: o passado fica nulo de propósito (Q-031).
  O único ponto de criação de evento é `FluxoTarefaService` (~linha 187),
  que já usa `auth()` — gravar `auth()->id()` ali cobre criar, mover,
  concluir, cancelar e reabrir de uma vez. Relação `autor()` no
  `TarefaEvento`. Ensaio de migração só vale em MySQL, não em sqlite.

## T-119 — Partial da linha do tempo da tarefa [concluida]
- Refs: US-082, AC-295, AC-296, AC-302
- Arquivos: resources/views/tarefas/_linha-do-tempo.blade.php
- Esforço: medio
- Notas: partial nova, somente leitura, espera `$tarefa` com `eventos.autor`
  carregado. Ordem cronológica (`entrou_em`), rótulo via
  `Tarefa::rotuloDaEtapa` (cobre `ETAPAS_APOSENTADAS` — AC-296), duração via
  `Tarefa::duracaoCurta`, motivo quando houver, autor quando houver (evento
  antigo sem autor não quebra — AC-302). Só tokens e componentes existentes
  (ASM-072): `x-badge` com `corDaEtapa`, `font-mono` para data/duração. As
  provas de página (@spec) destes critérios ficam no teste de T-120, que é
  quem monta a partial na tela.

## T-120 — Página do histórico: linha clicável e modal completo [concluida]
- Refs: US-082, AC-293, AC-294, AC-297, AC-298, AC-299, AC-300
- Arquivos: resources/views/tarefas/historico.blade.php, app/Http/Controllers/TarefaController.php, tests/Feature/TarefasDesenvolvimento/ModalHistoricoDaTarefaTest.php
- Esforço: alto
- Notas: depende de T-118 e T-119. A linha inteira dispara
  `open-modal` (cursor pointer); Reabrir leva `@click.stop` para continuar
  agindo sozinho (AC-294). O modal `comentarios-tarefa-{id}` vira
  `historico-tarefa-{id}` com TODAS as seções — linha do tempo (sempre),
  conversa/anexos/checklist/relatórios só quando existem (AC-300); o botão
  "N comentários · N anexos" vira texto informativo (ASM-075). Checklist via
  `_checklist` somente leitura se a partial aceitar, senão listagem simples
  no próprio modal — sem inventar valor visual (ASM-072). No
  `historico()` do controller, acrescentar `relatoriosTeste` e `eventos.autor`
  ao `with()` (ASM-074). O modal agora existe para TODA linha (a linha do
  tempo sempre existe), não só para quem tem comentário/anexo. Este teste
  também carrega as provas @spec de AC-295, AC-296 e AC-302 (a página é quem
  renderiza a partial de T-119). Rodar a suíte inteira ao final: os testes
  atuais do histórico não citam o modal antigo pelo nome, mas asserções de
  conteúdo podem esbarrar na mudança.
