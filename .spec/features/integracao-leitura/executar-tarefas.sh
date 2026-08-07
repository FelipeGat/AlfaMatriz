#!/usr/bin/env bash
# executar-tarefas.sh — gerado por `onp-spec plano integracao-leitura` em 2026-08-07 16:18
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
# resumo do que está rolando, a qualquer momento: onp-spec resumo integracao-leitura
set -u
set -o pipefail

RUN_ID='AlfaMatriz-integracao-leitura-msj5gfum'
FEATURE='integracao-leitura'
BASE_BRANCH='spec/integracao-leitura'
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
  git ls-files --error-unmatch -- '.spec/features/integracao-leitura/spec.md' >/dev/null 2>&1 || falhar "spec.md não está commitada — os worktrees das faixas precisam dela no git"
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
  LOG_DIR="$(dirname "$TOPLEVEL")/onp-worktrees/AlfaMatriz-integracao-leitura-logs"
  WT_BASE="$(dirname "$TOPLEVEL")/onp-worktrees/AlfaMatriz-integracao-leitura"
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
    amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --faixa $1"
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

# ── sequencial T-059 (ordem do tasks.md) ──
executar_seq_T_059() {
  info 'sequencial T-059 — Escrever o contrato da integração'
  if rodar_tarefa seq 'T-059' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-059 — "Escrever o contrato da integração"
  critérios/refs: AC-078 (O contrato está escrito, versionado e é a referência de todos)
  arquivos permitidos (e seus testes): docs/integracao/CONTRATO-API-v1.md, docs/integracao/CHANGELOG.md, tests/Feature/Integracao/ContratoDocumentadoTest.php
  mensagem de commit: "T-059 integracao-leitura: Escrever o contrato da integração"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-059 integracao-leitura: Escrever o contrato da integração (auto-commit do plano)'
    fi
    marcar_concluidas T-059
    verde "✔ T-059 concluída"
    return 0
  fi
  vermelho "✘ T-059 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-059"
  FALHAS="$FALHAS T-059"
  return 1
}

# ── sequencial T-060 (ordem do tasks.md) ──
executar_seq_T_060() {
  info 'sequencial T-060 — Configuração do sistema: chave preservada e estado da integração'
  if rodar_tarefa seq 'T-060' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-060 — "Configuração do sistema: chave preservada e estado da integração"
  critérios/refs: AC-079 (Sistema sem endereço ou sem chave é recusado com motivo legível), AC-080 (Salvar o cadastro do sistema não apaga a chave de integração)
  arquivos permitidos (e seus testes): config/integracao.php, app/Http/Controllers/SistemaController.php, app/Models/Sistema.php, database/migrations/2026_08_07_120000_add_integracao_to_sistemas_table.php, tests/Feature/Integracao/ConfiguracaoDoSistemaTest.php
  mensagem de commit: "T-060 integracao-leitura: Configuração do sistema: chave preservada e estado da integração"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-060 integracao-leitura: Configuração do sistema: chave preservada e estado da integração (auto-commit do plano)'
    fi
    marcar_concluidas T-060
    verde "✔ T-060 concluída"
    return 0
  fi
  vermelho "✘ T-060 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-060"
  FALHAS="$FALHAS T-060"
  return 1
}

# ── sequencial T-061 (ordem do tasks.md) ──
executar_seq_T_061() {
  info 'sequencial T-061 — Retrato local: revendas, clientes e planos'
  if rodar_tarefa seq 'T-061' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-061 — "Retrato local: revendas, clientes e planos"
  critérios/refs: AC-084 (Sincronizar traz o cadastro e as licenças do sistema)
  arquivos permitidos (e seus testes): database/migrations/2026_08_07_120100_create_sistema_revendas_table.php, database/migrations/2026_08_07_120200_create_sistema_clientes_table.php, database/migrations/2026_08_07_120300_create_sistema_planos_table.php, app/Models/SistemaRevenda.php, app/Models/SistemaCliente.php, app/Models/SistemaPlano.php
  mensagem de commit: "T-061 integracao-leitura: Retrato local: revendas, clientes e planos"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-061 integracao-leitura: Retrato local: revendas, clientes e planos (auto-commit do plano)'
    fi
    marcar_concluidas T-061
    verde "✔ T-061 concluída"
    return 0
  fi
  vermelho "✘ T-061 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-061"
  FALHAS="$FALHAS T-061"
  return 1
}

