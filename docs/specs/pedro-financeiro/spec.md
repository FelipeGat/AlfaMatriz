# Feature Specification: Finanças do Pedro — passado, presente e futuro

**Feature**: `pedro-financeiro`
**Created**: 31/10/2026
**Status**: Clarified. Escopo aprovado pelo Felipe em 31/10/2026, depois das 21:52 ("Sim, as duas coisas"): finanças
futuras do Gestor e financeiro da Alfa no Matriz, este só para o diretor. FR-005 e FR-012 decididos às 22:19 do
mesmo dia, pelas recomendações. Sem plano e sem código.

O Pedro é o assistente do suporte no WhatsApp (`docs/specs/suporte-pedro-whatsapp`). Para quem é da casa ele já
responde sobre o financeiro do Gestor, mas só sobre o que venceu ou vence hoje. Esta spec trata de **o que ele sabe
de finanças**: o futuro, o saldo, o realizado por mês e o financeiro da própria Alfa, que mora no Matriz desde
outubro. O jeito de responder continua o de `pedro-comunicacao`.

## Contexto e evidência

**O pedido (31/10/2026, 21:46):** "fiz uma pergunta sobre as finanças futuras e ele falou que não tem essa
informação; quero que ele tenha acesso completo ao sistema antigo e novos". Entendido assim, e aprovado na
resposta seguinte:

- **Sistema antigo, o Gestor:** o financeiro do Grupo Soluções e o histórico da Alfa até 30/09/2026.
- **Sistema novo, o Matriz:** o financeiro da Alfa a partir de 01/10/2026 (regra do Felipe de 18/10/2026: conta
  da Alfa lançada no Matriz não fica também no Gestor).

**A conversa (31/10/2026, lida no Gestor de produção só para leitura, valores mascarados):**

- 21:37:03 — Felipe, do número de diretor: "O que tenho que pagar no dia 01 de Novembro?"
- 21:37:17 — o Pedro respondeu que não vê datas futuras. Não inventou nada: a regra de honestidade funcionou.

**O que o Pedro recebe hoje (código conferido em 31/10/2026):**

| Perfil | Do Gestor | Do Matriz |
|---|---|---|
| diretor | cobranças vencidas e de hoje, contas a pagar vencidas ou de hoje, orçamentos, funil | só os orçamentos em aberto |
| gestor | o mesmo do Gestor | nada |
| suporte, cliente | nada de financeiro | nada |

Nada depois de hoje, nenhum saldo em conta, nenhum mês fechado.

**A informação existe.** Contagem no Gestor de produção em 31/10/2026, às 21:49, só leitura e sem valores:

| Período | A pagar em aberto | A receber em aberto |
|---|---|---|
| 01/11 | 2 | 0 |
| 02 a 08/11 | 8 | 4 |
| novembro | 64 | 41 |
| dezembro | 58 | 40 |

As 64 contas a pagar de novembro, por empresa: DELTA 26, INVEST 13, GW 6 e **19 em centro de custo sem empresa**.
O Gestor tem 6 contas financeiras ativas.

**O que já existe para reaproveitar:**

- **Gestor:** o Fluxo de caixa projetado, que já calcula saldo das contas, a receber e a pagar por dia, em 30, 60,
  90 ou 180 dias.
- **Matriz:** a projeção que as telas de Receitas e Despesas já mostram (despesas fixas e cobranças de revenda dos
  meses ainda não lançados), e os cadastros de contas a pagar, cobranças e contas financeiras da Alfa.

**Pré-requisito resolvido à parte (bugfix de 31/10/2026):** a máquina rodava uma cópia de 17/10 do script que lê o
financeiro do Gestor, e a leitura publicada às 20:10 não chegava ao Pedro. A cópia foi trocada, e a máquina passa a
copiar o script instalado a cada leitura (`docs/specs/secretario-emails-gestor/research.md`).

## Clarifications

### Session 2026-10-31

