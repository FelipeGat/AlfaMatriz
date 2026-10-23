# Feature Specification: O Pedro lança e corrige o dia do técnico

**Feature**: `pedro-dia-do-tecnico`
**Created**: 2026-10-23
**Status**: Clarified

## Contexto

O Pedro (assistente do suporte no WhatsApp) já atende a equipe interna pelo número, a partir
da lista de acesso interno (`docs/specs/suporte-pedro-whatsapp/acesso-interno.md`), e já
registra no Gestor um atendimento novo com agendamento: confere o cliente, pergunta o que
falta (técnico, horário de início e de fim), mostra uma prévia e só grava depois do "sim".

Em 23/10/2026 o Wallas relatou por mensagem o dia de dois técnicos, para o Pedro acertar a
agenda no Gestor:

- **Vitor**: chegou no Corais de Parati às 08:00; às 09:30 foi para a casa da Manuela, em
  Vitória, fazer a manutenção dos ar-condicionados; às 14:30 foi para a casa do Éder, em
  Cariacica, instalar o ar, e encerrou lá às 18:40, sem horário de almoço.
- **Cristiano**: chegou no Mundo Animal por volta das 09:40, pegou o computador que não dava
  para testar no local e levou ao Centro Jurídico; lá testou e condenou a placa, testou
  também o computador do próprio Centro Jurídico e o condenou; depois foi ao Condomínio
  Castanheiras, onde encerrou o dia.

Hoje o Pedro não lê a agenda dos técnicos e não corrige visita existente: o que ele faria é
abrir atendimentos novos, duplicando o que já existe. No mesmo dia, a agenda do Gestor
mostrava para o Vitor uma visita das 08:00 às 10:00 em outro cliente (a Revizza), em conflito
com o relato. O dono decidiu que **o que lançar em cada caso é de quem pede**: o Pedro
propõe, aponta o que existe e o que não bate, e confirma antes de gravar.

## Clarifications

### Session 2026-10-23

- Q: Visita relatada como feita entra como finalizada ou agendada? → A: agendada; o técnico
  finaliza pelo app (FR-015).
- Q: Quem da lista de acesso interno pode alterar a agenda? → A: só diretor e gestor; os demais
  só consultam (FR-017).

## User Scenarios & Testing

### User Story 1 - Ver como está o dia de um técnico (Priority: P1)

Quem está na lista de acesso interno pergunta ao Pedro como está a agenda de um técnico num
dia ("como está o dia do Vitor hoje?"). O Pedro responde com as visitas daquele técnico
naquele dia, em ordem de horário: horário de início e fim, cliente (ou pré-cliente),
número do atendimento, o que é o serviço e a situação da visita (agendada, em andamento,
finalizada, cancelada).

**Why this priority**: é a base de tudo o que vem depois — sem ler a agenda, o Pedro não
sabe o que já existe e duplica. Sozinha já tem valor: o gestor confere o dia de qualquer
técnico pelo WhatsApp, sem abrir o Gestor.

**Independent Test**: com visitas cadastradas para um técnico num dia, um número da lista de
acesso interno pergunta pelo dia dele e recebe a lista certa; um número fora da lista não
recebe nada da agenda.

**Acceptance Scenarios**:

1. **Given** o Vitor tem uma visita no dia 23/10 das 08:00 às 10:00 no atendimento nº 824
   (pré-cliente Revizza), **When** o Wallas pergunta "como está o dia do Vitor hoje?",
   **Then** o Pedro responde com essa visita, mostrando o nome do pré-cliente (e não "sem
   cliente"), o número 824 e a situação "agendada".
2. **Given** o Cristiano não tem visita no dia, **When** o Wallas pergunta pelo dia dele,
   **Then** o Pedro diz que não há nada agendado para ele naquele dia.
3. **Given** um número que não está na lista de acesso interno, **When** ele pergunta pela
   agenda de um técnico, **Then** o Pedro não mostra agenda nenhuma e segue o atendimento
   de cliente de sempre.
4. **Given** o nome do técnico escrito de outro jeito ("Victor" para o Vitor), **When** o
   Wallas pergunta pelo dia dele, **Then** o Pedro reconhece o técnico, ou pergunta qual é
   se houver mais de um parecido.

---

### User Story 2 - Relatar o dia e receber a prévia do que muda (Priority: P1)

Quem está na lista de acesso interno manda ao Pedro o relato livre do dia de um técnico,
por texto ou áudio. O Pedro quebra o relato em paradas (técnico, cliente ou local, horário
de início e de fim, serviço feito e resultado), cruza cada parada com o que existe no
Gestor — visitas daquele técnico no dia, atendimentos abertos e orçamentos do cliente — e
devolve uma **prévia do dia**: para cada parada, o que existe e o que ele propõe fazer
(vincular a uma visita existente, corrigir o horário dela, criar visita num atendimento
aberto, abrir atendimento novo, criar pré-cliente novo). Aponta os **conflitos** (o mesmo
técnico em dois lugares ao mesmo tempo, uma visita agendada que o relato não menciona) e as
**lacunas** (horário de fim que falta, cliente que não foi achado, atendimento que não ficou
claro), e pergunta até fechar.

**Why this priority**: é o pedido do dono — o relato vira agenda certa sem ninguém digitar
no Gestor. Mesmo sem gravar (story 3), a prévia já mostra ao gestor o que não bate.

**Independent Test**: com a agenda e os atendimentos do dia conhecidos, mandar o relato do
Vitor de 23/10 e conferir que a prévia lista as três paradas, cada uma com o que existe, e
aponta o conflito com a visita da Revizza das 08:00 às 10:00.

**Acceptance Scenarios**:

1. **Given** o relato do Vitor de 23/10 e o atendimento nº 756 aberto no Corais de Parati,
   **When** o Wallas manda o relato, **Then** a prévia mostra a parada no Corais (08:00 às
   09:30) proposta como visita nova no atendimento nº 756, e as paradas da Manuela (a partir
   das 09:30) e do Éder (14:30 às 18:40) como clientes não encontrados.
2. **Given** a visita do Vitor das 08:00 às 10:00 na Revizza, **When** a prévia é montada,
   **Then** o Pedro aponta o conflito com o Corais às 08:00 e pergunta qual vale, sem
   decidir sozinho.
3. **Given** a parada da Manuela sem horário de fim explícito, **When** a prévia é montada,
   **Then** o Pedro propõe o fim às 14:30 (saída para a parada seguinte) e pede confirmação,
   ou pergunta o fim se não houver parada seguinte.
4. **Given** o relato do Cristiano ("por volta das 09:40", sem os demais horários), **When**
   o Wallas manda o relato, **Then** a prévia mostra as paradas no Mundo Animal (atendimento
   nº 822), no Centro Jurídico e no Condomínio Castanheiras (atendimento nº 776) e pergunta
   os horários que faltam, sem gravar nada até tê-los.
5. **Given** um relato por áudio, **When** ele chega, **Then** o Pedro usa a transcrição
   como faz hoje com os áudios e monta a mesma prévia.
6. **Given** um cliente citado com nome parecido com mais de um cadastro (cliente e
   pré-cliente, ou duas unidades), **When** a prévia é montada, **Then** o Pedro mostra os
   candidatos com a cidade e pergunta qual é, ou se deve criar um pré-cliente novo.

---

### User Story 3 - Confirmar e gravar no Gestor (Priority: P2)

Depois da prévia fechada, quem pediu responde "sim". O Pedro grava no Gestor exatamente o
que estava na prévia e devolve o resultado de cada parada: o número do atendimento, o que
foi criado ou corrigido, e o que não deu para gravar e por quê.

**Why this priority**: fecha o ciclo, mas depende das stories 1 e 2. Até lá, o gestor ainda
ganha a leitura e a prévia e lança à mão o que o Pedro apontou.

**Independent Test**: confirmar uma prévia com uma visita nova num atendimento existente,
um horário corrigido e um atendimento novo, e conferir no Gestor que as três mudanças
aconteceram e que o Pedro devolveu os números.

**Acceptance Scenarios**:

1. **Given** a prévia fechada do Vitor, **When** o Wallas responde "sim", **Then** as visitas
   do dia ficam no Gestor como estavam na prévia, cada uma no atendimento combinado, e o
   Pedro responde com o resumo e os números.
2. **Given** a prévia não confirmada, **When** o Wallas manda outra coisa ou some, **Then**
   nada é gravado no Gestor.
3. **Given** que uma das mudanças falha ao gravar, **When** o Pedro responde, **Then** ele
   diz quais foram gravadas e qual falhou, com o motivo, sem repetir as que já entraram.
4. **Given** a máquina que grava no Gestor fora do ar, **When** a prévia é confirmada,
   **Then** o Pedro avisa que a gravação ficou pendente e grava quando a máquina voltar,
   sem duplicar.
5. **Given** a mesma prévia confirmada duas vezes (o "sim" repetido), **When** o Pedro
   processa, **Then** cada mudança acontece uma vez só.

---

### User Story 4 - Corrigir uma visita já lançada (Priority: P3)

Quem está na lista de acesso interno pede para acertar uma visita que já está no Gestor:
trocar o horário, o técnico ou o atendimento, ou cancelá-la ("a visita da Revizza não foi
às 08:00, foi das 10:00 às 11:00"). O Pedro mostra como a visita está e como ficará, e só
muda depois do "sim".

**Why this priority**: cobre o caso pontual sem passar pelo relato do dia inteiro; é a
mesma capacidade da story 3 aplicada a uma visita só.

**Independent Test**: pedir a troca de horário de uma visita existente e conferir no Gestor
que só aquela visita mudou.

**Acceptance Scenarios**:

1. **Given** a visita do nº 824 das 08:00 às 10:00, **When** o Wallas pede para passá-la para
   as 10:00 às 11:00 e confirma, **Then** a visita passa a ter esse horário e o Pedro
   confirma a mudança.
2. **Given** um pedido de cancelamento de visita, **When** o Pedro monta a prévia, **Then** ele
   pede o motivo do cancelamento antes de confirmar.

---

### Edge Cases

- O relato fala de um técnico que não está na equipe do Gestor: o Pedro diz que não achou e
  pergunta quem é.
- O relato atravessa a meia-noite ou cita outro dia ("ontem"): o Pedro converte para a data
  e a mostra na prévia.
- Duas paradas do mesmo técnico se sobrepõem no próprio relato: o Pedro aponta e pergunta.
- Uma parada em que o técnico só buscou um equipamento e levou para outro lugar (o
  computador do Mundo Animal testado no Centro Jurídico): a prévia deixa claro a qual
  atendimento cada trabalho pertence e pergunta quando não estiver claro.
- O relato traz um resultado técnico ("placa condenada", "PC condenado"): o resultado vai
  junto da visita, como registro do que foi feito.
- "Sem horário de almoço" e outros dados de jornada: ficam fora desta feature (são do ponto
  do RH); o Pedro não os lança na agenda e diz isso se perguntarem.
- A visita citada já está finalizada no Gestor: o Pedro mostra isso e pergunta antes de
  alterá-la.
- Pedido feito por número fora da lista de acesso interno: nada da agenda é mostrado nem
  alterado.

## Requirements

### Functional Requirements

- **FR-001**: O Pedro MUST atender pedidos de agenda de técnico somente para números da lista
  de acesso interno; para os demais números, nenhuma informação da agenda é mostrada ou
  alterada.
- **FR-002**: O Pedro MUST mostrar, a pedido, as visitas de um técnico num dia, em ordem de
  horário, com início, fim, cliente ou pré-cliente (nunca "sem cliente" quando houver um dos
  dois), número do atendimento, serviço e situação.
- **FR-003**: O Pedro MUST reconhecer o técnico pelo nome com grafia aproximada e perguntar
  quando houver mais de um candidato.
- **FR-004**: O Pedro MUST quebrar um relato livre (texto ou áudio transcrito) em paradas com
  técnico, cliente ou local, horário de início, horário de fim, serviço feito e resultado.
- **FR-005**: Para cada parada, o Pedro MUST procurar e mostrar o que já existe no Gestor: as
  visitas do técnico no dia, os atendimentos abertos e os orçamentos do cliente.
- **FR-006**: O Pedro MUST montar uma prévia do dia dizendo, para cada parada, o que propõe:
  vincular a uma visita existente, corrigir horário de visita existente, criar visita num
  atendimento aberto, abrir atendimento novo ou criar pré-cliente novo.
- **FR-007**: O Pedro MUST apontar conflitos (o mesmo técnico em dois lugares ao mesmo tempo;
  visita agendada no dia que o relato não menciona) e não resolvê-los sozinho.
- **FR-008**: O Pedro MUST apontar lacunas (horário de fim que falta, cliente não encontrado,
  atendimento não identificado) e insistir na pergunta até fechar; nenhuma parada vai à
  gravação sem técnico, cliente ou pré-cliente, início e fim.
- **FR-009**: Quando o fim de uma parada não for dito e houver parada seguinte, o Pedro MAY
  propor como fim o início da seguinte, sempre mostrando a proposta na prévia para
  confirmação.
- **FR-010**: O Pedro MUST gravar no Gestor somente depois do "sim" explícito de quem pediu, e
  somente o que estava na última prévia mostrada.
- **FR-011**: A gravação MUST passar pelo mesmo caminho que hoje grava os atendimentos do Pedro
  no Gestor, e MUST ser idempotente: a mesma prévia confirmada mais de uma vez muda o Gestor
  uma vez só.
- **FR-012**: Depois de gravar, o Pedro MUST devolver a quem pediu o resultado de cada parada:
  números dos atendimentos, o que foi criado ou corrigido, e o que falhou com o motivo.
- **FR-013**: O Pedro MUST permitir corrigir uma visita existente (horário, técnico,
  atendimento) ou cancelá-la, mostrando o antes e o depois e pedindo o motivo do
  cancelamento.
- **FR-014**: Cada mudança gravada pelo Pedro na agenda MUST registrar quem pediu, pelo nome da
  lista de acesso interno, como já acontece com os atendimentos que ele abre.
- **FR-015**: As visitas lançadas a partir de um relato do que já aconteceu MUST entrar como
  **agendadas**, com o horário relatado; quem finaliza é o técnico, pelo app, como hoje
  (decisão do dono em 23/10/2026). A prévia e a resposta final dizem isso.
- **FR-016**: O resultado técnico citado no relato ("placa condenada") MUST ficar registrado na
  visita ou no atendimento correspondente, como o que foi feito.
- **FR-017**: Só quem tem papel **diretor** ou **gestor** na lista de acesso interno MAY pedir
  mudanças na agenda (lançar, corrigir, cancelar); os demais da lista (ex.: suporte) podem
  apenas consultar, e o Pedro diz isso a quem pedir mudança sem poder (decisão do dono em
  23/10/2026).

### Key Entities

- **Técnico**: pessoa da equipe do Gestor que faz as visitas; reconhecido pelo nome.
- **Visita (agenda do técnico)**: uma ida de um técnico a um atendimento num horário, com
  início, fim, situação (agendada, em andamento, finalizada, cancelada) e observações.
  Pertence a um atendimento.
- **Atendimento**: o chamado do cliente ou do pré-cliente no Gestor, com número; pode ter
  várias visitas e pode ter nascido de um orçamento.
- **Orçamento**: proposta ao cliente no Gestor; um atendimento pode estar ligado a ele.
- **Cliente / pré-cliente**: quem recebe o serviço; o pré-cliente vem do funil e pode virar
  cliente.
- **Relato do dia**: a mensagem livre de quem pede, quebrada em paradas.
- **Prévia do dia**: a lista de paradas com o que existe, o que muda, conflitos e lacunas; é o
  que o "sim" confirma.

> Decisões de infraestrutura: a gravação reusa a fila existente de gravação no Gestor do
> Pedro (máquina do dono, só grava com o "sim"); idempotência é requisito (FR-011). Sem
> scheduling novo, sem chave nova.

## Success Criteria

### Measurable Outcomes

- **SC-001**: O relato do dia de um técnico com até 5 paradas vira agenda gravada no Gestor em
  até 5 mensagens de ida e volta, quando todos os horários estão no relato.
- **SC-002**: Zero atendimentos ou pré-clientes duplicados criados a partir de relatos em que
  o atendimento ou o cadastro já existiam (conferido nos 30 primeiros relatos).
- **SC-003**: 100% dos conflitos de horário do mesmo técnico presentes no dia aparecem na
  prévia antes da confirmação.
- **SC-004**: Nenhuma mudança na agenda acontece sem o "sim" de quem pediu (auditável pelo
  registro de quem pediu em cada mudança).
- **SC-005**: Quem pede deixa de abrir o Gestor para lançar o dia dos técnicos na maioria dos
  dias: pelo menos 4 de cada 5 relatos são resolvidos inteiros pelo Pedro.

## Delta Requirements

**Skip**: o corpus canônico `docs/specs/current/` ainda não existe neste repositório; a
capacidade é nova e não altera comportamento ativo documentado nele — Claude (pedido do
Felipe), 2026-10-23