# ── sequencial T-062 (ordem do tasks.md) ──
executar_seq_T_062() {
  info 'sequencial T-062 — Retrato local: licenças, usuários, financeiro e contadores'
  if rodar_tarefa seq 'T-062' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-062 — "Retrato local: licenças, usuários, financeiro e contadores"
  critérios/refs: AC-084 (Sincronizar traz o cadastro e as licenças do sistema), AC-089 (A contagem da unidade de cobrança fica guardada por competência)
  arquivos permitidos (e seus testes): database/migrations/2026_08_07_120400_create_sistema_licencas_table.php, database/migrations/2026_08_07_120500_create_sistema_usuarios_table.php, database/migrations/2026_08_07_120600_create_sistema_faturas_table.php, database/migrations/2026_08_07_120700_create_sistema_contadores_table.php, app/Models/SistemaLicenca.php, app/Models/SistemaUsuario.php, app/Models/SistemaFatura.php, app/Models/SistemaContador.php
  mensagem de commit: "T-062 integracao-leitura: Retrato local: licenças, usuários, financeiro e contadores"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-062 integracao-leitura: Retrato local: licenças, usuários, financeiro e contadores (auto-commit do plano)'
    fi
    marcar_concluidas T-062
    verde "✔ T-062 concluída"
    return 0
  fi
  vermelho "✘ T-062 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-062"
  FALHAS="$FALHAS T-062"
  return 1
}

# ── sequencial T-063 (ordem do tasks.md) ──
executar_seq_T_063() {
  info 'sequencial T-063 — Registro de cada execução de sincronização'
  if rodar_tarefa seq 'T-063' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-063 — "Registro de cada execução de sincronização"
  critérios/refs: AC-084 (Sincronizar traz o cadastro e as licenças do sistema), AC-087 (Varredura interrompida não desativa quem nem chegou a ser lido)
  arquivos permitidos (e seus testes): database/migrations/2026_08_07_120800_create_sincronizacoes_table.php, app/Models/Sincronizacao.php
  mensagem de commit: "T-063 integracao-leitura: Registro de cada execução de sincronização"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-063 integracao-leitura: Registro de cada execução de sincronização (auto-commit do plano)'
    fi
    marcar_concluidas T-063
    verde "✔ T-063 concluída"
    return 0
  fi
  vermelho "✘ T-063 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-063"
  FALHAS="$FALHAS T-063"
  return 1
}

# ── sequencial T-064 (ordem do tasks.md) ──
executar_seq_T_064() {
  info 'sequencial T-064 — O contrato em código: interface, transportes e erro'
  if rodar_tarefa seq 'T-064' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-064 — "O contrato em código: interface, transportes e erro"
  critérios/refs: AC-078 (O contrato está escrito, versionado e é a referência de todos)
  arquivos permitidos (e seus testes): app/Services/Integracao/ConectorSistema.php, app/Services/Integracao/RespostaIntegracao.php, app/Services/Integracao/ErroIntegracao.php, app/Services/Integracao/Documento.php, app/Services/Integracao/Dto/ClienteExterno.php, app/Services/Integracao/Dto/RevendaExterna.php, app/Services/Integracao/Dto/LicencaExterna.php, app/Services/Integracao/Dto/PlanoExterno.php, app/Services/Integracao/Dto/UsuarioExterno.php, app/Services/Integracao/Dto/FaturaExterna.php, app/Services/Integracao/Dto/ContadoresExternos.php
  mensagem de commit: "T-064 integracao-leitura: O contrato em código: interface, transportes e erro"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-064 integracao-leitura: O contrato em código: interface, transportes e erro (auto-commit do plano)'
    fi
    marcar_concluidas T-064
    verde "✔ T-064 concluída"
    return 0
  fi
  vermelho "✘ T-064 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-064"
  FALHAS="$FALHAS T-064"
  return 1
}

