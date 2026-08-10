# Plano de execução — tarefas-desenvolvimento

> gerado por `onp-spec plano` em 2026-08-10 14:00 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano tarefas-desenvolvimento --sequencial`

## Resumo — o que vai acontecer

- **modo SEQUENCIAL (escolha do usuário)**: 3 tarefa(s) pendente(s), UMA APÓS A OUTRA, na árvore principal (8 já concluída(s): T-058, T-059, T-060, T-061, T-062, T-063, T-064, T-065)
- sem worktrees e sem paralelismo — cada tarefa roda numa janela de contexto limpa, na ordem do tasks.md
- tudo acontece na branch de trabalho `spec/tarefas-desenvolvimento`; levar para a main é decisão sua

## Ordem de execução (uma tarefa após a outra)

| tarefa | título | modelo | esforço |
|---|---|---|---|
| T-073 | Menu "Mover" volta a oferecer os destinos permitidos | `claude-sonnet-5` | high |
| T-074 | Prioridade Crítica, o quarto nível do ciclo | `claude-sonnet-5` | high |
| T-075 | Devolver do Backlog para Aberta, soltando o responsável | `claude-sonnet-5` | medium |

## Gestão de branches e commits

1. branch de trabalho `spec/tarefas-desenvolvimento` criada do ponto atual (se ainda não existir)
2. as tarefas rodam nela mesma, na ordem — **1 tarefa = 1 commit** (`T-xxx feature: título`), marcada `[concluida]` só com trabalho feito
3. gate final na branch de trabalho: `onp-spec verify tarefas-desenvolvimento` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/tarefas-desenvolvimento/executar-tarefas.sh
```

Cada tarefa roda `claude -p` com **janela de contexto limpa**, na árvore principal,
uma após a outra, com `--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`.
Os prompts exatos estão embutidos no script.
Logs: `../onp-worktrees/AlfaMatriz-tarefas-dev-tarefas-desenvolvimento-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo tarefas-desenvolvimento --tabela   # a tabela de andamento
onp-spec resumo tarefas-desenvolvimento            # o resumo em texto
```

