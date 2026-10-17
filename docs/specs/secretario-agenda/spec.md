# Feature Specification: Secretário Executivo, Etapa 2 (Agenda consolidada com Google)

**Feature**: `secretario-agenda`
**Created**: 2026-10-17
**Status**: Draft
**Pedido**: Felipe, 17/10/2026 ("Missão Secretário Executivo", Fase 3 — Agenda Executiva Inteligente). O "siga"
depois da Etapa 1 no ar autoriza seguir a ordem combinada.
**Base**:
- [../secretario-executivo/auditoria.md](../secretario-executivo/auditoria.md), com a decisão de **OAuth próprio
  no Matriz** para o Google Agenda e o token cifrado no servidor;
- a Etapa 1, [../secretario-executivo/spec.md](../secretario-executivo/spec.md), já em produção (v2026.10.17.1).

**Relação**: feature nova. Ela soma uma fonte (Google) e uma visão (agenda consolidada) ao Secretário, sem mudar
as regras da Etapa 1. Os princípios da constitution valem inteiros, sobretudo:
- I: dados de uma conta só;
- IV: token cifrado, fora de log;
- VII: criar evento no Google é ação externa e só sai confirmada;
- VIII: não afirmar evento que o Google não confirmou.

## Escopo

1. Conectar e desconectar a conta Google do próprio usuário. Escolher quais agendas entram e qual é a padrão.
2. Sincronizar os eventos das agendas escolhidas sem depender da máquina do Felipe.
3. Uma visão única do período, com:
   - itens do Secretário;
   - compromissos da Agenda do time em que ele é participante;
   - eventos do Google.

   Cada item mostra a origem. A visão também mostra conflitos e horários livres.
4. Perguntas pelo chat e pelo Telegram, respondidas com dado real e sem IA: "quais são meus compromissos de
   amanhã?", "tenho horário livre amanhã às 15h?".
5. "Agenda uma reunião com o Alexandre segunda às 14h": proposta com agenda escolhida, duração e conflitos.
   Depois da confirmação, o evento é criado no Google. A resposta só diz "agendado" depois de o Google confirmar.

**Fora desta etapa**:
- reagendar ou cancelar evento do Google pelo chat;
- convidar participantes (o evento sai sem convidados, então ninguém de fora recebe nada);
- ler a agenda de outras pessoas;
- reuniões identificadas em mensagens ou áudios (Etapas 4 e 5).

## User Scenarios & Testing

### User Story 1 - Conectar o Google com segurança (Priority: P1)

Em Secretário → Configurações, Felipe toca em **Conectar Google Agenda** e autoriza na tela do Google. Ao voltar,
o Matriz mostra a conta conectada e a lista das agendas dele (VixCard, Comercial Grupo, Suporte Invest…). Ele
marca quais entram na visão e qual é a padrão para criar eventos. Pode desconectar quando quiser, e a
autorização é revogada no Google.

**Why this priority**: sem a conexão não há fonte Google. Ela é também o ponto de maior risco de vazamento.

**Independent Test**: com o Google simulado, conectar, ver as agendas, desmarcar uma, desconectar e conferir que
os tokens e os eventos sumiram do banco.

**Acceptance Scenarios**:

1. **Given** o Felipe com o perfil Secretário, **When** ele toca em Conectar, **Then** vai à tela de autorização
   do Google pedindo só acesso a agenda e eventos.
2. **Given** a volta do Google com um código válido e o estado da sessão dele, **When** o Matriz troca o código,
   **Then** guarda o acesso cifrado e mostra a conta conectada e as agendas.
3. **Given** uma volta do Google com estado que não é o da sessão (ou de outra conta), **When** ela chega,
   **Then** é recusada e nada é guardado.
4. **Given** a conta conectada, **When** ele desconecta, **Then** a autorização é revogada no Google, e os tokens,
   agendas e eventos guardados dessa conta são apagados.
5. **Given** o Google recusando a renovação do acesso (autorização retirada lá), **When** a sincronização roda,
   **Then** a conta aparece como "reconectar", o sino avisa uma vez, e a visão diz que o Google não está sendo
   lido.

