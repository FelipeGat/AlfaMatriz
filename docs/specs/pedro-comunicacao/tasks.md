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

## Fase 7 — Segunda parte (FR-016 a FR-023)

- [x] **7.1** `[N]` Bateria: cenários 21 a 26, campo `checar`, rótulos livres; rodar o **r2-antes** com o código da
  v2026.10.31.5 antes de mexer (26 de 26 respondidas).
- [x] **7.2** `[C]` Período: `Contexto::PERIODO` e a marca `(NÃO PEDIDO)` na agenda (FR-016, FR-017). Testes
  ajustados em `PedroComunicacaoTest` e `PedroAgendaDoTecnicoTest`.
- [x] **7.3** `[C]` Prazo: `Contexto::PRAZO`, roteiro sem `prazo_horas` no pedido, migração do "Portão parado",
  rótulo da tela (FR-018). `PedroConhecimentoTest` ajustado.
- [x] **7.4** `[C]` O que faz: `AcessoInterno::oQueFaz` e `Contexto::ACOES_DO_CLIENTE` (FR-019).
- [x] **7.5** `[C]` Preço na entrega: `Contexto::semPrecoParaCliente` em `Pedro::concluir` (FR-020).
- [x] **7.6** `[C]` Nada deduzido e próxima visita na linha do atendimento (FR-021).
- [x] **7.7** `[N]` Comercial curto (FR-022).
- [x] **7.8** `[C]` `PedroComunicacaoSegundaRodadaTest`; Suporte + Secretário verdes; suíte isolada sem falha nova.
- [x] **7.9** `[N]` Rodar o **r2-depois** e o relatório `relatorio-r2-depois.html` (FR-023).
- [x] **7.10** `[N]` Roteiro de validação: passos 7 a 12 do bloco G.
- [x] **7.11** `[C]` Publicar só com autorização: Matriz (tag, com a migração); sem instalador nem Gestor.

