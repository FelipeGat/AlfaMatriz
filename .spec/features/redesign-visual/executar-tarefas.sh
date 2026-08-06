#!/usr/bin/env bash
# executar-tarefas.sh — gerado por `onp-spec plano redesign-visual` em 2026-08-06 16:40
# NÃO edite à mão: mudou tasks.md ou a config, regenere o plano.
#
# uso:
#   bash executar-tarefas.sh                  tudo (ondas → sequenciais → gate)
#   bash executar-tarefas.sh --faixa <id>     reexecuta UMA faixa (+ merge + gate)
#   bash executar-tarefas.sh --seq <T-xxx>    reexecuta UMA tarefa sequencial
#   bash executar-tarefas.sh --gate           só o gate (verify + audit)
#   bash executar-tarefas.sh --listar         mostra faixas, tarefas e estados
#   (acrescente --sem-gate para não rodar o gate ao final)
#
# resumo do que está rolando, a qualquer momento: onp-spec resumo redesign-visual
set -u
set -o pipefail

RUN_ID='AlfaMatriz-redesign-visual-mshqs60f'
FEATURE='redesign-visual'
BASE_BRANCH='spec/redesign-visual'
ENGINE='.claude/skills/onp-spec-driven/scripts/onp-spec.mjs'
CLAUDE_FLAGS=(--permission-mode acceptEdits --allowedTools 'Bash(git add:*),Bash(git commit:*),Bash(git status:*),Bash(git diff:*),Bash(git log:*),Bash(php:*)')
STREAM_FLAGS=(--output-format stream-json --verbose)
FALHAS=""
COM_GATE=1
RESUMO_MODEL='claude-haiku-4-5'
RESUMO_PID=""

verde()    { printf '\033[32m%s\033[0m\n' "$*"; }
vermelho() { printf '\033[31m%s\033[0m\n' "$*"; }
amarelo()  { printf '\033[33m%s\033[0m\n' "$*"; }
info()     { printf '· %s\n' "$*"; }
falhar()   { vermelho "✘ $*"; exit 1; }

# eventos vão para o ledger GLOBAL (~/.onp-spec/painel/ledger.jsonl):
# um arquivo para todos os projetos, é o que o onp-spec resumo lê
evento() { node "$ENGINE" evento --run "$RUN_ID" "$@" >/dev/null 2>&1 || true; }

# ── ambiente (todos os modos passam por aqui) ────────────────────────
preparar_ambiente() {
  command -v git >/dev/null 2>&1 || falhar "git não encontrado"
  command -v node >/dev/null 2>&1 || falhar "node não encontrado"
  command -v claude >/dev/null 2>&1 || falhar "Claude Code CLI (claude) não encontrado — instale-o ou siga o modo manual em plano-execucao.md"
  TOPLEVEL=$(git rev-parse --show-toplevel 2>/dev/null) || falhar "fora de um repositório git"
  cd "$TOPLEVEL" || exit 1
  # artefatos recém-gerados pelo `onp-spec plano` são sujeira esperada:
  # se forem a ÚNICA sujeira, o script mesmo commita; qualquer outra, aborta
  if [ -n "$(git status --porcelain)" ]; then
    if [ -z "$(git status --porcelain | grep -v -e 'plano-execucao\.' -e 'plano\.json' -e 'executar-tarefas\.sh')" ]; then
      git add -A
      git commit -q -m "plano de execução: $FEATURE (artefatos gerados)"
      info "artefatos do plano commitados"
    else
      falhar "árvore suja além dos artefatos do plano — commite ou faça git stash antes (os worktrees partem do último commit)"
    fi
  fi
  git ls-files --error-unmatch -- '.spec/features/redesign-visual/spec.md' >/dev/null 2>&1 || falhar "spec.md não está commitada — os worktrees das faixas precisam dela no git"
  ATUAL=$(git rev-parse --abbrev-ref HEAD)
  [ "$ATUAL" != "HEAD" ] || falhar "HEAD destacado — troque para uma branch"
  if [ "$ATUAL" != "$BASE_BRANCH" ]; then
    if git show-ref --verify --quiet "refs/heads/$BASE_BRANCH"; then
      git checkout -q "$BASE_BRANCH" || falhar "não consegui trocar para $BASE_BRANCH"
    else
      git checkout -q -b "$BASE_BRANCH" || falhar "não consegui criar $BASE_BRANCH"
    fi
    info "branch de trabalho: $BASE_BRANCH (a partir de $ATUAL)"
  fi
  git worktree prune
  LOG_DIR="$(dirname "$TOPLEVEL")/onp-worktrees/AlfaMatriz-redesign-visual-logs"
  WT_BASE="$(dirname "$TOPLEVEL")/onp-worktrees/AlfaMatriz-redesign-visual"
  STREAMS_DIR="${ONP_SPEC_HOME:-$HOME/.onp-spec}/painel/streams/$RUN_ID"
  mkdir -p "$LOG_DIR" "$STREAMS_DIR"
}

