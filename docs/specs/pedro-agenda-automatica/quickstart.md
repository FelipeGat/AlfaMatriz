# Quickstart: pedro-agenda-automatica

Cenários de prova. Os automáticos rodam no Gestor na suíte `Integracao` (`DatabaseTransactions`, banco
`gestor_alfa_test`: `docker exec gestor_php sh -c 'cd /var/www && php artisan test --testsuite=Integracao --filter=Agenda'`)
com a Meta falsificada (`Http::fake` no `graph.facebook.com`) e o relógio fixo (`travel`); no Matriz, em
`tests/Feature/Suporte/` com o Gestor falsificado, e na suíte isolada (`tools/central-ia/run-tests.py
--central-root /home/duque_de_caxias/projetos/central-agentes`). Dados fictícios: os da spec. **Nenhum cenário
dispara mensagem real**; o envio real só existe na Fase G, com números controlados e autorização do dono.

## Scenario 1: Programação das 16:30 (US1)

1. Destinatários técnico Cristiano (celular válido, consentimento) e Vitor (idem); modo `ligada`; `APP_ENV=production`
   simulado; janela fechada para os dois; modelos aprovados na lista falsa.
2. Amanhã: Cristiano 09:00–10:00 Praia Branca nº 837 e 10:00–18:00 Castanheiras nº 776; Vitor sem visita.
3. Relógio em 16:30; roda o ciclo.
4. **Expected**: uma chamada `agenda_programacao` para o Cristiano com
   `{{3}} = "09:00–10:00 Praia Branca (nº 837) • 10:00–18:00 Castanheiras (nº 776)"`; aviso `aceita` com wamid;
   linha `sem_envio` para o Vitor e nenhuma chamada para ele (FR-008); `agenda_verificacoes` (programacao, hoje,
   real) concluída.
5. Roda o ciclo de novo às 16:31. **Expected**: nenhuma chamada nova (US1-5).

Variações: adicional com horário da pivô (US1-4); janela aberta → `enviarTexto` com o texto livre (US1-7); sábado com
visita (US1-9); técnico sem celular → `pendente/sem_numero`, nada sai (US1-6).

## Scenario 2: Mudança por evento e silêncio (US2)

1. Programação de amanhã já comunicada ao Cristiano (Scenario 1).
2. Às 17:10, o atendimento 776 é reagendado pela Agenda Técnica (`definirVisitas`: a visita antiga é apagada e outra
   criada) para 13:00–18:00.
3. Ciclos às 17:10 e 17:11. **Expected**: nada sai (instável há menos de 2 min).
4. Ciclo às 17:13. **Expected**: uma `agenda_mudanca` com "Horário mudou: Castanheiras (nº 776), de 10:00–18:00 para
   13:00–18:00" — e não "cancelada + nova" (Decision 3).
5. Às 22:00 a visita 837 é cancelada. Ciclos até 06:29. **Expected**: nada sai.
6. Ciclo às 06:30. **Expected**: "Cancelada: Praia Branca (nº 837), 09:00–10:00. Não deve mais ser executada."

Variações: troca do responsável Cristiano → Alefe (Cristiano recebe "Saiu da sua agenda", Alefe "Nova visita"); horário
mudou e voltou em 1 min (nada sai); mudança só em `observacoes` (nada sai); remarcação para 05/11 ("Remarcada … de 03/11
para 05/11"); visita finalizada (nada sai).

## Scenario 3: Relatório das 17:00 (US3)

1. Agenda fictícia da US3-1; gestores Felipe e Wallas destinatários; Alefe sem celular.
2. Relógio em 17:00; roda o ciclo.
3. **Expected**: um `agenda_relatorio` por gestor com "4 visitas, 4 técnicos, 0 conflitos, 1 pendência (Alefe: sem
   número)"; Alefe e Vitor numa linha só se tiverem os mesmos compromissos; rodar de novo não reenvia.
4. Variação: Cristiano com 09:00–11:00 e 10:00–12:00 → "1 conflito" nomeado (técnico, horários, nºs).
5. Variação: amanhã sem visita → a linha única do FR-020.

## Scenario 4: Idempotência e verificação perdida (FR-028, FR-029, FR-030)

1. Sem ciclo das 16:20 às 18:40 (sistema fora do ar). Ciclo às 18:41.
2. **Expected**: a programação e o relatório de amanhã rodam uma vez cada, nessa ordem.
3. Dois ciclos simultâneos (dois processos no teste, ou a linha `preparada` já existente). **Expected**: uma chamada à
   Meta por destinatário; a segunda tentativa de criar a linha bate no índice único e não envia.

