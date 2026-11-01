# Feature Specification: Comunicação do Pedro

**Feature**: `pedro-comunicacao`
**Created**: 31/10/2026
**Status**: Primeira parte publicada na v2026.10.31.5. Segunda parte (FR-016 a FR-023) aprovada pelo Felipe em
31/10/2026, 20:19, com os seis pontos dele, e liberada para implementar às 20:34 ("Sim, tudo"); sem publicação.

O Pedro é o assistente do suporte no WhatsApp (`docs/specs/suporte-pedro-whatsapp`). Ele já consulta o Gestor e
o Matriz e responde a clientes e a quem é da casa. Esta spec trata de **como ele responde**: o que diz, quanto diz
e com que dado. Não cria capacidade de consulta nova; melhora os dados que ele já recebe, as regras que ele já
segue e a entrega que já existe.

## Contexto e evidência

As quatro respostas da IA ao número do Felipe (final 5397, perfil diretor) em 31/10/2026, lidas no Gestor de
produção só para leitura, com os valores mascarados, mostraram:

- **Nome e hora em toda resposta:** as 4 começam pelo nome dele e as 4 trazem "lido às HH:MM". O pedido à IA
  manda dizer a hora da leitura "quando ela importar" e tratar pelo primeiro nome.
- **Oferta do que não existe (16:53):** "Quer ver as outras 19?" das cobranças vencidas, quando os dados traziam
  só os 10 itens mais antigos — ele teria mais 5 para mostrar.
- **Contas a pagar sem valor (18:05):** "16 contas a pagar vencidas ou de hoje", só a quantidade, com vencidas e
  de hoje juntas: é tudo o que a leitura do Gestor traz.
- **"Fora do horário" ao diretor (17:19):** a máquina estava parada das 16:54 às 18:03. Resolvido no pacote do
  horário do cliente (`suporte-pedro-whatsapp`, Clarificações de 31/10/2026): quem é da casa ouve que o Pedro está
  sem as consultas.

Hoje a resposta da casa é cortada em 3.500 caracteres e a do cliente em 1.500, com "…", numa mensagem só; a lista
longa vem com 5 itens e uma pergunta; a agenda só entende "ontem", "hoje", "amanhã" e datas dd/mm.

**Fora do escopo:** histórico e andamentos de OS, técnico e POPs (`pedro-inteligencia-operacional`, decisões
FR-011, FR-012 e FR-016 pendentes) — até lá o Pedro não oferece "ver os andamentos". Preço para cliente: proibido
(regra comercial definitiva, 31/10/2026).

## User Scenarios & Testing

### User Story 1 - Resposta direta, sem enfeite nem oferta vazia (Priority: P1)

Quem é da casa pergunta e recebe a resposta primeiro, sem o nome dele em toda mensagem, sem a hora da leitura
quando o dado é recente, sem palavra de bastidor, e sem ofertas de algo que o Pedro não tem para mostrar. O cliente
recebe o mesmo cuidado no tom dele.

**Why this priority**: É o que o Felipe viu em todas as respostas de 31/10. Afeta toda conversa, de todo perfil.

**Independent Test**: Com dados fictícios, pedir ao Pedro "quantas cobranças vencidas?" duas vezes seguidas, com o
retrato recente: a segunda resposta não traz o nome, nenhuma traz a hora, e nenhuma oferece mais itens do que os
dados têm.

**Acceptance Scenarios**:

1. **Given** uma conversa da casa já em andamento, **When** ele faz a segunda pergunta, **Then** a resposta não
   começa pelo nome dele.
2. **Given** dados lidos há 10 minutos, **When** ele pergunta pelos atendimentos, **Then** a resposta não diz a
   hora da leitura.
3. **Given** dados lidos há mais tempo do que o limite de atualização, **When** ele pergunta, **Then** a resposta
   avisa que o dado pode estar desatualizado e diz quando foi lido.