# worktree limpo mesmo depois de uma tentativa que falhou
preparar_worktree() { # $1=faixa $2=branch $3=worktree
  git worktree prune
  if [ -e "$3" ]; then git worktree remove --force "$3" >/dev/null 2>&1; rm -rf "$3"; fi
  if git show-ref --verify --quiet "refs/heads/$2"; then git branch -D "$2" >/dev/null 2>&1; fi
  git worktree add "$3" -b "$2" >/dev/null 2>&1 || { vermelho "✘ não consegui criar o worktree de $1 em $3"; return 1; }
}

tentativa() { # $1=faixa — conta reexecuções (vai para o ledger)
  local arq="$LOG_DIR/.tentativa-$1"
  local n=1
  [ -f "$arq" ] && n=$(( $(cat "$arq") + 1 ))
  printf "%s" "$n" > "$arq"
  printf "%s" "$n"
}

# uma tarefa = uma sessão claude headless com contexto limpo.
# o JSONL da sessão vira o stream da tarefa no ledger
rodar_tarefa() { # $1=escopo(faixa|seq) $2=T-xxx $3=prompt $4=modelo $5=esforço
  local chave="$1--$2"
  local stream="$STREAMS_DIR/$chave.jsonl"
  evento --tipo tarefa --tarefa "$2" --faixa "$1" --estado executando --stream "$chave"
  info "$2 — claude -p ($4 · $5) · stream: $chave"
  if claude -p "$3" --model "$4" --effort "$5" "${STREAM_FLAGS[@]}" "${CLAUDE_FLAGS[@]}" > "$stream" 2>>"$LOG_DIR/$1.log"; then
    evento --tipo tarefa --tarefa "$2" --faixa "$1" --estado concluida --stream "$chave"
    node "$ENGINE" stream-resumo "$RUN_ID" "$chave" 2>/dev/null || true
    return 0
  fi
  evento --tipo tarefa --tarefa "$2" --faixa "$1" --estado falhou --stream "$chave"
  node "$ENGINE" stream-resumo "$RUN_ID" "$chave" 2>/dev/null || true
  return 1
}

mesclar_faixa() { # $1=faixa $2=branch $3=worktree $4=exit-da-faixa
  if [ "$4" -ne 0 ]; then
    evento --tipo faixa --faixa "$1" --estado falhou
    vermelho "✘ $1 falhou (log: $LOG_DIR/$1.log) — worktree mantido para inspeção: $3"
    amarelo "  reexecute só ela: bash .spec/features/redesign-visual/executar-tarefas.sh --faixa $1"
    FALHAS="$FALHAS $1"; return 1
  fi
  evento --tipo faixa --faixa "$1" --estado mesclando
  if git merge --no-ff "$2" -m "merge $1 ($FEATURE)"; then
    git worktree remove --force "$3" >/dev/null 2>&1
    git branch -d "$2" >/dev/null 2>&1
    evento --tipo faixa --faixa "$1" --estado mesclada
    verde "✔ $1 mesclada em $BASE_BRANCH"
  else
    git merge --abort >/dev/null 2>&1
    evento --tipo faixa --faixa "$1" --estado conflito
    vermelho "✘ conflito ao mesclar $1 — resolva na mão: git merge $2 (worktree mantido: $3)"
    FALHAS="$FALHAS $1"; return 1
  fi
}

marcar_concluidas() { # $@=T-xxx
  for t in "$@"; do node "$ENGINE" tarefa "$FEATURE" "$t" concluida >/dev/null || true; done
}