# ── sequencial T-065 (ordem do tasks.md) ──
executar_seq_T_065() {
  info 'sequencial T-065 — Conector falso e amostras de resposta'
  if rodar_tarefa seq 'T-065' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-065 — "Conector falso e amostras de resposta"
  critérios/refs: AC-078 (O contrato está escrito, versionado e é a referência de todos)
  arquivos permitidos (e seus testes): app/Services/Integracao/ConectorFalso.php, app/Services/Integracao/FabricaDeConector.php, app/Providers/AppServiceProvider.php, tests/Fixtures/Integracao/v1/clientes.json, tests/Fixtures/Integracao/v1/revendas.json, tests/Fixtures/Integracao/v1/licencas.json, tests/Fixtures/Integracao/v1/planos.json, tests/Fixtures/Integracao/v1/financeiro.json, tests/Fixtures/Integracao/v1/contadores.json, tests/Feature/Integracao/ConectorFalsoTest.php
  mensagem de commit: "T-065 integracao-leitura: Conector falso e amostras de resposta"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-065 integracao-leitura: Conector falso e amostras de resposta (auto-commit do plano)'
    fi
    marcar_concluidas T-065
    verde "✔ T-065 concluída"
    return 0
  fi
  vermelho "✘ T-065 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-065"
  FALHAS="$FALHAS T-065"
  return 1
}

# ── sequencial T-066 (ordem do tasks.md) ──
executar_seq_T_066() {
  info 'sequencial T-066 — Conector HTTP'
  if rodar_tarefa seq 'T-066' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-066 — "Conector HTTP"
  critérios/refs: AC-079 (Sistema sem endereço ou sem chave é recusado com motivo legível), AC-081 (A chave nunca aparece em registro nem em tela)
  arquivos permitidos (e seus testes): app/Services/Integracao/ConectorHttp.php, tests/Feature/Integracao/ConectorHttpTest.php
  mensagem de commit: "T-066 integracao-leitura: Conector HTTP"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-066 integracao-leitura: Conector HTTP (auto-commit do plano)'
    fi
    marcar_concluidas T-066
    verde "✔ T-066 concluída"
    return 0
  fi
  vermelho "✘ T-066 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-066"
  FALHAS="$FALHAS T-066"
  return 1
}

# ── sequencial T-067 (ordem do tasks.md) ──
executar_seq_T_067() {
  info 'sequencial T-067 — Casar cliente e revenda do sistema com os da matriz'
  if rodar_tarefa seq 'T-067' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-067 — "Casar cliente e revenda do sistema com os da matriz"
  critérios/refs: AC-091 (O casamento automático só acontece quando não há dúvida)
  arquivos permitidos (e seus testes): app/Services/Integracao/VinculadorService.php, tests/Feature/Integracao/VinculoTest.php
  mensagem de commit: "T-067 integracao-leitura: Casar cliente e revenda do sistema com os da matriz"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-067 integracao-leitura: Casar cliente e revenda do sistema com os da matriz (auto-commit do plano)'
    fi
    marcar_concluidas T-067
    verde "✔ T-067 concluída"
    return 0
  fi
  vermelho "✘ T-067 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-067"
  FALHAS="$FALHAS T-067"
  return 1
}

# ── sequencial T-068 (ordem do tasks.md) ──
executar_seq_T_068() {
  info 'sequencial T-068 — Sincronizar o cadastro do sistema'
  if rodar_tarefa seq 'T-068' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-068 — "Sincronizar o cadastro do sistema"
  critérios/refs: AC-084 (Sincronizar traz o cadastro e as licenças do sistema), AC-085 (Sincronizar de novo não duplica nada)
  arquivos permitidos (e seus testes): app/Services/Integracao/SincronizacaoService.php, tests/Feature/Integracao/SincronizacaoCadastroTest.php
  mensagem de commit: "T-068 integracao-leitura: Sincronizar o cadastro do sistema"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-068 integracao-leitura: Sincronizar o cadastro do sistema (auto-commit do plano)'
    fi
    marcar_concluidas T-068
    verde "✔ T-068 concluída"
    return 0
  fi
  vermelho "✘ T-068 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-068"
  FALHAS="$FALHAS T-068"
  return 1
}