- Q: O que é "acesso completo ao sistema antigo e novos"? → A: Gestor (Grupo Soluções e Alfa até 30/09) e Matriz
  (Alfa desde 01/10), com passado, presente e futuro já calculados. **Não** é consulta livre ao banco: o Pedro roda
  sem ferramentas, e a regra da casa é ler só o recorte necessário pela ponte (recomendação aceita pelo Felipe em
  31/10/2026).
- Q: Quem vê o financeiro da Alfa? → A: só o diretor (Felipe, 31/10/2026).
- Q: Até onde vai o futuro? → A: dia a dia nos próximos 60 dias, com a lista de cada dia; totais por mês até 6
  meses à frente (proposta aprovada em 31/10/2026).
- Q: O que conta como "em aberto" (FR-005)? → A: o critério da leitura que o Pedro já usa: cobrança fora de paga e
  cancelada, e sem as lançadas como prejuízo; conta a pagar só "em aberto". O Fluxo de caixa projetado da tela do
  Gestor conta essas cobranças, e o Pedro não promete dinheiro que não vai entrar (recomendação aceita pelo Felipe em
  31/10/2026, 22:19).
- Q: Quem vê o saldo em conta e o realizado do Gestor (FR-012)? → A: só o diretor. O perfil gestor continua com o
  que já vê do Gestor (cobranças e contas a pagar) e passa a ver também o futuro em aberto (FR-001 a FR-003); saldo
  atual, saldo previsto e realizado por mês ficam com o diretor (recomendação aceita pelo Felipe em 31/10/2026,
  22:19).

## User Scenarios & Testing

### User Story 1 - O que vence num dia ou numa semana que ainda não chegou (Priority: P1)

O diretor ou o gestor pergunta o que tem a pagar ou a receber num dia, numa semana ou num intervalo dos próximos
60 dias e recebe o total, a quantidade e a lista, separada por empresa.

**Why this priority**: é exatamente a pergunta que falhou em 31/10/2026, e é a decisão do dia a dia (o que pagar,
de quem cobrar). Sozinha já resolve o pedido.

**Independent Test**: com o Gestor contendo contas em aberto em datas futuras, perguntar por um desses dias e
conferir quantidade, total e itens contra a tela de contas do Gestor.

**Acceptance Scenarios**:

1. **Given** o Gestor com 2 contas a pagar em aberto vencendo em 01/11, **When** o diretor pergunta "o que tenho
   que pagar no dia 01/11?", **Then** o Pedro responde com as 2 contas, o total, e cada item com fornecedor ou
   descrição, empresa e valor.
2. **Given** um dia futuro sem nenhuma conta em aberto, **When** perguntam por ele, **Then** o Pedro diz que não
   há conta em aberto naquele dia, e não diz que não tem a informação.
3. **Given** a pergunta "o que vence semana que vem?", **When** o Pedro responde, **Then** ele agrupa por dia,
   dá o total do período e só traz o que é daquele período (regra de período de `pedro-comunicacao`).
4. **Given** contas em centro de custo sem empresa, **When** o Pedro lista um dia, **Then** elas aparecem num grupo
   "sem empresa", e não somem nem entram em outra empresa.
5. **Given** um dia com mais contas do que cabem na lista, **When** o Pedro responde, **Then** traz o total do dia,
   as de maior valor, e diz quantas ficaram de fora e quanto somam.
6. **Given** uma pergunta sobre um dia além dos 60 dias, **When** o Pedro responde, **Then** dá o total do mês
   daquele dia e diz que a lista dia a dia só vai até 60 dias.

---

### User Story 2 - Quanto tem em caixa e como fica nos próximos meses (Priority: P2)

O diretor pergunta o saldo das contas e como o caixa fica nos próximos meses, por empresa. O gestor não recebe
saldo (FR-012).

**Why this priority**: depois de saber o que vence, a pergunta seguinte é se tem dinheiro para isso. Depende do
mesmo futuro da US1, mas entrega valor sozinha com o saldo e os meses.

