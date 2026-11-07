# Plano técnico: Fila por projeto sem espera parada

## Onde mexe

- **Banco**: `central_ia_execucoes.prioridade` (tinyint, 0). A fila ordena por `prioridade desc, id`.
  Estado novo `pausada` (string, sem migração: a coluna `status` é `string(16)`).
- **`AgentesService::proxima`**: depois de não achar pedido entregável, procura implementação parada SÓ pela trava
  do projeto e cria o levantamento (execução nova, `modo=analisar`, `roteamento.levantamento_de`, `pedido_id` =
  aviso do sistema, como a troca de provedor já faz por causa do `unique`). O original ganha
  `roteamento.levantamento` e fica pendente: a regra "nunca duas da mesma tarefa" o segura até o levantamento acabar.
  No payload: `acesso=leitura` e a nota da fase 1.
- **Continuação**: implementação com `levantamento` concluído/pausado, ou com `retomada_de`, vai com
  `continuar = sessão anterior` (inclusive em chat de assunto, que antes nunca retomava) e com `retomada.contexto`
  curto. O runner novo usa o curto quando de fato retoma; o antigo usa o `contexto` inteiro (gasta mais, funciona).
- **`AgentesService::concluir`**: levantamento não troca provedor, não escala, não delega; com dúvida, encerra o
  original (`expirada`, com aviso). `status=pausada` → execução `pausada`, aviso no chat e, se não for levantamento,
  a retomada (pendente, prioridade 1, `retomada_de`).
- **`AgentesService::passarNaFrente`**: valida que o pedido espera o projeto, marca prioridade 1 e
  `roteamento.pausar` no(s) que ocupam (a implementação do projeto e o levantamento do próprio pedido).
- **`ProgressoService` / `progresso`**: guarda `pode_pausar` do runner e responde `pausar: true` quando pedido.
- **`CockpitService::atividade`**: `espera` (texto), `pode_passar_na_frente`, `pausando`.
- **Runner (`deploy/central-ia/runner.mjs`)**: manda `pode_pausar`, lê `pausar` da resposta do andamento, encerra o
  grupo do processo do agente (SIGTERM, SIGKILL em 10 s), conclui `pausada` com a sessão (Claude: a prevista; Codex:
  `thread.started`; Copilot: o nome) e o checkpoint; usa `retomada.contexto` quando retoma a sessão.
- **Tela**: `andamento(a)` mostra `a.espera`; botão "Passar na frente" na linha.

## Decisões

- **Pausa pelo andamento, e não por endpoint novo na máquina**: o runner já fala com o servidor a cada 30 s por
  execução; a máquina não abre porta nenhuma.
- **Duplicar a execução, e não reabrir a mesma**: a conclusão é idempotente por id e o `pedido_id` é único; a
  retomada é uma execução nova, como a troca de provedor e a escalada já fazem.
- **Levantamento só quando nada mais é entregável**: pedido que pode rodar inteiro passa antes de um levantamento.