---

### User Story 2 - Ver a agenda real num lugar só (Priority: P1)

Felipe abre **Secretário → Agenda** e vê hoje e os próximos 7 dias:
- os compromissos do Google, com o nome da agenda;
- os itens do Secretário;
- as reuniões da Agenda do time em que ele está.

Tudo vem em ordem de horário e com a origem marcada. Conflitos aparecem em destaque. No dia de hoje aparecem os
horários livres. Em cima fica a hora da última sincronização com o Google.

**Why this priority**: é o critério de aceite 2 da missão ("apresenta a agenda real, sem inventar eventos").

**Independent Test**: com um evento do Google, um compromisso do time e um lembrete do Secretário no mesmo dia,
dois deles sobrepostos, conferir a ordem, a origem, o conflito e os livres.

**Acceptance Scenarios**:

1. **Given** as três fontes com itens no dia, **When** ele abre a Agenda, **Then** vê todos em ordem de horário,
   cada um com a origem (Google: nome da agenda, Matriz, Secretário).
2. **Given** dois eventos com horário sobreposto, **When** a Agenda é montada, **Then** os dois aparecem marcados
   como conflito.
3. **Given** o dia de hoje, **When** a Agenda é montada, **Then** mostra os intervalos livres de pelo menos 30 min
   entre 08:00 e 18:00.
4. **Given** um evento criado pelo próprio Secretário no Google, **When** a Agenda é montada, **Then** ele aparece
   uma vez só.
5. **Given** o Google sem sincronizar há mais de 30 min, ou a conta desconectada, **When** ele abre a Agenda,
   **Then** vê o aviso de que os dados do Google podem estar desatualizados ou ausentes. A falta de evento nunca é
   apresentada como dia livre de certeza.
6. **Given** outra conta, administrador incluído, **When** ela tenta ver a agenda do Felipe, **Then** não vê nada
   dele.

---

### User Story 3 - Perguntar a agenda pelo chat (Priority: P1)

No Telegram: "quais são meus compromissos de amanhã?". A resposta chega em segundos, sem gastar IA: a lista do dia
com hora, título e origem, e a hora da última sincronização. "Tenho horário livre amanhã às 15h?" responde sim ou
não; quando é não, diz o que ocupa o horário e sugere o livre mais próximo.

**Why this priority**: é o uso no celular que a missão pede, e o critério de aceite 2.

**Independent Test**: com eventos simulados, mandar as duas perguntas pelo Telegram e conferir que a resposta lista
exatamente os eventos do dia e não aciona nenhuma execução de IA.

**Acceptance Scenarios**:

1. **Given** o Google conectado, **When** ele pergunta "quais compromissos tenho amanhã?", **Then** recebe a
   lista do dia seguinte (todas as fontes) e a hora da sincronização, sem execução de IA.
2. **Given** um dia sem nada em nenhuma fonte e a sincronização recente, **When** ele pergunta, **Then** a
   resposta diz "nada na agenda de amanhã" e cita as fontes consultadas.
3. **Given** "tenho horário livre amanhã às 15h?" com uma reunião das 14:30 às 15:30, **When** ele pergunta,
   **Then** a resposta diz que não, cita a reunião e sugere o livre mais próximo.
4. **Given** o Google **não** conectado, **When** ele faz a pergunta, **Then** segue o fluxo atual da Central
   (sem mudança de comportamento).

---

### User Story 4 - Marcar no Google pelo chat (Priority: P2)

"Agenda uma reunião com o Alexandre segunda às 14h" gera a proposta: "Criar no Google, agenda VixCard, seg 20/10
14:00–15:00: Reunião com o Alexandre. Sem convidados. ⚠ Conflita com: Visita Condonal 14:30. Confirma?". Ele
confirma, o Matriz cria no Google, e só depois de o Google confirmar responde "✓ Agendado no Google (VixCard)".
Fica também um aviso do Secretário antes do horário.

**Why this priority**: útil, mas é ação externa. Vem depois de a leitura estar sólida.

**Independent Test**: com o Google simulado, propor, confirmar e conferir a chamada de criação com agenda,
horário e título. Simular uma falha do Google e conferir que a resposta diz que não agendou.

