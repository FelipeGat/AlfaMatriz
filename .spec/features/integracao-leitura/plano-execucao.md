# Plano de execução — integracao-leitura

> gerado por `onp-spec plano` em 2026-08-07 16:18 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano integracao-leitura --sequencial`

## Resumo — o que vai acontecer

- **modo SEQUENCIAL (escolha do usuário)**: 23 tarefa(s) pendente(s), UMA APÓS A OUTRA, na árvore principal
- sem worktrees e sem paralelismo — cada tarefa roda numa janela de contexto limpa, na ordem do tasks.md
- tudo acontece na branch de trabalho `spec/integracao-leitura`; levar para a main é decisão sua

## Ordem de execução (uma tarefa após a outra)

| tarefa | título | modelo | esforço |
|---|---|---|---|
| T-059 | Escrever o contrato da integração | `claude-sonnet-5` | medium |
| T-060 | Configuração do sistema: chave preservada e estado da integração | `claude-sonnet-5` | medium |
| T-061 | Retrato local: revendas, clientes e planos | `claude-sonnet-5` | medium |
| T-062 | Retrato local: licenças, usuários, financeiro e contadores | `claude-sonnet-5` | medium |
| T-063 | Registro de cada execução de sincronização | `claude-sonnet-5` | medium |
| T-064 | O contrato em código: interface, transportes e erro | `claude-sonnet-5` | medium |
| T-065 | Conector falso e amostras de resposta | `claude-sonnet-5` | medium |
| T-066 | Conector HTTP | `claude-sonnet-5` | medium |
| T-067 | Casar cliente e revenda do sistema com os da matriz | `claude-sonnet-5` | medium |
| T-068 | Sincronizar o cadastro do sistema | `claude-sonnet-5` | medium |
| T-069 | Ausência na origem e varredura interrompida | `claude-sonnet-5` | medium |
| T-070 | Sincronizar licenças, financeiro e contadores | `claude-sonnet-5` | medium |
| T-071 | Comando e agendamento da sincronização | `claude-sonnet-5` | medium |
| T-072 | Importar o cadastro que já existe no sistema | `claude-sonnet-5` | medium |
| T-073 | O corte, sistema por sistema | `claude-sonnet-5` | medium |
| T-074 | Painel de integração e o selo de "atualizado há" | `claude-sonnet-5` | medium |
| T-075 | Tela de conferência e aplicação do corte | `claude-sonnet-5` | medium |
| T-076 | Tela de clientes por sistema | `claude-sonnet-5` | medium |
| T-077 | Tela de licenças dos sistemas | `claude-sonnet-5` | medium |
| T-078 | Financeiro dos sistemas e exportação | `claude-sonnet-5` | medium |
| T-079 | Tela de divergências | `claude-sonnet-5` | medium |
| T-080 | AlfaGym: chave da matriz e endereços de leitura | `claude-sonnet-5` | medium |
| T-081 | Ligar de verdade e provar o formato | `claude-sonnet-5` | medium |

## Gestão de branches e commits

1. branch de trabalho `spec/integracao-leitura` criada do ponto atual (se ainda não existir)
2. as tarefas rodam nela mesma, na ordem — **1 tarefa = 1 commit** (`T-xxx feature: título`), marcada `[concluida]` só com trabalho feito
3. gate final na branch de trabalho: `onp-spec verify integracao-leitura` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/integracao-leitura/executar-tarefas.sh
```

Cada tarefa roda `claude -p` com **janela de contexto limpa**, na árvore principal,
uma após a outra, com `--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`.
Os prompts exatos estão embutidos no script.
Logs: `../onp-worktrees/AlfaMatriz-integracao-leitura-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo integracao-leitura --tabela   # a tabela de andamento
onp-spec resumo integracao-leitura            # o resumo em texto
```