# ── resumo geral de andamento: 1/min enquanto a execução roda ─────────
# escrito por IA (claude -p, sem ferramentas) com fallback do motor; vai
# para o terminal e para o ledger — o agente repassa o texto no chat.
gerar_resumo() {
  local ctx ia
  ctx=$(node "$ENGINE" resumo "$FEATURE" --contexto 2>/dev/null) || ctx=""
  [ -n "$ctx" ] || return 0
  ia=$(claude -p "Você narra, para o dono do produto, uma execução de tarefas de código em andamento. Estado mecânico:

$ctx

Escreva o RESUMO GERAL DE ANDAMENTO: um parágrafo único de 2 a 4 frases, em português simples, dizendo o que está acontecendo agora, o que já terminou, o que falhou e se o usuário precisa agir. Sem markdown, sem listas." --model "$RESUMO_MODEL" 2>/dev/null)
  if [ -n "$ia" ]; then
    node "$ENGINE" resumo "$FEATURE" --gravar --origem ia --texto "$ia" >/dev/null 2>&1 || true
    printf '\n📣 resumo (IA): %s\n' "$ia"
  else
    node "$ENGINE" resumo "$FEATURE" --gravar >/dev/null 2>&1 || true
    printf '\n📣 resumo: %s\n' "$(node "$ENGINE" resumo "$FEATURE" 2>/dev/null)"
  fi
}

# mata o loop E o sleep filho — senão o sleep herda o stdout e quem chamou
# o script via pipe fica esperando EOF por até 60s depois do exit
parar_resumos() {
  [ -n "$RESUMO_PID" ] || return 0
  command -v pkill >/dev/null 2>&1 && pkill -P "$RESUMO_PID" 2>/dev/null
  kill "$RESUMO_PID" 2>/dev/null
  RESUMO_PID=""
}

iniciar_resumos() {
  ( while :; do sleep 60; gerar_resumo; done ) &
  RESUMO_PID=$!
  # ao sair: para o loop e grava um último resumo (o estado final, do motor)
  trap 'parar_resumos; node "$ENGINE" resumo "$FEATURE" --gravar >/dev/null 2>&1 || true' EXIT
}

# ── faixa-1: T-034 ──
executar_faixa_1() {
  local WT="$WT_BASE-faixa-1"
  preparar_worktree 'faixa-1' 'spec/redesign-visual-faixa-1' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-1' --estado executando --tentativa "$(tentativa 'faixa-1')"
  : > "$LOG_DIR/faixa-1.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-1' 'T-034' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-034 — "Centro de Controle"
  critérios/refs: AC-039 (A fila de ação lista o que pede decisão e leva até lá), AC-040 (Os cards de destaque trazem valor, variação e tendência), AC-041 (A régua de origem da receita desenha barras proporcionais ao valor)
  arquivos permitidos (e seus testes): resources/views/centro-controle/index.blade.php, app/Http/Controllers/CentroControleController.php, tests/Feature/Redesign/CentroControleTest.php
  mensagem de commit: "T-034 redesign-visual: Centro de Controle"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' high
  ) >> "$LOG_DIR/faixa-1.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-1' 'spec/redesign-visual-faixa-1' "$WT" "$st" || return 1
  marcar_concluidas T-034
  return 0
}

# ── faixa-2: T-035 ──
executar_faixa_2() {
  local WT="$WT_BASE-faixa-2"
  preparar_worktree 'faixa-2' 'spec/redesign-visual-faixa-2' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-2' --estado executando --tentativa "$(tentativa 'faixa-2')"
  : > "$LOG_DIR/faixa-2.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-2' 'T-035' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-035 — "Painéis Financeiro e Comercial"
  critérios/refs: AC-042 (O painel Financeiro mostra o mês, o histórico e o que está em aberto), AC-043 (O painel Comercial ranqueia os produtos com grandeza comparável)
  arquivos permitidos (e seus testes): resources/views/dashboard.blade.php, resources/views/dashboard-comercial.blade.php, app/Http/Controllers/PainelController.php, tests/Feature/Redesign/PaineisTest.php
  mensagem de commit: "T-035 redesign-visual: Painéis Financeiro e Comercial"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' high
  ) >> "$LOG_DIR/faixa-2.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-2' 'spec/redesign-visual-faixa-2' "$WT" "$st" || return 1
  marcar_concluidas T-035
  return 0
}

