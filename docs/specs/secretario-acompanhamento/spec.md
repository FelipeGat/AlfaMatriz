# Feature Specification: Secretário, Etapa 7 (Acompanhamento Proativo)

**Feature**: `secretario-acompanhamento`
**Created**: 2026-10-17
**Status**: Draft
**Pedido**: Felipe, 17/10/2026 ("Missão", Fase 8: Acompanhamento Proativo, 8.1 e 8.2; Fase 10.1, item
"Acompanhamentos"). "Siga" depois da Etapa 6. A publicação só acontece depois do teste local e da liberação dele.

**Relação**: feature nova sobre as Etapas 1 a 6. O lembrete de item no horário (Etapa 1, `Despachante`) continua
como está. Esta etapa olha as **situações** que as fontes já guardam e avisa só o que pede ação, com controle de
excesso:
- itens do Secretário;
- e-mails;
- reuniões e as propostas de revisão;
- a agenda consolidada (Matriz e Google);
- o retrato do Gestor.

## Escopo

1. **Motor de regras, sem IA**: cada regra tem liga/desliga, um parâmetro (horas, dias ou minutos) e uma
   prioridade, configuráveis pela pessoa. A IA não decide quando avisar (Fase 8.1).
2. **As situações da missão**:
   - tarefa vencida e ainda aberta;
   - reunião (evento do Google) começando em N minutos;
   - proposta aguardando retorno (e-mail de proposta ou orçamento sem resposta; orçamentos do Gestor parados);
   - cliente pediu resposta e não há resposta registrada;
   - reunião com tarefas ainda não revisadas;
   - conflito entre compromissos;
   - entrega prometida próxima (prazo de amanhã).
3. **Contra o excesso** (Fase 8.2):
   - agrupamento num resumo só;
   - prioridade (alta sai logo; normal espera o resumo; baixa só na tela e no briefing);
   - controle de frequência (intervalo entre resumos, teto por dia, repetição limitada);
   - horário de silêncio e fim de semana silencioso;
   - preferências por pessoa;
   - registro de cada envio;
   - supressão de duplicatas (a mesma situação é uma linha só e não volta a avisar antes da hora).