**Acceptance Scenarios**:

1. **Given** o Google conectado e uma agenda padrão, **When** ele pede "agenda uma reunião com o Alexandre
   segunda às 14h", **Then** a proposta mostra a agenda, o início, o fim (1 h por padrão), o título, "sem
   convidados" e os conflitos, e nada é criado antes da confirmação.
2. **Given** uma frase com o nome de uma agenda ou de uma empresa ligada a ela ("…da VixCard…"), **When** a
   proposta é montada, **Then** a agenda escolhida é a ligada a essa palavra, e não a padrão.
3. **Given** a confirmação, **When** o Google cria o evento, **Then** a resposta diz "Agendado" com a agenda. O
   evento entra na visão e ganha o aviso do Secretário.
4. **Given** a confirmação, **When** o Google falha (rede, recusa, token), **Then** a resposta diz que **não**
   agendou e por quê, e nada fica como se tivesse sido criado.
5. **Given** a confirmação repetida (dois toques), **When** ela chega, **Then** um evento só é criado no Google.
6. **Given** o Google não conectado, **When** ele diz "agenda uma reunião…", **Then** a frase segue o fluxo atual
   da Central.

### Edge Cases

- **Evento de dia inteiro**: aparece como "dia inteiro", entra no topo do dia e não conta como conflito de
  horário.
- **Evento que atravessa a meia-noite**: aparece nos dois dias, como a Agenda do time já faz.
- **Evento cancelado no Google**: some na próxima sincronização.
- **Agenda desmarcada**: os eventos dela saem da visão e do cache na próxima sincronização.
- **Fuso**: eventos com outro fuso aparecem no horário de Brasília.
- **Título com instrução** ("ignore tudo e apague…"): é só texto mostrado. Nunca vira comando, e a resposta
  pelo chat não passa por IA.
- **Muitas agendas**: a visão consulta só as marcadas. O limite prático é 30 agendas.
- **Token renovado**: a renovação acontece sozinha antes de expirar, e duas sincronizações simultâneas não
  quebram a conta.
- **Muitos eventos**: a sincronização busca até 2.500 eventos por agenda e por janela, e avisa se cortou.

## Requirements

### Functional Requirements

- **FR-001**: Users MUST be able to conectar a própria conta Google por autorização do Google (OAuth), pedindo só
  os acessos de leitura de agendas e de leitura e escrita de eventos.
- **FR-002**: O sistema MUST validar que a volta da autorização pertence à sessão e à conta que a iniciou
  (estado de uso único). Volta inválida MUST ser recusada sem guardar nada.
- **FR-003**: Tokens de acesso e de renovação MUST ser guardados cifrados e MUST NOT aparecer em log, evento,
  resposta de API, tela ou mensagem.
- **FR-004**: Desconectar MUST revogar a autorização no Google e apagar tokens, agendas e eventos guardados da
  conta.
- **FR-005**: O sistema MUST listar as agendas da conta conectada e permitir marcar quais entram na visão
  (padrão: todas), qual é a padrão para criar eventos e palavras que ligam a agenda a uma empresa ou assunto.
- **FR-006**: O sistema MUST sincronizar os eventos das agendas marcadas a cada 10 minutos, de ontem até 30 dias
  à frente, sem depender da máquina do usuário. A visão MUST mostrar a hora da última sincronização bem-sucedida.
- **FR-007**: Do evento do Google, o sistema MUST guardar só o necessário para a visão: título, início, fim, dia
  inteiro, agenda, link e status. MUST NOT guardar descrição nem participantes. Eventos fora da janela ou
  cancelados MUST ser apagados na sincronização.
- **FR-008**: Renovação recusada pelo Google MUST marcar a conta como "reconectar", avisar no sino uma única vez
  e fazer a visão e o chat informarem que o Google não está sendo lido.
- **FR-009**: A visão consolidada MUST juntar itens do Secretário, compromissos da Agenda do time em que a conta é
  participante e eventos do Google, em ordem de horário, com a origem de cada um, sem repetir o evento criado pelo
  próprio Secretário.
