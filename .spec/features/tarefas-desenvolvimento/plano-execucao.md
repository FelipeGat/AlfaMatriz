# Plano de execução — tarefas-desenvolvimento

> gerado por `onp-spec plano` em 2026-08-10 12:58 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano tarefas-desenvolvimento --sequencial`

## Resumo — o que vai acontecer

- **modo SEQUENCIAL (escolha do usuário)**: 8 tarefa(s) pendente(s), UMA APÓS A OUTRA, na árvore principal
- sem worktrees e sem paralelismo — cada tarefa roda numa janela de contexto limpa, na ordem do tasks.md
- tudo acontece na branch de trabalho `spec/tarefas-desenvolvimento`; levar para a main é decisão sua

## Ordem de execução (uma tarefa após a outra)

| tarefa | título | modelo | esforço |
|---|---|---|---|
| T-058 | Estrutura de dados: tarefa, evento de etapa e relatório de teste | `claude-sonnet-5` | high |
| T-059 | Motor do fluxo: transições permitidas, exigências e tempo por etapa | `claude-sonnet-5` | high |
| T-060 | Quadro no ar: permissão `tarefas`, rotas, controller e a tela com as colunas | `claude-sonnet-5` | high |
| T-061 | Grupo "Desenvolvimento" no menu lateral | `claude-sonnet-5` | medium |
| T-062 | Card da tarefa: sistema, prioridade, tempo na etapa e destaque de esquecida | `claude-sonnet-5` | medium |
| T-063 | Criar e editar tarefa: sistema, responsável e prioridade | `claude-sonnet-5` | medium |
| T-064 | Mover card: arrastar, menu "Mover" e confirmação com motivo ou notas de teste | `claude-sonnet-5` | high |
| T-065 | Quadro enxuto e histórico inteiro: recorte de 30 dias e listagem sem filtro | `claude-sonnet-5` | medium |

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
Logs: `../onp-worktrees/AlfaMatriz-tarefas-desenvolvimento-logs/`.

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

