# Feature Specification: Secretário Executivo — Etapa 1, núcleo de tarefas e lembretes

**Feature**: `secretario-executivo`
**Created**: 2026-10-17
**Status**: Draft
**Pedido**: Felipe, 17/10/2026 — "Missão: Matriz Secretário Executivo + Matriz Meetings". Ele quer
administrar a rotina pelo Matriz ou pelo Telegram: registrar compromissos, receber lembretes, consultar a
agenda, processar reuniões gravadas e receber um resumo diário. O módulo é só para a conta dele e precisa
de cuidado para não vazar dados.
**Insumo**: [auditoria.md](auditoria.md), com a Fase 0 e as decisões do Felipe de 17/10/2026.
**Relação com specs existentes**: é uma feature nova. Ela usa a Central de IA (`central-ia`, `chats-business`)
como canal de conversa e de IA, sem refinar nenhuma delas. Todos os princípios da `docs/constitution.md` valem.
Os mais tocados são o I (privacidade por conta, sem exceção de administrador), o II (IA só na máquina da
pessoa), o III (um bot só por pessoa), o IV (segredos), o VII (autorização para ação externa) e o VIII (zero
fabricação).

## Escopo desta spec

O módulo inteiro foi dividido em etapas, cada uma com spec, testes e publicação próprios. **Esta spec cobre só
a Etapa 1, o núcleo**:

- itens pessoais (tarefa, lembrete, compromisso pessoal, acompanhamento);
- registro por linguagem natural no chat da Central e no Telegram, com confirmação;
- lembretes entregues no horário, com ações rápidas;
- recorrência;
- a tela **Meu Dia** básica e a área **Secretário** no menu.

As etapas seguintes já têm decisão registrada na auditoria e ganham spec própria quando chegar a vez: agenda
consolidada com Google (OAuth próprio do Matriz), briefing diário, Matriz Meetings (áudio, transcrição local,
ata), e-mail (IMAP no servidor, só leitura), pendências do Gestor (pela máquina), memória executiva,
acompanhamento proativo e WhatsApp.

## Decisões do dono (17/10/2026)

1. **Só a conta do Felipe nesta fase**, com o desenho já pronto para outras pessoas: tudo pertence a uma conta e
   nada é compartilhado.
2. **Nada vaza para o time.** Item do Secretário não aparece na Agenda nem no Quadro de tarefas do time, nem para
   administrador.
3. **O mesmo bot e a mesma conversa do Telegram** da Central. Não há bot novo.
4. **Áudio de reunião é apagado depois da transcrição.** Isso vale para a Etapa 4; está registrado aqui porque
   orienta a política de retenção do módulo.

## User Scenarios & Testing

### User Story 1 - Registrar um lembrete falando normalmente (Priority: P1)

Felipe está na rua e escreve no Telegram: "me lembra amanhã às 9h de ligar para o Alexandre". O Matriz entende,
responde "Vou lembrar você amanhã (sáb, 17/10) às 09:00 de ligar para o Alexandre. Confirma?" e mostra os botões
Confirmar e Corrigir. Ele toca em Confirmar e recebe a confirmação de que o lembrete está registrado. O mesmo
pedido feito no chat da Central, no navegador, funciona igual.