# ── sequencial T-069 (ordem do tasks.md) ──
executar_seq_T_069() {
  info 'sequencial T-069 — Ausência na origem e varredura interrompida'
  if rodar_tarefa seq 'T-069' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-069 — "Ausência na origem e varredura interrompida"
  critérios/refs: AC-086 (Registro que sumiu na origem é marcado, nunca apagado), AC-087 (Varredura interrompida não desativa quem nem chegou a ser lido)
  arquivos permitidos (e seus testes): app/Services/Integracao/SincronizacaoService.php, tests/Feature/Integracao/AusenciaNaOrigemTest.php
  mensagem de commit: "T-069 integracao-leitura: Ausência na origem e varredura interrompida"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-069 integracao-leitura: Ausência na origem e varredura interrompida (auto-commit do plano)'
    fi
    marcar_concluidas T-069
    verde "✔ T-069 concluída"
    return 0
  fi
  vermelho "✘ T-069 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-069"
  FALHAS="$FALHAS T-069"
  return 1
}

# ── sequencial T-070 (ordem do tasks.md) ──
executar_seq_T_070() {
  info 'sequencial T-070 — Sincronizar licenças, financeiro e contadores'
  if rodar_tarefa seq 'T-070' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-070 — "Sincronizar licenças, financeiro e contadores"
  critérios/refs: AC-084 (Sincronizar traz o cadastro e as licenças do sistema), AC-088 (O financeiro aparece por competência, revenda e cliente), AC-089 (A contagem da unidade de cobrança fica guardada por competência)
  arquivos permitidos (e seus testes): app/Services/Integracao/SincronizacaoService.php, tests/Feature/Integracao/SincronizacaoFinanceiroTest.php
  mensagem de commit: "T-070 integracao-leitura: Sincronizar licenças, financeiro e contadores"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-070 integracao-leitura: Sincronizar licenças, financeiro e contadores (auto-commit do plano)'
    fi
    marcar_concluidas T-070
    verde "✔ T-070 concluída"
    return 0
  fi
  vermelho "✘ T-070 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-070"
  FALHAS="$FALHAS T-070"
  return 1
}

# ── sequencial T-071 (ordem do tasks.md) ──
executar_seq_T_071() {
  info 'sequencial T-071 — Comando e agendamento da sincronização'
  if rodar_tarefa seq 'T-071' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-071 — "Comando e agendamento da sincronização"
  critérios/refs: AC-084 (Sincronizar traz o cadastro e as licenças do sistema)
  arquivos permitidos (e seus testes): app/Console/Commands/SincronizarSistemas.php, routes/console.php, tests/Feature/Integracao/ComandoSincronizarTest.php
  mensagem de commit: "T-071 integracao-leitura: Comando e agendamento da sincronização"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-071 integracao-leitura: Comando e agendamento da sincronização (auto-commit do plano)'
    fi
    marcar_concluidas T-071
    verde "✔ T-071 concluída"
    return 0
  fi
  vermelho "✘ T-071 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-071"
  FALHAS="$FALHAS T-071"
  return 1
}

# ── sequencial T-072 (ordem do tasks.md) ──
executar_seq_T_072() {
  info 'sequencial T-072 — Importar o cadastro que já existe no sistema'
  if rodar_tarefa seq 'T-072' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-072 — "Importar o cadastro que já existe no sistema"
  critérios/refs: AC-091 (O casamento automático só acontece quando não há dúvida), AC-092 (Todo caso duvidoso vira pendência com ação, não vínculo errado), AC-093 (A importação nunca cria cliente sozinha)
  arquivos permitidos (e seus testes): app/Services/Integracao/ImportacaoService.php, tests/Feature/Integracao/ImportacaoTest.php
  mensagem de commit: "T-072 integracao-leitura: Importar o cadastro que já existe no sistema"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-072 integracao-leitura: Importar o cadastro que já existe no sistema (auto-commit do plano)'
    fi
    marcar_concluidas T-072
    verde "✔ T-072 concluída"
    return 0
  fi
  vermelho "✘ T-072 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-072"
  FALHAS="$FALHAS T-072"
  return 1
}

