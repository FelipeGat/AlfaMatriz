# Plano de execução — clientes-via-revenda

> gerado por `onp-spec plano` em 2026-08-08 13:57 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano clientes-via-revenda`

## Resumo — o que vai acontecer

- **5 tarefa(s) pendente(s)**: 5 em 2 faixa(s) paralela(s) + 0 sequencial(is)
- **1 faixa = 1 worktree + 1 branch + 1 janela de contexto limpa** — faixas não compartilham nenhum arquivo entre si
- prefere outra seleção ou uma após a outra? Regenere com `onp-spec plano clientes-via-revenda --paralelizar T-xxx,T-yyy` ou `--sequencial`
- tudo acontece na branch de trabalho `spec/clientes-via-revenda`; levar para a main é decisão sua

## Faixas e ondas

### Onda 1 — faixa-1 ∥ faixa-2

#### faixa-1 — branch `spec/clientes-via-revenda-faixa-1` — worktree `../onp-worktrees/AlfaMatriz-clientes-via-revenda-faixa-1`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-053 | Revenda obrigatória no cadastro de cliente | `claude-sonnet-5` | low | `app/Http/Controllers/ClienteController.php`, `resources/views/clientes/_form.blade.php`, `tests/Feature/Redesign/ClientesTest.php` |
| T-054 | Listagem sem recorte "venda direta" e sync sempre vincula à revenda | `claude-sonnet-5` | medium | `app/Http/Controllers/ClienteController.php`, `resources/views/clientes/index.blade.php`, `app/Services/SincronizadorSistemaService.php`, `tests/Feature/Redesign/ClientesTest.php`, `tests/Feature/SincronizadorAlfaGymTest.php` |
| T-056 | Persistir status do cliente vindo do AlfaGym no vínculo | `claude-sonnet-5` | medium | `database/migrations/2026_08_08_120000_add_status_saas_to_cliente_sistema_table.php`, `app/Services/SincronizadorSistemaService.php`, `app/Models/Cliente.php`, `app/Models/Sistema.php`, `tests/Feature/SincronizadorAlfaGymTest.php` |
| T-057 | Admin libera a licença do cliente pelo AlfaGym | `claude-sonnet-5` | high | `app/Http/Controllers/ClienteController.php`, `app/Services/LiberadorLicencaAlfaGymService.php`, `resources/views/clientes/index.blade.php`, `routes/web.php`, `tests/Feature/SincronizadorAlfaGymTest.php`, `tests/Feature/Redesign/ClientesTest.php` |

#### faixa-2 — branch `spec/clientes-via-revenda-faixa-2` — worktree `../onp-worktrees/AlfaMatriz-clientes-via-revenda-faixa-2`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-055 | Aba "Clientes" dentro da tela de Revendas | `claude-sonnet-5` | high | `app/Http/Controllers/RevendaController.php`, `resources/views/revendas/index.blade.php`, `resources/views/layouts/navigation.blade.php`, `tests/Feature/Redesign/RevendasTest.php`, `tests/Feature/Autorizacao/EscopoDeRevendaTest.php` |

## Gestão de branches e commits

1. branch de trabalho `spec/clientes-via-revenda` criada do ponto atual (se ainda não existir)
2. cada faixa nasce dela como branch própria e roda no seu worktree — **1 tarefa = 1 commit** (`T-xxx feature: título`)
3. terminou a onda → merge `--no-ff` de cada faixa de volta, na ordem; conflito interrompe a faixa e pede resolução humana
4. faixa mesclada → worktree removido, branch apagada, tarefa marcada `[concluida]` no tasks.md
5. gate final na branch de trabalho: `onp-spec verify clientes-via-revenda` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/clientes-via-revenda/executar-tarefas.sh
```

Cada faixa roda `claude -p` com **janela de contexto limpa**, no seu worktree, com
`--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`. Os prompts exatos estão
embutidos no script — quer rodar uma faixa na mão, é só copiá-los de lá.
Logs: `../onp-worktrees/AlfaMatriz-clientes-via-revenda-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo clientes-via-revenda --tabela   # a tabela de andamento
onp-spec resumo clientes-via-revenda            # o resumo em texto
```