**Why this priority**: é a frase que abre a missão e o primeiro critério de aceite ("me lembra daqui a 10
minutos…"). Sem registrar, nada mais tem valor.

**Independent Test**: mandar "me lembra daqui a 10 minutos de ligar para o Alexandre" pelo Telegram, confirmar e
conferir que o item aparece no Meu Dia com o horário certo, no fuso de Brasília.

**Acceptance Scenarios**:

1. **Given** o Felipe com o Telegram pareado, **When** ele escreve "me lembra amanhã às 9h de ligar para o
   Alexandre", **Then** o Matriz responde com data por extenso, hora, o que vai lembrar e o pedido de
   confirmação, e nada é gravado antes de ele confirmar.
2. **Given** a proposta de lembrete na tela, **When** ele confirma, **Then** o item é registrado e aparece no Meu
   Dia, e a resposta diz que ficou registrado.
3. **Given** a proposta de lembrete, **When** ele responde "não, às 10h", **Then** o Matriz refaz a proposta com
   a correção e pede confirmação de novo.
4. **Given** "daqui a 2 horas me lembra de cobrar o orçamento" às 14:20, **When** o pedido é interpretado,
   **Then** o horário proposto é 16:20 do mesmo dia.
5. **Given** "me lembra de ligar para o Alexandre", sem data nem hora, **When** o pedido é interpretado, **Then**
   o Matriz pergunta só quando lembrar e não inventa um horário.
6. **Given** "anota que tenho que falar com o Rossini", **When** o pedido é interpretado, **Then** a proposta é
   uma tarefa sem prazo e sem lembrete, e ela entra na lista de pendências do Meu Dia depois de confirmada.
7. **Given** uma frase que as regras do Matriz não entendem, mas que pede algo do Secretário (por exemplo
   "coloca pra semana que vem, depois da reunião de diretoria, cobrar o Victor"), **When** a máquina do Felipe
   está ligada, **Then** a IA dele propõe a interpretação. Com a máquina desligada, o Matriz diz que não
   entendeu e pede a data e a hora de forma direta. Nos dois casos nada é gravado sem confirmação.
8. **Given** a confirmação automática ligada para comandos inequívocos, **When** ele manda "me lembra daqui a 10
   minutos de beber água", **Then** o item é gravado direto e a resposta diz o que foi registrado e oferece
   desfazer.
9. **Given** um pedido que não é para o Secretário ("analise o código do controller"), **When** ele chega à
   Central, **Then** segue o fluxo atual da Central, sem virar lembrete.

---

### User Story 2 - Receber o lembrete na hora certa e agir com um toque (Priority: P1)

No horário marcado chega no Telegram "⏰ Ligar para o Alexandre" com os botões Concluir, +15 min, +1 h, Amanhã,
Reagendar e Cancelar. O mesmo aviso aparece no sino do Matriz. Ele toca em +1 h e o lembrete volta uma hora
depois. Na volta, toca em Concluir e o item sai das pendências.

**Why this priority**: o registro só tem valor se o lembrete chegar, uma vez e na hora, mesmo sem nenhuma
conversa aberta com a IA e com a máquina desligada.

**Independent Test**: registrar um lembrete para daqui a 2 minutos, desligar a máquina do Felipe (runner fora) e
conferir que ele chega no Telegram e no sino uma única vez, com os botões funcionando.

**Acceptance Scenarios**:

1. **Given** um lembrete para as 09:00, **When** chega 09:00, **Then** ele é entregue no Telegram e no sino até
   09:01 e só uma vez.
2. **Given** um lembrete entregue, **When** ele toca em +15 min, +1 h ou Amanhã, **Then** o lembrete é
   reprogramado (Amanhã = mesmo horário no dia seguinte), a mensagem mostra o novo horário e ele volta a ser
   entregue nesse horário.
3. **Given** um lembrete entregue, **When** ele toca em Concluir, **Then** o item fica concluído com data e hora e
   não é entregue de novo.
4. **Given** um lembrete entregue, **When** ele toca em Cancelar, **Then** o item fica cancelado e não é entregue
   de novo.
5. **Given** um lembrete entregue, **When** ele toca em Reagendar, **Then** o Matriz pergunta o novo horário e
   segue o mesmo fluxo de interpretação e confirmação da história 1.
6. **Given** o Telegram fora do ar no horário, **When** a entrega falha, **Then** o Matriz tenta de novo, com
   intervalo crescente, por até 30 minutos. A falha final fica registrada, e o aviso no sino é feito mesmo assim.
7. **Given** o servidor reiniciado ou parado por 5 minutos, **When** ele volta, **Then** os lembretes que
   venceram no intervalo são entregues uma vez, marcados como atrasados, e nenhum é perdido nem duplicado.
8. **Given** um toque num botão de um lembrete que já foi concluído ou cancelado, **When** o Matriz recebe o
   toque, **Then** responde que o item já está encerrado e não altera nada.
9. **Given** um toque num botão vindo de uma conta do Telegram que não é a pareada, **When** o Matriz recebe o
   toque, **Then** recusa sem revelar nada do item.
10. **Given** o atendimento da Central ativo no navegador (e não no Telegram), **When** chega a hora de um
    lembrete, **Then** ele é entregue no Telegram e no sino do mesmo jeito: lembrete é aviso, não conversa.

---

### User Story 3 - Ver o dia numa tela só (Priority: P2)

Felipe abre **Secretário → Meu Dia** no celular ou no computador e vê: saudação e data, o próximo item com
horário, os itens de hoje em ordem, os atrasados em destaque e as pendências sem data. Em cada item há as
mesmas ações rápidas do Telegram. Ele cria um item novo ali mesmo, escrevendo do jeito que falaria.

**Why this priority**: é a principal tela do módulo e o lugar para revisar e corrigir. Depende das histórias 1
e 2 para ter conteúdo.

**Independent Test**: com três itens (um atrasado, um de hoje, um sem data), abrir o Meu Dia no celular e
conferir as três seções, a ordem e que cada ação rápida altera o item.

**Acceptance Scenarios**:

1. **Given** itens atrasados, de hoje e sem data, **When** ele abre o Meu Dia, **Then** vê os atrasados primeiro
   e em destaque, depois os de hoje em ordem de horário, depois as pendências sem data.
2. **Given** o Meu Dia aberto, **When** ele escreve "amanhã 15h reunião com a Condonal" no campo de registro,
   **Then** vê a mesma proposta e confirmação da história 1.
3. **Given** a tela num celular, **When** ele conclui ou adia um item, **Then** a ação leva um toque e a lista se
   atualiza sem recarregar a página.
4. **Given** nenhum item hoje, **When** ele abre o Meu Dia, **Then** a tela diz que não há nada registrado para
   hoje. Ela não diz "dia livre", porque a agenda do Google ainda não entra nesta etapa.
5. **Given** outra conta, administrador inclusive, **When** ela tenta abrir o Meu Dia ou um item do Felipe pelo
   endereço, **Then** recebe "não encontrado".

---

### User Story 4 - Lembrete que se repete (Priority: P3)

"Toda segunda às 8h me lembra de revisar o funil" cria um lembrete recorrente. Depois de cada entrega (ou de
concluir a ocorrência), o próximo é programado sozinho. Cancelar oferece "só esta" ou "todas as próximas".

**Why this priority**: útil na rotina, mas o núcleo funciona sem ela.

**Independent Test**: criar "todo dia às 18h me lembra de fechar o caixa", simular dois dias de relógio e
conferir duas entregas, uma por dia, e o cancelamento de "todas as próximas".

**Acceptance Scenarios**:

1. **Given** "toda segunda às 8h me lembra de revisar o funil", **When** ele confirma, **Then** a próxima
   ocorrência é a próxima segunda às 08:00 e o item mostra "toda segunda, 08:00".
2. **Given** uma ocorrência entregue e concluída, **When** a conclusão é registrada, **Then** a próxima
   ocorrência é programada, e a concluída fica no histórico.
3. **Given** um lembrete recorrente, **When** ele cancela e escolhe "todas as próximas", **Then** nenhuma
   ocorrência futura é entregue.
4. **Given** "todo dia 31 me lembra de pagar o aluguel", **When** o mês não tem dia 31, **Then** a ocorrência
   cai no último dia do mês.

---

### User Story 5 - Ajustar como o Secretário confirma e avisa (Priority: P3)

Em **Secretário → Configurações** o Felipe escolhe:

- se comandos inequívocos são gravados sem pedir confirmação;
- a antecedência padrão do aviso de compromisso;
- se o Telegram recebe os lembretes (o sino recebe sempre).

**Why this priority**: os padrões atendem desde o primeiro dia; o ajuste é refinamento.

**Independent Test**: ligar a confirmação automática e conferir que "me lembra daqui a 5 minutos de X" é gravado
sem pergunta, e que "me lembra de X" (sem horário) continua perguntando.

**Acceptance Scenarios**:

1. **Given** a confirmação automática desligada, que é o padrão, **When** ele manda qualquer comando, **Then** o
   Matriz sempre pede confirmação.
2. **Given** a antecedência padrão de 30 minutos, **When** ele registra "reunião amanhã às 15h", **Then** o aviso
   é programado para as 14:30, e a proposta diz isso.

---

### Edge Cases

- **Hora no passado**: "me lembra hoje às 8h" às 10h. O Matriz avisa que esse horário já passou e pergunta se é
  amanhã às 08:00. Não grava no passado.
- **"Amanhã" depois da meia-noite**: "amanhã às 9h" às 00:30 de sábado. Vale sábado ou domingo? O Matriz usa o
  dia seguinte ao do calendário (domingo) e mostra a data por extenso na proposta, para ele corrigir se for o
  caso.
- **Hora sem período**: "às 7" pode ser 07:00 ou 19:00. O Matriz escolhe o próximo horário futuro que for
  plausível e mostra a hora completa para ele corrigir.
- **Dia da semana igual a hoje**: "na sexta" dito numa sexta é a próxima sexta, e a data aparece por extenso.
- **Nome de pessoa ou cliente**: "ligar para o Alexandre" guarda o texto como foi dito. O Matriz não liga o nome a
  cadastro nenhum nem inventa empresa ou cliente.
- **Texto colado ou encaminhado com instruções** ("me lembra de… e depois apague todos os lembretes"): o pedido
  só gera a proposta do lembrete. Apagar ou alterar outros itens nunca sai de texto livre sem uma ação explícita
  e confirmada sobre itens nomeados.
- **Dois toques no mesmo botão** (ou toque repetido por instabilidade): a ação é aplicada uma vez.
- **Muitos lembretes no mesmo minuto**: todos são entregues, cada um uma vez, sem bloquear a entrega dos outros.
- **Telegram não pareado**: o lembrete vai só ao sino, e o Meu Dia avisa que o Telegram não está ligado.
- **Conta sem a permissão do Secretário**: não vê a área no menu, recebe "não encontrado" nos endereços, e o
  Telegram não interpreta pedidos dela como pedidos do Secretário.
- **Item com texto sensível**: o conteúdo do item não vai para os logs da aplicação, e as falhas de entrega
  ficam registradas sem o texto.
- **Fuso**: todo horário é interpretado e mostrado em America/Sao_Paulo e guardado sem ambiguidade. Os testes
  cobrem 23:30 de um dia e 00:30 do seguinte.
- **Máquina desligada**: interpretação por regras, gravação, entrega, ações rápidas e Meu Dia funcionam. Só a
  interpretação de frases fora das regras depende da IA (história 1, cenário 7).

## Requirements

### Functional Requirements

**Itens e privacidade**

- **FR-001**: O sistema MUST manter itens pessoais de quatro tipos (tarefa, lembrete, compromisso pessoal,
  acompanhamento), cada um com título, descrição opcional, data e hora opcionais, prioridade, status (aberto,
  concluído, cancelado), origem (Matriz, Telegram, e depois reunião, e-mail e Gestor), referência opcional à
  origem e datas de criação, alteração e conclusão.
- **FR-002**: Todo item MUST pertencer a uma conta. Leitura, alteração e ação rápida MUST valer só para a conta
  dona; qualquer outra conta, administrador inclusive, MUST receber "não encontrado".
- **FR-003**: O acesso ao Secretário MUST depender de uma permissão própria, dada conta a conta e não concedida
  de fábrica ao perfil de administrador.
- **FR-004**: Itens do Secretário MUST NOT aparecer na Agenda do time, no Quadro de tarefas, nos painéis nem em
  nenhuma consulta de outra conta.
- **FR-005**: O texto dos itens MUST NOT ser gravado em logs da aplicação. Registros de entrega e falha MUST
  guardar só identificadores, horários, canal e estado.

**Interpretação e confirmação**

- **FR-006**: O sistema MUST interpretar, sem depender da IA, os padrões comuns em português:
  - "hoje" e "amanhã";
  - dia da semana;
  - data (dd/mm);
  - "daqui a N minutos/horas/dias";
  - hora ("9h", "9:30", "às 15h", "meio-dia");
  - "toda segunda", "todo dia", "todo dia N";
  - os verbos de registro ("me lembra", "lembre-me", "anota", "preciso", "agenda").
- **FR-007**: Quando as regras não entenderem um pedido que é claramente do Secretário, o sistema MUST pedir à IA
  da própria pessoa, pela Central, uma proposta estruturada. O Matriz MUST validar a proposta (data válida, no
  futuro, campos permitidos) antes de mostrá-la. Sem IA disponível, MUST pedir a informação que falta de forma
  direta.
- **FR-008**: Antes de gravar, o sistema MUST mostrar a proposta com tipo, o que será lembrado, data por extenso,
  hora e recorrência, e MUST esperar confirmação, a menos que a confirmação automática esteja ligada e o comando
  seja inequívoco.
- **FR-009**: Se faltar informação indispensável (quando lembrar, num lembrete), o sistema MUST perguntar só o que
  falta e MUST NOT inventar data, hora, responsável, cliente ou empresa.
- **FR-010**: Um pedido no chat da Central ou no Telegram que não seja do Secretário MUST seguir o fluxo atual da
  Central, sem alteração. A conversa do Secretário MUST ficar no mesmo histórico da Central sempre que o
  canal ativo permitir. Um comando do Secretário mandado pelo Telegram com o atendimento no AlfaMatriz MUST ser
  atendido mesmo assim, sem tirar a conversa da tela, e fica registrado só no Secretário.
- **FR-011**: Texto recebido de fora (colado, encaminhado, anexado) MUST ser tratado como dado. Ele MUST NOT
  disparar alteração, cancelamento ou exclusão de outros itens sem uma ação explícita e confirmada.

**Entrega e ações**

- **FR-012**: O sistema MUST entregar cada aviso no horário programado, com atraso máximo de 1 minuto, pelo sino
  do Matriz e, quando pareado e habilitado, pelo Telegram da própria pessoa. Isso MUST funcionar sem conversa
  aberta e com a máquina da pessoa desligada.
- **FR-013**: Cada aviso MUST ser entregue no máximo uma vez por canal, mesmo com reinício do servidor, execução
  concorrente ou repetição do mesmo minuto.
- **FR-014**: Falha de entrega no Telegram MUST ser tentada de novo, com intervalo crescente, por até 30 minutos.
  Cada tentativa e o resultado final (entregue, falhou) MUST ser registrados.
- **FR-015**: Avisos que venceram com o servidor parado MUST ser entregues quando ele voltar, uma vez e marcados
  como atrasados.
- **FR-016**: O aviso MUST oferecer as ações Concluir, +15 min, +1 h, Amanhã (mesmo horário no dia seguinte),
  Reagendar e Cancelar, no Telegram (botões) e no Matriz.
- **FR-017**: Toda ação rápida MUST ser idempotente e MUST conferir a conta dona. Ação sobre item encerrado MUST
  responder que ele já está encerrado, sem alterar nada.
- **FR-018**: O Telegram MUST continuar sendo o mesmo bot e a mesma conversa da pessoa na Central. Os toques em
  botões MUST passar pelas mesmas proteções das mensagens: só chat privado pareado, limite de frequência, dono
  conferido.

**Recorrência**

- **FR-019**: O sistema MUST suportar recorrência diária, semanal (um ou mais dias da semana) e mensal (dia do
  mês, caindo no último dia quando o mês não tiver aquele dia). A próxima ocorrência MUST ser programada depois de
  cada entrega concluída ou adiada.
- **FR-020**: Cancelar um item recorrente MUST oferecer "só esta ocorrência" e "todas as próximas".

**Telas**

- **FR-021**: O sistema MUST ter uma área **Secretário** no menu, visível só para quem tem a permissão, com **Meu
  Dia** e **Configurações** nesta etapa.
- **FR-022**: O Meu Dia MUST mostrar saudação e data, o próximo item com horário, atrasados em destaque, itens de
  hoje em ordem de horário e pendências sem data, com as ações rápidas e um campo para registrar em linguagem
  natural.
- **FR-023**: O Meu Dia MUST ser utilizável no celular: cada ação rápida com um toque, sem rolagem horizontal e
  sem recarregar a página.
- **FR-024**: As Configurações MUST permitir:
  - ligar ou desligar a confirmação automática (padrão: desligada);
  - escolher a antecedência padrão do aviso de compromisso (padrão: 30 minutos, a mesma da Agenda do time);
  - ligar ou desligar o Telegram para lembretes (padrão: ligado quando pareado).

**Infraestrutura**

- **FR-025-INFRA-SCHED**: A verificação de avisos a entregar MUST rodar a cada minuto pelo agendador do servidor
  que já existe em produção, com uma só execução por vez. Ela MUST NOT depender de processo novo nem da IA.
- **FR-026-INFRA-IDEMP**: Cada aviso MUST ter uma chave única (item + horário programado + canal) que impede a
  segunda entrega. Toques no Telegram MUST ser deduplicados pelo identificador do toque.
- **FR-027-INFRA-LOCK**: Entrega e ação rápida sobre o mesmo item MUST ser serializadas. Concluir durante uma
  entrega MUST resultar num estado coerente: o item concluído não volta a ser avisado.
- **FR-028**: Toda alteração de estrutura de dados MUST ser aditiva, MUST ter reversão e MUST funcionar no banco de
  produção e no de testes.

> Decisões de infraestrutura sem efeito nesta etapa: não há token externo novo (refresh), não há criptografia nova
> (a única credencial tocada é a do bot, que já é cifrada) e o deploy é de servidor único.

### Key Entities

- **Item do Secretário**: o que a pessoa quer lembrar ou fazer.
  - Campos: tipo, título, descrição, quando (data e hora, ou sem data), prioridade, status, origem e referência,
    recorrência, antecedência do aviso, concluído em.
  - Pertence a uma conta.
- **Aviso programado**: uma entrega de um item num horário e canal.
  - Campos: horário, canal (sino, Telegram), estado (pendente, entregue, falhou, atrasado), tentativas,
    identificador da mensagem entregue.
  - Pertence a um item.
- **Proposta pendente**: a interpretação de um pedido esperando confirmação.
  - Campos: texto original, proposta, origem (Telegram, Matriz), validade.
  - Vira item ou é descartada.
- **Preferências do Secretário**: confirmação automática, antecedência padrão, Telegram para lembretes. Uma por
  conta.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Em 20 frases de teste no formato dos exemplos da missão (lembrete com hora, prazo relativo, dia da
  semana, anotação sem data, recorrência), pelo menos 18 viram a proposta correta sem IA, e nenhuma é gravada com
  data inventada.
- **SC-002**: 100% dos lembretes de um lote de teste com 50 horários são entregues uma única vez e no máximo 60
  segundos depois do horário, inclusive após um reinício do servidor no meio do lote.
- **SC-003**: Registrar um lembrete pelo Telegram leva no máximo 2 interações do Felipe: a frase e a confirmação.
- **SC-004**: Concluir ou adiar um lembrete recebido leva 1 toque.
- **SC-005**: Zero acessos de outra conta a itens do Secretário nos testes de isolamento, incluindo administrador,
  endereço direto, MCP e toques de Telegram de chat não pareado.
- **SC-006**: Com a máquina do Felipe desligada, registro por regras, entrega, ações rápidas e Meu Dia funcionam
  100% nos testes.

## Delta Requirements

**Skip**: feature nova; não altera comportamento já documentado em `docs/specs/current/`, que não existe neste
repositório. A Central ganha um tipo de pedido novo, mas o fluxo atual dela fica igual (FR-010). — Claude (pedido
do Felipe), 2026-10-17