4. **Given** 24 cobranças vencidas e só 10 na lista dos dados, **When** o Pedro mostra 5, **Then** ele oferece só
   as outras 5 que tem e diz que a lista completa está no Gestor — nunca "as outras 19".
5. **Given** uma visita de atendimento sem cliente cadastrado, **When** o Pedro lista a agenda, **Then** a linha
   não traz termo de bastidor como "(sem cadastro)".
6. **Given** um perfil diretor e um perfil suporte, **When** os dois perguntam algo, **Then** cada um recebe a
   resposta no jeito do perfil (o diretor, números e o que pede decisão; o suporte, o atendimento e o próximo
   passo).

---

### User Story 2 - Financeiro com valor (Priority: P1)

Quem é da casa com acesso ao financeiro pergunta das contas a pagar e recebe vencidas e de hoje separadas, cada
uma com quantidade e valor total, e a lista com fornecedor, vencimento e valor. As cobranças de hoje vêm com o
total, e há como ver as maiores por valor.

**Why this priority**: "16 contas a pagar vencidas ou de hoje" sem valor não serve para decidir o que pagar.

**Independent Test**: Com um retrato fictício de 3 contas vencidas e 2 de hoje, perguntar "contas a pagar": a
resposta separa 3 vencidas (com o total) de 2 de hoje (com o total).

**Acceptance Scenarios**:

1. **Given** contas a pagar vencidas e de hoje no Gestor, **When** o retrato é lido, **Then** ele traz, para cada
   grupo, a quantidade e o valor total, e uma lista com até 10 itens (fornecedor ou descrição, vencimento, valor).
2. **Given** cobranças que vencem hoje, **When** o retrato é lido, **Then** ele traz o valor total delas.
3. **Given** cobranças e contas a pagar vencidas, **When** alguém pede "as maiores", **Then** o Pedro mostra até
   10 de cada, da maior para a menor.
4. **Given** a leitura nova chegando a um Matriz ainda na versão anterior, **When** ele recebe o retrato, **Then**
   aceita a leitura (perde só o que é novo) — e a leitura antiga também é aceita pelo Matriz novo.
5. **Given** o resumo do dia no Telegram, **When** ele é montado depois da mudança, **Then** as contas a pagar e
   as cobranças de hoje aparecem com o valor.
6. **Given** um perfil "suporte" ou um cliente, **When** pergunta de contas ou cobranças, **Then** não recebe
   nada disso (como hoje).

---

### User Story 3 - Resposta longa inteira, em partes (Priority: P2)

Quem é da casa pede "a lista completa" e recebe tudo o que os dados têm, em várias mensagens se for preciso, sem
corte no meio de uma linha.

**Why this priority**: Hoje a resposta longa some depois de 3.500 caracteres com "…", e a lista longa para em 5.

**Independent Test**: Uma resposta fictícia de 6.000 caracteres em parágrafos sai em 2 mensagens, cada uma com
parágrafos inteiros.

**Acceptance Scenarios**:

1. **Given** uma resposta da casa maior que uma mensagem, **When** ela é enviada, **Then** sai em partes, cada uma
   abaixo do limite do WhatsApp, cortada entre parágrafos (ou entre linhas, se um parágrafo sozinho passar).
2. **Given** um pedido de "todos" ou "lista completa", **When** o Pedro responde, **Then** lista tudo o que os
   dados trazem, sem parar em 5.
3. **Given** uma resposta maior que o teto de partes, **When** ela é enviada, **Then** as partes até o teto saem
   inteiras e a última avisa que o resto está no Gestor.
4. **Given** uma resposta ao cliente, **When** ela é enviada, **Then** continua numa mensagem só e curta, como
   hoje.

---

### User Story 4 - Agenda por dia da semana (Priority: P2)

Quem é da casa pergunta "a agenda de quarta" ou "da semana que vem" e recebe as visitas dos dias certos.

**Why this priority**: "Quarta" e "semana que vem" são o jeito comum de perguntar; hoje só datas e
ontem/hoje/amanhã funcionam.

