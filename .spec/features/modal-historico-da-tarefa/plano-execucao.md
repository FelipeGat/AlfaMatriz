# Plano de execução — modal-historico-da-tarefa

> gerado por `onp-spec plano` em 2026-08-15 10:16 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano modal-historico-da-tarefa --sequencial`

## Resumo — o que vai acontecer

- **modo SEQUENCIAL (escolha do usuário)**: 3 tarefa(s) pendente(s), UMA APÓS A OUTRA, na árvore principal
- sem worktrees e sem paralelismo — cada tarefa roda numa janela de contexto limpa, na ordem do tasks.md
- tudo acontece na branch de trabalho `spec/modal-historico-da-tarefa`; levar para a main é decisão sua

## Ordem de execução (uma tarefa após a outra)

| tarefa | título | modelo | esforço |
|---|---|---|---|
| T-118 | Autor do evento: banco, serviço e modelo | `claude-sonnet-5` | medium |
| T-119 | Partial da linha do tempo da tarefa | `claude-sonnet-5` | medium |
| T-120 | Página do histórico: linha clicável e modal completo | `claude-sonnet-5` | high |

## Gestão de branches e commits

1. branch de trabalho `spec/modal-historico-da-tarefa` criada do ponto atual (se ainda não existir)
2. as tarefas rodam nela mesma, na ordem — **1 tarefa = 1 commit** (`T-xxx feature: título`), marcada `[concluida]` só com trabalho feito
3. gate final na branch de trabalho: `onp-spec verify modal-historico-da-tarefa` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/modal-historico-da-tarefa/executar-tarefas.sh
```

Cada tarefa roda `claude -p` com **janela de contexto limpa**, na árvore principal,
uma após a outra, com `--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`.
Os prompts exatos estão embutidos no script.
Logs: `../onp-worktrees/AlfaMatriz-modal-historico-da-tarefa-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo modal-historico-da-tarefa --tabela   # a tabela de andamento
onp-spec resumo modal-historico-da-tarefa            # o resumo em texto
```

