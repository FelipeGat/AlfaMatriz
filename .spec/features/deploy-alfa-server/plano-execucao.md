# Plano de execução — deploy-alfa-server

> gerado por `onp-spec plano` em 2026-08-05 16:08 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano deploy-alfa-server --paralelizar T-001,T-002,T-003,T-004,T-005,T-006,T-007,T-008,T-009,T-010,T-011,T-012`

## Resumo — o que vai acontecer

- **13 tarefa(s) pendente(s)**: 12 em 12 faixa(s) paralela(s) + 1 sequencial(is)
- **seleção do usuário**: paralelizar só T-001, T-002, T-003, T-004, T-005, T-006, T-007, T-008, T-009, T-010, T-011, T-012 — as demais rodam uma após a outra, ao final
- **1 faixa = 1 worktree + 1 branch + 1 janela de contexto limpa** — faixas não compartilham nenhum arquivo entre si
- prefere outra seleção ou uma após a outra? Regenere com `onp-spec plano deploy-alfa-server --paralelizar T-xxx,T-yyy` ou `--sequencial`
- tudo acontece na branch de trabalho `spec/deploy-alfa-server`; levar para a main é decisão sua

## Faixas e ondas

### Onda 1 — faixa-1 ∥ faixa-2 ∥ faixa-3

#### faixa-1 — branch `spec/deploy-alfa-server-faixa-1` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-1`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-001 | Desativar o cadastro público | `claude-sonnet-5` | medium | `routes/auth.php`, `tests/Feature/Deploy/RegistroDesativadoTest.php` |

#### faixa-2 — branch `spec/deploy-alfa-server-faixa-2` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-2`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-002 | Comando administrativo de criação de usuário | `claude-sonnet-5` | medium | `app/Console/Commands/CriarUsuario.php`, `tests/Feature/Deploy/CriarUsuarioCommandTest.php` |

#### faixa-3 — branch `spec/deploy-alfa-server-faixa-3` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-3`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-003 | Bloqueio por tentativas repetidas de login | `claude-sonnet-5` | medium | `tests/Feature/Deploy/LoginThrottleTest.php` |

### Onda 2 — faixa-4 ∥ faixa-5 ∥ faixa-6

#### faixa-4 — branch `spec/deploy-alfa-server-faixa-4` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-4`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-004 | HTTPS correto atrás do Funnel | `claude-sonnet-5` | medium | `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/Deploy/HttpsAtrasDoProxyTest.php` |

#### faixa-5 — branch `spec/deploy-alfa-server-faixa-5` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-5`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-005 | Checagem de saúde sem login | `claude-sonnet-5` | medium | `routes/web.php`, `app/Http/Controllers/SaudeController.php`, `tests/Feature/Deploy/SaudeTest.php` |

#### faixa-6 — branch `spec/deploy-alfa-server-faixa-6` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-6`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-006 | Senha do admin vinda do ambiente | `claude-sonnet-5` | medium | `database/seeders/DadosIniciaisSeeder.php`, `tests/Feature/Deploy/SeederSenhaAdminTest.php` |

### Onda 3 — faixa-7 ∥ faixa-8 ∥ faixa-9

#### faixa-7 — branch `spec/deploy-alfa-server-faixa-7` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-7`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-007 | Modelo de ambiente de produção endurecido | `claude-sonnet-5` | medium | `deploy/.env.producao.exemplo`, `tests/Feature/Deploy/AmbienteProducaoTest.php` |

#### faixa-8 — branch `spec/deploy-alfa-server-faixa-8` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-8`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-008 | Script de provisionamento do container | `claude-sonnet-5` | medium | `deploy/provisionar.sh`, `deploy/nginx-alfamatriz.conf`, `tests/Feature/Deploy/ScriptProvisionarTest.php` |

#### faixa-9 — branch `spec/deploy-alfa-server-faixa-9` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-9`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-009 | Script de publicação da aplicação | `claude-sonnet-5` | medium | `deploy/publicar.sh`, `tests/Feature/Deploy/ScriptPublicarTest.php` |

### Onda 4 — faixa-10 ∥ faixa-11 ∥ faixa-12

#### faixa-10 — branch `spec/deploy-alfa-server-faixa-10` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-10`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-010 | Script de conferência pós-deploy | `claude-sonnet-5` | medium | `deploy/smoke.sh`, `tests/Feature/Deploy/ScriptSmokeTest.php` |

#### faixa-11 — branch `spec/deploy-alfa-server-faixa-11` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-11`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-011 | Backup diário com retenção de sete dias | `claude-sonnet-5` | medium | `deploy/backup.sh`, `tests/Feature/Deploy/ScriptBackupTest.php` |

#### faixa-12 — branch `spec/deploy-alfa-server-faixa-12` — worktree `../onp-worktrees/AlfaMatriz-deploy-alfa-server-faixa-12`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-012 | Restauração protegida | `claude-sonnet-5` | medium | `deploy/restaurar.sh`, `tests/Feature/Deploy/ScriptRestaurarTest.php` |

## Tarefas sequenciais (após as ondas, na árvore principal)

| tarefa | título | modelo | esforço | por que sequencial |
|---|---|---|---|---|
| T-013 | Provisionar, publicar e conferir no alfa-server | `claude-sonnet-5` | medium | fora da seleção do usuário |

## Gestão de branches e commits

1. branch de trabalho `spec/deploy-alfa-server` criada do ponto atual (se ainda não existir)
2. cada faixa nasce dela como branch própria e roda no seu worktree — **1 tarefa = 1 commit** (`T-xxx feature: título`)
3. terminou a onda → merge `--no-ff` de cada faixa de volta, na ordem; conflito interrompe a faixa e pede resolução humana
4. faixa mesclada → worktree removido, branch apagada, tarefa marcada `[concluida]` no tasks.md
5. gate final na branch de trabalho: `onp-spec verify deploy-alfa-server` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/deploy-alfa-server/executar-tarefas.sh
```

Cada faixa roda `claude -p` com **janela de contexto limpa**, no seu worktree, com
`--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`. Os prompts exatos estão
embutidos no script — quer rodar uma faixa na mão, é só copiá-los de lá.
Logs: `../onp-worktrees/AlfaMatriz-deploy-alfa-server-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo deploy-alfa-server --tabela   # a tabela de andamento
onp-spec resumo deploy-alfa-server            # o resumo em texto
```

