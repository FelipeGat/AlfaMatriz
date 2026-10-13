# Implementation Plan: Chats Business

**Feature**: `chats-business` | **Date**: 2026-10-13 | **Spec**: [spec.md](spec.md)

## Summary

Chats contínuos por assunto, cada um com memória própria, que qualquer agente da pessoa recebe. Os
quatro chats da primeira leva são semeados a partir das conversas do VS Code.

A abordagem reaproveita a Central inteira:
- o chat de assunto é uma `central_ia_tarefas` de `tipo = 'assunto'` com grupo;
- a memória é o dossiê com três chaves a mais;
- o histórico vai ao agente dentro de um orçamento de contexto, com condensação depois do pedido;
- a semente é uma execução do runner que lê a sessão localmente por partes, com modelo econômico.

Detalhes em [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12), Node 22 (runner), JavaScript (Alpine)
**Primary Dependencies**: Laravel, Sanctum, Alpine.js, Tailwind com os tokens do redesign. Nenhuma
dependência nova.
**Storage**: MariaDB (`alfamatriz-dev`, porta 3311) e SQLite nos testes. Tabelas `central_ia_*`.
**Testing**:
- PHPUnit pela suíte isolada (`scratchpad/lab-test.sh --filter 'CentralIa|Redesign'`, SQLite, sem
  rede);
- `node tools/central-ia/test-*.mjs` para o runner;
- Playwright em Docker para a tela.

**Target Platform**: AlfaMatriz local (`alfamatriz-dev`) e runner systemd `--user` no WSL. Produção
só quando o dono autorizar.
**Project Type**: web-service com runner local
**Performance Goals**: a semente de um chat comum fica pronta em menos de 2 minutos (SC-002). A troca
de assunto leva no máximo 2 cliques (SC-005).
**Constraints**:
- o contexto por pedido não passa do orçamento (SC-003);
- a semente para acima do teto de importação;
- nenhum scheduler nem queue local (Princípio III).

**Scale/Scope**: 1 pessoa (Felipe) e dezenas de assuntos. A primeira leva tem 4 chats, com sessões de
até ~119 MB brutos.

## Constitution Check

*Gate antes do Phase 0 e re-checado depois do Phase 1.*

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade por conta | PASS | Toda consulta de assunto, memória e sessão é escopada por `CentralIaTarefa::de($user)` e pelos runners da conta. Outra conta recebe 404 (Cenário 8). Admin não vê. |
| II. Cada pessoa com as suas IAs e a sua máquina | PASS | A semente e a condensação rodam no runner da conta, com as credenciais dela. O servidor nunca lê sessão nem guarda credencial. |
| III. Um sistema só, um consumidor só | PASS | Tudo em `central_ia_*`. O Telegram continua com um consumidor. Sem job periódico: a condensação é enfileirada pelo próprio pedido. |
| IV. Segredos fora de código, log e prompt | PASS | Memória, condensado e semente passam por `ProtecaoDeSegredos` antes de gravar e de ir ao prompt. O servidor nunca envia caminho de arquivo ao runner. |
| V. Prova executável | PASS | Cada FR com teste. Migrações aditivas com `down()`. Runner reiniciado em ociosidade e observado. |
| VI. Acesso total com rastro | PASS | `semear` e `condensar` são execuções com evento e auditoria. "Aguardando você" só para a dúvida do teto de importação. |
| VII. Autorização explícita | PASS | Nada de commit, push ou deploy novo. A branch continua `Felipe`. |
| VIII. Zero fabricação | PASS | O prompt da semente proíbe item que não esteja no texto (FR-016). Os números de tamanho do research vêm de medição real. Contratos novos estão marcados `[PROPOSTA]`. |

**Re-check pós-design**: PASS. O design não cria serviço, fila nem tabela de chat paralela. A única
tabela tocada além de `central_ia_tarefas` é o vínculo do Telegram (uma coluna).

## Design

### Servidor

