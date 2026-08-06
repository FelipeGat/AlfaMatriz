# Plano de execução — redesign-visual

> gerado por `onp-spec plano` em 2026-08-06 16:22 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano redesign-visual`

## Resumo — o que vai acontecer

- **15 tarefa(s) pendente(s)**: 15 em 15 faixa(s) paralela(s) + 0 sequencial(is)
- **1 faixa = 1 worktree + 1 branch + 1 janela de contexto limpa** — faixas não compartilham nenhum arquivo entre si
- prefere outra seleção ou uma após a outra? Regenere com `onp-spec plano redesign-visual --paralelizar T-xxx,T-yyy` ou `--sequencial`
- tudo acontece na branch de trabalho `spec/redesign-visual`; levar para a main é decisão sua

## Faixas e ondas

### Onda 1 — faixa-1 ∥ faixa-2 ∥ faixa-3

#### faixa-1 — branch `spec/redesign-visual-faixa-1` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-1`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-031 | Fundação visual: tokens dos dois temas e tipografia | `claude-sonnet-5` | high | `tailwind.config.js`, `resources/css/app.css`, `tests/Feature/Redesign/TokensTest.php` |

#### faixa-2 — branch `spec/redesign-visual-faixa-2` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-2`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-032 | Shell: sidebar com rail, topbar, alternador de tema e marca | `claude-sonnet-5` | high | `resources/views/layouts/app.blade.php`, `resources/views/layouts/navigation.blade.php`, `resources/views/components/nav-icon.blade.php`, `resources/views/components/application-logo.blade.php`, `resources/js/app.js`, `public/icon-matriz.svg`, `public/alfamatriz.png`, `tests/Feature/Redesign/ShellTest.php` |

#### faixa-3 — branch `spec/redesign-visual-faixa-3` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-3`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-033 | Componentes compartilhados do sistema visual | `claude-sonnet-5` | high | `resources/views/components/kpi-card.blade.php`, `resources/views/components/sparkline.blade.php`, `resources/views/components/painel.blade.php`, `resources/views/components/badge.blade.php`, `resources/views/components/faixa-segmentada.blade.php`, `resources/views/components/tabela.blade.php`, `resources/views/components/linha-total.blade.php`, `resources/views/components/acao-tabela.blade.php`, `resources/views/components/stat-card.blade.php`, `resources/views/components/bar-chart.blade.php`, `tests/Feature/Redesign/ComponentesTest.php` |

### Onda 2 — faixa-4 ∥ faixa-5 ∥ faixa-6

#### faixa-4 — branch `spec/redesign-visual-faixa-4` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-4`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-034 | Centro de Controle | `claude-sonnet-5` | high | `resources/views/centro-controle/index.blade.php`, `app/Http/Controllers/CentroControleController.php`, `tests/Feature/Redesign/CentroControleTest.php` |

#### faixa-5 — branch `spec/redesign-visual-faixa-5` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-5`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-035 | Painéis Financeiro e Comercial | `claude-sonnet-5` | high | `resources/views/dashboard.blade.php`, `resources/views/dashboard-comercial.blade.php`, `app/Http/Controllers/PainelController.php`, `tests/Feature/Redesign/PaineisTest.php` |

#### faixa-6 — branch `spec/redesign-visual-faixa-6` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-6`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-036 | Funil de vendas com arrastar e soltar | `claude-sonnet-5` | high | `resources/views/leads/index.blade.php`, `app/Http/Controllers/LeadController.php`, `tests/Feature/Redesign/FunilTest.php` |

### Onda 3 — faixa-7 ∥ faixa-8 ∥ faixa-9

#### faixa-7 — branch `spec/redesign-visual-faixa-7` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-7`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-037 | Revendas | `claude-sonnet-5` | medium | `resources/views/revendas/index.blade.php`, `resources/views/revendas/_form.blade.php`, `resources/views/revendas/create.blade.php`, `resources/views/revendas/edit.blade.php`, `app/Http/Controllers/RevendaController.php`, `tests/Feature/Redesign/RevendasTest.php` |

#### faixa-8 — branch `spec/redesign-visual-faixa-8` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-8`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-038 | Clientes: lista e formulário | `claude-sonnet-5` | high | `resources/views/clientes/index.blade.php`, `resources/views/clientes/_form.blade.php`, `resources/views/clientes/create.blade.php`, `resources/views/clientes/edit.blade.php`, `app/Http/Controllers/ClienteController.php`, `tests/Feature/Redesign/ClientesTest.php` |

