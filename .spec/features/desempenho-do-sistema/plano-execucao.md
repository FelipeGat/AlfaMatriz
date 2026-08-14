# Plano de execução — desempenho-do-sistema

> gerado por `onp-spec plano` em 2026-08-14 14:12 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano desempenho-do-sistema`

## Resumo — o que vai acontecer

- **6 tarefa(s) pendente(s)**: 6 em 5 faixa(s) paralela(s) + 0 sequencial(is)
- **1 faixa = 1 worktree + 1 branch + 1 janela de contexto limpa** — faixas não compartilham nenhum arquivo entre si
- prefere outra seleção ou uma após a outra? Regenere com `onp-spec plano desempenho-do-sistema --paralelizar T-xxx,T-yyy` ou `--sequencial`
- tudo acontece na branch de trabalho `spec/desempenho-do-sistema`; levar para a main é decisão sua

## Faixas e ondas

### Onda 1 — faixa-1 ∥ faixa-2 ∥ faixa-3

#### faixa-1 — branch `spec/desempenho-do-sistema-faixa-1` — worktree `../onp-worktrees/AlfaMatriz-desempenho-do-sistema-faixa-1`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-103 | Permissão resolvida uma vez por requisição | `claude-sonnet-5` | medium | `app/Models/User.php`, `tests/Feature/Desempenho/PermissaoEmCacheTest.php` |

#### faixa-2 — branch `spec/desempenho-do-sistema-faixa-2` — worktree `../onp-worktrees/AlfaMatriz-desempenho-do-sistema-faixa-2`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-104 | O sino consulta uma vez por requisição | `claude-sonnet-5` | low | `app/Providers/AppServiceProvider.php`, `tests/Feature/Desempenho/SinoUmaVezTest.php` |

#### faixa-3 — branch `spec/desempenho-do-sistema-faixa-3` — worktree `../onp-worktrees/AlfaMatriz-desempenho-do-sistema-faixa-3`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-105 | O quadro para de imprimir um modal por card | `claude-sonnet-5` | high | `resources/views/tarefas/index.blade.php`, `resources/views/tarefas/_modais.blade.php`, `app/Http/Controllers/TarefaController.php`, `routes/web.php`, `tests/Feature/Desempenho/QuadroLeveTest.php` |

### Onda 2 — faixa-4 ∥ faixa-5

#### faixa-4 — branch `spec/desempenho-do-sistema-faixa-4` — worktree `../onp-worktrees/AlfaMatriz-desempenho-do-sistema-faixa-4`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-106 | Painéis: as séries saem de uma consulta agrupada | `claude-sonnet-5` | high | `app/Services/IndicadoresService.php`, `app/Http/Controllers/PainelController.php`, `tests/Feature/Desempenho/PaineisTest.php` |
| T-107 | Ranking de sistemas sem uma consulta por sistema | `claude-sonnet-5` | medium | `app/Models/Sistema.php`, `app/Services/IndicadoresService.php`, `tests/Feature/Desempenho/RankingSistemasTest.php` |

#### faixa-5 — branch `spec/desempenho-do-sistema-faixa-5` — worktree `../onp-worktrees/AlfaMatriz-desempenho-do-sistema-faixa-5`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-108 | A previsão de faturamento consulta em bloco | `claude-sonnet-5` | high | `app/Services/FaturamentoService.php`, `tests/Feature/Desempenho/PrevisaoEmBlocoTest.php` |

## Gestão de branches e commits

1. branch de trabalho `spec/desempenho-do-sistema` criada do ponto atual (se ainda não existir)
2. cada faixa nasce dela como branch própria e roda no seu worktree — **1 tarefa = 1 commit** (`T-xxx feature: título`)
3. terminou a onda → merge `--no-ff` de cada faixa de volta, na ordem; conflito interrompe a faixa e pede resolução humana
4. faixa mesclada → worktree removido, branch apagada, tarefa marcada `[concluida]` no tasks.md
5. gate final na branch de trabalho: `onp-spec verify desempenho-do-sistema` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/desempenho-do-sistema/executar-tarefas.sh
```

Cada faixa roda `claude -p` com **janela de contexto limpa**, no seu worktree, com
`--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`. Os prompts exatos estão
embutidos no script — quer rodar uma faixa na mão, é só copiá-los de lá.
Logs: `../onp-worktrees/AlfaMatriz-desempenho-do-sistema-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo desempenho-do-sistema --tabela   # a tabela de andamento
onp-spec resumo desempenho-do-sistema            # o resumo em texto
```