# ── sequencial T-073 (ordem do tasks.md) ──
executar_seq_T_073() {
  info 'sequencial T-073 — O corte, sistema por sistema'
  if rodar_tarefa seq 'T-073' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-073 — "O corte, sistema por sistema"
  critérios/refs: AC-094 (O corte só pode ser aplicado com a conferência zerada), AC-095 (O painel mostra, por sistema, se o corte já valeu)
  arquivos permitidos (e seus testes): app/Services/Integracao/CorteService.php, tests/Feature/Integracao/CorteTest.php
  mensagem de commit: "T-073 integracao-leitura: O corte, sistema por sistema"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-073 integracao-leitura: O corte, sistema por sistema (auto-commit do plano)'
    fi
    marcar_concluidas T-073
    verde "✔ T-073 concluída"
    return 0
  fi
  vermelho "✘ T-073 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-073"
  FALHAS="$FALHAS T-073"
  return 1
}

# ── sequencial T-074 (ordem do tasks.md) ──
executar_seq_T_074() {
  info 'sequencial T-074 — Painel de integração e o selo de "atualizado há"'
  if rodar_tarefa seq 'T-074' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-074 — "Painel de integração e o selo de "atualizado há""
  critérios/refs: AC-082 (Sistema fora do ar mostra o último retrato e desde quando falha), AC-083 (Cada tela diz de quando é o dado que está mostrando), AC-095 (O painel mostra, por sistema, se o corte já valeu)
  arquivos permitidos (e seus testes): resources/views/components/atualizado-em.blade.php, resources/views/integracao/index.blade.php, app/Http/Controllers/IntegracaoController.php, resources/views/layouts/navigation.blade.php, routes/web.php, tests/Feature/Integracao/PainelTest.php
  mensagem de commit: "T-074 integracao-leitura: Painel de integração e o selo de "atualizado há""

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-074 integracao-leitura: Painel de integração e o selo de "atualizado há" (auto-commit do plano)'
    fi
    marcar_concluidas T-074
    verde "✔ T-074 concluída"
    return 0
  fi
  vermelho "✘ T-074 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-074"
  FALHAS="$FALHAS T-074"
  return 1
}

# ── sequencial T-075 (ordem do tasks.md) ──
executar_seq_T_075() {
  info 'sequencial T-075 — Tela de conferência e aplicação do corte'
  if rodar_tarefa seq 'T-075' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-075 — "Tela de conferência e aplicação do corte"
  critérios/refs: AC-092 (Todo caso duvidoso vira pendência com ação, não vínculo errado), AC-093 (A importação nunca cria cliente sozinha), AC-094 (O corte só pode ser aplicado com a conferência zerada)
  arquivos permitidos (e seus testes): resources/views/integracao/conferencia.blade.php, app/Http/Controllers/ConferenciaController.php, tests/Feature/Integracao/TelaConferenciaTest.php
  mensagem de commit: "T-075 integracao-leitura: Tela de conferência e aplicação do corte"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-075 integracao-leitura: Tela de conferência e aplicação do corte (auto-commit do plano)'
    fi
    marcar_concluidas T-075
    verde "✔ T-075 concluída"
    return 0
  fi
  vermelho "✘ T-075 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-075"
  FALHAS="$FALHAS T-075"
  return 1
}

# ── sequencial T-076 (ordem do tasks.md) ──
executar_seq_T_076() {
  info 'sequencial T-076 — Tela de clientes por sistema'
  if rodar_tarefa seq 'T-076' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-076 — "Tela de clientes por sistema"
  critérios/refs: AC-091 (O casamento automático só acontece quando não há dúvida)
  arquivos permitidos (e seus testes): resources/views/integracao/clientes.blade.php, app/Http/Controllers/IntegracaoClienteController.php, tests/Feature/Integracao/TelaClientesTest.php
  mensagem de commit: "T-076 integracao-leitura: Tela de clientes por sistema"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-076 integracao-leitura: Tela de clientes por sistema (auto-commit do plano)'
    fi
    marcar_concluidas T-076
    verde "✔ T-076 concluída"
    return 0
  fi
  vermelho "✘ T-076 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-076"
  FALHAS="$FALHAS T-076"
  return 1
}

