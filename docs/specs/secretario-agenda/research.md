# Research: Secretário, Etapa 2 (Agenda com Google)

## Decision 1: OAuth do Google sem SDK

**Decision**: chamar os endpoints OAuth 2.0 e Calendar v3 do Google pelo cliente HTTP do Laravel (`Http::`),
classe `GoogleAgenda`. O `composer.json` fica sem dependência nova.

**Rationale**:
- São 6 chamadas documentadas: autorizar, trocar código, renovar, revogar, `calendarList` e `events`
  (list/insert).
- O SDK `google/apiclient` traz centenas de serviços, e o repositório não tem nenhum.
- O mesmo desenho do `TelegramApi`: erros classificados (`nao_enviada`, `recusada`, `limite`), sem token em
  mensagem.

**Fonte dos endpoints**: documentação oficial do Google ("Using OAuth 2.0 for Web Server Applications" e
"Calendar API v3 reference": `CalendarList: list`, `Events: list`, `Events: insert`). São os mesmos recursos que
o `gcal.py` do Felipe já usa pelo cliente Python (`calendarList`, `events().list`, `insert`).

## Decision 2: Escopos mínimos

`https://www.googleapis.com/auth/calendar.readonly`, para listar agendas e eventos, e
`https://www.googleapis.com/auth/calendar.events`, para criar eventos. Não pede `calendar` completo (o `gcal.py`
usa esse, e é mais do que o Matriz precisa). Não pede e-mail nem perfil: a identificação mostrada é o id da agenda
primária, que é o e-mail da conta.

## Decision 3: Cliente OAuth

- Tipo "Aplicativo da Web", com URI de redirecionamento `https://matriz.alfasolucoes.cloud/secretario/google/retorno`.
- Id e segredo ficam em `GOOGLE_SECRETARIO_CLIENT_ID` e `GOOGLE_SECRETARIO_CLIENT_SECRET`, no `.env` de produção
  (FR-021).
- **Pendente do Felipe**: criar esse cliente no projeto Google Cloud (o `agenda-felipe` pessoal ou outro). Até
  lá, a tela mostra "integração não configurada" e nada quebra.

## Decision 4: Cache de eventos, e não consulta ao vivo

**Decision**: tabela `secretario_google_eventos`, sincronizada a cada 10 min (de ontem até +30 dias).

**Rationale**:
- O chat responde em menos de 3 s (SC-001).
- O briefing das 07:00 (Etapa 3) lê sem depender de o Google responder naquele instante.
- Conflito e livre são calculados sem N chamadas.

**Mínimo guardado (FR-007)**: sem descrição nem convidados. Fora da janela, o evento é apagado.

**Alternatives considered**:
- Consulta ao vivo a cada pergunta: lenta, sujeita a cota, e o briefing quebraria com o Google fora.

## Decision 5: Renovação com trava

`lockForUpdate` na linha da conta antes de renovar. Quem chegar depois relê e usa o token novo. Um `invalid_grant`
marca a conta como `reconectar` e avisa no sino uma vez (`aviso_reconectar_em`).

## Decision 6: Perguntas de agenda sem IA

Um `ConsultaDeAgenda` reconhece "compromissos/agenda/reuniões" mais um período, e "tenho horário livre … às HH".
O período reusa o `Interpretador::soQuando` da Etapa 1. A resposta só intercepta com o Google conectado (FR-012).
Sem ele, a Central segue igual, porque hoje ela responde pela agenda do runner (`CENTRAL_IA_AGENDA`).

## Decision 7: Criar evento

- Proposta da Etapa 1 com `tipo = evento_google`.
- Agenda: a primeira cujas palavras aparecem na frase; senão a padrão; senão a primária.
- Duração: "por N min/horas" ou "das X às Y"; senão 60 min.
- Na confirmação: `POST events` com `sendUpdates=none` e sem `attendees` (FR-016). O id do Google vira
  `origem_ref` de um `SecretarioItem` compromisso (aviso da Etapa 1, e a visão deduplica por esse id).
- Idempotência: a proposta é travada e marcada `confirmada` só depois do sucesso. Uma segunda confirmação
  encontra `confirmada` e não chama o Google de novo. Falha deixa a proposta pendente para tentar de novo.

## Decision 8: Conflito e livres

Intervalos `[início, fim)`. Encostar (fim = início) não é conflito. Dia inteiro e prazo sem hora não entram no
cálculo. Livres: o complemento dos ocupados entre 08:00 e 18:00, só blocos de 30 min ou mais. Itens do Secretário
sem duração (lembrete) não ocupam horário; compromisso do Secretário ocupa 60 min.
