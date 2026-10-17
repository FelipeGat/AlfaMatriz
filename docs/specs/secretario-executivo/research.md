# Research: Secretário Executivo, Etapa 1 (núcleo)

Fontes: [auditoria.md](auditoria.md) e leitura do código em `e35d667` (17/10/2026). Cada decisão cita o
trecho que a sustenta.

## Decision 1: Onde os itens moram

**Decision**: tabelas próprias `secretario_*`, todas com `user_id`, no banco do AlfaMatriz. Nada de `Compromisso`
nem de `Tarefa`.

**Rationale**:
- `AgendaService::itens()` com o filtro de pessoas vazio devolve os compromissos de todo mundo
  (`app/Services/AgendaService.php:43-46`). Item pessoal lá vazaria para o time.
- `Tarefa` é o kanban de desenvolvimento, com portões de revisão (`app/Models/Tarefa.php:69-78`).
- O banco e a aplicação continuam um só (constitution III).

**Alternatives considered**:
- Flag `privado` em `Compromisso`: exigiria auditar todas as consultas da Agenda, do MCP e dos lembretes de
  hoje, e um esquecimento vaza. Rejeitada.

## Decision 2: Permissão

**Decision**: recurso `secretario` e perfil "Secretário" (slug `secretario`), criados por migração, como a
Central (`database/migrations/2026_10_10_200000_criar_central_ia.php:52-76`). O `PerfilPermissaoSeeder` passa a
excluir `secretario` do administrador (hoje exclui `central_ia` e `central_ia_agentes`, na `:107`). O Felipe
liga o perfil na própria conta pela tela de usuários.

**Rationale**: constitution I (nenhum perfil pessoal dado ao admin de fábrica) e o padrão que já existe.

## Decision 3: Entrega sem fila e sem IA

**Decision**: o comando `secretario:despachar` roda a cada minuto pelo `schedule:run` que já está no cron de
produção (`deploy/provisionar.sh:442-457`), com `withoutOverlapping()->onOneServer()` como os demais
(`routes/console.php`). Ele manda o Telegram direto pela `TelegramApi` do bot da pessoa (`doBot`). Mandar não é
consumir: a regra de um consumidor só vale para o `getUpdates` (constitution III).

**Rationale**:
- Não há worker de fila em produção, nem nenhum `ShouldQueue` em `app/`.
- Um processo novo seria mudança de infraestrutura.
- O cron por minuto cumpre o atraso máximo de 1 minuto (FR-012).

**Alternatives considered**:
- Pôr o lembrete na outbox `central_ia_entregas`: ela entrega só quando `CanalAtivo::entregaPermitida` deixa
  (`CanalAtivo.php:78-85`). Com o canal no web, o lembrete não sairia, o que viola o cenário 2.10. Além disso,
  ela não manda teclado. Rejeitada.
- Despachar dentro do loop do bot (`central-ia:telegram`): ele já roda o tempo todo, mas ficaria acoplado ao
  consumidor, e o sino dependeria do bot. Rejeitada.

## Decision 4: Exatamente uma vez

**Decision**:
- Cada aviso é uma linha com chave única `(item_id, para, canal)`.
- O despacho reserva com `lockForUpdate` e grava `enviando` ANTES da chamada ao Telegram, que fica fora da
  transação. Aviso preso em `enviando` há mais de 2 min vira `incerta` e não é reenviado. É o mesmo desenho de
  `TelegramCanal::entregarInterno` (`:875-935`), que já resolveu esse problema.
- O sino é gravado na mesma transação que marca o aviso como entregue.

**Rationale**: FR-013, FR-026 e FR-027 usam o padrão já testado no repositório.

## Decision 5: Tentativas

**Decision**:
- `nao_enviada` (rede) e `limite` (429) voltam a `pendente`, com `proxima_tentativa_em` de 1, 2, 4, 8 e 15 min,
  até `para + 30 min`. Depois disso, `falhou`.
- `recusada` (400/403) vira `falhou` na hora.
- Os códigos de erro são os que a `TelegramApi` já devolve (`TelegramCanal.php:935-955`).

## Decision 6: Atrasados depois de parada

**Decision**: o despacho pega todo aviso `pendente` com `para <= agora`, sem janela mínima. Se
`agora - para > 2 min`, marca `atrasado` e o texto diz "(atrasado, era HH:MM)" (FR-015).

**Rationale**: o `agenda:lembrar-compromissos` usa janela de 30 min e perde o que passou dela. Aqui nada pode se
perder.

## Decision 7: Interpretação determinística

**Decision**: uma classe `Interpretador` pura (sem banco, relógio injetado) reconhece:

- **Gatilho**: "me lembra/lembre/lembrar", "lembre-me", "lembrete", "anota/anote/anotar", "preciso … <data>",
  "agenda/marca … <data>" (compromisso pessoal) e o comando `/lembrete`.
- **Quando**:
  - "hoje" e "amanhã";
  - dias da semana com e sem "-feira";
  - "dd/mm[/aaaa]";
  - "daqui a/em N min/minutos/h/horas/dias";
  - "às 9h", "9h30", "9:30", "15h", "meio-dia", "meia-noite";
  - "de manhã/à tarde/à noite" para desambiguar a hora.