**Independent Test**: Numa segunda-feira, "agenda de quarta" traz as visitas de quarta desta semana.

**Acceptance Scenarios**:

1. **Given** hoje é segunda, **When** ele pede "quarta", **Then** vêm as visitas da quarta desta semana.
2. **Given** hoje é quarta, **When** ele pede "quarta", **Then** vêm as de hoje; **When** pede "próxima quarta",
   **Then** vêm as da semana seguinte.
3. **Given** qualquer dia, **When** ele pede "semana que vem" ou "próxima semana", **Then** vêm os dias úteis da
   semana seguinte; **When** pede "esta semana", **Then** os dias úteis desta semana.
4. **Given** um dia fora do período do retrato, **When** ele pede, **Then** o Pedro diz que não consegue consultar
   aquele dia (como hoje).

---

### User Story 5 - Aprovação pelas respostas, não pela descrição (Priority: P3)

O Felipe aprova a mudança lendo o que o Pedro responde em 20 situações, antes e depois, com a IA de verdade e
dados fictícios.

**Why this priority**: Os testes de hoje conferem o texto do pedido à IA, não o que ela responde.

**Independent Test**: A bateria roda nesta máquina e gera um relatório com as 20 perguntas, a resposta de antes e
a de depois.

**Acceptance Scenarios**:

1. **Given** a bateria, **When** ela roda, **Then** cada uma das 20 situações gera a resposta da IA real com o
   mesmo pedido que o Pedro receberia, montado sobre dados fictícios.
2. **Given** o relatório, **When** o Felipe o lê, **Then** vê lado a lado antes e depois, por perfil (cliente
   novo, cliente do cadastro, desconhecido, suporte, gestor, diretor).
3. **Given** a bateria, **When** ela roda, **Then** nenhum dado real de cliente, nem conversa real, entra nela.

---

### Edge Cases

- Lista de itens vazia com quantidade maior que zero (leitura antiga ou parcial): o Pedro diz a quantidade e que
  os itens estão no Gestor.
- Item sem valor ou sem data: aparece sem esse campo, sem inventar.
- Dado desatualizado E pergunta da casa: a hora e o aviso vão, mesmo depois da primeira resposta.
- "Quarta" num pedido de agenda que também cita uma data: entram os dois dias, até o teto de dias.
- "Domingo" ou "sábado": entram como qualquer dia; dia sem visita diz que não há visita.
- Resposta com um parágrafo maior que uma mensagem inteira: o corte vai para a quebra de linha; sem quebra, para o
  espaço.
- Uma parte é recusada pelo WhatsApp ou pelo Gestor: as seguintes não saem, e a falha fica registrada como hoje.
- A pessoa escreve de novo enquanto as partes saem: a resposta segue; a mensagem nova vira o pedido seguinte.
- Cliente pergunta preço: nunca recebe valor (regra de `suporte-pedro-whatsapp`), e nenhum valor financeiro desta
  spec chega ao pedido do cliente.

## Requirements

### Functional Requirements

- **FR-001**: Os três pedidos à IA (identificação, cliente, casa) MUST seguir uma regra de comunicação comum: responder
  primeiro ao que foi perguntado; o nome da pessoa só na saudação da primeira resposta do atendimento; sem termos
  de bastidor; oferecer só o que existe nos dados.
- **FR-002**: A hora da leitura de um dado MUST aparecer na resposta só quando o dado estiver desatualizado; o dado
  recente é dito sem hora. O retrato do Gestor (atendimentos e agenda) é desatualizado a partir de 30 minutos; o
  financeiro, a partir de 3 horas (o limite que já existe).
- **FR-003**: Termos de bastidor MUST ficar fora da resposta: "retrato", "dados", "bloco", "prompt", "leitura",
  "sistema interno", "(sem cadastro)" e equivalentes. A linha da visita na agenda MUST não trazer "(sem
  cadastro)".