**Independent Test**: comparar o saldo por empresa e os totais por mês da resposta com o Fluxo de caixa projetado
da tela do Gestor, no mesmo critério.

**Acceptance Scenarios**:

1. **Given** contas financeiras ativas com saldo, **When** o diretor pergunta "quanto tem em caixa?", **Then** o
   Pedro dá o saldo por empresa e o total, com a hora da leitura se o dado estiver desatualizado.
2. **Given** a pergunta "como fica dezembro?", **When** o Pedro responde, **Then** traz o a receber e o a pagar em
   aberto de dezembro e o saldo previsto ao fim do mês, rotulado como **previsto**.
3. **Given** qualquer resposta com saldo previsto, **When** o Pedro a escreve, **Then** ele nunca o chama de lucro,
   resultado ou sobra.
4. **Given** um mês além de 6 meses à frente, **When** perguntam por ele, **Then** o Pedro diz que a previsão vai
   até o sexto mês e não estima.
5. **Given** quem pergunta tem o perfil gestor, **When** pergunta "quanto tem em caixa?" ou "quanto entrou em
   setembro?", **Then** o Pedro diz que isso não está no que ele consulta para esse perfil, sem dar número nenhum, e
   continua respondendo o que vence (US1).

---

### User Story 3 - O financeiro da Alfa, só para o diretor (Priority: P2)

O diretor pergunta sobre o financeiro da própria Alfa (desde 01/10/2026 no Matriz): saldo, contas a pagar,
cobranças das revendas e o que ainda vai ser lançado.

**Why this priority**: o diretor hoje não tem nada da Alfa pelo Pedro, nem o que já está lançado no Matriz.

**Independent Test**: com contas da Alfa lançadas e despesas fixas ainda não geradas no Matriz, perguntar como
diretor e conferir com as telas de Receitas e Despesas; perguntar o mesmo como gestor e não receber nada da Alfa.

**Acceptance Scenarios**:

1. **Given** contas a pagar da Alfa em aberto no Matriz, **When** o diretor pergunta "o que a Alfa tem para pagar
   em novembro?", **Then** o Pedro lista o lançado e, à parte, o **previsto, ainda não lançado** (despesa fixa sem a
   conta do mês), com os dois totais separados.
2. **Given** a despesa fixa de novembro já lançada, **When** o diretor pergunta por novembro, **Then** ela aparece
   uma vez só, como lançada.
3. **Given** a pergunta "o que tenho para pagar no dia 01/11?", vinda do diretor, **When** há contas nos dois
   sistemas, **Then** o Pedro separa "Grupo Soluções (Gestor)" de "Alfa (Matriz)".
4. **Given** quem pergunta tem o perfil gestor, **When** pergunta sobre a Alfa, **Then** o Pedro diz que isso não
   está no que ele consulta para esse perfil, sem dar número nenhum.
5. **Given** cobranças das revendas no Matriz, **When** o diretor pergunta o que a Alfa tem a receber, **Then** o
   Pedro mostra as lançadas (vencidas, de hoje e futuras) e, à parte, a prévia das ainda não lançadas.

---

### User Story 4 - Quanto entrou e saiu num mês que já passou (Priority: P3)

