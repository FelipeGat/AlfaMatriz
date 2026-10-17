# Contrato: Telegram do Secretário

[PROPOSTA — a validar na implementação]. Usa só métodos públicos da Bot API do Telegram, os mesmos que a
`TelegramApi` já chama: `sendMessage`, `getUpdates`. Também usa `answerCallbackQuery` e `editMessageText`, da
mesma API, ainda não usados no repositório.

## Recebimento

- `getUpdates` com `allowed_updates: ["message", "callback_query"]`.
- **Texto**: o mesmo update de mensagem de hoje. O Secretário entra depois da recusa de encaminhada e do código
  de confirmação, e antes da regra do canal ativo.
- **Toque**: `callback_query` com `id`, `from.id`, `message.chat.id`, `message.message_id` e `data`.

## `callback_data` (até 64 bytes)

Formato `s:<ação>:<id>`. A ação vem de uma lista fechada:

| Ação | Alvo | Efeito |
|---|---|---|
| `pc` | proposta | confirmar |
| `pd` | proposta | descartar ("Corrigir": o bot pede a frase corrigida) |
| `c` | aviso | concluir o item do aviso |
| `a15`, `a60` | aviso | adiar 15 ou 60 min a partir de agora |
| `am` | aviso | amanhã, no mesmo horário do item |
| `r` | aviso | reagendar: o bot pergunta o novo horário (a proposta fica com `falta=quando`) |
| `x` | aviso | cancelar; num item recorrente, troca o teclado por `xs` / `xt` |
| `xs`, `xt` | aviso | cancelar só esta ocorrência / todas as próximas |

## Regras de segurança, nesta ordem

Qualquer falha encerra com `answerCallbackQuery` genérico ("Ação indisponível.") e evento `secretario_toque_recusado`.

1. `message.chat.type == private` e `from.id == message.chat.id`.
2. Existe um vínculo com `chat_id = from.id` e `user_id = bot.user_id`.
3. A conta pode `secretario,editar`.
4. 30 toques por minuto por chat, no mesmo limitador das mensagens.
5. `callback_id` inédito; repetido não faz nada.
6. O alvo (proposta ou aviso) pertence a `bot.user_id`.
7. Item encerrado responde "Já estava concluído/cancelado" sem alterar nada.

## Mensagens

- **Proposta**: "📝 Vou lembrar você **sáb, 17/10 às 09:00** de *ligar para o Alexandre*. Confirma?" com o
  teclado `[Confirmar] [Corrigir]`.
- **Lembrete**: "⏰ Ligar para o Alexandre" com uma linha de horário (mais "(atrasado, era 09:00)" quando for o
  caso) e o teclado `[✓ Concluir] [+15 min] [+1 h]` / `[Amanhã] [Reagendar] [Cancelar]`.
- **Depois do toque**: a mensagem é editada com o resultado ("✓ Concluído às 09:04", "⏰ Adiado para 10:04"),
  sem teclado.
- Toda mensagem vai com `link_preview_options.is_disabled = true`, como as da Central.
