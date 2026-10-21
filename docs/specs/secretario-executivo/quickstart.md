# Quickstart: Secretário, Etapa 1

Os cenários automatizados rodam na suíte isolada (SQLite, `Http::fake` para o Telegram, relógio com
`Carbon::setTestNow`). O real é conferido em produção, na conta do Felipe, depois da publicação.

## Cenário 1: Lembrete pelo Telegram (critério de aceite 1)

1. Felipe manda ao bot "me lembra daqui a 10 minutos de ligar para o Alexandre".
2. O bot responde com a proposta (hora = agora + 10 min) e os botões.
3. Ele toca em Confirmar.
4. **Expected**: o item aparece no Meu Dia. Em até 1 minuto depois da hora, o lembrete chega no Telegram e no
   sino, uma vez só.

## Cenário 2: Ações rápidas

1. No lembrete entregue, tocar em +15 min.
2. **Expected**: a mensagem vira "⏰ Adiado para HH:MM" e o lembrete volta nessa hora.
3. Tocar em Concluir.
4. **Expected**: o item fica concluído, e um segundo toque em qualquer botão responde "Já estava concluído".

## Cenário 3: Sem IA

1. Com o runner parado, repetir o cenário 1.
2. **Expected**: tudo funciona.
3. Mandar "coloca pra semana que vem, depois da diretoria, cobrar o Victor".
4. **Expected**: "Não entendi quando…", e nada é gravado.

## Cenário 4: Isolamento (erro)

1. A conta B, administrador, abre `/secretario/itens/{id do Felipe}/acao` e `/secretario`.
2. **Expected**: 404 na ação, e a tela sem itens do Felipe (ou 403 sem o perfil).
3. Um toque com `from.id` diferente do pareado.
4. **Expected**: "Ação indisponível.", nada é alterado e fica registrado um evento.

## Cenário 5: Reinício

1. Agendar 3 lembretes para os próximos 3 minutos.
2. Parar o cron por 5 minutos e voltar.
3. **Expected**: os 3 são entregues uma vez, com "(atrasado)".

## Cenário 6: Roundtrip end-to-end (web)

1. No Meu Dia, `POST /secretario/interpretar` com "amanhã 15h reunião com a Condonal", com a sessão real.
2. **Expected**: o payload tem `proposta.dados.tipo = compromisso` e `quando` = amanhã 15:00, e o aviso fica
   para 14:30 (antecedência padrão).
3. Confirmar.
4. **Expected**: `GET /secretario/itens` devolve o item em `proximos`, com `quando_texto` por extenso.
