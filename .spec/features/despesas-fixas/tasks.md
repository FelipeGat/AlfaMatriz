# Tasks: Despesas fixas recorrentes

> feature: despesas-fixas

## T-019 — Fábricas dos modelos do contas a pagar [concluida]
- Refs: US-009, AC-020
- Arquivos: database/factories/ContaFixaPagarFactory.php, database/factories/CentroCustoFactory.php, database/factories/FornecedorFactory.php
- Notas: sem estas fábricas não dá para montar uma despesa fixa completa em
  teste (centro de custo, fornecedor, conta financeira). Base para T-020 e T-021.

## T-020 — Provar a geração das parcelas do mês [concluida]
- Refs: US-009, AC-020, AC-021
- Arquivos: app/Services/DespesaFixaService.php, tests/Feature/DespesasFixas/GeracaoMensalTest.php
- Notas: a conta a pagar nasce em aberto, marcada como fixa, ligada à despesa
  de origem e com todos os campos copiados do cadastro; desativada, ainda não
  vigente e já encerrada não geram nada.

## T-021 — Provar idempotência e data de vencimento [concluida]
- Refs: US-010, AC-022, AC-023
- Arquivos: app/Models/ContaFixaPagar.php, tests/Feature/DespesasFixas/VencimentoEIdempotenciaTest.php
- Notas: rodar duas vezes a mesma competência não duplica; dia 31 numa
  competência de fevereiro cai no último dia do mês. Cobrir também o caminho
  pelo comando `app:fechar-competencia-mensal`.

## T-022 — Fechar Q-005 com o dono do produto [pendente]

- Refs: US-010, AC-022
- Arquivos: .spec/features/despesas-fixas/spec.md
- Notas: não é código — decidir se apagar uma parcela e regerar deve recriá-la
  (comportamento atual) ou se isso precisa de trava. Conforme a resposta, abrir
  tarefa de correção.