# ── faixa-3: T-036 ──
executar_faixa_3() {
  local WT="$WT_BASE-faixa-3"
  preparar_worktree 'faixa-3' 'spec/redesign-visual-faixa-3' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-3' --estado executando --tentativa "$(tentativa 'faixa-3')"
  : > "$LOG_DIR/faixa-3.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-3' 'T-036' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-036 — "Funil de vendas com arrastar e soltar"
  critérios/refs: AC-044 (O quadro ocupa a tela e cada coluna rola por dentro), AC-045 (Arrastar move o lead, e o menu faz o mesmo sem mouse)
  arquivos permitidos (e seus testes): resources/views/leads/index.blade.php, app/Http/Controllers/LeadController.php, tests/Feature/Redesign/FunilTest.php
  mensagem de commit: "T-036 redesign-visual: Funil de vendas com arrastar e soltar"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' high
  ) >> "$LOG_DIR/faixa-3.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-3' 'spec/redesign-visual-faixa-3' "$WT" "$st" || return 1
  marcar_concluidas T-036
  return 0
}

# ── faixa-4: T-037 ──
executar_faixa_4() {
  local WT="$WT_BASE-faixa-4"
  preparar_worktree 'faixa-4' 'spec/redesign-visual-faixa-4' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-4' --estado executando --tentativa "$(tentativa 'faixa-4')"
  : > "$LOG_DIR/faixa-4.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-4' 'T-037' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-037 — "Revendas"
  critérios/refs: AC-048 (Revendas, Clientes e Produtos trazem resumo, filtro, tabela e contagem)
  arquivos permitidos (e seus testes): resources/views/revendas/index.blade.php, resources/views/revendas/_form.blade.php, resources/views/revendas/create.blade.php, resources/views/revendas/edit.blade.php, app/Http/Controllers/RevendaController.php, tests/Feature/Redesign/RevendasTest.php
  mensagem de commit: "T-037 redesign-visual: Revendas"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium
  ) >> "$LOG_DIR/faixa-4.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-4' 'spec/redesign-visual-faixa-4' "$WT" "$st" || return 1
  marcar_concluidas T-037
  return 0
}

# ── faixa-5: T-038 ──
executar_faixa_5() {
  local WT="$WT_BASE-faixa-5"
  preparar_worktree 'faixa-5' 'spec/redesign-visual-faixa-5' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-5' --estado executando --tentativa "$(tentativa 'faixa-5')"
  : > "$LOG_DIR/faixa-5.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-5' 'T-038' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-038 — "Clientes: lista e formulário"
  critérios/refs: AC-048 (Revendas, Clientes e Produtos trazem resumo, filtro, tabela e contagem)
  arquivos permitidos (e seus testes): resources/views/clientes/index.blade.php, resources/views/clientes/_form.blade.php, resources/views/clientes/create.blade.php, resources/views/clientes/edit.blade.php, app/Http/Controllers/ClienteController.php, tests/Feature/Redesign/ClientesTest.php
  mensagem de commit: "T-038 redesign-visual: Clientes: lista e formulário"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' high
  ) >> "$LOG_DIR/faixa-5.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-5' 'spec/redesign-visual-faixa-5' "$WT" "$st" || return 1
  marcar_concluidas T-038
  return 0
}

# ── faixa-6: T-039 ──
executar_faixa_6() {
  local WT="$WT_BASE-faixa-6"
  preparar_worktree 'faixa-6' 'spec/redesign-visual-faixa-6' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-6' --estado executando --tentativa "$(tentativa 'faixa-6')"
  : > "$LOG_DIR/faixa-6.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-6' 'T-039' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-039 — "Produtos: lista comparável e gestão do sistema"
  critérios/refs: AC-048 (Revendas, Clientes e Produtos trazem resumo, filtro, tabela e contagem), AC-049 (Produtos abre como lista comparável, e o modo escolhido persiste)
  arquivos permitidos (e seus testes): resources/views/produtos/index.blade.php, resources/views/sistemas/index.blade.php, resources/views/sistemas/edit.blade.php, app/Http/Controllers/ProdutoController.php, tests/Feature/Redesign/ProdutosTest.php
  mensagem de commit: "T-039 redesign-visual: Produtos: lista comparável e gestão do sistema"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' high
  ) >> "$LOG_DIR/faixa-6.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-6' 'spec/redesign-visual-faixa-6' "$WT" "$st" || return 1
  marcar_concluidas T-039
  return 0
}

