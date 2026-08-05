# Plano de execução — fluxo-deploy

> gerado por `onp-spec plano` em 2026-08-05 22:13 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano fluxo-deploy --sequencial`

## Resumo — o que vai acontecer

- **modo SEQUENCIAL (escolha do usuário)**: 7 tarefa(s) pendente(s), UMA APÓS A OUTRA, na árvore principal
- sem worktrees e sem paralelismo — cada tarefa roda numa janela de contexto limpa, na ordem do tasks.md
- tudo acontece na branch de trabalho `spec/fluxo-deploy`; levar para a main é decisão sua

## Ordem de execução (uma tarefa após a outra)

| tarefa | título | modelo | esforço |
|---|---|---|---|
| T-031 | Cadastro do AlfaMatriz no inventário do painel | `claude-sonnet-5` | medium |
| T-032 | Executor do staging com portão de testes | `claude-sonnet-5` | medium |
| T-033 | Vigia de tag para produção | `claude-sonnet-5` | medium |
| T-034 | Provisionar o container de staging | `claude-sonnet-5` | medium |
| T-035 | Verificação automática no GitHub | `claude-sonnet-5` | medium |
| T-036 | Cópia embaralhada da produção para o staging | `claude-sonnet-5` | medium |
| T-037 | Instalar e conferir no servidor | `claude-sonnet-5` | medium |

## Gestão de branches e commits

1. branch de trabalho `spec/fluxo-deploy` criada do ponto atual (se ainda não existir)
2. as tarefas rodam nela mesma, na ordem — **1 tarefa = 1 commit** (`T-xxx feature: título`), marcada `[concluida]` só com trabalho feito
3. gate final na branch de trabalho: `onp-spec verify fluxo-deploy` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/fluxo-deploy/executar-tarefas.sh
```

Cada tarefa roda `claude -p` com **janela de contexto limpa**, na árvore principal,
uma após a outra, com `--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`.
Os prompts exatos estão embutidos no script.
Logs: `../onp-worktrees/AlfaMatriz-fluxo-deploy-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo fluxo-deploy --tabela   # a tabela de andamento
onp-spec resumo fluxo-deploy            # o resumo em texto
```

