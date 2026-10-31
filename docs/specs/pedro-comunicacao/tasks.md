# Tarefas: Comunicação do Pedro (primeira parte)

**Spec**: `spec.md` · **Plano**: `plan.md` · **Data**: 31/10/2026

Legenda: `[C]` crítica (bloqueia publicar) · `[N]` normal · `→` depende de.

## Fase 1 — Bateria, antes de mexer (FR-015)

- [x] **1.1** `[N]` `tools/central-ia/bateria-pedro/CenariosTest.php`: 20 situações com dados fictícios, pedidos montados
  pelo `Contexto` real, gravados em `storage/app/bateria-pedro/<rótulo>/pedidos.json`.
- [x] **1.2** `[N]` `rodar.mjs`: cada pedido ao `claude` com os argumentos do runner; `respostas.json`. → 1.1
- [x] **1.3** `[N]` Rodar o **antes** (código atual) e guardar. → 1.2

## Fase 2 — Financeiro com valor (US2; FR-007 a FR-011)

- [x] **2.1** `[C]` `secretario_gestor.php`: `pagar_vencendo` com valor, itens, `vencidas`, `hoje`, `maiores`;
  `cobrancas_hoje` com valor; `cobrancas_vencidas` com `maiores`. E o `gestor.mjs` da máquina deixando passar os
  campos novos (`test-gestor.mjs`). Conferido contra a cópia local do Gestor, só leitura.
- [x] **2.2** `[C]` `SecretarioRunnerController::gestor`: regras `sometimes` para os campos novos; leitura antiga e nova
  aceitas. Teste. → 2.1
- [x] **2.3** `[C]` `AcessoInterno::gestor`: vencidas/hoje e maiores no pedido da casa; suporte e cliente sem nada. Teste.
  → 2.2

## Fase 3 — Regras de comunicação (US1; FR-001 a FR-006, FR-014)

- [x] **3.1** `[C]` `Contexto::COMUNICACAO` nos três pedidos; frases antigas fora; `JEITO_POR_PERFIL`. Teste.
- [x] **3.2** `[C]` `AcessoInterno`: DESATUALIZADO depois de 30 min; nota da lista parcial; sem "(sem cadastro)". Teste.

## Fase 4 — Envio em partes (US3; FR-012)

- [x] **4.1** `[C]` `Contexto::partes` e o envio parte a parte em `Pedro::concluir` (só a casa). Teste.

## Fase 5 — Agenda por dia da semana (US4; FR-013)

- [x] **5.1** `[N]` `AcessoInterno::agenda`: dia da semana, "próxima", "esta semana", "semana que vem", teto de 7.
  Teste.

## Fase 6 — Prova e entrega

- [x] **6.1** `[C]` Suporte + Secretário verdes; suíte inteira sem falha nova (isolada: 2.114, só a falha antiga do `TelasDoMonitorTest`). → 2.x, 3.x, 4.1, 5.1
- [x] **6.2** `[N]` Rodar o **depois** na bateria e gerar o relatório lado a lado (`relatorio.mjs`). → 1.3, 6.1
- [x] **6.3** `[N]` Roteiro de validação em produção: passos da comunicação (`validacao-em-producao.md`, bloco G).
- [x] **6.4** `[C]` Publicar só com autorização: Matriz → instalador da máquina → conferir o `secretario_gestor.php`
  instalado e um retrato novo aceito. Autorizada pelo Felipe em 31/10/2026 ("aprovado os spec pode subir"); sai na
  v2026.10.31.5, junto do pacote 24 h.