# ── sequencial T-077 (ordem do tasks.md) ──
executar_seq_T_077() {
  info 'sequencial T-077 — Tela de licenças dos sistemas'
  if rodar_tarefa seq 'T-077' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-077 — "Tela de licenças dos sistemas"
  critérios/refs: AC-084 (Sincronizar traz o cadastro e as licenças do sistema)
  arquivos permitidos (e seus testes): resources/views/integracao/licencas.blade.php, app/Http/Controllers/IntegracaoLicencaController.php, tests/Feature/Integracao/TelaLicencasTest.php
  mensagem de commit: "T-077 integracao-leitura: Tela de licenças dos sistemas"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-077 integracao-leitura: Tela de licenças dos sistemas (auto-commit do plano)'
    fi
    marcar_concluidas T-077
    verde "✔ T-077 concluída"
    return 0
  fi
  vermelho "✘ T-077 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-077"
  FALHAS="$FALHAS T-077"
  return 1
}

# ── sequencial T-078 (ordem do tasks.md) ──
executar_seq_T_078() {
  info 'sequencial T-078 — Financeiro dos sistemas e exportação'
  if rodar_tarefa seq 'T-078' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-078 — "Financeiro dos sistemas e exportação"
  critérios/refs: AC-088 (O financeiro aparece por competência, revenda e cliente), AC-090 (O que os sistemas dizem é confrontado com o que a Alfa faturou)
  arquivos permitidos (e seus testes): resources/views/integracao/financeiro.blade.php, app/Http/Controllers/IntegracaoFinanceiroController.php, tests/Feature/Integracao/TelaFinanceiroTest.php
  mensagem de commit: "T-078 integracao-leitura: Financeiro dos sistemas e exportação"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-078 integracao-leitura: Financeiro dos sistemas e exportação (auto-commit do plano)'
    fi
    marcar_concluidas T-078
    verde "✔ T-078 concluída"
    return 0
  fi
  vermelho "✘ T-078 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-078"
  FALHAS="$FALHAS T-078"
  return 1
}

# ── sequencial T-079 (ordem do tasks.md) ──
executar_seq_T_079() {
  info 'sequencial T-079 — Tela de divergências'
  if rodar_tarefa seq 'T-079' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-079 — "Tela de divergências"
  critérios/refs: AC-090 (O que os sistemas dizem é confrontado com o que a Alfa faturou)
  arquivos permitidos (e seus testes): app/Services/Integracao/DivergenciaService.php, resources/views/integracao/divergencias.blade.php, app/Http/Controllers/DivergenciaController.php, tests/Feature/Integracao/DivergenciaTest.php
  mensagem de commit: "T-079 integracao-leitura: Tela de divergências"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-079 integracao-leitura: Tela de divergências (auto-commit do plano)'
    fi
    marcar_concluidas T-079
    verde "✔ T-079 concluída"
    return 0
  fi
  vermelho "✘ T-079 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-079"
  FALHAS="$FALHAS T-079"
  return 1
}

# ── sequencial T-080 (ordem do tasks.md) ──
executar_seq_T_080() {
  info 'sequencial T-080 — AlfaGym: chave da matriz e endereços de leitura'
  if rodar_tarefa seq 'T-080' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-080 — "AlfaGym: chave da matriz e endereços de leitura"
  critérios/refs: AC-096 (O AlfaGym só atende quem apresenta a chave da matriz)
  arquivos permitidos (e seus testes): tests/Feature/Integracao/ContratoAlfaGymTest.php
  mensagem de commit: "T-080 integracao-leitura: AlfaGym: chave da matriz e endereços de leitura"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-080 integracao-leitura: AlfaGym: chave da matriz e endereços de leitura (auto-commit do plano)'
    fi
    marcar_concluidas T-080
    verde "✔ T-080 concluída"
    return 0
  fi
  vermelho "✘ T-080 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-080"
  FALHAS="$FALHAS T-080"
  return 1
}