- **FR-004**: Quando os dados trazem só parte de uma lista (a quantidade é maior que os itens), o Pedro MUST oferecer
  só os itens que tem e dizer que a lista completa está no Gestor.
- **FR-005**: O Pedro MUST responder no jeito do perfil: diretor — números, o que pede decisão e o que mudou; gestor —
  o operacional do dia; suporte — o atendimento e o próximo passo; cliente — curto, cordial, sem markdown.
- **FR-006**: Quando a pessoa da casa pedir a lista completa ("todos", "lista completa", "manda tudo"), o Pedro MUST
  listar todos os itens que os dados trazem; sem esse pedido, mostra os principais e oferece o resto.
- **FR-007**: A leitura financeira MUST trazer as contas a pagar vencidas e as de hoje separadas, cada grupo com
  quantidade e valor total, e uma lista com até 10 contas (as mais antigas), cada uma com fornecedor ou descrição,
  vencimento e valor.
- **FR-008**: A leitura financeira MUST trazer o valor total das cobranças que vencem hoje.
- **FR-009**: A leitura financeira MUST trazer as maiores por valor: até 10 cobranças vencidas e até 10 contas a
  pagar vencidas, da maior para a menor.
- **FR-010**: A leitura nova e a antiga MUST ser aceitas pelas duas versões do Matriz: nenhuma ordem de publicação
  entre o Matriz e a máquina pode fazer o Matriz recusar a leitura inteira.
- **FR-011**: O resumo do dia (Telegram) e as outras telas que mostram a leitura financeira MUST passar a mostrar o
  valor das contas a pagar e das cobranças de hoje, sem repetir as listas de maiores.
- **FR-012**: A resposta à casa maior que uma mensagem MUST sair em partes, cada uma abaixo de 4.096 caracteres,
  cortadas entre parágrafos (senão entre linhas, senão entre palavras), na ordem, até 3 partes; o que passar disso
  é substituído por um aviso de que o resto está no Gestor. A resposta ao cliente continua numa mensagem só.
- **FR-013**: A agenda dos técnicos MUST reconhecer o dia da semana citado ("segunda" a "domingo", com ou sem
  "-feira"), "próxima <dia>", "esta semana" e "semana que vem"/"próxima semana", somando esses dias aos que já
  entram (ontem, hoje, amanhã e datas), com teto de 7 dias por pedido.
- **FR-014**: Nenhuma mudança desta spec MUST expor a um perfil ou nível de identificação dado que ele não via antes
  (`Identificacao`, `AcessoInterno`); o cliente nunca recebe valor financeiro.
- **FR-015**: Uma bateria de 20 situações MUST rodar o pedido real do Pedro, montado sobre dados fictícios, na IA
  real desta máquina, antes e depois da mudança, e gerar um relatório lado a lado para aprovação, sem dado real de
  cliente nem conversa real.

> Decisões de infraestrutura: a leitura financeira continua na rotina horária que já existe na máquina; sem
> scheduler, sessão, chave ou token novos.

### Key Entities

- **Leitura financeira do Gestor**: o retrato horário de pendências (orçamentos, cobranças, contas a pagar,
  reuniões, funil), cada grupo com quantidade, valor total e até 10 itens; ganha a separação vencidas/hoje das
  contas a pagar e as listas dos maiores por valor.
- **Resposta do Pedro**: o texto que a IA devolve; para a casa, pode virar várias mensagens.
- **Situação da bateria**: uma pergunta, o perfil de quem pergunta, os dados fictícios e as respostas de antes e
  de depois.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Na bateria, nenhuma resposta a dado recente traz a hora da leitura, e o nome de quem pergunta aparece
  no máximo na primeira resposta de cada conversa.
- **SC-002**: Na bateria, nenhuma resposta oferece mais itens do que os dados trazem.
- **SC-003**: Uma pergunta só ("contas a pagar") traz ao diretor as vencidas e as de hoje separadas, com quantidade e
  valor total de cada.
