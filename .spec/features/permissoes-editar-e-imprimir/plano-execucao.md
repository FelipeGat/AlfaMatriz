# Plano de execução — permissoes-editar-e-imprimir

> gerado por `onp-spec plano` em 2026-08-14 22:26 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano permissoes-editar-e-imprimir`

## Resumo — o que vai acontecer

- **4 tarefa(s) pendente(s)**: 4 em 3 faixa(s) paralela(s) + 0 sequencial(is)
- **1 faixa = 1 worktree + 1 branch + 1 janela de contexto limpa** — faixas não compartilham nenhum arquivo entre si
- prefere outra seleção ou uma após a outra? Regenere com `onp-spec plano permissoes-editar-e-imprimir --paralelizar T-xxx,T-yyy` ou `--sequencial`
- tudo acontece na branch de trabalho `spec/permissoes-editar-e-imprimir`; levar para a main é decisão sua

## Faixas e ondas

### Onda 1 — faixa-1 ∥ faixa-2 ∥ faixa-3

#### faixa-1 — branch `spec/permissoes-editar-e-imprimir-faixa-1` — worktree `../onp-worktrees/AlfaMatriz-permissoes-editar-e-imprimir-faixa-1`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-001 | A ação `imprimir` passa a recusar | `claude-sonnet-5` | medium | `routes/web.php`, `app/Http/Controllers/FaturamentoController.php`, `tests/Feature/Permissoes/ImprimirTest.php` |
| T-004 | As edições que usam POST | `claude-sonnet-5` | medium | `routes/web.php`, `tests/Feature/Permissoes/EdicaoPorPostTest.php` |

#### faixa-2 — branch `spec/permissoes-editar-e-imprimir-faixa-2` — worktree `../onp-worktrees/AlfaMatriz-permissoes-editar-e-imprimir-faixa-2`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-002 | A coluna `editar` e o backfill | `claude-sonnet-5` | medium | `database/migrations/2026_08_15_090000_separar_editar_de_incluir.php`, `database/seeders/PerfilPermissaoSeeder.php`, `tests/Feature/Permissoes/MigracaoEditarTest.php` |

#### faixa-3 — branch `spec/permissoes-editar-e-imprimir-faixa-3` — worktree `../onp-worktrees/AlfaMatriz-permissoes-editar-e-imprimir-faixa-3`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-003 | `editar` vira ação de verdade no domínio | `claude-sonnet-5` | medium | `app/Models/User.php`, `app/Http/Middleware/ChecarPermissao.php`, `app/Http/Controllers/PerfilController.php`, `resources/views/usuarios/_permissoes.blade.php`, `tests/Feature/Permissoes/GradeComEditarTest.php` |

## Gestão de branches e commits

1. branch de trabalho `spec/permissoes-editar-e-imprimir` criada do ponto atual (se ainda não existir)
2. cada faixa nasce dela como branch própria e roda no seu worktree — **1 tarefa = 1 commit** (`T-xxx feature: título`)
3. terminou a onda → merge `--no-ff` de cada faixa de volta, na ordem; conflito interrompe a faixa e pede resolução humana
4. faixa mesclada → worktree removido, branch apagada, tarefa marcada `[concluida]` no tasks.md
5. gate final na branch de trabalho: `onp-spec verify permissoes-editar-e-imprimir` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/permissoes-editar-e-imprimir/executar-tarefas.sh
```

Cada faixa roda `claude -p` com **janela de contexto limpa**, no seu worktree, com
`--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`. Os prompts exatos estão
embutidos no script — quer rodar uma faixa na mão, é só copiá-los de lá.
Logs: `../onp-worktrees/AlfaMatriz-permissoes-editar-e-imprimir-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo permissoes-editar-e-imprimir --tabela   # a tabela de andamento
onp-spec resumo permissoes-editar-e-imprimir            # o resumo em texto
```