#### faixa-9 — branch `spec/redesign-visual-faixa-9` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-9`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-039 | Produtos: lista comparável e gestão do sistema | `claude-sonnet-5` | high | `resources/views/produtos/index.blade.php`, `resources/views/sistemas/index.blade.php`, `resources/views/sistemas/edit.blade.php`, `app/Http/Controllers/ProdutoController.php`, `tests/Feature/Redesign/ProdutosTest.php` |

### Onda 4 — faixa-10 ∥ faixa-11 ∥ faixa-12

#### faixa-10 — branch `spec/redesign-visual-faixa-10` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-10`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-040 | Faturamento das revendas | `claude-sonnet-5` | high | `resources/views/faturamento/index.blade.php`, `app/Http/Controllers/FaturamentoController.php`, `app/Services/FaturamentoService.php`, `tests/Feature/Redesign/FaturamentoTest.php` |

#### faixa-11 — branch `spec/redesign-visual-faixa-11` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-11`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-041 | Receitas / contas a receber | `claude-sonnet-5` | high | `resources/views/cobrancas/index.blade.php`, `resources/views/cobrancas/_form.blade.php`, `resources/views/cobrancas/create.blade.php`, `resources/views/cobrancas/edit.blade.php`, `resources/views/cobrancas/show.blade.php`, `app/Http/Controllers/CobrancaController.php`, `tests/Feature/Redesign/ReceitasTest.php` |

#### faixa-12 — branch `spec/redesign-visual-faixa-12` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-12`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-042 | Despesas / contas a pagar | `claude-sonnet-5` | high | `resources/views/contas-pagar/index.blade.php`, `resources/views/contas-pagar/_form.blade.php`, `resources/views/contas-pagar/create.blade.php`, `resources/views/contas-pagar/edit.blade.php`, `resources/views/contas-fixas-pagar/index.blade.php`, `app/Http/Controllers/ContaPagarController.php`, `tests/Feature/Redesign/DespesasTest.php` |

### Onda 5 — faixa-13 ∥ faixa-14 ∥ faixa-15

#### faixa-13 — branch `spec/redesign-visual-faixa-13` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-13`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-043 | Caixa e extrato | `claude-sonnet-5` | medium | `resources/views/contas-financeiras/index.blade.php`, `resources/views/contas-financeiras/_form.blade.php`, `resources/views/contas-financeiras/create.blade.php`, `resources/views/contas-financeiras/edit.blade.php`, `resources/views/contas-financeiras/extrato.blade.php`, `app/Http/Controllers/ContaFinanceiraController.php`, `tests/Feature/Redesign/CaixaTest.php` |

#### faixa-14 — branch `spec/redesign-visual-faixa-14` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-14`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-044 | Cadastros auxiliares e plano de contas | `claude-sonnet-5` | medium | `resources/views/cadastros-auxiliares/index.blade.php`, `app/Http/Controllers/CadastroAuxiliarController.php`, `tests/Feature/Redesign/CadastrosTest.php` |

#### faixa-15 — branch `spec/redesign-visual-faixa-15` — worktree `../onp-worktrees/AlfaMatriz-redesign-visual-faixa-15`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-045 | Login e telas de autenticação | `claude-sonnet-5` | medium | `resources/views/auth/login.blade.php`, `resources/views/auth/forgot-password.blade.php`, `resources/views/auth/reset-password.blade.php`, `resources/views/auth/confirm-password.blade.php`, `resources/views/auth/verify-email.blade.php`, `resources/views/layouts/guest.blade.php`, `tests/Feature/Redesign/LoginTest.php` |

## Gestão de branches e commits

1. branch de trabalho `spec/redesign-visual` criada do ponto atual (se ainda não existir)
2. cada faixa nasce dela como branch própria e roda no seu worktree — **1 tarefa = 1 commit** (`T-xxx feature: título`)
3. terminou a onda → merge `--no-ff` de cada faixa de volta, na ordem; conflito interrompe a faixa e pede resolução humana
4. faixa mesclada → worktree removido, branch apagada, tarefa marcada `[concluida]` no tasks.md
5. gate final na branch de trabalho: `onp-spec verify redesign-visual` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/redesign-visual/executar-tarefas.sh
```

Cada faixa roda `claude -p` com **janela de contexto limpa**, no seu worktree, com
`--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`. Os prompts exatos estão
embutidos no script — quer rodar uma faixa na mão, é só copiá-los de lá.
Logs: `../onp-worktrees/AlfaMatriz-redesign-visual-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo redesign-visual --tabela   # a tabela de andamento
onp-spec resumo redesign-visual            # o resumo em texto
```