- **SC-004**: Nenhuma resposta à casa termina cortada com "…" no meio; até 3 mensagens cabem inteiras.
- **SC-005**: "Agenda de <dia da semana>" traz as visitas do dia certo em todas as situações de teste, incluindo
  "próxima" e "semana que vem".
- **SC-006**: Nenhum cenário da bateria mostra valor financeiro a cliente ou a perfil sem acesso.

## Segunda parte (31/10/2026)

### Contexto e evidência

Pedido do Felipe às 20:19, depois de ler a bateria da primeira parte (`storage/app/bateria-pedro`, só dados
fictícios), em seis pontos: não misturar períodos quando a pergunta cita uma data; não trazer pendência de fora do
período sem dizer que é adicional; não prometer SLA nem prazo sem confirmação no Gestor ou no contrato; respostas
comerciais mais curtas; nenhum preço ao cliente; não oferecer o que o Pedro não faz. Na bateria:

- **Período:** a agenda da semana que vem terminou nos três atendimentos sem data (cenário 9), e as cobranças de
  hoje, nas 24 vencidas desde setembro (cenário 7), sem dizer que eram de fora.
- **Prazo:** "a equipe técnica tem até 3 horas para atender" ao cliente (cenários 19 e 20). Não foi a IA: é a
  mensagem padrão do roteiro "Portão parado" (migração `2026_10_20_150000`, `prazo_horas = 3`), e o pedido
  mandava ao cliente o "prazo de até 3 h para atender" do roteiro. Não há SLA de cliente no Gestor nem no
  contrato que o Pedro recebe.
- **Comercial:** a resposta ao cliente novo juntou empresa, recusa de preço, telefone, site e horário (cenário
  16).
- **Preço:** nenhum cenário deu preço, mas nada conferia a resposta antes de sair.
- **Achado extra:** ao perfil suporte, "não há visita agendada" e "próximo passo: esperar a peça chegar" para o
  1203 (cenário 13), que tinha visita em 09/11 — fora dos dias da agenda do pedido — e nenhum passo registrado.

### User Story 6 - Só o período perguntado (Priority: P1)

Quem é da casa pergunta por um dia ou período e recebe só o que é dele; o que for de fora e importar vem numa linha
à parte, dita como informação adicional.

**Independent Test**: "o que tem na agenda de amanhã?" num dia sem visita amanhã: a resposta diz que não há visita e
não lista as de quarta.

**Acceptance Scenarios**:

1. **Given** uma pergunta com dia ou período, **When** o Pedro responde, **Then** só os itens daquele período
   entram no corpo da resposta.
2. **Given** algo de fora que pede atenção, **When** o Pedro o menciona, **Then** é numa linha no fim, que começa
   por "Fora desse período:".
3. **Given** um pedido de agenda de um dia, **When** o pedido à IA é montado, **Then** os dias vizinhos que entram
   para remarcar visitas vão marcados "NÃO PEDIDO".

### User Story 7 - O cliente sem prazo, sem preço e sem texto demais (Priority: P1)

O cliente pergunta "em quanto tempo vocês vêm?" ou "quanto custa?" e não ouve prazo nem valor; quem quer conhecer
um serviço recebe poucas frases e uma pergunta sobre a necessidade.

**Independent Test**: um cliente do cadastro com o portão parado pergunta o prazo: a resposta diz que vai com
prioridade e que a equipe confirma o horário, sem horas nem dias.

**Acceptance Scenarios**:

1. **Given** o roteiro "Portão parado" com prazo de 3 h, **When** o pedido do cliente é montado, **Then** o prazo
   não vai, e a mensagem padrão não promete horas.
2. **Given** uma resposta da IA com valor em dinheiro para quem não é da casa, **When** ela é entregue, **Then** a
   frase com o valor sai, e no lugar vai que valores são passados pelo comercial, na proposta.
3. **Given** uma resposta com valor para quem é da casa (financeiro), **When** ela é entregue, **Then** sai como
   veio.