# ── faixa-7: T-040 ──
executar_faixa_7() {
  local WT="$WT_BASE-faixa-7"
  preparar_worktree 'faixa-7' 'spec/redesign-visual-faixa-7' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-7' --estado executando --tentativa "$(tentativa 'faixa-7')"
  : > "$LOG_DIR/faixa-7.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-7' 'T-040' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-040 — "Faturamento das revendas"
  critérios/refs: AC-050 (A barra do ciclo resume o que será gerado, e o botão diz quanto), AC-051 (Cada linha mostra o cálculo, e o subtotal é a soma das linhas), AC-052 (Linha sem tier fica de fora e aparece explicada)
  arquivos permitidos (e seus testes): resources/views/faturamento/index.blade.php, app/Http/Controllers/FaturamentoController.php, app/Services/FaturamentoService.php, tests/Feature/Redesign/FaturamentoTest.php
  mensagem de commit: "T-040 redesign-visual: Faturamento das revendas"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' high
  ) >> "$LOG_DIR/faixa-7.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-7' 'spec/redesign-visual-faixa-7' "$WT" "$st" || return 1
  marcar_concluidas T-040
  return 0
}

# ── faixa-8: T-041 ──
executar_faixa_8() {
  local WT="$WT_BASE-faixa-8"
  preparar_worktree 'faixa-8' 'spec/redesign-visual-faixa-8' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-8' --estado executando --tentativa "$(tentativa 'faixa-8')"
  : > "$LOG_DIR/faixa-8.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-8' 'T-041' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-041 — "Receitas / contas a receber"
  critérios/refs: AC-053 (A faixa de atraso distribui o que está em aberto por vencimento), AC-054 (Selecionar títulos mostra o quanto e dá baixa de uma vez), AC-055 (Atrasado e a vencer se distinguem à primeira vista)
  arquivos permitidos (e seus testes): resources/views/cobrancas/index.blade.php, resources/views/cobrancas/_form.blade.php, resources/views/cobrancas/create.blade.php, resources/views/cobrancas/edit.blade.php, resources/views/cobrancas/show.blade.php, app/Http/Controllers/CobrancaController.php, tests/Feature/Redesign/ReceitasTest.php
  mensagem de commit: "T-041 redesign-visual: Receitas / contas a receber"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' high
  ) >> "$LOG_DIR/faixa-8.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-8' 'spec/redesign-visual-faixa-8' "$WT" "$st" || return 1
  marcar_concluidas T-041
  return 0
}

# ── faixa-9: T-042 ──
executar_faixa_9() {
  local WT="$WT_BASE-faixa-9"
  preparar_worktree 'faixa-9' 'spec/redesign-visual-faixa-9' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-9' --estado executando --tentativa "$(tentativa 'faixa-9')"
  : > "$LOG_DIR/faixa-9.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-9' 'T-042' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-042 — "Despesas / contas a pagar"
  critérios/refs: AC-053 (A faixa de atraso distribui o que está em aberto por vencimento), AC-054 (Selecionar títulos mostra o quanto e dá baixa de uma vez), AC-055 (Atrasado e a vencer se distinguem à primeira vista)
  arquivos permitidos (e seus testes): resources/views/contas-pagar/index.blade.php, resources/views/contas-pagar/_form.blade.php, resources/views/contas-pagar/create.blade.php, resources/views/contas-pagar/edit.blade.php, resources/views/contas-fixas-pagar/index.blade.php, app/Http/Controllers/ContaPagarController.php, tests/Feature/Redesign/DespesasTest.php
  mensagem de commit: "T-042 redesign-visual: Despesas / contas a pagar"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' high
  ) >> "$LOG_DIR/faixa-9.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-9' 'spec/redesign-visual-faixa-9' "$WT" "$st" || return 1
  marcar_concluidas T-042
  return 0
}

