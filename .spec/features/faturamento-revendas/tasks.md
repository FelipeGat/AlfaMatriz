# Tasks: Faturamento mensal das revendas

> feature: faturamento-revendas

## T-014 — Fábricas dos modelos do faturamento [pendente]

- Refs: US-006, AC-013
- Arquivos: database/factories/RevendaFactory.php, database/factories/SistemaFactory.php, database/factories/ClienteFactory.php, database/factories/PrecoAtacadoFactory.php
- Notas: hoje só existe `UserFactory` — sem estas fábricas não dá para montar
  o cenário de revenda/sistema/clientes/tier em teste. Base para T-015 a T-017.

## T-015 — Provar a cobrança consolidada [pendente]

- Refs: US-006, AC-013, AC-014, AC-015
- Arquivos: app/Services/FaturamentoService.php, tests/Feature/Faturamento/CobrancaConsolidadaTest.php
- Notas: uma cobrança por revenda/competência somando os sistemas; clientes
  inativos, vínculos inativos e sistemas inativos ficam de fora; revenda sem
  nada elegível não gera cobrança nenhuma.

## T-016 — Provar o cálculo pelo tier de atacado [pendente]

- Refs: US-007, AC-016, AC-017
- Arquivos: app/Models/PrecoAtacado.php, tests/Feature/Faturamento/CalculoTierTest.php
- Notas: preço base quando o volume cabe nas inclusas; base + excedente ×
  valor unitário acima disso; volume acima de todos os limites resulta em
  "sem tier compatível" e o sistema fica fora da cobrança.

## T-017 — Provar idempotência e vencimento [pendente]

- Refs: US-008, AC-018, AC-019
- Arquivos: tests/Feature/Faturamento/FechamentoIdempotenteTest.php
- Notas: rodar a mesma competência duas vezes não cria segunda cobrança nem
  novo registro de apuração; o vencimento é fim da competência + 5 dias.
  Cobrir também o caminho pelo comando `app:fechar-competencia-mensal`.

## T-018 — Levar Q-003 e Q-004 ao dono do produto [pendente]

- Refs: US-007, AC-016
- Arquivos: .spec/features/faturamento-revendas/spec.md
- Notas: não é código — é fechar as duas perguntas em aberto (sobreposição de
  tier próprio da revenda e preço de competência retroativa) e, conforme a
  resposta, abrir tarefa de correção. Enquanto abertas, o audit acusa.
