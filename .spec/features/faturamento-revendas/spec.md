# Spec: Faturamento mensal das revendas

> feature: faturamento-revendas
> status: rascunho

## Contexto

A Alfa licencia seus sistemas (AlfaGym, AlfaControl, AlfaHome, AlfaMed,
AlfaJornada, AlfaSchool, Gestor) para revendas, que por sua vez atendem os
clientes finais. Todo mês a Alfa precisa cobrar cada revenda pelo que ela
usou — não cliente a cliente, mas uma conta só, consolidando todos os
sistemas daquela revenda.

O motor disso já existe e roda em produção (`app/Services/FaturamentoService.php`,
chamado pela tela de Faturamento e pelo comando `app:fechar-competencia-mensal`).
Esta especificação descreve o comportamento que ele já tem, para que o
faturamento — que é a receita da empresa — passe a ter prova executável.

## Histórias

### US-006 — Cada revenda recebe uma conta só por mês

Como responsável pela Alfa, quero uma única cobrança por revenda a cada
competência, para que a revenda receba uma conta consolidada em vez de uma
cobrança por cliente ou por sistema.

#### AC-013 — Uma cobrança por revenda, com o detalhamento dentro

- **Dado** uma revenda ativa com clientes ativos em dois sistemas diferentes
- **Quando** o faturamento da competência é gerado
- **Então** é criada exatamente uma cobrança para essa revenda na competência,
  cujo valor é a soma dos dois sistemas e cujo detalhamento mostra, por
  sistema, o tier aplicado, a quantidade de clientes e o valor

#### AC-014 — Só entra quem está ativo

- **Dado** uma revenda com um cliente ativo, um cliente inativo e um cliente
  ativo cujo vínculo com o sistema foi desativado
- **Quando** o faturamento da competência é gerado
- **Então** só o primeiro cliente é contado no volume do sistema, e sistemas
  desativados não entram na cobrança de forma alguma

#### AC-015 — Revenda sem nada a cobrar não gera cobrança

- **Dado** uma revenda ativa sem nenhum cliente ativo em sistema algum
- **Quando** o faturamento da competência é gerado
- **Então** nenhuma cobrança é criada para ela, em vez de uma cobrança de
  valor zero

### US-007 — O valor vem do tier de atacado

Como responsável pela Alfa, quero que o valor cobrado saia do tier de atacado
aplicável ao volume da revenda, para que o preço acompanhe a faixa contratada
sem cálculo manual.

#### AC-016 — Preço base mais o excedente por unidade

- **Dado** um tier com preço base e um número de unidades inclusas, e uma
  revenda com mais clientes ativos do que as unidades inclusas
- **Quando** o valor do sistema é calculado
- **Então** o valor é o preço base somado ao excedente (clientes além das
  inclusas) multiplicado pelo valor por unidade excedente; e quando o volume
  cabe nas inclusas, o valor é exatamente o preço base

#### AC-017 — Volume acima de todas as faixas é sinalizado, não cobrado errado

- **Dado** uma revenda cujo volume de clientes ativos ultrapassa o limite de
  todos os tiers vigentes do sistema
- **Quando** o faturamento é gerado
- **Então** aquele sistema não entra na cobrança e o resultado sinaliza
  "sem tier compatível" com a quantidade encontrada, para que alguém trate o
  caso — em vez de cobrar por uma faixa que não comporta o volume

### US-008 — Fechar o mês duas vezes não cobra duas vezes

Como responsável pela Alfa, quero poder rodar o fechamento de novo sem medo,
para que uma reexecução (ou o agendamento disparando junto com a tela) não
gere cobrança duplicada para a revenda.

#### AC-018 — Reexecutar a mesma competência não duplica

- **Dado** uma competência cujo faturamento já foi gerado
- **Quando** o faturamento da mesma competência é gerado de novo
- **Então** nenhuma cobrança nova é criada, nenhum registro de apuração novo é
  criado, e o resultado avisa que já existia

#### AC-019 — O vencimento cai cinco dias depois do fim da competência

- **Dado** o faturamento de uma competência
- **Quando** a cobrança é criada
- **Então** o vencimento é o último dia daquela competência mais cinco dias

## Fora de escopo

- Cobrança de clientes diretos (sem revenda) — ver ASM-007.
- Baixa/pagamento da cobrança: esta feature só gera a cobrança.
- Envio da cobrança por e-mail ou boleto.
- Tela de Faturamento (preview): a prova aqui é do motor de geração, não da
  renderização da tela.
- Reajuste de preços e vigência retroativa de tiers — ver ASM-009 e Q-004.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-007 | Clientes diretos (sem revenda) ficam fora deste motor e são cobrados manualmente pela tela de Receitas | aberta | O código documenta isso; falta confirmar com o dono do produto que é a regra desejada |
| ASM-008 | O tier escolhido é o primeiro por `ordem` que comporta o volume — assume-se que a ordem cadastrada reflete o preço crescente | aberta | O código chama isso de "tier mais barato", mas quem decide é a `ordem` cadastrada, não o preço |
| ASM-009 | A vigência dos tiers é avaliada na data em que o fechamento roda, não na competência faturada | aberta | Consequência: faturar um mês retroativo usa os preços de hoje. Ver Q-004 |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-003 | Um tier próprio da revenda deve substituir o tier padrão de mesmo nome? O comentário no código diz que sim, mas hoje os dois convivem e vence o de menor `ordem` | aberta | — |
| Q-004 | Faturar uma competência retroativa deveria usar os preços vigentes naquela época, em vez dos de hoje? | aberta | — |