# ── faixa-10: T-043 ──
executar_faixa_10() {
  local WT="$WT_BASE-faixa-10"
  preparar_worktree 'faixa-10' 'spec/redesign-visual-faixa-10' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-10' --estado executando --tentativa "$(tentativa 'faixa-10')"
  : > "$LOG_DIR/faixa-10.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-10' 'T-043' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-043 — "Caixa e extrato"
  critérios/refs: AC-056 (O caixa mostra o consolidado, cada conta e o mês), AC-057 (O extrato mostra o saldo resultante linha a linha)
  arquivos permitidos (e seus testes): resources/views/contas-financeiras/index.blade.php, resources/views/contas-financeiras/_form.blade.php, resources/views/contas-financeiras/create.blade.php, resources/views/contas-financeiras/edit.blade.php, resources/views/contas-financeiras/extrato.blade.php, app/Http/Controllers/ContaFinanceiraController.php, tests/Feature/Redesign/CaixaTest.php
  mensagem de commit: "T-043 redesign-visual: Caixa e extrato"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium
  ) >> "$LOG_DIR/faixa-10.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-10' 'spec/redesign-visual-faixa-10' "$WT" "$st" || return 1
  marcar_concluidas T-043
  return 0
}

# ── faixa-11: T-044 ──
executar_faixa_11() {
  local WT="$WT_BASE-faixa-11"
  preparar_worktree 'faixa-11' 'spec/redesign-visual-faixa-11' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-11' --estado executando --tentativa "$(tentativa 'faixa-11')"
  : > "$LOG_DIR/faixa-11.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-11' 'T-044' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-044 — "Cadastros auxiliares e plano de contas"
  critérios/refs: AC-058 (Cada item mostra quantos lançamentos dependem dele), AC-059 (O plano de contas lê-se na horizontal)
  arquivos permitidos (e seus testes): resources/views/cadastros-auxiliares/index.blade.php, app/Http/Controllers/CadastroAuxiliarController.php, tests/Feature/Redesign/CadastrosTest.php
  mensagem de commit: "T-044 redesign-visual: Cadastros auxiliares e plano de contas"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium
  ) >> "$LOG_DIR/faixa-11.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-11' 'spec/redesign-visual-faixa-11' "$WT" "$st" || return 1
  marcar_concluidas T-044
  return 0
}

# ── faixa-12: T-045 ──
executar_faixa_12() {
  local WT="$WT_BASE-faixa-12"
  preparar_worktree 'faixa-12' 'spec/redesign-visual-faixa-12' "$WT" || return 1
  evento --tipo faixa --faixa 'faixa-12' --estado executando --tentativa "$(tentativa 'faixa-12')"
  : > "$LOG_DIR/faixa-12.log"
  (
    cd "$WT" || exit 9
    rodar_tarefa 'faixa-12' 'T-045' 'Você executa UMA tarefa da feature "redesign-visual" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/redesign-visual/spec.md, .spec/features/redesign-visual/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-045 — "Login e telas de autenticação"
  critérios/refs: AC-060 (O login traz a marca, os campos e o estado do sistema)
  arquivos permitidos (e seus testes): resources/views/auth/login.blade.php, resources/views/auth/forgot-password.blade.php, resources/views/auth/reset-password.blade.php, resources/views/auth/confirm-password.blade.php, resources/views/auth/verify-email.blade.php, resources/views/layouts/guest.blade.php, tests/Feature/Redesign/LoginTest.php
  mensagem de commit: "T-045 redesign-visual: Login e telas de autenticação"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium
  ) >> "$LOG_DIR/faixa-12.log" 2>&1
  local st=$?
  mesclar_faixa 'faixa-12' 'spec/redesign-visual-faixa-12' "$WT" "$st" || return 1
  marcar_concluidas T-045
  return 0
}

# ── gate: quem decide é a máquina ────────────────────────────────────
rodar_gate() {
  echo
  info "gate: verify + audit --ci"
  evento --tipo gate --etapa inicio
  node "$ENGINE" verify "$FEATURE"
  local v=$?
  evento --tipo gate --etapa verify --exit "$v"
  node "$ENGINE" audit --ci
  AUDIT=$?
  evento --tipo gate --etapa audit --exit "$AUDIT"
  # fecha a contabilidade: status das tarefas + prova do verify no git
  if [ -n "$(git status --porcelain -- '.spec')" ]; then
    git add -A -- '.spec'
    git commit -q -m "$FEATURE: status das tarefas + prova do verify (plano)"
    info "status das tarefas e prova do verify commitados"
  fi
  return "$AUDIT"
}

