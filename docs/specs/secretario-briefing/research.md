# Research: Secretário, Etapa 3 (Briefing)

## Decision 1: Montagem por regra, sem IA

**Decision**: o briefing é montado pelo Laravel com as fontes das Etapas 1 e 2, e a "sugestão" também é por regra
(FR-005).

**Rationale**: o servidor não tem IA (constitution II), e o briefing das 07:00 tem de sair com a máquina do Felipe
desligada.

**Alternatives considered**: pedir ao runner um texto "inteligente". Ficou para depois, como extra opcional,
porque às 07:00 a máquina pode estar desligada e o briefing não pode depender disso.

## Decision 2: Disparo pelo agendador a cada minuto, com chave única por dia

**Decision**: o comando `secretario:briefing` roda a cada minuto e gera para a conta cujo horário já chegou hoje,
num dia escolhido, antes das 12:00 e sem briefing automático no dia. `unique(user_id, data, origem)` em
`secretario_briefings`.

**Rationale**: é o mesmo desenho do `secretario:despachar`. A chave única impede a duplicata em execução
concorrente, e a regra do meio-dia cobre a parada do servidor (FR-003).

## Decision 3: Entrega

**Decision**: o texto é gravado primeiro e a entrega no Telegram vem depois, fora da transação, com `tentativas` e
`proxima_tentativa_em` (1, 2, 4, 8, 15 min, até +30 min). É o mesmo padrão do `Despachante` (Etapa 1).

## Decision 4: Pedido sob demanda

**Decision**: "resume meu dia", "briefing", "como está meu dia" e "o que tenho hoje" geram o briefing de agora,
com origem `pedido`. A resposta vai pela conversa (Etapa 1, `Conversa`), sem botões. Fica gravado como pedido e
não conta como o automático do dia.

## Decision 5: Formato

Texto puro com emojis de seção, como as mensagens da Central (o `TelegramCanal` manda sem `parse_mode`). No
máximo 8 linhas por seção. O texto é cortado em 3.800 caracteres, como `TelegramCanal::entregarInterno`.
