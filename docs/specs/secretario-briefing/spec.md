# Feature Specification: Secretário, Etapa 3 (Briefing Executivo diário)

**Feature**: `secretario-briefing`
**Created**: 2026-10-17
**Status**: Draft
**Pedido**: Felipe, 17/10/2026 ("Missão Secretário Executivo", Fase 4: Resumo Executivo Diário). A ordem
combinada era "pode seguir, sem deploy ainda, somente após os testes locais".
**Base**:
- Etapa 1 ([../secretario-executivo/spec.md](../secretario-executivo/spec.md)): itens, despacho e Telegram com
  botões;
- Etapa 2 ([../secretario-agenda/spec.md](../secretario-agenda/spec.md)): agenda consolidada, Google e livres.

**Relação**: feature nova. Ela consome as Etapas 1 e 2 e não muda as regras delas. Os princípios da constitution
valem inteiros, sobretudo o I (só a conta dona), o II (o servidor não tem IA, então o briefing é montado por
regra) e o VIII (só dado real, sem "nada pendente" quando a fonte faltou).

## Escopo

1. Um resumo do dia gerado sozinho no horário escolhido (padrão 07:00), nos dias escolhidos (padrão: segunda a
   sexta). Entrega no Telegram e registro no Matriz.
2. Conteúdo, das fontes que existem hoje:
   - a agenda do dia (Etapa 2);
   - atrasados, itens de hoje e pendências do Secretário (Etapa 1);
   - conflitos e o maior horário livre.
3. Uma sugestão do Matriz feita por regra, ligando o maior livre do dia ao atrasado mais antigo.
4. Rodapé honesto com as fontes usadas, o horário da última sincronização com o Google e as fontes ainda
   ausentes: e-mail, reuniões e Gestor, que vêm em etapas seguintes.
5. "Resume meu dia" pelo chat ou pelo Telegram gera o mesmo briefing na hora.
6. No Meu Dia, o briefing de hoje aparece no topo, recolhível.

**Fora desta etapa**: e-mails, reuniões, Gestor e WhatsApp no briefing (cada um entra quando a etapa dele
existir) e a sugestão escrita por IA.

## User Scenarios & Testing

### User Story 1 - Receber o resumo do dia sem pedir (Priority: P1)

Às 07:00 de um dia útil, chega no Telegram:

```
☀️ BOM DIA — sex, 17/10

📅 Agenda de hoje
08:30–09:30 Reunião comercial (Google · VixCard)
10:00–11:00 Cliente RFID (Google · Comercial Grupo) ⚠ conflito
...

⚠ Atrasados (2)
• Enviar proposta Condonal (desde qua, 15/10)

✅ Hoje
• 14:00 Ligar para o Alexandre

📌 Pendências sem data (3)
• Falar com o Rossini ...

💡 Livre 11:00–12:00: bom momento para "Enviar proposta Condonal".

Fontes: Google (lido às 06:58), Agenda do Matriz, Secretário.
Ainda não entram: e-mail, reuniões, Gestor.
```

O mesmo texto fica no Matriz.