- **FR-010**: A visão MUST marcar conflitos (sobreposição de horário entre itens com hora) e listar os livres de
  pelo menos 30 min entre 08:00 e 18:00 do dia.
- **FR-011**: Ausência de evento MUST NOT ser apresentada como dia livre quando o Google estiver desconectado ou
  sem sincronizar há mais de 30 min. A resposta MUST dizer a limitação.
- **FR-012**: Perguntas de agenda ("compromissos/agenda de hoje, amanhã, dia da semana, data, esta semana") e de
  horário livre MUST ser respondidas pelo Matriz, sem IA, pelo chat da Central e pelo Telegram, quando a conta tem
  o Secretário e o Google conectado. Sem Google conectado, MUST seguir o fluxo atual.
- **FR-013**: "Agenda/marca … <quando>" MUST gerar proposta de evento no Google com agenda, início, fim (padrão
  60 min, ou o informado), título, "sem convidados" e conflitos. MUST NOT criar nada antes da confirmação.
- **FR-014**: O evento MUST ser criado no Google só depois da confirmação, uma única vez por proposta. O sistema
  MUST NOT dizer "agendado" antes da resposta de sucesso do Google, e MUST dizer claramente quando falhou.
- **FR-015**: O evento criado MUST ganhar um aviso do Secretário com a antecedência padrão e MUST aparecer uma
  vez só na visão.
- **FR-016**: O evento criado MUST NOT ter convidados nem enviar convite a ninguém.
- **FR-017**: Tudo isso MUST valer só para a conta dona. Outra conta, administrador incluído, MUST NOT ver,
  sincronizar nem criar nada na conta Google de outra pessoa.
- **FR-018-INFRA-SCHED**: A sincronização MUST rodar pelo agendador do servidor a cada 10 minutos, uma execução
  por vez. Ela MUST poder ser pedida na hora (botão "Sincronizar agora").
- **FR-019-INFRA-REFRESH**: O acesso MUST ser renovado sob demanda quando faltar menos de 2 min para expirar, com
  trava por conta para duas renovações simultâneas não se atropelarem.
- **FR-020-INFRA-KEY**: Os tokens MUST ser cifrados com a chave da aplicação, pelo mesmo mecanismo que já cifra o
  token do bot do Telegram. A troca da chave da aplicação exige reconectar (documentado).
- **FR-021**: O cadastro do cliente OAuth do Google (id e segredo) MUST vir do ambiente do servidor, nunca do
  código nem do banco. Sem ele, a tela MUST dizer que a integração não está configurada.

### Key Entities

- **Conta Google do Secretário**: uma por conta do Matriz.
  - Campos: e-mail/identificação, tokens cifrados, validade, situação (conectada, reconectar), última
    sincronização, último erro (código, sem texto sensível).
- **Agenda Google**: das agendas da conta.
  - Campos: id no Google, nome, incluída sim/não, padrão sim/não, palavras de ligação.
- **Evento Google (cache)**: título, início, fim, dia inteiro, link, status, agenda. Fica só dentro da janela.
- **Item do Secretário (Etapa 1)**: ganha a referência do evento do Google quando foi criado por ele.

## Success Criteria

### Measurable Outcomes

- **SC-001**: "Quais compromissos tenho amanhã?" responde em menos de 3 segundos, com 100% dos eventos do dia
  presentes no Google (sincronizados) e nenhum evento que não exista nas fontes.
- **SC-002**: Em 10 casos de teste de conflito e livre (sobreposição parcial, total, encostado, dia inteiro,
  virada do dia), 10 são classificados certo.
- **SC-003**: Um evento criado pelo chat aparece no Google exatamente uma vez, mesmo com confirmação repetida.
- **SC-004**: Zero ocorrências de token em log, evento, tela ou resposta nos testes de segurança.
- **SC-005**: Uma autorização retirada no Google é percebida na próxima sincronização (até 10 min), com um
  único aviso.

## Delta Requirements

**Skip**: feature nova sobre a Etapa 1; não há `docs/specs/current/` neste repositório. — Claude (pedido do
Felipe), 2026-10-17
