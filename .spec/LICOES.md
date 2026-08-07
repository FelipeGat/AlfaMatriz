# LIÇÕES — mantido pelo motor (`onp-spec licoes`)

> Não edite à mão: qualquer escrita do motor sobrescreve este arquivo.
> Estado canônico em `.spec/licoes.json`; mutação só via `onp-spec licoes`.

## Confirmadas — carregue no Especificar/Projetar

Corroboradas em múltiplas features. Aplique como guia.

_nenhuma_

## Candidatas — em observação, NÃO aplicar ainda

Vistas em uma feature só. Registradas, não confiadas.

### L-001 — Não marque tarefa como concluída para destravar o plano: se a prova ainda não existe, a tarefa não está pronta e o audit acusa até o verify passar.
- sinal: `TASK_CONCLUIDA_SEM_PROVA` · recorrência: 1 feature(s) · penalidades: 0
- features: redesign-visual
- última evidência: T-031 (redesign-visual, 2026-08-06T18:20:50.737Z)

### L-002 — Spec escrita depois que o código já roda não vira prova sozinha: enquanto ninguém escrever o teste anotado, o motor que ela descreve segue em produção sem nenhuma garantia. Escreva o teste na mesma entrega da spec retroativa.
- sinal: `AC_SEM_TESTE` · recorrência: 1 feature(s) · penalidades: 0
- features: faturamento-revendas
- última evidência: AC-013 (faturamento-revendas, 2026-08-07T16:05:51.816Z)

## Quarentena — aplicadas e falharam, ignorar

A falha recorreu mesmo com a lição aplicada. Revisão é do usuário.

_nenhuma_