# ── sequencial T-081 (ordem do tasks.md) ──
executar_seq_T_081() {
  info 'sequencial T-081 — Ligar de verdade e provar o formato'
  if rodar_tarefa seq 'T-081' 'Você executa UMA tarefa da feature "integracao-leitura" (fluxo onp-spec, spec-anchored).
Leia primeiro: .spec/features/integracao-leitura/spec.md, .spec/features/integracao-leitura/tasks.md e .spec/constituicao.md.

Sua tarefa (somente ela):
T-081 — "Ligar de verdade e provar o formato"
  critérios/refs: AC-097 (Os dados do AlfaGym chegam à matriz no formato do contrato)
  arquivos permitidos (e seus testes): tests/Fixtures/Integracao/alfagym/clientes.json, tests/Fixtures/Integracao/alfagym/licencas.json, tests/Fixtures/Integracao/alfagym/contadores.json, README.md
  mensagem de commit: "T-081 integracao-leitura: Ligar de verdade e provar o formato"

Regras inegociáveis:
- Todo critério de aceite referenciado vira teste com @spec:AC-xxx no título.
- NUNCA enfraqueça, pule (skip/todo) ou apague um teste para passar — teste pulado não é prova e o audit acusa.
- Rode os testes localmente com `php tools/onp-spec-tap.php` até passarem.
- NÃO edite tasks.md, NÃO rode onp-spec verify/audit e NÃO toque em outras tarefas — o orquestrador cuida disso.
- Ao final de CADA tarefa: `git add` só no que você tocou e um commit próprio.' 'claude-sonnet-5' medium >> "$LOG_DIR/seq.log" 2>&1; then
    # commit de segurança se o agente esqueceu (rastreabilidade > perfeição)
    if [ -n "$(git status --porcelain)" ]; then
      git add -A && git commit -q -m 'T-081 integracao-leitura: Ligar de verdade e provar o formato (auto-commit do plano)'
    fi
    marcar_concluidas T-081
    verde "✔ T-081 concluída"
    return 0
  fi
  vermelho "✘ T-081 falhou (log: $LOG_DIR/seq.log)"
  amarelo "  reexecute só ela: bash .spec/features/integracao-leitura/executar-tarefas.sh --seq T-081"
  FALHAS="$FALHAS T-081"
  return 1
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
      amarelo "  para o veredito: bash .spec/features/integracao-leitura/executar-tarefas.sh --gate"
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
  executar_seq_T_059 || true
  executar_seq_T_060 || true
  executar_seq_T_061 || true
  executar_seq_T_062 || true
  executar_seq_T_063 || true
  executar_seq_T_064 || true
  executar_seq_T_065 || true
  executar_seq_T_066 || true
  executar_seq_T_067 || true
  executar_seq_T_068 || true
  executar_seq_T_069 || true
  executar_seq_T_070 || true
  executar_seq_T_071 || true
  executar_seq_T_072 || true
  executar_seq_T_073 || true
  executar_seq_T_074 || true
  executar_seq_T_075 || true
  executar_seq_T_076 || true
  executar_seq_T_077 || true
  executar_seq_T_078 || true
  executar_seq_T_079 || true
  executar_seq_T_080 || true
  executar_seq_T_081 || true
  encerrar tudo
}