## Scenario 5: Falha da Meta e tentativas (FR-026, US4-5)

1. A Meta falsa responde 400 com `error.code`/`error.message` na chamada.
2. **Expected**: aviso `falha`, `erro_codigo` e `erro` gravados, `tentativas = 1`, `proxima_tentativa_em` +5 min.
3. Ciclos até a 3ª tentativa. **Expected**: `falha` final; pendência "falha de envio" no relatório das 17:00; nada no
   dia seguinte.
4. Variação: aceite e depois webhook `failed` com `errors[0]` → o mesmo caminho; `read` antes de `delivered` → `lida`.

## Scenario 6: Resposta do técnico e "parar" (FR-021, FR-006, SC-007)

1. Cristiano, técnico destinatário, fora da lista da casa, manda "vou chegar às 10h" ao número do Pedro.
2. **Expected**: `com_pedro_desde` nulo na conversa; resposta fixa gravada como `system`; nenhum menu; repasse ao
   Matriz com `com_pedro = false` (o Matriz não enfileira); um `alerta` para cada gestor destinatário.
3. Mais duas mensagens em 1 min. **Expected**: nenhuma resposta nem alerta novos.
4. Ele manda "Parar." **Expected**: `parou_em` preenchido, a resposta de confirmação, o alerta "pediu para parar";
   a programação seguinte vira `pendente/parou`.
5. Variação: o Felipe (lista da casa) também destinatário gestor → segue com o Pedro, sem resposta fixa.

## Scenario 7: Ensaio e desligada (FR-025, SC-006)

1. Modo `desligada`. Ciclo às 16:30. **Expected**: nenhuma linha de aviso nem chamada; a verificação registra que
   rodou desligada (é a prova de que o agendador do host roda — Decision 4).
2. Modo `ensaio`. **Expected**: avisos `ensaio` com o texto exato; nenhuma chamada à Meta.
3. Modo `ligada` com `APP_ENV=local`. **Expected**: comporta-se como ensaio.
4. "Prévia agora" com modo `ligada` em produção simulada. **Expected**: avisos `ensaio`, nenhuma chamada, versão
   comunicada real intacta.

## Scenario 8: Administração no Matriz (US4)

1. Conta dona abre Suporte › Agenda automática; o Gestor falso devolve `estado` e `funcionarios`.
2. Inclui o Cristiano como técnico com consentimento. **Expected**: `POST agenda/destinatarios` com `autor` = e-mail da
   conta dona; a linha mostra o nome e o final do celular.
3. Outra conta com `suporte,ler` abre a aba. **Expected**: vê; os botões de mudar não aparecem e o `POST` dá 403.
4. Gestor fora do ar. **Expected**: a aba diz que o Gestor não respondeu, sem erro 500.

## Scenario 9: Roundtrip End-to-End (borda Matriz ↔ Gestor)

Sem mock: o payload real do Gestor contra `contracts/integracao-agenda.md`.

1. No Gestor, teste de contrato na `Integracao` que chama as portas reais (`getJson`/`postJson` com o Bearer de teste)
   e confere chaves, tipos e enums de `estado`, `funcionarios`, `destinatarios`, `configuracao`, `avisos` e `previa`.
2. Depois da publicação do Gestor (modo `desligada`) e do Matriz, a aba de produção carrega `estado` e `funcionarios`
   do Gestor real (só leitura) e roda uma "Prévia agora" (ensaio, nada sai).
3. **Expected**: entre os funcionários ativos, os sete da tabela da spec aparecem com os finais lidos em 02/11 (o
   Cristiano com o 5943 e o Alefe "sem número", até o cadastro ser corrigido); as respostas reais batem com o contrato e
   com o que o teste do Matriz falsifica — divergência aqui volta para o contrato antes da Fase G.

## Scenario 10: Validação real (Fase G, só com autorização do dono)

1. Modelos aprovados na Meta; cadastros corrigidos; modo `ensaio` em produção por 2 dias úteis **(padrão)**, conferindo
   na aba os textos de cada verificação contra a agenda (SC-004, SC-005).
2. Com autorização expressa, `ligada` só com o Felipe (5397) como gestor e um técnico que aceitou testar.
3. **Expected**: SC-001 a SC-003 e SC-007 observados na supervisão; nenhuma mensagem ao 3269 (`AGENDA_NUNCA_ENVIAR`).
