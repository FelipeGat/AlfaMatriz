# Tarefas AlfaMatriz - De quem é cada coisa em Acompanhamentos

Escopo: separar em Acompanhamentos o que é do Felipe, do Rossini e do Alexandre, nas notificações e na tabela
(spec `secretario-acompanhamentos-por-area`).

**Legenda de status:**
- `[ ]` Pendente
- `[~]` Em andamento
- `[x]` Concluido
- `[!]` Bloqueado

**Legenda de criticidade:**
- `[C]` Critico - Impacto financeiro direto ou bloqueante
- `[A]` Alto - Funcionalidade essencial
- `[M]` Medio - Necessario mas sem urgencia imediata

---

## FASE 1 - Domínio

### 1.1 A área de cada coisa `[A]`

Ref: FR-001, FR-003, FR-004

- [x] 1.1.1 `services.areas` (dono de cada área pelo e-mail)
- [x] 1.1.2 `Areas`: avisos, blocos do Gestor, rótulo, filtro
- [x] 1.1.3 `CentralDeGestao::linhas` com `de`

---

## FASE 2 - Tela

### 2.1 Acompanhamentos `[A]`

Ref: FR-001, FR-002

- [x] 2.1.1 Filtro "De quem é" com contagem, para as notificações e a tabela
- [x] 2.1.2 Rótulo em cada notificação e em cada linha da tabela
- [x] 2.1.3 Testes: classificação, tela, tela de outra pessoa, área sem dono
- [x] 2.1.4 Suíte; teste local na 8096; publicação (aprovada pelo Felipe em 07/11/2026)