4. **Onde aparece**: sino e Telegram (resumo), Secretário → Acompanhamentos (lista, ações e regras), Meu Dia (uma
   linha com o que é alta prioridade), briefing (pendências comerciais além do prazo) e o chat ("o que preciso
   acompanhar?").

**Fora desta etapa**: WhatsApp (Fase 9), regras criadas pela pessoa em linguagem livre e avisos para terceiros.

## User Scenarios & Testing

### User Story 1 - Situação vira acompanhamento, uma vez (Priority: P1)

A cada 5 minutos o Secretário confere as regras ligadas. Cada situação encontrada vira um **acompanhamento** com
uma chave estável (ex.: `tarefa_vencida:item:12`). Ela não duplica enquanto continuar valendo e é resolvida
sozinha quando deixa de valer (a tarefa foi concluída, o e-mail foi respondido).

**Why this priority**: sem isso não há o que avisar nem como evitar duplicata.

**Independent Test**: semear uma tarefa vencida, um e-mail sem resposta há 2 dias e um conflito; rodar o motor
duas vezes; conferir 3 acompanhamentos (não 6). Concluir a tarefa e rodar de novo: ela aparece resolvida.

**Acceptance Scenarios**:

1. **Given** uma tarefa vencida há 3 horas, **When** o motor roda, **Then** surge "Tarefa vencida: …" com o link
   para o Meu Dia e a prioridade da regra.
2. **Given** o mesmo cenário, **When** o motor roda de novo, **Then** continua um acompanhamento só.
3. **Given** a tarefa concluída, **When** o motor roda, **Then** o acompanhamento fica resolvido e sai da lista.
4. **Given** uma regra desligada, **When** o motor roda, **Then** ela não gera nada, e os acompanhamentos ativos
   dela são encerrados.
5. **Given** outra conta com situações iguais, **Then** cada conta só vê e só recebe as suas.

---

### User Story 2 - Avisos úteis, não invasivos (Priority: P1)

Os acompanhamentos devidos saem num **resumo** só, no sino e no Telegram:

```
🔔 Para acompanhar (3)
🔴 Conflito amanhã 10:00: “Visita RFID” × “Call Condonal”
🟡 Cliente sem resposta há 2 dias: Compras RFID — Orçamento da demonstração
🟡 Reunião “Demonstração RFID” com 3 tarefas esperando sua revisão
Ver todos: <link>
```

**Why this priority**: é o pedido explícito da Fase 8.2.

**Independent Test**: com prioridades diferentes, conferir que alta sai na hora, normal espera o intervalo, baixa
não sai, nada sai no silêncio e a repetição respeita o limite.

**Acceptance Scenarios**:

1. **Given** um acompanhamento de prioridade alta, **When** o motor roda fora do silêncio, **Then** o resumo sai
   na hora, com os normais devidos juntos.
2. **Given** só normais e um resumo enviado há 1 hora (intervalo padrão de 3 horas), **When** o motor roda,
   **Then** nada sai; depois das 3 horas, sai um resumo com todos.
3. **Given** o horário de silêncio (padrão 20:00–07:00) ou o fim de semana silencioso, **When** há o que avisar,
   **Then** nada sai, e o resumo sai no fim do silêncio.
4. **Given** o teto diário alcançado (padrão 4 resumos), **When** há o que avisar, **Then** só sai no dia
   seguinte; a tela mostra tudo.
5. **Given** um acompanhamento já avisado, **When** passa menos que o intervalo de repetição (padrão 24 h),
   **Then** ele não entra de novo; depois do limite de repetições (padrão 3), não entra mais em resumo.
6. **Given** prioridade baixa, **Then** nunca sai em resumo, só aparece na tela e no briefing.
7. **Given** o Telegram fora do ar, **When** o envio falha, **Then** tenta de novo até 30 minutos, e o sino não
   duplica.
8. **Given** um resumo enviado, **Then** o envio fica registrado (quando, canal, quantos, quais), sem o texto das
   situações no rastro de eventos.

---

### User Story 3 - Tela de Acompanhamentos e regras (Priority: P2)

Secretário → Acompanhamentos lista os ativos por prioridade, com fonte, desde quando e o link. As ações são:
- **Resolvido**: dispensa a situação até ela deixar de valer;
- **Adiar 1 dia**: some dos resumos até amanhã.

A tela também mostra o histórico de envios dos últimos 7 dias e a configuração das regras: liga/desliga, parâmetro
e prioridade de cada uma, silêncio, fim de semana, intervalo, teto diário, repetição e limite.

**Acceptance Scenarios**:

1. **Given** acompanhamentos ativos, **When** ele abre a tela, **Then** vê alta, normal e baixa, nessa ordem, cada
   um com o link para o original.
2. **Given** "Resolvido", **When** o motor roda e a situação ainda vale, **Then** ela não volta; se deixar de valer
   e voltar depois, é um acompanhamento novo.
3. **Given** "Adiar 1 dia", **Then** não entra em resumo até o dia seguinte no mesmo horário.
4. **Given** um valor inválido nas regras (parâmetro fora da faixa), **When** salva, **Then** é recusado com a
   mensagem do campo.
5. **Given** outra conta, administrador incluído, **When** tenta agir num acompanhamento que não é dela, **Then**
   recebe 404.

---

### User Story 4 - No Telegram, no chat, no Meu Dia e no briefing (Priority: P2)

- O resumo do Telegram traz um botão **"Adiar estes até amanhã"**.
- "O que preciso acompanhar?" no chat ou no Telegram lista os ativos, sem IA.
- O Meu Dia mostra uma linha com o que é alta prioridade e o total, com o link para a tela.
- O briefing ganha a seção "🔔 Acompanhar", com as pendências comerciais além do prazo (cliente sem resposta,
  proposta parada, revisão de reunião esquecida). O que já aparece em outra seção do briefing não se repete.

**Acceptance Scenarios**:

1. **Given** um resumo no Telegram, **When** ele toca "Adiar estes até amanhã", **Then** os acompanhamentos daquele
   resumo ficam adiados, e o toque repetido (reentrega do Telegram) não faz nada a mais.
2. **Given** acompanhamentos ativos, **When** ele pergunta "o que preciso acompanhar?", **Then** recebe a lista
   por prioridade, com links; sem nenhum, a resposta diz isso.
3. **Given** um cliente sem resposta há 2 dias, **When** o briefing é montado, **Then** ele aparece em "🔔
   Acompanhar" e não de novo em "E-mails".

### Edge Cases

- **Gestor com 104 orçamentos parados**: vira um acompanhamento só ("N orçamentos aguardando aprovação há mais de 7
  dias"), com os 5 mais antigos. Sem retrato recente (mais de 3 h), a regra não gera nada, e a tela diz isso.
- **Tarefa sem hora** (só data): vencida a partir do dia seguinte, e não à meia-noite.
- **Evento do Google que também é item do Secretário**: o item já tem lembrete (Etapa 1); a regra de reunião
  próxima ignora o evento.
- **Conflito**: um acompanhamento por par de compromissos e dia.
- **Motor parado** (servidor fora): na volta, o resumo sai uma vez com o que está devido, sem rajada.
- **Conta que perdeu o perfil Secretário**: nada é avaliado nem enviado para ela.

## Requirements

### Functional Requirements

- **FR-001**: O sistema MUST avaliar, a cada 5 minutos e sem IA, as regras ligadas de cada conta com o perfil
  Secretário: tarefa vencida, reunião próxima (Google), proposta parada, cliente sem resposta, revisão de reunião
  pendente, conflito e entrega próxima.
- **FR-002**: Cada regra MUST ter liga/desliga, um parâmetro com faixa válida e uma prioridade (alta, normal,
  baixa), com padrões e configuração por pessoa.
- **FR-003**: Cada situação MUST virar um acompanhamento com uma chave estável e única por conta. Ela é resolvida
  sozinha quando deixa de valer, e ao voltar depois de resolvida é um acompanhamento novo.
- **FR-004**: O envio MUST agrupar os devidos num resumo só por vez, no sino e no Telegram (quando pareado e
  ligado), com alta antes de normal, até 8 linhas e "+N" depois.
- **FR-005**: O envio MUST respeitar: prioridade (alta imediata; normal só depois do intervalo entre resumos; baixa
  nunca), horário de silêncio, fim de semana silencioso, teto de resumos por dia, intervalo de repetição e limite de
  repetições.
- **FR-006**: Cada envio MUST ficar registrado (quando, canal, situação, tentativas, quais acompanhamentos), com
  retentativas até 30 minutos no Telegram, sem envio duplo e sem o texto das situações no rastro de eventos.
- **FR-007**: A tela Secretário → Acompanhamentos MUST listar os ativos por prioridade, com fonte, data e link, e
  permitir "Resolvido" e "Adiar 1 dia". Ela MUST mostrar o histórico de envios de 7 dias e a configuração das
  regras.
- **FR-008**: O resumo do Telegram MUST trazer "Adiar estes até amanhã", com a mesma proteção dos toques da Etapa 1
  (dono do chat, toque único).
- **FR-009**: "O que preciso acompanhar?" MUST ser respondida pelo chat e pelo Telegram, sem IA.
- **FR-010**: O Meu Dia MUST mostrar uma linha com os acompanhamentos de prioridade alta e o total. O briefing MUST
  ter a seção "🔔 Acompanhar" com as pendências comerciais além do prazo, sem repetir o que outra seção já mostra.
- **FR-011**: Tudo MUST valer só para a conta dona. Outra conta, administrador incluído, MUST NOT ver nem agir.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Com 10 situações semeadas, duas rodadas seguidas geram exatamente 10 acompanhamentos e 1 resumo.
- **SC-002**: Em um dia simulado com situações novas a cada hora, saem no máximo 4 resumos e nenhum no horário de
  silêncio.
- **SC-003**: Zero avisos de situação já resolvida, de regra desligada ou de outra conta.

## Delta Requirements

**Skip**: feature nova sobre as Etapas 1 a 6; não há `docs/specs/current/` neste repositório. — Claude (pedido do
Felipe), 2026-10-17