**Why this priority**: é o critério de aceite 3 da missão ("no horário configurado, o sistema gera e envia o
resumo do dia").

**Independent Test**: com o relógio às 07:00 e itens nas fontes, rodar a volta do agendador e conferir uma
entrega no Telegram, o registro no Matriz e nenhuma repetição na volta seguinte.

**Acceptance Scenarios**:

1. **Given** o briefing ligado às 07:00 de segunda a sexta, **When** chega 07:00 de uma sexta, **Then** ele é
   gerado, entregue no Telegram e registrado no Matriz uma vez só.
2. **Given** o mesmo dia, **When** o agendador roda de novo às 07:01, 07:02 e assim por diante, **Then** nada é
   repetido.
3. **Given** um sábado, com o sábado fora dos dias escolhidos, **When** chega 07:00, **Then** nada é gerado.
4. **Given** o servidor parado das 06:55 às 07:20, **When** ele volta, **Then** o briefing do dia sai uma vez,
   desde que ainda seja antes do meio-dia. Depois disso, o do dia é dado como perdido e não sai atrasado.
5. **Given** o Telegram fora do ar às 07:00, **When** a entrega falha, **Then** ela é tentada de novo por até
   30 min, e o briefing continua disponível no Matriz.
6. **Given** o Google sincronizado há mais de 10 min, **When** o briefing vai ser gerado, **Then** o Google é lido
   antes. Se a leitura falhar, o briefing sai mesmo assim e diz que o Google pode estar desatualizado.

---

### User Story 2 - Só dado real, com a limitação dita (Priority: P1)

O briefing nunca afirma "nenhuma pendência" quando uma fonte faltou: diz quais fontes leu, quando, e quais ainda
não existem. Dia sem nada vira "Nada na agenda de hoje" só com o Google em dia. Com o Google desconectado, o texto
avisa.

**Why this priority**: a missão exige "não considerar a ausência de dados como ausência de pendências" e "se uma
integração falhar, informar a limitação". Com a constitution VIII, é o que torna o resumo confiável.

**Independent Test**: com o Google desconectado e um dia vazio, conferir que o texto não diz "dia livre" e lista a
limitação.

**Acceptance Scenarios**:

1. **Given** o Google desconectado, **When** o briefing é gerado, **Then** ele traz a agenda do Matriz e do
   Secretário e avisa que o Google não está conectado.
2. **Given** um dia sem itens e o Google em dia, **When** o briefing é gerado, **Then** diz "Nada na agenda de
   hoje" e cita as fontes lidas.
3. **Given** qualquer briefing, **When** ele é gerado, **Then** o rodapé lista as fontes usadas e as ainda
   ausentes.

---

### User Story 3 - Pedir o resumo quando quiser (Priority: P2)

"Resume meu dia", "briefing" ou "como está meu dia?", no chat ou no Telegram: a resposta é o briefing de agora,
na hora, sem IA.

**Why this priority**: o mesmo conteúdo, sob demanda, no celular.

**Independent Test**: mandar "resume meu dia" às 15:00 e conferir que a agenda considera o restante do dia e que
nenhuma execução de IA foi criada.

**Acceptance Scenarios**:

1. **Given** a conta com o Secretário, **When** ele manda "resume meu dia", **Then** recebe o briefing de agora,
   sem IA.
2. **Given** o briefing pedido à tarde, **When** ele é montado, **Then** os itens que já passaram aparecem como
   feitos ou atrasados, conforme o estado, e o livre considera só o que ainda vem.
3. **Given** uma conta sem o perfil, **When** ela manda a frase, **Then** nada muda no fluxo da Central.

---

### User Story 4 - Ver e ajustar no Matriz (Priority: P3)

No Meu Dia, o briefing de hoje aparece no topo, recolhível, com a hora de geração. Em Configurações, ele liga ou
desliga o briefing e escolhe o horário e os dias.

**Why this priority**: os padrões (07:00, segunda a sexta) atendem desde o primeiro dia; ver e ajustar no Matriz
é complemento.

**Independent Test**: gerar o briefing de hoje, abrir o Meu Dia e conferir o texto recolhível com a hora de
geração. Depois, mudar horário e dias nas Configurações e conferir o efeito no agendador.

**Acceptance Scenarios**:

1. **Given** o briefing de hoje gerado, **When** ele abre o Meu Dia, **Then** vê o briefing de hoje no topo,
   recolhível, com a hora de geração.
2. **Given** as Configurações com o briefing ligado, horário 06:30 e dias de segunda a sábado, **When** chega
   06:30 de um sábado, **Then** o briefing sai.
3. **Given** o briefing desligado, **When** chega o horário, **Then** nada é gerado.

### Edge Cases

- **Mudança de horário no mesmo dia** depois de o briefing ter saído: não sai de novo naquele dia.
- **Muitos itens**: cada seção mostra até 8 linhas e diz "+N" quando há mais. A mensagem cabe no limite do
  Telegram.
- **Texto do item com instrução**: é só exibido, sem IA no caminho, e passa pela proteção de segredos.
- **Conta sem Telegram pareado**: o briefing é registrado no Matriz e não tenta o Telegram.
- **Conta sem a permissão ou desativada**: nada é gerado.
- **Fuso**: horário e dia sempre em America/Sao_Paulo.

## Requirements

### Functional Requirements

- **FR-001**: O sistema MUST gerar o briefing de cada conta com o Secretário e o briefing ligado no horário e nos
  dias escolhidos por ela (padrão 07:00, segunda a sexta), sem depender da máquina do usuário nem de IA.
- **FR-002**: Cada conta MUST ter no máximo um briefing automático por dia, mesmo com repetição da volta do
  agendador, reinício ou mudança de horário no mesmo dia.
- **FR-003**: Com o servidor parado no horário, o briefing do dia MUST sair quando ele voltar, se ainda for antes
  do meio-dia. Depois disso, MUST NOT sair.
- **FR-004**: O briefing MUST conter, das fontes disponíveis:
  - a agenda do dia (todas as fontes da Etapa 2, com origem e conflitos);
  - os atrasados do Secretário;
  - os itens de hoje;
  - as pendências sem data;
  - o maior horário livre.
  Cada seção vazia é omitida, exceto a agenda.
- **FR-005**: A sugestão MUST ser por regra: o maior livre do dia, de 30 min ou mais, associado ao atrasado mais
  antigo. Sem atrasado, à primeira pendência sem data. Sem livre ou sem pendência, não há sugestão.
- **FR-006**: O briefing MUST listar as fontes usadas (com o horário da leitura do Google) e as fontes ainda
  ausentes. MUST NOT afirmar ausência de compromissos quando o Google estiver desconectado ou desatualizado.
- **FR-007**: Antes de gerar, se a última sincronização do Google tiver mais de 10 min, o sistema MUST tentar
  sincronizar. A falha MUST NOT impedir o briefing.
- **FR-008**: O briefing MUST ser entregue no Telegram da conta, quando pareado e habilitado, com novas
  tentativas por até 30 min. MUST ser registrado no Matriz com o texto, a hora de geração, as fontes e o estado
  da entrega.
- **FR-009**: Cada seção MUST ter no máximo 8 linhas, com "+N" para o resto. A mensagem MUST caber no limite do
  Telegram.
- **FR-010**: "Resume meu dia", "briefing", "como está meu dia" e frases equivalentes MUST gerar o briefing de
  agora pelo chat e pelo Telegram, sem IA, só para quem tem o Secretário.
- **FR-011**: O Meu Dia MUST mostrar o briefing de hoje, recolhível, com a hora de geração.
- **FR-012**: As Configurações MUST permitir ligar ou desligar o briefing e escolher o horário (HH:MM) e os dias
  da semana.
- **FR-013**: Tudo MUST valer só para a conta dona (constitution I). O texto do briefing MUST NOT ir para log.
- **FR-014-INFRA-SCHED**: A verificação do horário MUST rodar a cada minuto pelo agendador, uma execução por vez,
  com chave única por conta e dia.

### Key Entities

- **Briefing**: conta, dia, texto, gerado em, fontes (usadas, ausentes, sincronização do Google), estado da entrega
  no Telegram (pendente, entregue, falhou, sem Telegram), tentativas e origem (automático ou pedido).
- **Preferências do Secretário** (Etapa 1): ganham briefing ligado, horário e dias.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Em 30 dias úteis simulados, 30 briefings saem no horário (até 1 min depois), nenhum repetido e
  nenhum em dia não escolhido.
- **SC-002**: 100% dos itens do dia presentes nas fontes aparecem no briefing (até o limite por seção, com "+N"), e
  nenhum item que não exista nas fontes.
- **SC-003**: "Resume meu dia" responde em menos de 3 segundos, sem execução de IA.
- **SC-004**: Com qualquer fonte ausente, 100% dos briefings dizem a limitação.

## Delta Requirements

**Skip**: feature nova sobre as Etapas 1 e 2; não há `docs/specs/current/` neste repositório. — Claude (pedido
do Felipe), 2026-10-17
