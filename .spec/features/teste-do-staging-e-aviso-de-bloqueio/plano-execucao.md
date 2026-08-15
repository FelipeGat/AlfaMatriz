# Plano de execução — teste-do-staging-e-aviso-de-bloqueio

> gerado por `onp-spec plano` em 2026-08-15 14:11 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano teste-do-staging-e-aviso-de-bloqueio --sequencial`

## Resumo — o que vai acontecer

- **modo SEQUENCIAL (escolha do usuário)**: 6 tarefa(s) pendente(s), UMA APÓS A OUTRA, na árvore principal
- sem worktrees e sem paralelismo — cada tarefa roda numa janela de contexto limpa, na ordem do tasks.md
- tudo acontece na branch de trabalho `spec/teste-do-staging-e-aviso-de-bloqueio`; levar para a main é decisão sua

## Ordem de execução (uma tarefa após a outra)

| tarefa | título | modelo | esforço |
|---|---|---|---|
| T-121 | Quem testou entra no relatório de teste | `claude-sonnet-5` | medium |
| T-122 | Registrar o teste do staging sem mover o card | `claude-sonnet-5` | medium |
| T-123 | O bloqueio avisa no sino | `claude-sonnet-5` | medium |
| T-124 | Comando do portão do staging | `claude-sonnet-5` | medium |
| T-125 | O deploy chama o portão nos dois vereditos | `claude-sonnet-5` | medium |
| T-126 | A ação na tela e o autor no histórico | `claude-sonnet-5` | medium |

## Gestão de branches e commits

1. branch de trabalho `spec/teste-do-staging-e-aviso-de-bloqueio` criada do ponto atual (se ainda não existir)
2. as tarefas rodam nela mesma, na ordem — **1 tarefa = 1 commit** (`T-xxx feature: título`), marcada `[concluida]` só com trabalho feito
3. gate final na branch de trabalho: `onp-spec verify teste-do-staging-e-aviso-de-bloqueio` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/teste-do-staging-e-aviso-de-bloqueio/executar-tarefas.sh
```

Cada tarefa roda `claude -p` com **janela de contexto limpa**, na árvore principal,
uma após a outra, com `--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`.
Os prompts exatos estão embutidos no script.
Logs: `../onp-worktrees/AlfaMatriz-teste-do-staging-e-aviso-de-bloqueio-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo teste-do-staging-e-aviso-de-bloqueio --tabela   # a tabela de andamento
onp-spec resumo teste-do-staging-e-aviso-de-bloqueio            # o resumo em texto
```

