# Feature Specification: Cobrança da revenda por cliente, com boleto e baixa automática

**Feature**: `cobranca-revendas-boleto`
**Created**: 2026-10-18
**Status**: Draft
**Pedido**: Felipe, 18/10/2026: "A Alfa é a desenvolvedora de sistemas e a Invest a representante dela, então
preciso cobrar da Invest esses clientes […] todo dia 01 a Alfa emita um boleto para a Invest cobrando esses clientes
[…] coloque o pagamento para o dia 11 […] o Matriz terá que analisar se houve o pagamento e dar baixa nele
automaticamente; o financeiro apenas confere […] isso só pode ficar disponibilizado para meu usuário".

## O que já existia

- **Revenda, cliente, produto e vínculo** cliente ↔ produto (`cliente_sistema.ativo`).
- **Preço de atacado** por faixa ou por unidade (`precos_atacado`), com preço próprio por revenda.
- **Fechamento mensal** (`app:fechar-competencia-mensal`, no último dia às 20:00), que gera duas coisas:
  - uma cobrança `locacao_sistema` por revenda, vencendo 5 dias após o fim do mês, e tirando do total quem tem
    contrato de cliente final;
  - uma cobrança `locacao_cliente` por contrato de cliente final.
- **Baixa manual**, que lança a entrada no Caixa (`Cobranca::baixar`).
- **Não existiam** boleto, gateway, baixa automática nem restrição por pessoa.
- No **Gestor**, o boleto sai pelo Mercado Pago (`POST /v1/payments`, `bolbradesco`), por uma única conta, e a
  baixa vem por webhook.

## Decisões do Felipe (18/10/2026)

1. **Preço por cliente**: AlfaJornada R$ 99,90, AlfaGym R$ 79,90, AlfaControl R$ 99,90.
2. **Só os clientes da lista** dele entram. Os outros ativos ficam **não faturáveis** e podem ser ligados depois:
   Vitta (Gym), CEDTEC, Corais de Parati, Parque das Castanheiras, IDES, TAJ, INVEST e ALFA TESTE.
   - Jornada: VixCard e NONA LU.
   - Gym: Boa Forma, Fit Club, Info Fitness, R1, GoFit, EvoFit e World.
   - Control: Jaraguá, Biblioteca, Centro Jurídico, Experience, Pacoti, VixCard, VD Cariacica e Duque.
   - Total: **R$ 1.558,30 por mês**.
3. **Competência**: o boleto do dia 01 cobra o **mês anterior** e vence no **dia 11**. A cobrança de setembro
   (R$ 403,00, pelas faixas antigas) é **cancelada**.
4. **Cobrança de cliente final** (planos Premium/Pro/Basic) **deixa de ser gerada** para os clientes da Invest, e
   as 12 de setembro são canceladas. A Alfa cobra só a Invest.
5. **Mercado Pago**: a mesma conta do Gestor, com a credencial copiada de servidor para servidor.
6. A entrada vai para uma conta nova do Caixa, **"Mercado Pago (Invest)"**.
7. **Só o Felipe** vê e opera. Isso vem de uma permissão própria (`cobranca_revendas`), fora do "tudo do
   administrador".

## Escopo

1. **Revenda "por cliente"** (`revendas.cobranca_por_cliente`), com estas regras no fechamento:
   - conta os clientes ativos e **faturáveis** (`cliente_sistema.faturavel`), sem tirar quem tem contrato de
     cliente final;
   - o preço é o **preço por cliente da própria revenda** (faixa por unidade com `revenda_id`), e não as faixas
     padrão;
   - módulos não entram, porque o preço por cliente já é o valor cheio;
   - vence no **dia de vencimento** da revenda (`revendas.dia_vencimento`, 11) do mês seguinte à competência;
   - não gera `locacao_cliente` para os clientes dela.
2. **Dados de cobrança da revenda**: CNPJ (já existe), e-mail financeiro e endereço (CEP, logradouro, número,
   bairro, cidade, UF). Sem eles o boleto não sai, e a tela diz o que falta.
3. **Boleto**: `cobrancas:emitir-boletos-revendas` roda **todo dia às 08:00**. Ele emite pelo Mercado Pago o boleto
   da cobrança `locacao_sistema` pendente de revenda "por cliente" que ainda não tem boleto. Na prática, o dia 01;
   se falhar, tenta de novo no dia seguinte.
4. **Baixa automática**: `cobrancas:conferir-boletos` roda **de hora em hora**. Ele consulta os boletos em
   aberto no Mercado Pago:
   - aprovado: dá baixa com o valor e a data do pagamento, na conta "Mercado Pago (Invest)", e avisa no sino;
   - cancelado ou expirado: registra e avisa.
   Consultar, e não esperar webhook: não depende de o Matriz receber chamada de fora.
5. **Tela** Financeiro › Cobrança de revendas, só com a permissão nova:
   - os dados de boleto da revenda;
   - o preço por cliente de cada produto;
   - os clientes de cada produto, com a chave "faturável";
   - a prévia do próximo boleto;
   - as cobranças com o boleto (link, linha digitável e situação no Mercado Pago), com os botões "Emitir agora" e
     "Conferir pagamento agora".

## Requirements

- **FR-001**: Só quem tem `cobranca_revendas` MUST ver a tela e as ações (as outras contas recebem 403).
  `cobranca_revendas` MUST NOT estar no "tudo do administrador".
- **FR-002**: Emitir MUST ser idempotente: cobrança com boleto não emite de novo, e a chave de idempotência é
  fixa por cobrança. Duas emissões simultâneas MUST NOT gerar dois boletos.
- **FR-003**: A baixa MUST vir da consulta ao Mercado Pago (`GET /v1/payments/{id}`), nunca de dado vindo de
  fora. MUST usar o valor e a data aprovados, e MUST acontecer uma vez só.
- **FR-004**: O vencimento MUST respeitar o limite do boleto do Mercado Pago (até 28 dias) e o valor mínimo de
  R$ 3,00.
- **FR-005**: O access token MUST ficar só no `.env` do servidor e MUST NOT ir para log, tela ou erro.
- **FR-006**: Revenda que não é "por cliente" MUST seguir exatamente como antes (faixas, contratos e +5 dias).

## Delta Requirements

**Skip**: frente nova sem `docs/specs/current/`. — Claude (pedido do Felipe), 2026-10-18
