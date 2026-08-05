# Spec: Despesas fixas recorrentes

> feature: despesas-fixas
> status: rascunho

## Contexto

A Alfa tem despesas que se repetem todo mês — aluguel, contabilidade,
servidores, salários. Em vez de alguém relançar cada uma manualmente a cada
competência, o sistema guarda um cadastro recorrente ("despesa fixa") e gera
as parcelas do mês a partir dele.

O motor já existe e roda em produção (`app/Services/DespesaFixaService.php`,
chamado pela tela de Despesas Fixas e pelo comando
`app:fechar-competencia-mensal`). Esta especificação descreve o comportamento
que ele já tem, para que o contas a pagar da empresa passe a ter prova
executável.

## Histórias

### US-009 — As despesas do mês aparecem sozinhas

Como responsável pelo financeiro da Alfa, quero que as despesas recorrentes
virem contas a pagar do mês automaticamente, para que ninguém precise
relançar as mesmas contas toda competência e nenhuma seja esquecida.

#### AC-020 — Cada despesa fixa vigente vira uma conta a pagar do mês

- **Dado** uma despesa fixa ativa e vigente na competência
- **Quando** as despesas da competência são geradas
- **Então** é criada uma conta a pagar em aberto para ela, marcada como fixa e
  ligada à despesa que a originou, carregando o valor, o centro de custo, a
  conta contábil, o fornecedor, a conta financeira e a forma de pagamento do
  cadastro

#### AC-021 — Só entra o que está ativo e vigente naquele mês

- **Dado** uma despesa fixa desativada, uma que só começa depois da
  competência e uma que já terminou antes dela
- **Quando** as despesas da competência são geradas
- **Então** nenhuma das três gera conta a pagar, e uma despesa cuja vigência
  cobre a competência gera normalmente

### US-010 — O mês fecha sem duplicar nem errar a data

Como responsável pelo financeiro, quero rodar a geração de novo sem criar
contas duplicadas e com vencimento numa data que exista, para que o contas a
pagar continue confiável.

#### AC-022 — Gerar duas vezes a mesma competência não duplica

- **Dado** uma competência cujas despesas fixas já foram geradas
- **Quando** a geração da mesma competência é executada de novo
- **Então** nenhuma conta a pagar nova é criada e o resultado avisa quais já
  existiam

#### AC-023 — Dia de vencimento que não existe no mês cai no último dia

- **Dado** uma despesa fixa com vencimento no dia 31
- **Quando** as despesas de uma competência de fevereiro são geradas
- **Então** o vencimento fica no último dia daquele mês, em vez de uma data
  inválida

## Fora de escopo

- Baixa/pagamento da conta a pagar: esta feature só gera a parcela em aberto.
- Reajuste automático de valor: mudar o valor do cadastro só afeta as
  competências geradas depois.
- Rateio de uma despesa entre vários centros de custo.
- Tela de cadastro de despesas fixas: a prova aqui é do motor de geração.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-010 | Alterar o valor de uma despesa fixa não deve mexer nas contas a pagar já geradas de competências anteriores | aberta | É o comportamento atual (a conta a pagar copia o valor no momento da geração); falta confirmar que é o desejado |
| ASM-011 | Uma despesa fixa gera no máximo uma parcela por competência | aberta | A idempotência é por (despesa fixa, competência); despesas quinzenais ou semanais não são representáveis hoje |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-005 | Se alguém apagar por engano a conta a pagar gerada, rodar a geração de novo deve recriá-la, ou isso é indesejado? Hoje recria, porque a idempotência olha só se existe | aberta | — |
