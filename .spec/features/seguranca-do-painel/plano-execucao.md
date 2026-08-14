# Plano de execução — seguranca-do-painel

> gerado por `onp-spec plano` em 2026-08-14 21:15 — NÃO edite à mão;
> mudou tasks.md ou a config? Regenere: `onp-spec plano seguranca-do-painel`

## Resumo — o que vai acontecer

- **6 tarefa(s) pendente(s)**: 6 em 5 faixa(s) paralela(s) + 0 sequencial(is)
- **1 faixa = 1 worktree + 1 branch + 1 janela de contexto limpa** — faixas não compartilham nenhum arquivo entre si
- prefere outra seleção ou uma após a outra? Regenere com `onp-spec plano seguranca-do-painel --paralelizar T-xxx,T-yyy` ou `--sequencial`
- tudo acontece na branch de trabalho `spec/seguranca-do-painel`; levar para a main é decisão sua

## Faixas e ondas

### Onda 1 — faixa-1 ∥ faixa-2 ∥ faixa-3

#### faixa-1 — branch `spec/seguranca-do-painel-faixa-1` — worktree `../onp-worktrees/AlfaMatriz-seguranca-do-painel-faixa-1`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-001 | Tirar do ar a recuperação de senha por e-mail | `claude-sonnet-5` | medium | `routes/auth.php`, `app/Http/Controllers/Auth/NewPasswordController.php`, `app/Http/Controllers/Auth/PasswordResetLinkController.php`, `app/Http/Controllers/Auth/RegisteredUserController.php`, `resources/views/auth/forgot-password.blade.php`, `resources/views/auth/reset-password.blade.php`, `resources/views/auth/register.blade.php`, `tests/Feature/Auth/PasswordResetTest.php`, `tests/Feature/Seguranca/RecuperacaoDeSenhaTest.php` |
| T-002 | Limitar as tentativas de confirmar a senha | `claude-sonnet-5` | medium | `routes/auth.php`, `tests/Feature/Seguranca/ConfirmarSenhaTest.php` |

#### faixa-2 — branch `spec/seguranca-do-painel-faixa-2` — worktree `../onp-worktrees/AlfaMatriz-seguranca-do-painel-faixa-2`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-003 | Fechar a escalada por `usuarios` | `claude-sonnet-5` | medium | `app/Http/Controllers/UsuarioController.php`, `app/Models/User.php`, `tests/Feature/Seguranca/EscaladaDeUsuariosTest.php` |

#### faixa-3 — branch `spec/seguranca-do-painel-faixa-3` — worktree `../onp-worktrees/AlfaMatriz-seguranca-do-painel-faixa-3`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-004 | Cabeçalhos de segurança emitidos pelo aplicativo | `claude-sonnet-5` | medium | `app/Http/Middleware/CabecalhosDeSeguranca.php`, `bootstrap/app.php`, `tests/Feature/Seguranca/CabecalhosDeSegurancaTest.php` |

### Onda 2 — faixa-4 ∥ faixa-5

#### faixa-4 — branch `spec/seguranca-do-painel-faixa-4` — worktree `../onp-worktrees/AlfaMatriz-seguranca-do-painel-faixa-4`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-005 | Endereço de sistema integrado só público e em HTTPS | `claude-sonnet-5` | medium | `app/Rules/EnderecoPublico.php`, `app/Http/Controllers/SistemaController.php`, `tests/Feature/Seguranca/EnderecoDeSistemaTest.php` |

#### faixa-5 — branch `spec/seguranca-do-painel-faixa-5` — worktree `../onp-worktrees/AlfaMatriz-seguranca-do-painel-faixa-5`

| tarefa | título | modelo | esforço | arquivos |
|---|---|---|---|---|
| T-006 | Guarda de regressão do fechamento de `/storage/` | `claude-sonnet-5` | medium | `tests/Feature/Seguranca/DiscoDeAnexosFechadoTest.php` |

## Gestão de branches e commits

1. branch de trabalho `spec/seguranca-do-painel` criada do ponto atual (se ainda não existir)
2. cada faixa nasce dela como branch própria e roda no seu worktree — **1 tarefa = 1 commit** (`T-xxx feature: título`)
3. terminou a onda → merge `--no-ff` de cada faixa de volta, na ordem; conflito interrompe a faixa e pede resolução humana
4. faixa mesclada → worktree removido, branch apagada, tarefa marcada `[concluida]` no tasks.md
5. gate final na branch de trabalho: `onp-spec verify seguranca-do-painel` + `onp-spec audit --ci` — **exit 0 ou não está pronto**

## Como executar

### ▶ Execução — Claude Code headless

```bash
bash .spec/features/seguranca-do-painel/executar-tarefas.sh
```

Cada faixa roda `claude -p` com **janela de contexto limpa**, no seu worktree, com
`--model` e `--effort` já definidos por tarefa e permissões `acceptEdits`. Os prompts exatos estão
embutidos no script — quer rodar uma faixa na mão, é só copiá-los de lá.
Logs: `../onp-worktrees/AlfaMatriz-seguranca-do-painel-logs/`.

### 📣 Acompanhamento — tabela + resumo no chat (a cada 1 min)

O script roda em **background**: o agente AVISA o usuário antes de iniciar e,
enquanto roda, posta no chat a cada ~1 minuto a **tabela de andamento** (qual
tarefa está rodando, qual não está, o que concluiu/falhou) junto com o
**resumo geral de andamento** (escrito por IA; sem IA, o motor resume). Ao
final, o usuário recebe o resumo completo da execução. A qualquer momento:

```bash
onp-spec resumo seguranca-do-painel --tabela   # a tabela de andamento
onp-spec resumo seguranca-do-painel            # o resumo em texto
```

