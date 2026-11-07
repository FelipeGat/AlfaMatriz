# Tarefas AlfaMatriz - Fila por projeto sem espera parada

Escopo: motivo da espera, levantamento enquanto o projeto está ocupado e "Passar na frente" com pausa e retomada
(spec `central-ia-fila-por-projeto`).

**Legenda de status:** `[ ]` Pendente · `[~]` Em andamento · `[x]` Concluido · `[!]` Bloqueado

---

## FASE 1 - Servidor

- [x] 1.1 Migração `prioridade` e estado `pausada` [A]
- [x] 1.2 `proxima`: ordem por prioridade, levantamento, continuação com sessão [C]
- [x] 1.3 `concluir`: levantamento (sem troca/escalada; dúvida encerra o original) e `pausada` (retomada) [C]
- [x] 1.4 `passarNaFrente` + rota + resposta `pausar` no andamento [A]
- [x] 1.5 Motivo da espera no painel [A]

## FASE 2 - Runner

- [x] 2.1 `pode_pausar`, `pausar` → encerra o agente e conclui `pausada` com sessão e checkpoint [C]
- [x] 2.2 Contexto curto quando retoma a sessão [M]

## FASE 3 - Tela

- [x] 3.1 Linha da fila com o motivo e o botão "Passar na frente" [A]

## FASE 4 - Testes

- [x] 4.1 Feature: levantamento, continuação, dúvida, falha, passar na frente, pausada, retomada, motivo, 404/422 [C]
- [x] 4.2 Runner de verdade contra servidor falso: pausa encerra o agente e conclui `pausada` com a sessão [C]
- [x] 4.3 Suíte inteira [C]