encerrar() { # $1=escopo
  echo
  if [ -n "$FALHAS" ]; then vermelho "faixas/tarefas com falha:$FALHAS"; fi
  # sem gate não existe veredito: NUNCA anunciar alinhamento sem o audit
  if [ "$COM_GATE" -eq 0 ]; then
    evento --tipo fim --exit 1 --escopo "$1"
    if [ -z "$FALHAS" ]; then
      amarelo "○ trabalho de '$1' terminou SEM o gate (--sem-gate) — isto NÃO é prova de nada"
      amarelo "  para o veredito: bash .spec/features/redesign-visual/executar-tarefas.sh --gate"
      exit 0
    fi
    vermelho "e ainda há falhas — conserte e rode o gate"
    exit 1
  fi
  rodar_gate
  local audit=$?
  if [ "$audit" -eq 0 ] && [ -z "$FALHAS" ]; then
    evento --tipo fim --exit 0 --escopo "$1"
    verde "✔ plano concluído — especificação e código alinhados (audit exit 0) na branch $BASE_BRANCH"
    info "próximo passo: revise e leve para a main quando quiser (git merge $BASE_BRANCH)"
    exit 0
  fi
  evento --tipo fim --exit 1 --escopo "$1"
  vermelho "plano terminou com pendências — leia a saída do audit acima e os logs em $LOG_DIR"
  amarelo "dica: reexecute só o que falhou (--faixa <id> / --seq <T-xxx>)"
  exit 1
}

executar_tudo() {
  evento --tipo inicio --escopo tudo
  iniciar_resumos
  info "logs em: $LOG_DIR"
  info "resumo geral de andamento: a cada 1 min aqui no terminal (e via: onp-spec resumo)"
  # onda 1: faixa-1 ∥ faixa-2 ∥ faixa-3
  info "onda 1: faixa-1 ∥ faixa-2 ∥ faixa-3 — janelas limpas em paralelo"
  executar_faixa_1 & PID_FAIXA_1=$!
  executar_faixa_2 & PID_FAIXA_2=$!
  executar_faixa_3 & PID_FAIXA_3=$!
  wait "$PID_FAIXA_1" || true
  wait "$PID_FAIXA_2" || true
  wait "$PID_FAIXA_3" || true
  # onda 2: faixa-4 ∥ faixa-5 ∥ faixa-6
  info "onda 2: faixa-4 ∥ faixa-5 ∥ faixa-6 — janelas limpas em paralelo"
  executar_faixa_4 & PID_FAIXA_4=$!
  executar_faixa_5 & PID_FAIXA_5=$!
  executar_faixa_6 & PID_FAIXA_6=$!
  wait "$PID_FAIXA_4" || true
  wait "$PID_FAIXA_5" || true
  wait "$PID_FAIXA_6" || true
  # onda 3: faixa-7 ∥ faixa-8 ∥ faixa-9
  info "onda 3: faixa-7 ∥ faixa-8 ∥ faixa-9 — janelas limpas em paralelo"
  executar_faixa_7 & PID_FAIXA_7=$!
  executar_faixa_8 & PID_FAIXA_8=$!
  executar_faixa_9 & PID_FAIXA_9=$!
  wait "$PID_FAIXA_7" || true
  wait "$PID_FAIXA_8" || true
  wait "$PID_FAIXA_9" || true
  # onda 4: faixa-10 ∥ faixa-11 ∥ faixa-12
  info "onda 4: faixa-10 ∥ faixa-11 ∥ faixa-12 — janelas limpas em paralelo"
  executar_faixa_10 & PID_FAIXA_10=$!
  executar_faixa_11 & PID_FAIXA_11=$!
  executar_faixa_12 & PID_FAIXA_12=$!
  wait "$PID_FAIXA_10" || true
  wait "$PID_FAIXA_11" || true
  wait "$PID_FAIXA_12" || true
  encerrar tudo
}