4. **Given** um cliente que quer conhecer um serviço, **When** o Pedro responde, **Then** são até 3 frases e uma
   pergunta, sem telefone, site nem horário, a não ser que ele peça o contato ou seja encaminhado.

### User Story 8 - Só o que ele faz, só o que está escrito (Priority: P2)

O Pedro só oferece o que consegue fazer para aquele perfil, e não deduz causa, próximo passo ou ausência.

**Independent Test**: o perfil suporte pergunta por um atendimento com visita marcada daqui a uma semana: a resposta
traz a data da visita e nenhum "próximo passo" inventado.

**Acceptance Scenarios**:

1. **Given** um perfil, **When** o pedido da casa é montado, **Then** traz o que o Pedro faz por ele e o que não faz,
   montado no código a partir do perfil.
2. **Given** um atendimento com visita marcada nos próximos 14 dias, **When** ele aparece na lista, **Then** a linha
   traz a data, a hora e o técnico da próxima visita (cancelada e passada não contam).

### Functional Requirements (segunda parte)

- **FR-016**: Quando a pergunta da casa cita um dia ou um período, a resposta MUST trazer só o que é dele. Na agenda,
  os dias que entram só para conferência (vizinhos, para remarcar) MUST ir marcados "NÃO PEDIDO".
- **FR-017**: O que for de fora do período e pedir atenção MUST vir, no máximo, numa linha no fim, que começa por
  "Fora desse período:".
- **FR-018**: O cliente MUST não ouvir prazo de atendimento, de visita ou de solução, nem SLA, sem confirmação no
  Gestor ou no contrato: o prazo do roteiro não vai ao pedido do cliente (continua para a equipe, na cobrança dos
  críticos), e a mensagem padrão do "Portão parado" troca a promessa de 3 h por "já vai para a equipe técnica" —
  só se ainda for o texto original.
- **FR-019**: O pedido da casa MUST trazer, montado no código a partir do perfil, o que o Pedro faz e o que não faz;
  o pedido do cliente, a lista curta do que ele faz. O Pedro só oferece o que está no "faz".
- **FR-020**: A resposta a quem não é da casa MUST sair sem valor em dinheiro: na entrega, a frase com o valor sai e
  no lugar vai o aviso de que valores são passados pelo comercial; o pedido fica anotado. Sem exceção (o cliente
  não tem valor a ouvir nesta conversa, nem o do boleto).
- **FR-021**: Causa, próximo passo, previsão e a falta de algo MUST vir só quando os dados disserem. A linha de cada
  atendimento MUST trazer a próxima visita marcada no Gestor (até 14 dias à frente), para a falta dela ser um fato.
- **FR-022**: No assunto comercial, o Pedro MUST responder em até 3 frases, com uma pergunta sobre a necessidade;
  telefone, site e horário só quando a pessoa pedir o contato ou quando ele encaminhar.
- **FR-023**: A bateria MUST ganhar os cenários de período, prazo, comercial e preço no showroom (21 a 26) e as
  checagens por situação, e a segunda parte é aprovada pela comparação r2-antes × r2-depois, com a IA real e dados
  fictícios.

### Success Criteria (segunda parte)

- **SC-007**: Na bateria, nenhuma resposta da casa a um período mistura item de fora sem a linha "Fora desse
  período:".
- **SC-008**: Na bateria, nenhuma resposta a cliente promete prazo em horas, minutos ou dias, nem traz valor em
  dinheiro.
- **SC-009**: A resposta comercial ao cliente novo tem no máximo 3 frases além da apresentação (4 quando também
  recusa o preço).
- **SC-010**: Nenhuma resposta da casa oferece algo da lista "não faz", nem afirma visita ou passo que os dados não
  trazem.

## Delta Requirements

**Skip**: o corpus `docs/specs/current/` não existe neste projeto; o comportamento ativo do Pedro está em
`docs/specs/suporte-pedro-whatsapp` — Claude, 2026-10-31
