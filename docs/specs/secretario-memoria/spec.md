# Feature Specification: Secretário, Etapa 6 (Memória Executiva)

**Feature**: `secretario-memoria`
**Created**: 2026-10-17
**Status**: Draft
**Pedido**: Felipe, 17/10/2026 ("Missão", Fase 7: Memória Executiva; critério de aceite 5). "Siga" depois da
Etapa 5. A publicação só acontece depois do teste local e da liberação dele.

**Relação**: feature nova sobre as Etapas 1 a 5. Ela **consulta** o que elas já guardam e não guarda nada novo:
- reuniões, com transcrição, decisões, tarefas e evidências;
- itens do Secretário;
- metadados de e-mail;
- conversas da Central;
- o retrato do Gestor.

## Escopo

1. **Perguntas sobre o histórico**, pelo chat, pelo Telegram e por uma tela de busca:
   - "O que ficou decidido na reunião de ontem?" / "…na última reunião de diretoria?"
   - "O que combinamos com o cliente RFID?"
   - "Quais reuniões tive na semana passada?"
   - "Quais compromissos assumi?"
   - "O que ficou pendente com o Alexandre?"
   - "Quais propostas preciso acompanhar?"
   - "O que eu sei sobre X?" (busca geral)
2. **Toda resposta com fonte, data, cliente ou projeto quando houver, e link para o registro original.** A
   resposta separa **fato registrado** (decisão revisada, item que você criou, e-mail recebido) de **extraído
   pela IA** (análise de reunião ainda não revisada) e de **inferência** (nomes citados na fala).
3. **Recuperação, não "tudo no prompt"**: a busca acha os registros relevantes nas fontes e mostra os melhores. A IA
   só entra se você pedir uma síntese ("resuma o histórico com X"), recebendo só os trechos encontrados.
4. **Isolamento total**: só o histórico da própria conta.

**Fora desta etapa**: busca semântica por vetores (a busca por palavras atende o volume de uma pessoa; reavaliar se
não bastar) e memória sobre dados de outras pessoas.

## User Scenarios & Testing

### User Story 1 - O que ficou decidido (Priority: P1)

"O que ficou decidido na reunião de ontem?" responde:

```
🧠 Reunião “Demonstração RFID” — ontem (sex, 16/10), comercial
Decisões:
• Fazer demonstração na quinta-feira — evidência [3] 00:13 “Ficou decidido…”
Tarefas: 3 (2 aprovadas, 1 pendente de revisão)
Abrir: <link>
```

Sem reunião ontem, a resposta diz isso e cita a mais recente.

**Why this priority**: é o critério de aceite 5 da missão.

**Independent Test**: com duas reuniões analisadas, uma ontem e uma há 5 dias, perguntar e conferir que vem a de
ontem, com as decisões, o trecho de evidência e o link.

**Acceptance Scenarios**:

1. **Given** uma reunião analisada ontem, **When** ele pergunta "o que ficou decidido na reunião de ontem?",
   **Then** recebe título, data, tipo, as decisões com o trecho e o horário da evidência, a contagem de tarefas e o
   link.
2. **Given** "na última reunião de diretoria", **When** pergunta, **Then** vem a reunião de tipo diretoria mais
   recente.
3. **Given** nenhuma reunião no período, **When** pergunta, **Then** a resposta diz "nenhuma reunião registrada
   ontem" e cita a mais recente, sem inventar.
4. **Given** uma reunião ainda não revisada, **When** ela aparece na resposta, **Then** vem marcada "extraído pela
   IA, ainda não revisado".

---

### User Story 2 - O que combinamos com alguém (Priority: P1)

"O que combinamos com o cliente RFID?" ou "o que ficou pendente com o Alexandre?" junta, de todas as fontes, o que
cita o nome:
- decisões e tarefas de reuniões;
- itens do Secretário;
- e-mails do remetente ou com o nome no assunto;
- itens do retrato do Gestor;
- mensagens da Central.

Cada item vem com a fonte, a data e o link. Os pendentes vêm primeiro.

**Why this priority**: é o uso diário de "memória" que a missão descreve.

**Independent Test**: com o nome em uma reunião, um item e um e-mail, perguntar e conferir as três fontes, com
link e data, e nada de outra conta.

**Acceptance Scenarios**:

1. **Given** registros que citam "RFID" em reunião, item e e-mail, **When** ele pergunta "o que combinamos com o
   cliente RFID?", **Then** vê os três, agrupados por fonte, com data e link.
2. **Given** "o que ficou pendente com o Alexandre?", **When** pergunta, **Then** vêm só os abertos: tarefas não
   concluídas, pendências e e-mails aguardando resposta.
3. **Given** nada encontrado, **When** pergunta, **Then** a resposta diz que não encontrou registro com "X" e
   sugere a busca da tela, sem inventar.
4. **Given** outra conta com registros do mesmo nome, **When** ele pergunta, **Then** nada dela aparece.

---

### User Story 3 - Listas do período (Priority: P2)

