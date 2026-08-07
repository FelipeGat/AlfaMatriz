# Plano de execução — infra-agendamento

> gerado por `onp-spec plano` em 2026-08-07 15:30 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano infra-agendamento --sequencial`

## Resumo — o que vai acontecer

- **modo SEQUENCIAL (escolha do usuário)**: 6 tarefa(s) pendente(s), UMA APÓS A OUTRA, na árvore principal
- sem worktrees e sem paralelismo — cada tarefa roda numa janela de contexto limpa, na ordem do tasks.md
- tudo acontece na branch de trabalho `spec/infra-agendamento`; levar para a main é decisão sua

## Ordem de execução (uma tarefa após a outra)

| tarefa | título | modelo | esforço |
|---|---|---|---|
| T-053 | Conferir o fechamento mensal antes de ligar o agendamento | `claude-sonnet-5` | medium |
| T-054 | Agendamento de sistema chamando as rotinas do painel | `claude-sonnet-5` | medium |
| T-055 | Configuração legível pelo aplicativo e pastas de trabalho no dono certo | `claude-sonnet-5` | medium |
| T-056 | Serviço permanente que consome a fila | `claude-sonnet-5` | medium |
| T-057 | Publicar avisa o executor da fila a pegar o código novo | `claude-sonnet-5` | medium |
| T-058 | Envio de e-mail real no ambiente publicado e comando de conferência | `claude-sonnet-5` | medium |

## Gestão de branches e commits

1. branch de trabalho `spec/infra-agendamento` criada do ponto atual (se ainda não existir)
2. as tarefas rodam nela mesma, na ordem — **1 tarefa = 1 commit** (`T-xxx feature: título`), marcada `[concluida]` só com trabalho feito
3. gate final na branch de trabalho: `onp-spec verify infra-agendamento` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/infra-agendamento/executar-tarefas.sh
```

Cada tarefa roda `claude -p` com **janela de contexto limpa**, na árvore principal,
uma após a outra, com `--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`.
Os prompts exatos estão embutidos no script.
Logs: `../onp-worktrees/AlfaMatriz-infra-agendamento-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo infra-agendamento --tabela   # a tabela de andamento
onp-spec resumo infra-agendamento            # o resumo em texto
```