listar() {
  echo "execução: $RUN_ID (feature $FEATURE, branch $BASE_BRANCH)"
  echo "  seq       T-059 (sequencial)"
  echo "  seq       T-060 (sequencial)"
  echo "  seq       T-061 (sequencial)"
  echo "  seq       T-062 (sequencial)"
  echo "  seq       T-063 (sequencial)"
  echo "  seq       T-064 (sequencial)"
  echo "  seq       T-065 (sequencial)"
  echo "  seq       T-066 (sequencial)"
  echo "  seq       T-067 (sequencial)"
  echo "  seq       T-068 (sequencial)"
  echo "  seq       T-069 (sequencial)"
  echo "  seq       T-070 (sequencial)"
  echo "  seq       T-071 (sequencial)"
  echo "  seq       T-072 (sequencial)"
  echo "  seq       T-073 (sequencial)"
  echo "  seq       T-074 (sequencial)"
  echo "  seq       T-075 (sequencial)"
  echo "  seq       T-076 (sequencial)"
  echo "  seq       T-077 (sequencial)"
  echo "  seq       T-078 (sequencial)"
  echo "  seq       T-079 (sequencial)"
  echo "  seq       T-080 (sequencial)"
  echo "  seq       T-081 (sequencial)"
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
      *) falhar "faixa desconhecida: '$ALVO' — veja as disponíveis com --listar" ;;
    esac ;;
  seq)
    case "$ALVO" in
      T-059) evento --tipo inicio --escopo "seq:T-059"; iniciar_resumos; executar_seq_T_059 || true; encerrar "seq:T-059" ;;
      T-060) evento --tipo inicio --escopo "seq:T-060"; iniciar_resumos; executar_seq_T_060 || true; encerrar "seq:T-060" ;;
      T-061) evento --tipo inicio --escopo "seq:T-061"; iniciar_resumos; executar_seq_T_061 || true; encerrar "seq:T-061" ;;
      T-062) evento --tipo inicio --escopo "seq:T-062"; iniciar_resumos; executar_seq_T_062 || true; encerrar "seq:T-062" ;;
      T-063) evento --tipo inicio --escopo "seq:T-063"; iniciar_resumos; executar_seq_T_063 || true; encerrar "seq:T-063" ;;
      T-064) evento --tipo inicio --escopo "seq:T-064"; iniciar_resumos; executar_seq_T_064 || true; encerrar "seq:T-064" ;;
      T-065) evento --tipo inicio --escopo "seq:T-065"; iniciar_resumos; executar_seq_T_065 || true; encerrar "seq:T-065" ;;
      T-066) evento --tipo inicio --escopo "seq:T-066"; iniciar_resumos; executar_seq_T_066 || true; encerrar "seq:T-066" ;;
      T-067) evento --tipo inicio --escopo "seq:T-067"; iniciar_resumos; executar_seq_T_067 || true; encerrar "seq:T-067" ;;
      T-068) evento --tipo inicio --escopo "seq:T-068"; iniciar_resumos; executar_seq_T_068 || true; encerrar "seq:T-068" ;;
      T-069) evento --tipo inicio --escopo "seq:T-069"; iniciar_resumos; executar_seq_T_069 || true; encerrar "seq:T-069" ;;
      T-070) evento --tipo inicio --escopo "seq:T-070"; iniciar_resumos; executar_seq_T_070 || true; encerrar "seq:T-070" ;;
      T-071) evento --tipo inicio --escopo "seq:T-071"; iniciar_resumos; executar_seq_T_071 || true; encerrar "seq:T-071" ;;
      T-072) evento --tipo inicio --escopo "seq:T-072"; iniciar_resumos; executar_seq_T_072 || true; encerrar "seq:T-072" ;;
      T-073) evento --tipo inicio --escopo "seq:T-073"; iniciar_resumos; executar_seq_T_073 || true; encerrar "seq:T-073" ;;
      T-074) evento --tipo inicio --escopo "seq:T-074"; iniciar_resumos; executar_seq_T_074 || true; encerrar "seq:T-074" ;;
      T-075) evento --tipo inicio --escopo "seq:T-075"; iniciar_resumos; executar_seq_T_075 || true; encerrar "seq:T-075" ;;
      T-076) evento --tipo inicio --escopo "seq:T-076"; iniciar_resumos; executar_seq_T_076 || true; encerrar "seq:T-076" ;;
      T-077) evento --tipo inicio --escopo "seq:T-077"; iniciar_resumos; executar_seq_T_077 || true; encerrar "seq:T-077" ;;
      T-078) evento --tipo inicio --escopo "seq:T-078"; iniciar_resumos; executar_seq_T_078 || true; encerrar "seq:T-078" ;;
      T-079) evento --tipo inicio --escopo "seq:T-079"; iniciar_resumos; executar_seq_T_079 || true; encerrar "seq:T-079" ;;
      T-080) evento --tipo inicio --escopo "seq:T-080"; iniciar_resumos; executar_seq_T_080 || true; encerrar "seq:T-080" ;;
      T-081) evento --tipo inicio --escopo "seq:T-081"; iniciar_resumos; executar_seq_T_081 || true; encerrar "seq:T-081" ;;
      *) falhar "tarefa sequencial desconhecida: '$ALVO' — veja as disponíveis com --listar" ;;
    esac ;;
esac