listar() {
  echo "execução: $RUN_ID (feature $FEATURE, branch $BASE_BRANCH)"
  echo "  faixa-1  onda 1  T-034"
  echo "  faixa-2  onda 1  T-035"
  echo "  faixa-3  onda 1  T-036"
  echo "  faixa-4  onda 2  T-037"
  echo "  faixa-5  onda 2  T-038"
  echo "  faixa-6  onda 2  T-039"
  echo "  faixa-7  onda 3  T-040"
  echo "  faixa-8  onda 3  T-041"
  echo "  faixa-9  onda 3  T-042"
  echo "  faixa-10  onda 4  T-043"
  echo "  faixa-11  onda 4  T-044"
  echo "  faixa-12  onda 4  T-045"
  echo
  echo "reexecutar uma faixa:    --faixa <id>"
  echo "reexecutar sequencial:   --seq <T-xxx>"
  echo "só o gate:               --gate"
}

MODO="tudo"
ALVO=""
while [ $# -gt 0 ]; do
  case "$1" in
    --listar) MODO="listar" ;;
    --gate) MODO="gate" ;;
    --sem-gate) COM_GATE=0 ;;
    --faixa) MODO="faixa"; ALVO="${2:-}"; shift ;;
    --seq) MODO="seq"; ALVO="${2:-}"; shift ;;
    -h|--help) sed -n "2,14p" "$0"; exit 0 ;;
    *) vermelho "argumento desconhecido: $1"; sed -n "2,14p" "$0"; exit 2 ;;
  esac
  shift
done

if [ "$MODO" = "listar" ]; then listar; exit 0; fi

preparar_ambiente

case "$MODO" in
  tudo) executar_tudo ;;
  gate) COM_GATE=1; iniciar_resumos; encerrar gate ;;
  faixa)
    case "$ALVO" in
      faixa-1) evento --tipo inicio --escopo "faixa:faixa-1"; iniciar_resumos; executar_faixa_1 || true; encerrar "faixa:faixa-1" ;;
      faixa-2) evento --tipo inicio --escopo "faixa:faixa-2"; iniciar_resumos; executar_faixa_2 || true; encerrar "faixa:faixa-2" ;;
      faixa-3) evento --tipo inicio --escopo "faixa:faixa-3"; iniciar_resumos; executar_faixa_3 || true; encerrar "faixa:faixa-3" ;;
      faixa-4) evento --tipo inicio --escopo "faixa:faixa-4"; iniciar_resumos; executar_faixa_4 || true; encerrar "faixa:faixa-4" ;;
      faixa-5) evento --tipo inicio --escopo "faixa:faixa-5"; iniciar_resumos; executar_faixa_5 || true; encerrar "faixa:faixa-5" ;;
      faixa-6) evento --tipo inicio --escopo "faixa:faixa-6"; iniciar_resumos; executar_faixa_6 || true; encerrar "faixa:faixa-6" ;;
      faixa-7) evento --tipo inicio --escopo "faixa:faixa-7"; iniciar_resumos; executar_faixa_7 || true; encerrar "faixa:faixa-7" ;;
      faixa-8) evento --tipo inicio --escopo "faixa:faixa-8"; iniciar_resumos; executar_faixa_8 || true; encerrar "faixa:faixa-8" ;;
      faixa-9) evento --tipo inicio --escopo "faixa:faixa-9"; iniciar_resumos; executar_faixa_9 || true; encerrar "faixa:faixa-9" ;;
      faixa-10) evento --tipo inicio --escopo "faixa:faixa-10"; iniciar_resumos; executar_faixa_10 || true; encerrar "faixa:faixa-10" ;;
      faixa-11) evento --tipo inicio --escopo "faixa:faixa-11"; iniciar_resumos; executar_faixa_11 || true; encerrar "faixa:faixa-11" ;;
      faixa-12) evento --tipo inicio --escopo "faixa:faixa-12"; iniciar_resumos; executar_faixa_12 || true; encerrar "faixa:faixa-12" ;;
      *) falhar "faixa desconhecida: '$ALVO' — veja as disponíveis com --listar" ;;
    esac ;;
  seq)
    case "$ALVO" in
      *) falhar "tarefa sequencial desconhecida: '$ALVO' — veja as disponíveis com --listar" ;;
    esac ;;
esac
