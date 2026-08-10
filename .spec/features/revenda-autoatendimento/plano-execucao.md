# Plano de execução — revenda-autoatendimento

> gerado por `onp-spec plano` em 2026-08-10 13:50 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano revenda-autoatendimento --paralelizar T-068,T-069,T-070,T-071,T-072`

## Resumo — o que vai acontecer

- **5 tarefa(s) pendente(s)**: 5 em 5 faixa(s) paralela(s) + 0 sequencial(is) (2 já concluída(s): T-066, T-067)
- **seleção do usuário**: paralelizar só T-068, T-069, T-070, T-071, T-072 — as demais rodam uma após a outra, ao final
- **1 faixa = 1 worktree + 1 branch + 1 janela de contexto limpa** — faixas não compartilham nenhum arquivo entre si
- prefere outra seleção ou uma após a outra? Regenere com `onp-spec plano revenda-autoatendimento --paralelizar T-xxx,T-yyy` ou `--sequencial`
- tudo acontece na branch de trabalho `spec/revenda-autoatendimento`; levar para a main é decisão sua

## Faixas e ondas

### Onda 1 — faixa-1 ∥ faixa-2 ∥ faixa-3

#### faixa-1 — branch `spec/revenda-autoatendimento-faixa-1` — worktree `../onp-worktrees/AlfaMatriz-revenda-autoatendimento-faixa-1`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-068 | A revenda cadastra o próprio cliente | `claude-sonnet-5` | high | `app/Http/Controllers/ClienteController.php`, `resources/views/clientes/_form.blade.php`, `tests/Feature/RevendaAutoatendimento/CadastroPelaRevendaTest.php` |

#### faixa-2 — branch `spec/revenda-autoatendimento-faixa-2` — worktree `../onp-worktrees/AlfaMatriz-revenda-autoatendimento-faixa-2`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-069 | Licença é assunto da Alfa, não da revenda | `claude-sonnet-5` | medium | `resources/views/clientes/_tabela.blade.php`, `tests/Feature/RevendaAutoatendimento/LicencaSoDoAdminTest.php` |

#### faixa-3 — branch `spec/revenda-autoatendimento-faixa-3` — worktree `../onp-worktrees/AlfaMatriz-revenda-autoatendimento-faixa-3`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-070 | Acesso das revendas migradas | `claude-sonnet-5` | medium | `app/Console/Commands/CriarAcessosDeRevendas.php`, `tests/Feature/RevendaAutoatendimento/AcessosDeRevendasMigradasTest.php` |

### Onda 2 — faixa-4 ∥ faixa-5

#### faixa-4 — branch `spec/revenda-autoatendimento-faixa-4` — worktree `../onp-worktrees/AlfaMatriz-revenda-autoatendimento-faixa-4`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-071 | Relatório de conferência da migração | `claude-sonnet-5` | medium | `app/Console/Commands/ConferirMigracaoAlfaGym.php`, `tests/Feature/RevendaAutoatendimento/ConferenciaDaMigracaoTest.php` |

#### faixa-5 — branch `spec/revenda-autoatendimento-faixa-5` — worktree `../onp-worktrees/AlfaMatriz-revenda-autoatendimento-faixa-5`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-072 | Fechar as pendências que o audit já aponta neste código | `claude-sonnet-5` | low | `.spec/features/clientes-via-revenda/tasks.md` |

## Gestão de branches e commits

1. branch de trabalho `spec/revenda-autoatendimento` criada do ponto atual (se ainda não existir)
2. cada faixa nasce dela como branch própria e roda no seu worktree — **1 tarefa = 1 commit** (`T-xxx feature: título`)
3. terminou a onda → merge `--no-ff` de cada faixa de volta, na ordem; conflito interrompe a faixa e pede resolução humana
4. faixa mesclada → worktree removido, branch apagada, tarefa marcada `[concluida]` no tasks.md
5. gate final na branch de trabalho: `onp-spec verify revenda-autoatendimento` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/revenda-autoatendimento/executar-tarefas.sh
```

Cada faixa roda `claude -p` com **janela de contexto limpa**, no seu worktree, com
`--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`. Os prompts exatos estão
embutidos no script — quer rodar uma faixa na mão, é só copiá-los de lá.
Logs: `../onp-worktrees/AlfaMatriz-revenda-autoatendimento-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo revenda-autoatendimento --tabela   # a tabela de andamento
onp-spec resumo revenda-autoatendimento            # o resumo em texto
```

