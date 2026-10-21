# Feature Specification: Secretário, agenda do Google pela máquina

**Feature**: `secretario-agenda-pela-maquina`
**Created**: 2026-10-17
**Status**: Draft
**Pedido**: Felipe, 17/10/2026: "analise a agenda que já tem integração, você mesmo que usa minha agenda para fazer as
agendas da VixCard".

**Relação**: complementa a Etapa 2 (`secretario-agenda`). A Etapa 2 prevê OAuth próprio no Matriz, mas o cliente
OAuth ainda não foi criado. Enquanto isso, a pessoa já tem uma ponte pessoal com o Google Calendar na própria
máquina: o projeto Google "agenda-felipe", com o token autorizado e renovação sozinha. É por ela que o Claude lança
os compromissos da VixCard. Esta feature usa essa ponte só para **ler**, pelo mesmo caminho do retrato do Gestor.

## Escopo

1. O exportador `secretario_agenda.py` fica na pasta da ponte e importa o `servico()` dela. Ele é **só leitura**:
   usa `calendarList` e `events.list`, numa janela de 2 dias atrás a 35 dias à frente.
2. O runner roda o exportador a cada 10 minutos (`CENTRAL_IA_GOOGLE="programa|script|pasta"`) e manda o resultado a
   `POST /api/central-ia/runner/agenda-google`.
3. O Matriz guarda agendas e eventos nas tabelas da Etapa 2. A conta fica com a situação `maquina` e **não tem
   token**.
4. Minha agenda, os conflitos, o briefing, as perguntas de agenda e os avisos passam a ver o Google.
5. Agenda do Google cujo nome cita a VixCard ou o Grupo ganha a cor daquela empresa na Minha agenda.
6. Criar evento no Google continua dependendo do OAuth: a leitura pela máquina não escreve no Google.

## Requirements

- **FR-001**: O exportador MUST só ler (sem insert, update ou delete) e MUST imprimir uma linha marcada
  `SECRETARIO_AGENDA:`.
- **FR-002**: O endpoint MUST aceitar só a máquina da própria conta, com o perfil Secretário. Outra conta MUST
  receber 404 ou 403.
- **FR-003**: Cada leitura MUST substituir os eventos da conta. Evento apagado no Google MUST sumir, e agenda
  desligada em Configurações MUST NOT ter eventos guardados.
- **FR-004**: Uma conta OAuth conectada no Matriz MUST ter precedência: a leitura pela máquina é ignorada.
- **FR-005**: Leitura com mais de 30 minutos MUST aparecer como desatualizada na agenda e em Configurações.
- **FR-006**: Links de evento MUST ser só https.

## User Scenarios & Testing

### User Story 1 - A agenda do Google aparece sem OAuth (Priority: P1)

**Acceptance Scenarios**:

1. **Given** a máquina ligada com `CENTRAL_IA_GOOGLE`, **When** passam 10 min, **Then** a Minha agenda mostra os
   eventos de todas as agendas, sem o aviso de "Google não conectado" (FR-001, FR-005).
2. **Given** uma agenda desligada em Configurações, **When** a máquina lê de novo, **Then** os eventos dela somem
   (FR-003).
3. **Given** a conta OAuth conectada, **Then** a leitura pela máquina é ignorada (FR-004).
4. **Given** a máquina de outra conta, **Then** o envio é recusado (FR-002).
5. **Given** um evento com link `javascript:`, **Then** ele é guardado sem link (FR-006).

## Delta Requirements

**Skip**: complemento da Etapa 2, sem `docs/specs/current/` neste repositório. — Claude (pedido do Felipe),
2026-10-17