- "Quais reuniões tive na semana passada?" lista as reuniões do período, com tipo, decisões e tarefas.
- "Quais compromissos assumi?" lista as tarefas de reunião com você como responsável ou classificadas como
  compromisso assumido, mais os itens do Secretário em aberto que vieram de reunião.
- "Quais propostas preciso acompanhar?" junta orçamentos aguardando aprovação do Gestor, e-mails de proposta ou
  orçamento aguardando resposta e itens do Secretário que falam de proposta.

**Independent Test**: com dados nas fontes, conferir cada lista e o período certo (semana passada = de segunda a
domingo anteriores).

**Acceptance Scenarios**:

1. **Given** reuniões em semanas diferentes, **When** ele pergunta "quais reuniões tive na semana passada?",
   **Then** vêm só as de segunda a domingo da semana anterior.
2. **Given** tarefas de reunião com responsável "Felipe" e outras de terceiros, **When** ele pergunta "quais
   compromissos assumi?", **Then** vêm as dele e as classificadas como compromisso.
3. **Given** o retrato do Gestor e e-mails de proposta, **When** ele pergunta "quais propostas preciso
   acompanhar?", **Then** vêm as duas fontes. Com o Gestor sem leitura recente, a limitação aparece.

---

### User Story 4 - Buscar e sintetizar (Priority: P3)

Em Secretário → Memória, uma busca por palavras mostra os resultados por fonte, cada um com link para o original.
"Resuma o histórico com o cliente RFID" manda à IA da pessoa só os trechos encontrados, como dado, e a síntese volta
na conversa.

**Acceptance Scenarios**:

1. **Given** a tela Memória, **When** ele busca "orçamento RFID", **Then** vê os registros que contêm os termos,
   ordenados por relevância e data, com fonte e link.
2. **Given** "resuma o histórico com o cliente RFID", **When** pede, **Then** a IA recebe até 30 trechos encontrados,
   com fonte e data, marcados como dado. Sem nada encontrado, nenhum pedido é feito.

### Edge Cases

- **Nome comum** ("Felipe" aparece em tudo): a resposta corta em 8 por fonte e mostra o total.
- **Acentos e maiúsculas**: "orcamento" acha "Orçamento".
- **Termo vazio** ("o que combinamos?", sem nome): a resposta pergunta "com quem?".
- **Reunião com análise que falhou**: aparece pela transcrição, marcada "sem análise".
- **Texto com instrução** num registro: é só mostrado. A síntese pela IA recebe o texto como dado.
- **Outra conta, administrador incluído**: pela tela, pelo chat ou pelo endereço direto, não vê nada do histórico
  da conta dona (só a própria conta tem acesso).

## Requirements

### Functional Requirements

- **FR-001**: O sistema MUST responder às perguntas da missão sobre o histórico (decidido em reunião, combinado ou
  pendente com alguém, reuniões do período, compromissos assumidos, propostas a acompanhar), sem IA, pelo chat, pelo
  Telegram e pela tela.
- **FR-002**: A busca MUST consultar, só da própria conta:
  - reuniões (título, resumo, contexto, decisões, tarefas, pendências e transcrição);
  - itens do Secretário;
  - metadados de e-mail;
  - mensagens da Central;
  - itens do retrato do Gestor.
- **FR-003**: Cada resultado MUST trazer fonte, data e link para o original. Na reunião, MUST trazer também o
  trecho e o horário da evidência.
- **FR-004**: A resposta MUST distinguir fato registrado (revisado ou criado pela pessoa), extraído pela IA e ainda
  não revisado, e inferência (nomes citados).
- **FR-005**: A busca MUST ignorar acentos e maiúsculas, somar pontos por termo encontrado e preferir registros
  mais recentes. Ela MUST mostrar até 8 por fonte, com o total.
- **FR-006**: Sem resultado ou sem o termo necessário, a resposta MUST dizer isso, perguntando o que falta, e MUST
  NOT inventar.
- **FR-007**: "Semana passada", "ontem", "hoje", "esta semana", "este mês" e "último(a)" MUST ser interpretados no
  fuso America/Sao_Paulo.
- **FR-008**: A síntese pela IA MUST ser pedida só quando a pessoa pedir, MUST mandar só os trechos encontrados (até
  30) com fonte e data, como dado não confiável, e MUST NOT ser pedida sem resultados.
- **FR-009**: Tudo MUST valer só para a conta dona. Outra conta, administrador incluído, MUST NOT ver nada.

## Success Criteria

### Measurable Outcomes

- **SC-001**: "O que ficou decidido na reunião de ontem?" responde em menos de 3 s, com 100% das decisões da reunião
  e as evidências.
- **SC-002**: Em 10 perguntas de teste sobre dados semeados, 10 trazem os registros certos com fonte, data e link,
  e nenhuma traz registro de outra conta.
- **SC-003**: Zero respostas afirmando algo que não está nas fontes. Com nada encontrado, a resposta diz isso.

## Delta Requirements

**Skip**: feature nova sobre as Etapas 1 a 5; não há `docs/specs/current/` neste repositório. — Claude (pedido do
Felipe), 2026-10-17