O diretor pergunta o realizado de um mês dos últimos 12 ("quanto a Alfa gastou em setembro?", "quanto a DELTA
recebeu em agosto?") e recebe entradas e saídas pagas, do sistema certo para cada mês.

**Why this priority**: completa o "passado"; é menos urgente que o que vence, mas é a pergunta de fechamento.

**Independent Test**: perguntar pela Alfa em setembro (Gestor) e em outubro (Matriz) e conferir cada resposta com a
tela do sistema de origem.

**Acceptance Scenarios**:

1. **Given** setembro de 2026, **When** o diretor pergunta quanto a Alfa gastou, **Then** o Pedro usa o Gestor e
   diz que o dado vem de lá.
2. **Given** outubro de 2026, **When** a mesma pergunta, **Then** o Pedro usa o Matriz.
3. **Given** um intervalo que cruza o corte (setembro a outubro), **When** perguntam, **Then** o Pedro mostra as duas
   partes, cada uma com a sua origem, e o total só se as duas tiverem sido lidas.
4. **Given** um mês com mais de 12 meses, **When** perguntam por ele, **Then** o Pedro diz que o histórico que ele
   consulta vai até 12 meses.

---

### Edge Cases

- **Leitura do Gestor falhou ou está velha:** o Pedro diz que não consegue consultar ou avisa que o dado está
  desatualizado; não usa número de outra leitura sem dizer de quando é.
- **Só uma das partes foi lida** (o futuro do Gestor sim, a parte de hoje não, ou o contrário): responde com o que
  tem e diz o que faltou; valor ausente nunca vira zero.
- **Conta da Alfa ainda no Gestor depois de 30/09:** o Pedro a mostra onde ela está lançada (empresa e centro de
  custo do Gestor) e não a reclassifica. O Felipe ainda não decidiu se as quinzenas lançadas no centro INVEST são
  custo da Alfa (memória `financeiro-alfa-so-no-matriz`).
- **Lançamento repetido** (a mesma conta duas ou três vezes): o Pedro mostra como está lançado; não deduplica nem
  corrige.
- **Cobrança cancelada ou lançada como prejuízo:** não conta como em aberto, nem no futuro nem no saldo previsto
  (FR-005); por isso o total do Pedro pode ser menor que o do Fluxo de caixa projetado da tela do Gestor.
- **Cliente, número desconhecido ou perfil suporte pergunta de finanças:** nada de financeiro chega ao Pedro para
  essas conversas (níveis de identificação de `suporte-pedro-whatsapp`); valor em dinheiro para quem não é da casa
  é barrado na entrega.
- **Pergunta sobre o saldo de uma conta específica** (um banco): responde se a conta está na leitura; senão, diz
  que não tem.
- **Pergunta de data passada pela lista do futuro** ("o que venceu ontem?"): vale o que já existe (vencidas); o
  futuro começa amanhã.
- **Fim de semana e feriado:** a conta é mostrada no dia do vencimento cadastrado; o Pedro não a muda de dia.

## Fora de escopo

- Consulta livre ao banco ou relatório montado pela IA a partir de tabelas.
- Pagar, baixar, lançar, editar ou cancelar conta ou cobrança.
- Previsão estatística (média, tendência): o futuro é só o que está lançado mais a projeção que o Matriz já faz.
- Mudar o resumo do Telegram, a Central de Gestão ou as telas do Gestor e do Matriz.
- Mostrar boleto ou NF de cliente (`suporte-pedro-whatsapp`).
- Finanças para cliente: preço e valor continuam proibidos para quem não é da casa.

## Requirements

### Functional Requirements

**Futuro do Gestor**

- **FR-001**: O Pedro MUST responder, para quem pode ver o financeiro do Gestor, o que está em aberto a pagar e a
  receber em cada dia dos próximos 60 dias, a contar de amanhã: quantidade, total e itens (fornecedor ou cliente,
  descrição, empresa, valor e vencimento).
- **FR-002**: Cada dia MUST trazer no máximo 10 itens, os de maior valor, junto da quantidade e do total do dia
  inteiro; o Pedro MUST dizer quantos ficaram de fora e quanto somam (padrão adotado; o plano pode ajustar o número
  pelo tamanho do pedido à IA).
- **FR-003**: O Pedro MUST responder os totais a pagar e a receber em aberto por mês, do mês corrente até 6 meses à
  frente, por empresa.
- **FR-004**: O Pedro MUST responder o saldo atual das contas financeiras ativas do Gestor, por empresa e no total,
  e o saldo previsto ao fim de cada mês (saldo atual + a receber − a pagar em aberto até lá), sempre rotulado
  "previsto" e nunca chamado de lucro, resultado ou sobra.
- **FR-005**: O que conta como "em aberto" MUST seguir um critério único, o mesmo nas respostas de hoje, do futuro
  e do saldo previsto: o da leitura que o Pedro já usa (`secretario_gestor.php`). Cobrança em aberto é a que não está
  paga nem cancelada e não foi lançada como prejuízo; conta a pagar em aberto é a de situação "em aberto". O critério
  do Fluxo de caixa projetado da tela (tudo que não está pago) MUST NOT ser usado. Decidido em 31/10/2026.
- **FR-006**: Toda resposta por empresa MUST mostrar as contas sem empresa num grupo próprio, "sem empresa", e o
  total MUST ser a soma de todos os grupos.

**Financeiro da Alfa (Matriz)**

- **FR-007**: Só o perfil diretor MUST receber o financeiro da Alfa: saldo das contas financeiras do Matriz, contas a
  pagar e cobranças lançadas (vencidas, de hoje, por dia nos próximos 60 dias e por mês até 6 meses), com o mesmo
  formato e limites de FR-001 a FR-004.
- **FR-008**: O previsto ainda não lançado do Matriz (despesa fixa vigente sem a conta do mês e cobrança de revenda
  pela prévia) MUST vir separado do lançado, rotulado "previsto, ainda não lançado", com total próprio.
- **FR-009**: Um mês com o lançamento real MUST NOT trazer também a projeção daquele lançamento.
- **FR-010**: Para o diretor, a resposta que juntar os dois sistemas MUST separar "Grupo Soluções (Gestor)" de
  "Alfa (Matriz)" e dizer a origem de cada parte.

**Realizado por mês**

- **FR-011**: O Pedro MUST responder o realizado de cada um dos últimos 12 meses fechados e do mês corrente:
  entradas recebidas e saídas pagas, pela data do pagamento, por empresa no Gestor e da Alfa no Matriz.
- **FR-012**: Só o perfil diretor MUST receber o saldo atual e o saldo previsto do Gestor (FR-004) e o realizado por
  mês (FR-011). O perfil gestor MUST continuar recebendo as cobranças e contas a pagar do Gestor e o futuro em aberto
  (FR-001 a FR-003), e MUST NOT receber saldo nem realizado: perguntado, o Pedro diz que isso não está no que ele
  consulta para esse perfil, sem número nenhum. Decidido em 31/10/2026.
- **FR-013**: Pergunta sobre a Alfa MUST usar o Gestor para meses até setembro de 2026 e o Matriz a partir de
  outubro de 2026; num intervalo que cruza o corte, o Pedro MUST mostrar as duas partes com a origem de cada uma e
  só somar quando as duas tiverem sido lidas. No Gestor, "a Alfa" é o centro de custo ALFA.

**Honestidade, frescor e segurança**

- **FR-014**: Cada parte (futuro do Gestor, saldo, realizado, Alfa) MUST carregar a hora em que foi lida; com mais
  de 3 horas, a parte do Gestor MUST ir marcada como desatualizada, como a leitura financeira de hoje.
- **FR-015**: Fora do horizonte (mais de 60 dias para a lista, mais de 6 meses para o futuro, mais de 12 meses para
  o realizado) ou sem leitura, o Pedro MUST dizer que não tem o dado e MUST NOT estimar. "Nenhuma conta" só quando a
  leitura disser zero.
- **FR-016**: A leitura do Gestor MUST ser só leitura, pela ponte que já existe, trazendo apenas os campos desta spec;
  nada é gravado em nenhum dos dois sistemas.
- **FR-017**: A falha ou recusa de uma parte nova MUST NOT derrubar a leitura financeira de hoje, o resumo do
  Telegram nem a Central de Gestão, que continuam com os mesmos blocos; os dois sistemas MUST poder ser publicados
  em qualquer ordem.
- **FR-018**: Nenhum dado desta spec MUST chegar ao Pedro em conversa de cliente, de número desconhecido ou de perfil
  suporte.

**Infraestrutura**

- **FR-019-INFRA-SCHED**: O futuro, o saldo e o realizado do Gestor MUST ser lidos no mesmo ritmo da leitura
  financeira que já existe (de hora em hora, pela máquina, uma chamada da ponte por vez); a parte do Matriz MUST ser
  montada na hora da pergunta. Sem agendamento novo.

> Decisões de infraestrutura: sem chave, sem token novo e sem gravação; idempotência não se aplica (só leitura).

### Key Entities

- **Futuro do Gestor**: por empresa (DELTA, INVEST, GW e "sem empresa"), os dias dos próximos 60 com quantidade,
  total e até 10 itens a pagar e a receber, e os totais dos próximos meses; com a hora da leitura.
- **Saldo**: por empresa, a soma das contas financeiras ativas, e o saldo previsto ao fim de cada mês.
- **Realizado mensal**: por mês dos últimos 12 e por empresa, as entradas recebidas e as saídas pagas.
- **Financeiro da Alfa**: o mesmo recorte do Matriz (saldo, a pagar, a receber, realizado), mais o previsto ainda não
  lançado, sempre separado.
- **Corte da Alfa**: 30/09/2026 no Gestor, 01/10/2026 no Matriz; decide de onde vem cada mês.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Em 5 dias futuros escolhidos ao acaso, a quantidade e o total que o Pedro dá batem com a tela de contas
  do Gestor no mesmo critério, nos 5.
- **SC-002**: "O que tenho que pagar no dia 01/11?" — a pergunta que falhou em 31/10/2026 — é respondida com as contas
  do dia, em vez de "não vejo datas futuras".
- **SC-003**: Na bateria do Pedro com cenários financeiros fictícios (dia futuro, semana, mês, saldo, Alfa, corte,
  perfil gestor), zero respostas com número que não está nos dados e zero "lucro" para saldo previsto.
- **SC-004**: Zero dado da Alfa, de saldo e de realizado em respostas ao perfil gestor, e zero dado financeiro para
  cliente, desconhecido ou suporte, nos testes por perfil.
- **SC-005**: Depois de publicado, o resumo do Telegram e a Central de Gestão continuam com os mesmos blocos e os mesmos
  números da leitura de hoje.
- **SC-006**: Toda parte do Gestor com mais de 3 horas sai marcada como desatualizada, em 100% das respostas que a usam.

## Delta Requirements

**Skip**: o corpus `docs/specs/current/` não existe neste projeto; o comportamento ativo do Pedro está em
`docs/specs/suporte-pedro-whatsapp` e `docs/specs/pedro-comunicacao` — Claude, 2026-10-31

## Fontes (conferidas em 31/10/2026)

- Conversa de 31/10/2026, 21:37, e contagem de 21:49: leituras só leitura no Gestor de produção pela ponte
  (`C:\tmp\e437\pedro_financas_futuras_ro.php` e `C:\tmp\e437\financas_futuras_contagem_ro.php`).
- O que cada perfil recebe: `app/Services/Suporte/AcessoInterno.php` (`VE`, `dados`, `gestor`).
- Desatualizado em 3 h: `SecretarioGestorRetrato::VELHO_HORAS`.
- Critério da leitura de hoje: `deploy/central-ia/secretario_gestor.php` (cobranças fora de pago e cancelado, sem
  prejuízo; contas a pagar em aberto).
- Critério da tela: `app/Services/Relatorios/FluxoCaixaService.php` do Gestor (tudo que não está pago; horizontes de
  30, 60, 90 e 180 dias).
- Projeção da Alfa: `app/Services/ProjecaoFinanceira.php` do Matriz.
- Contrato com a máquina: `SecretarioRunnerController::gestor` recusa bloco desconhecido e mais de 10 itens dentro de
  `blocos`, e ignora o que vem fora de `blocos`.
- Corte da Alfa e centro de custo ALFA: regra do Felipe de 18/10/2026 (memória `financeiro-alfa-so-no-matriz`).
