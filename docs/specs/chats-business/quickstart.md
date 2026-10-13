# Quickstart: Chats Business

**Feature**: `chats-business` | **Data**: 2026-10-13

Ambiente: `alfamatriz-dev` (http://localhost:8096 e, do Mac, http://home:8096), com o runner do WSL
ativo.

## Preparação — cópia única das duas conversas do Mac (FR-020)

No **Mac**, no Terminal, rode um comando por conversa. Troque `<ARQUIVO>` pelo caminho que o primeiro
comando mostrar:

```bash
grep -l '"customTitle":"Proposta comercial Grupo Sol' ~/.claude/projects/*/*.jsonl
grep -l '"customTitle":"Relatório avanço de obra' ~/.claude/projects/*/*.jsonl
ssh home 'mkdir -p ~/.local/share/alfa/central-ia-importadas'
scp <ARQUIVO> home:.local/share/alfa/central-ia-importadas/
```

**Expected**: na batida seguinte, "Trazer do VS Code" mostra as duas conversas com a origem "Mac
(cópia)".

**Pendente**: o título exato das duas no Mac é o do print ("Proposta comercial Grupo Sol…",
"Relatório avanço de obra"). Se o `grep` não achar nada, confira o título e o nome do host SSH do WSL
no Mac antes de copiar.

## Cenário 1 — Primeira leva: quatro chats semeados (FR-007, FR-008, FR-021)

1. Abra Central → **Business** → **Trazer do VS Code**.
2. Selecione "Proposta Comercial VixCard" (WSL), grupo VixCard, e confirme.
3. Repita para "Gestor.SG", selecionando as **duas** sessões do mesmo título, no grupo Alfa Tecnologia.
4. Repita para "Proposta comercial Grupo Soluções" e "Relatório avanço de obra" (Mac, cópia), no grupo
   Alfa Tecnologia.

**Expected**:
- Quatro chats nos grupos certos.
- Cada um tem a Memória preenchida com decisões que existem na conversa original e mostra o custo da
  semente.
- As duas cópias do Mac foram apagadas de `central-ia-importadas/`.

## Cenário 2 — Teto de importação

1. Com o teto padrão, semeie o Gestor.SG.

**Expected**:
- Se o extrato passar do teto, o chat fica "aguardando você" com a estimativa em caracteres.
- "Sim" segue.
- "Não" mantém o chat vazio e não gasta nada além da leitura local.

## Cenário 3 — O assunto lembra (FR-004, SC-001)

1. No chat "Comercial VixCard" (vazio), peça "a partir de agora, toda primeira versão de proposta vai
   sem valores".
2. Em seguida, peça "monte a proposta do cliente Teste".

**Expected**: a resposta vem sem valores, sem que a regra seja repetida. A Memória mostra a decisão com
autor e data.

## Cenário 4 — Troca de agente (FR-004)

1. No mesmo chat, escolha Codex no seletor e peça "qual é a regra das primeiras versões?".

**Expected**: o Codex responde com a regra registrada.

## Cenário 5 — Orçamento de contexto (FR-010, FR-011, SC-003)

1. Em Ajustes, baixe o orçamento para 20 mil tokens.
2. Peça algo no Gestor.SG semeado.

**Expected**:
- A conversa informa "histórico condensado" e o tamanho enviado, que fica abaixo de 20 mil tokens.
- Depois do pedido aparece uma linha de sistema da condensação, com o custo.

## Cenário 6 — Cota perto do fim (FR-012)

1. Com a janela de sessão do Claude em 80% ou mais (painel Consumo), peça algo a um assunto com Claude.

**Expected**: aviso de contexto reduzido. Vão a memória e as 10 últimas mensagens.

## Cenário 7 — Correção da memória (FR-006)

1. Na aba Memória, apague a decisão do Cenário 3.
2. Peça outra proposta.

**Expected**: a proposta não recebe mais a regra. A correção aparece no histórico da Memória.

## Cenário 8 — Isolamento (FR-014, SC-004), caso de erro

1. Logado como outra conta com Central, chame `GET central-ia/assuntos/{id do Felipe}/memoria`.

**Expected**: 404. O painel dessa conta não lista nenhum assunto do Felipe.

## Cenário 9 — Roundtrip ponta a ponta (borda tela ↔ servidor)

1. Na tela, crie um assunto pelo botão.
2. Capture no DevTools a resposta real de `GET central-ia/painel`.

**Expected**: o item em `assuntos[]` tem exatamente os campos do contrato (`id, titulo, grupo_id,
projeto, status, ultima_atividade_em, novidade, aguardando`), em snake_case, e a lista mostra o chat
no grupo escolhido.

## Cenário 10 — Telegram (FR-019)

1. No Telegram, mande `/assunto Comercial VixCard` e depois "resuma o que está pendente".

**Expected**: a resposta usa a memória do assunto e a troca aparece no chat da tela.