1. **Migração** `2026_10_14_090000_chats_de_assunto`:
   - `central_ia_tarefas`: `tipo`, `grupo_id` e o índice `(user_id, tipo, status)`;
   - `central_ia_telegram_vinculos`: `assunto_atual_id`.
2. **`CentralIaTarefa`**: `ASSUNTO`, `scopeAssuntos()`, `scopeTarefas()` e relação `grupo()`. Chats
   Ativos, Projetos e a contagem de tarefas passam a filtrar `tipo = 'tarefa'`, para o assunto não
   aparecer como tarefa.
3. **`ContextoDoAssunto`** (novo, `app/Services/CentralIa/`):
   - **Entrada**: `paraPrompt(tarefa, orcamentoTokens, reduzido)`.
   - **Montagem**: memória, depois condensado, depois a parte recente literal (research D3).
   - **Retorno**: o texto e um resumo `{tokens_estimados, inteiro|condensado|reduzido,
     mensagens_fora}`. Esse resumo vai para `roteamento.contexto` e aparece na conversa (FR-013).
4. **`AgentesService`**:
   - `pedir`: em `tipo = 'assunto'` usa `ContextoDoAssunto` em vez de `Dossie::paraPrompt`. Lê a cota
     (D5) e, depois de criar o pedido, decide se enfileira o `condensar` (D4).
   - `concluir`:
     - assunto nunca vai para `concluida`;
     - `finalidade = condensar` grava `dossie.condensado`;
     - `semear` grava a memória de partida e `sementes[]`;
     - parada por teto vira "aguardando você" com a estimativa.
   - `semear(user, sessoes, dados)`: cria o assunto e a execução de semente.
5. **`Dossie`**:
   - `jeito_de_fazer` no bloco e em `aplicarConclusao`, com dedupe;
   - `aplicarCorrecao`;
   - a instrução do bloco ganha o campo `jeito_de_fazer`.
6. **Controller e rotas**: as seis rotas do contrato, em `CentralIaController` (ou num
   `CentralIaAssuntoController` se o controller passar de ~1.500 linhas). Validação de grupo, projeto e
   sessão escopada.
7. **`CentralIaRunnerController::sessoes`**: aceita `titulo` e `origem`, aceita sessão da raiz com
   `projeto = ''` e o limite de 120.
8. **`Preferencias`**: as duas chaves novas, com faixa validada.
9. **`TelegramCanal`**: `/assunto` e `/geral` limpando o ponteiro. A mensagem com `assunto_atual_id`
   vira pedido nessa tarefa.
10. **`CockpitService`**: `assuntos[]` no painel. `sessoes[]` com `titulo`, `origem` e `semeada_em`.

### Runner (`deploy/central-ia/`)

1. **`uso-local.mjs`**:
   - `titulo` por sessão (customTitle, depois aiTitle, depois o primeiro pedido);
   - sessões da raiz com projeto `''`;
   - sessões de `~/.local/share/alfa/central-ia-importadas/*.jsonl` com origem `mac_copia`.
2. **`extrato.mjs`** (novo, puro e testável):
   - `extrair(arquivo, {desde})` devolve os turnos `{pedido, resposta}` cortados em 4 mil caracteres,
     sem ferramenta, sidechain nem meta;
   - `partes(turnos, 100_000)`;
   - `estimar()`.
3. **`runner.mjs`**:
   - `finalidade = semear`: localiza os arquivos pelo id entre as próprias sessões (nunca por caminho
     vindo do servidor) e estima o tamanho.
   - Acima do teto, conclui com `estimativa_caracteres` sem ler.
   - Abaixo do teto, roda uma passada por parte com o modelo do nível `leve` e uma passada final que
     junta. Devolve o bloco e `semente[]`.
   - Apaga a cópia `mac_copia` depois do sucesso.
   - `condensar` é uma execução comum de análise com o prompt pronto do servidor.

### Tela (`resources/views/central-ia/index.blade.php`, `resources/js/central-ia.js`)

