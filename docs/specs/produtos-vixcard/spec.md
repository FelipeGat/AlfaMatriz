# Feature Specification: Produtos da VixCard no catálogo

**Feature**: `produtos-vixcard`
**Created**: 2026-10-17
**Status**: Draft (local)
**Pedido**: Felipe, 17/10/2026: "preciso no matriz ter acesso a eles, igual tem do Control, Gym, Jornada. Control,
Gym, Jornada são produtos da Alfa. Vix-ID, Vix-Link, Vix-Beer são produtos da VixCard."

## Decisão

Terceira natureza em `sistemas`: `vixcard` ("Produto VixCard"). A Alfa desenvolve e a VixCard vende.

- Fica **fora** de `Sistema::produtos()`. A receita não é da Alfa, então não entra em MRR, tier, faturamento das
  revendas nem fechamento.
- Aparece na aba própria de Produtos ("Produtos VixCard"), com a régua dos internos: responsável, versão e tarefas
  abertas.
- As tarefas e os filtros apontam para ele, agrupado pela natureza.

## Requirements

- **FR-001**: Cadastro com natureza `vixcard` MUST descartar categoria e unidade de cobrança, e MUST voltar para a
  aba dele.
- **FR-002**: `Sistema::produtos()` MUST NOT incluir produto VixCard.
- **FR-003**: A edição de produto VixCard MUST NOT mostrar os tiers de atacado.

## Fora do escopo

- Monitor: os sistemas da VixCard entram no AlfaMonitor (serviço separado), com o endereço de produção de cada um.
  O Matriz mostra o que ele acompanha.

## Delta Requirements

**Skip**: ajuste de catálogo, sem `docs/specs/current/`. — Claude (pedido do Felipe), 2026-10-17