- **Recorrência**: "todo dia", "toda(s) segunda(s)…", "todo dia N", "toda semana".
- **O quê**: o resto da frase, sem gatilho nem marcadores de tempo ("de ligar para o Alexandre" vira "Ligar para
  o Alexandre").

Se um gatilho aparece mas falta o "quando" de um lembrete, o resultado diz o que falta (FR-009). Frase sem
gatilho não é do Secretário (FR-010).

**Rationale**:
- SC-001 pede 18 de 20 sem IA.
- Com a máquina desligada, o núcleo tem de funcionar (SC-006).
- Não existe biblioteca de datas em português no `composer.json`, e o vocabulário aqui é pequeno e fechado.

**Alternatives considered**:
- Mandar tudo à IA: depende da máquina e custa uma execução por lembrete. Rejeitada como caminho principal e
  mantida como fallback (Decision 9).

### Regras de ambiguidade (das Edge Cases da spec)

- Hora sem período ("às 7"): a próxima ocorrência futura entre 07:00 e 19:00, no mesmo dia ou no seguinte. A
  proposta mostra a hora completa.
- "Amanhã" depois da meia-noite: o dia seguinte no calendário.
- Dia da semana igual a hoje: a próxima semana.
- Hora no passado com data explícita "hoje": a proposta é recusada e o Matriz pergunta se é amanhã no mesmo
  horário.
- Item só com data, sem hora (tarefa "na sexta"): prazo na data e aviso às **08:00** do dia. É o mesmo horário
  de `agenda:lembrar-prazos` (`routes/console.php:36-39`). A proposta diz isso.

## Decision 8: Confirmação

**Decision**:
- Cada interpretação vira uma `secretario_propostas` pendente, válida por 30 min.
- **Telegram**: botões Confirmar e Corrigir.
- **Web** (chat da Central e Meu Dia): o Meu Dia tem botões; no chat da Central, responder "sim", "ok",
  "confirma" ou "pode" confirma. "Não, às 10h" corrige e gera uma nova proposta, que substitui a anterior.
- Mensagem que não é resposta segue o fluxo normal, e a proposta expira sozinha.
- Com a confirmação automática ligada, proposta **inequívoca** grava direto:
  - tem data e hora explícitas ou relativas;
  - não caiu em regra de ambiguidade;
  - não veio da IA.

## Decision 9: Fallback com IA

**Decision**:
- Frase com gatilho que o `Interpretador` não resolve, com a máquina online (o mesmo critério de
  `ProgressoService::texto`, `runner->online()`), vira uma execução da Central (`AgentesService::pedir`, projeto
  `''`, modo `analisar`).
- A instrução pede SÓ um bloco `<<<SECRETARIO {json} SECRETARIO>>>` com `tipo, titulo, quando (ISO local),
  recorrencia`.
- Na conclusão (`AgentesService::concluir`, onde o `Dossie::extrair` já separa o bloco da Central, `:763`), o
  bloco é extraído e **validado** pelo Laravel (tipo permitido, data futura, título até 200 caracteres) e vira
  proposta pendente. Ela é sempre confirmada pela pessoa, nunca automática.
- Com a máquina offline: "Não entendi quando. Diga, por exemplo: amanhã às 9h."

**Rationale**: constitution II (IA só no runner) e FR-007.

## Decision 10: Telegram, botões e voz

**Decision**:
- `allowed_updates` passa a `['message', 'callback_query']` (`CentralIaTelegram.php:152`).
- `TelegramCanal::receber` encaminha o `callback_query` ao `TelegramDoSecretario`, que confere:
  - chat privado;
  - `from.id == vinculo.chat_id`;
  - o vínculo é do dono do bot;
  - `secretario` liberado;
  - o limite de frequência de 30 por minuto.
- O id do toque é único (deduplicação).
- A resposta sempre chama `answerCallbackQuery` e edita a mensagem (`editMessageText`) com o resultado, sem
  teclado.
- `callback_data` (até 64 bytes) tem o formato `s:<ação>:<id>`. A ação vem de uma lista fechada e o id é
  conferido contra o dono. A voz fica para a Etapa 4.

**Interceptação do texto**: em `receberNoBot`, a ordem é:
1. forward recusado (`:233`);
2. código de confirmação (`:240`);
3. **o Secretário**;
4. canal ativo (`:245`).

O Secretário vem antes do canal ativo porque registrar um lembrete é um comando, não uma conversa com agente.
Isso funciona com o canal no web, sem tirar a conversa de lá. Com o canal no Telegram, o pedido e a resposta
entram no histórico da Central. Com o canal no web, ficam só no registro do Secretário, porque
`ConversaService::gravar` exige o canal certo para mensagem do usuário (`ConversaService.php:131-133`).

## Decision 11: Mensagem da Central sem eco no Telegram

**Decision**: `ConversaService::registrarDoAgente` ganha o parâmetro opcional `entregar = true`. O Secretário
passa `false` quando já respondeu no Telegram com teclado. Sem isso, a resposta sairia duas vezes: com teclado e
pela outbox.

**Rationale**: é uma mudança aditiva, com padrão igual ao comportamento de hoje (FR-010).

## Decision 12: Fuso

**Decision**:
- `config('app.timezone')` já é `America/Sao_Paulo` (`config/app.php:73`). Datas ficam em `datetime` no fuso da
  aplicação, como no resto do AlfaMatriz (não há `CONVERT_TZ` no código).
- Cada item também guarda `fuso` (padrão `America/Sao_Paulo`), para quando houver outra pessoa.
- O Brasil não tem horário de verão desde 2019, então não há horário ambíguo. O teste fixa o relógio em
  23:30 e 00:30.

## Decision 13: Rastro

**Decision**: confirmar, concluir, adiar, cancelar, falha final de entrega e toque recusado geram evento em
`central_ia_eventos` (`Eventos::registrar`). O evento leva o id do item e a ação, **sem o título** (FR-005,
constitution VI).