- **Aba Business**: lista agrupada e recolhível com pulso e novidade, mais "+ Novo assunto" e "Trazer
  do VS Code" (modal com a lista de `sessoes`, multiseleção, nome e grupo sugeridos).
- **Painel de conversa** reaproveitado:
  - o cabeçalho mostra o grupo;
  - a sub-aba **Memória** lista os itens com os botões editar e apagar;
  - a linha de sistema do contexto enviado e do custo.
- **Ajustes**: orçamento de contexto e teto de importação.

## Project Structure

### Documentation (this feature)

```
docs/specs/chats-business/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
└── contracts/assuntos.md
```

### Source Code (caminhos reais do worktree)

```
app/Models/CentralIaTarefa.php                  (alterado)
app/Services/CentralIa/ContextoDoAssunto.php    (novo)
app/Services/CentralIa/AgentesService.php       (alterado)
app/Services/CentralIa/Dossie.php               (alterado)
app/Services/CentralIa/Preferencias.php         (alterado)
app/Services/CentralIa/TelegramCanal.php        (alterado)
app/Services/CentralIa/CockpitService.php       (alterado)
app/Http/Controllers/CentralIaController.php    (alterado)
app/Http/Controllers/CentralIaRunnerController.php (alterado)
routes/web.php                                  (6 rotas)
database/migrations/2026_10_14_090000_chats_de_assunto.php (novo)
deploy/central-ia/uso-local.mjs                 (alterado)
deploy/central-ia/extrato.mjs                   (novo)
deploy/central-ia/runner.mjs                    (alterado)
resources/views/central-ia/index.blade.php      (alterado)
resources/js/central-ia.js                      (alterado)
tests/Feature/CentralIa/AssuntosTest.php        (novo: criação, grupos, isolamento, nunca conclui)
tests/Feature/CentralIa/ContextoDoAssuntoTest.php (novo: inteiro, condensado, reduzido por cota, orçamento)
tests/Feature/CentralIa/SementeTest.php         (novo: sessão escopada, teto, gravação, segredo mascarado)
tools/central-ia/test-extrato.mjs               (novo: extrato, partes, títulos, raiz, cópia do Mac)
```

**Structure Decision**: tudo dentro da Central que já existe. Só dois arquivos novos de regra
(`ContextoDoAssunto`, `extrato.mjs`).

## Convenções de Borda

| Camada | Case style | Validação | Fonte da verdade |
|---|---|---|---|
| Colunas do banco | snake_case | migração e casts do model | `database/migrations/2026_10_14_090000_chats_de_assunto.php` |
| JSON do dossiê | snake_case (`jeito_de_fazer`, `ate_mensagem_id`) | `Dossie::aplicarConclusao` | `data-model.md` |
| Payload tela ↔ servidor | snake_case, como o resto da Central | `$request->validate` | `contracts/assuntos.md` |
| Payload runner ↔ servidor | snake_case | `CentralIaRunnerController` | `contracts/assuntos.md` §A |
| Estado no Alpine | camelCase nos nomes de método, campos do servidor sem renomear | — | `resources/js/central-ia.js` |
| Rotas | kebab-case no caminho, ponto no nome | router | `routes/web.php` |

**Mapper**: não há. O Eloquent devolve snake_case e a tela consome como veio, como já é feito com
`tarefas` e `chats`.

## Ordem de entrega (fatias verticais)

1. **P1 — chat de assunto que lembra**:
   - migração, model e `ContextoDoAssunto` (inteiro e com orçamento);
   - criação manual e aba Business com Chat;
   - testes.
2. **P2 — semente**:
   - títulos e raiz no runner, `extrato.mjs`, `semear` e o modal;
   - os quatro chats da primeira leva (Cenário 1).
3. **P3 — condensação e cota**: `condensar`, a redução por cota e a linha de contexto e custo.
4. **P4 — Memória visível e corrigível**: mais `/assunto` no Telegram.

Cada fatia termina com a suíte verde, um commit local na `Felipe` e o runner reiniciado em ociosidade
quando mudar.
