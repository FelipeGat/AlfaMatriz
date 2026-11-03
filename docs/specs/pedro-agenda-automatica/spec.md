# Feature Specification: O Pedro comunica a agenda aos técnicos e aos gestores pelo WhatsApp

**Feature**: `pedro-agenda-automatica`
**Created**: 2026-11-02
**Status**: Clarified

## Contexto

Pedido do dono em 02/11/2026, no documento "PEDRO IA | Comunicação automática de agendas — Script
de implementação para Claude": o Gestor continua sendo a fonte oficial da agenda; o Pedro confere a
programação em horários fixos e avisa pelo WhatsApp, sem ninguém pedir:

- cada técnico, na véspera, com a programação dele e o link do Portal do Funcionário
  (`https://gestor.alfa.solucoesgrupo.com/portal-funcionario`);
- cada técnico, quando algo da programação dele muda depois do aviso;
- os gestores (Felipe e Wallas), às 17:00, com um relatório consolidado do dia seguinte.

O Portal do Funcionário e o acesso dos técnicos já funcionam e estão FORA do escopo de correção e
de teste: a mensagem só leva o link.

**O que já existe e é reaproveitado** (conferido no código em 02/11/2026):

- **Envio pelo número do Pedro.** O Pedro fala pelo (27) 4042-4157, que é do Gestor: o Matriz pede o
  envio ao Gestor, e o token da Meta fica só lá (`docs/specs/suporte-pedro-whatsapp`,
  `whatsapp-pedro` no Gestor). Hoje o Matriz só consegue enviar DENTRO de uma conversa já aberta e
  dentro da janela de 24 h: fora dela o Gestor recusa (`janela_fechada`).
- **Mensagem iniciada pela empresa.** O Gestor já envia modelo aprovado pela Meta fora da janela:
  pela tela (atendente) e pela rotina do lembrete de cobrança no dia do vencimento, que marca o que
  já enviou para não repetir. O próprio Gestor registra que mensagem iniciada pela empresa é paga e
  exige modelo aprovado.
- **Situação de cada mensagem.** O Gestor já recebe da Meta e grava, por mensagem, enviada,
  entregue, lida e falha.
- **A agenda.** As visitas do Gestor têm técnico responsável, técnicos adicionais (com horário
  individual quando registrado), início, fim, situação (agendado, em atendimento, finalizado,
  cancelado) e o atendimento. O Gestor já percebe, no momento da gravação, quando uma visita muda de
  horário, de técnico ou de situação (é o que hoje atualiza a Google Agenda).
- **Quem é quem.** O Pedro já liga um número a um funcionário e ao usuário dele no Gestor pelo
  celular do cadastro de funcionário (v2026.11.02.3), e a lista de acesso interno ("lista da casa")
  reconhece quem é da casa.
- **Agendador.** Os dois sistemas já têm rotinas agendadas no fuso America/Sao_Paulo.

**O que NÃO existe hoje:**

- Nenhuma mensagem sai do Pedro sem alguém ter escrito antes. A spec
  `pedro-inteligencia-operacional` deixou isso fora de escopo justamente pela janela de 24 h; esta
  feature resolve com modelo aprovado.
- Nenhum modelo de agenda aprovado na Meta. Leitura só leitura em 02/11/2026: a conta tem 2 modelos
  aprovados, `cobranca_vencimento_dia` e `hello_world`.
- Registro do que já foi comunicado a cada pessoa, para mandar só a diferença.

**Os números do documento contra o Gestor de produção** (leitura só leitura, 02/11/2026; telefone
só pelo final):

| Pessoa (documento) | Final | Cadastro de funcionário no Gestor | Situação |
|---|---|---|---|
| Cristiano (técnico) | 0122 | #5 Cristiano Lopes Lucas, celular final 5943 | número diferente do cadastro |
| Alefe (técnico) | 6889 | #21 Alefe Santos da Conceição, sem celular | sem número no cadastro; o final 6889 é o do plantão divulgado pelo Pedro |
| Vitor (técnico) | 1951 | #9 Vitor Emanuel Oliveira de Paula, celular final 2012 | número diferente do cadastro |
| Alexandre (técnico) | 3105 | #19 Alexandre Blank Lopes, celular final 3105 | bate |
| Cleyson (técnico) | 8977 | #12 Cleison Carlos Langa, celular final 8977 | bate |
| Felipe (gestor) | 3269 | #13 Felipe Henrique Gat, celular final 5397 | número diferente do cadastro; o 3269 é o número de teste "cliente novo" (decisão de 31/10) |
| Wallas (gestor) | 8265 | #7 Wallas Lovatti da Penha, sem celular | sem número no cadastro; o 8265 está na lista da casa |

Nenhum dos cinco técnicos escreveu ao número do Pedro nas últimas 24 h; o Alexandre escreveu pela
última vez em 30/10. Ou seja: hoje, toda mensagem a técnico seria FORA da janela e precisaria de
modelo aprovado. Agenda de 03/11 no Gestor, na mesma leitura: 7 visitas não canceladas, com 6
técnicos responsáveis distintos.

Decisão do dono em 02/11/2026 (FR-003): o número que vale é o celular do cadastro de funcionário do
Gestor. Antes da ativação, o cadastro de Cristiano, Vitor, Alefe e Wallas precisa ser corrigido no
Gestor; é tarefa de dado, fora do código, e esta feature MUST NOT gravar no cadastro de funcionário.
O Felipe já está certo no cadastro (final 5397). Corrigir o Wallas (final 8265, que já está na lista
da casa) também liga o celular dele ao usuário do Gestor, o vínculo que o Pedro usa desde a
v2026.11.02.3 para lançar agenda e criar orçamento em nome dele.

## Clarifications

### Session 2026-11-02

Respostas tiradas do documento do dono; onde ele não responde, o padrão adotado vai marcado
"(padrão)" e pode ser revisto no `/cstk:clarify`.

- Q: Quem recebe? → A: os técnicos e os gestores cadastrados como destinatários, ligados a um
  funcionário já existente no Gestor, sem criar funcionário nem usuário. Correspondência ambígua é
  confirmada por um administrador antes de valer.
- Q: Em que horários? → A: 06:30 (hoje, só mudanças ainda não avisadas), 16:30 (amanhã, programação
  individual dos técnicos) e 17:00 (amanhã, relatório dos gestores), configuráveis, no fuso
  America/Sao_Paulo, todos os dias: fim de semana e feriado dependem da agenda real, nunca de
  calendário presumido.
- Q: Mudança urgente depois da verificação? → A: avisada por evento, sem esperar a próxima
  verificação, quando mexe na programação de hoje ou de amanhã já comunicada.
- Q: O que vai na mensagem do técnico? → A: só onde (cliente), quando (horário), qual atendimento
  (nº) e o link do portal; nada financeiro nem confidencial; só os compromissos dele.
- Q: Quem não tem programação recebe algo? → A: não.
- Q: Pode ativar os disparos reais? → A: não sem autorização expressa do dono; a feature nasce
  desligada e é testada com números controlados.
- Q: Silêncio à noite? → A: (padrão) mudança feita entre 20:00 e a verificação das 06:30 não dispara
  aviso imediato; entra na verificação das 06:30. Configurável.
- Q: Quantas tentativas quando o envio falha? → A: (padrão) até 3, espaçadas; depois disso a falha
  vai ao relatório dos gestores como pendência.
- Q: De onde vem o número do destinatário (FR-003)? → A: do celular do cadastro de funcionário do
  Gestor, fonte única; a lista de destinatários guarda o funcionário e o papel, não o número. Os
  cadastros divergentes (Cristiano, Vitor, Alefe, Wallas) são corrigidos no Gestor.
- Q: Para qual número vai o relatório do Felipe (FR-004)? → A: 5397 (celular do cadastro, já na lista
  da casa como diretor); o 3269 continua número de teste "cliente novo" e nunca é destinatário.
- Q: Como tratar a resposta do técnico à mensagem automática (FR-021)? → A: resposta fixa do Pedro,
  sem IA ("esta é uma mensagem automática da agenda; fale com o seu gestor"), e aviso aos gestores;
  atender o técnico de verdade fica para o perfil técnico de `pedro-inteligencia-operacional`.

### Session 2026-11-02 (plano)

Os pontos que a spec deixou para o plano, decididos em `research.md` e confirmados pelo Felipe em
02/11/2026 ("Sim, as quatro e seguir para as tarefas").

- Q: Quem é o administrador autorizado (FR-005)? → A: só a conta dona do Pedro muda destinatários,
  consentimento, horários e modo; quem tem acesso ao Suporte só vê (Decision 16).
- Q: Por onde vai o aviso aos gestores (resposta de técnico e "parar")? → A: pelo WhatsApp, o mesmo
  canal do relatório; fora da janela de 24 h, pelo 4º modelo, `agenda_alerta` (Decision 15).
- Q: Quanto tempo o registro guarda número e texto (FR-024)? → A: 365 dias; depois ficam só tipo,
  dia, situação e contagens (Decision 20).
- Q: A programação ou o relatório perdidos que rodam depois das 20:00 esperam a manhã? → A: não; saem
  na hora, no mesmo dia (FR-028-INFRA-SCHED). O silêncio vale só para o aviso de mudança (Decision 11).

## User Scenarios & Testing

### User Story 1 - O técnico recebe a programação de amanhã (Priority: P1)

Às 16:30, cada técnico com visita no dia seguinte recebe pelo WhatsApp, do número do Pedro, uma
mensagem só com os compromissos dele, em ordem de horário, com cliente, horário e nº do atendimento,
e o link do Portal do Funcionário. Quem não tem visita amanhã não recebe nada.

**Why this priority**: É o núcleo do pedido e o que tira do gestor o trabalho de avisar um por um.
Sozinha, já entrega valor: a equipe sai de casa sabendo para onde vai.

**Independent Test**: Num ambiente de teste com agenda fictícia e números controlados, rodar a
verificação das 16:30 e conferir a mensagem de cada técnico, a ausência de mensagem para quem não
tem agenda e o registro de cada envio.

**Acceptance Scenarios**:

1. **Given** o Cristiano com duas visitas amanhã (09:00–10:00 Praia Branca nº 837 e 10:00–18:00
   Castanheiras nº 776, dados fictícios), **When** roda a verificação das 16:30, **Then** ele recebe
   uma mensagem com as duas, em ordem, com o link do portal e a assinatura do Pedro, e nenhuma
   informação de outro técnico.
2. **Given** um técnico com uma visita amanhã, **When** roda a verificação, **Then** a mensagem traz
   só essa visita, com cliente, horário e nº do atendimento.
3. **Given** um técnico sem nenhuma visita amanhã, **When** roda a verificação, **Then** nenhuma
   mensagem sai para ele.
4. **Given** dois técnicos na mesma visita (responsável e adicional), **When** roda a verificação,
   **Then** cada um recebe a própria mensagem com essa visita, e o adicional com o horário
   individual dele, quando houver.
5. **Given** a verificação das 16:30 já rodou e comunicou, **When** ela roda de novo sem nada ter
   mudado, **Then** nenhuma mensagem é reenviada.
6. **Given** um técnico com visita amanhã, mas sem número ligado ou desativado como destinatário,
   **When** roda a verificação, **Then** nada é enviado a ele e a falta aparece como pendência
   ("sem aviso: sem número") no relatório dos gestores.
7. **Given** o técnico fora da janela de 24 h do WhatsApp, **When** a programação sai, **Then** ela
   vai pelo modelo aprovado da Meta; **Given** o técnico dentro da janela, **Then** vai como mensagem
   livre, com o mesmo conteúdo.
8. **Given** a comunicação automática desligada ou em modo ensaio, **When** roda a verificação,
   **Then** nenhuma mensagem real sai; no ensaio, a mensagem que sairia fica registrada para
   conferência.
9. **Given** amanhã é sábado, domingo ou feriado e há visita marcada, **When** roda a verificação,
   **Then** o técnico recebe a programação normalmente; sem visita, nada sai.

---

### User Story 2 - O técnico fica sabendo do que mudou, sem receber tudo de novo (Priority: P2)

Depois de avisado, se a programação do técnico muda (visita nova, horário, cliente, troca de
técnico, cancelamento ou remarcação), ele recebe só a mudança. Na troca de responsável, o técnico
que saiu fica sabendo que não vai mais, e o que entrou recebe a visita. O cancelamento diz com
clareza que a visita não deve mais ser feita. Às 06:30, a verificação do dia avisa só o que mudou e
ainda não foi avisado.

**Why this priority**: Sem isso, a mensagem das 16:30 vira informação velha na primeira mudança e o
técnico vai ao lugar errado. Depende de a US1 ter registrado o que foi comunicado.

**Independent Test**: Com a programação de amanhã já comunicada, alterar a agenda de teste
(inclusão, horário, troca de técnico, cancelamento) e conferir que cada técnico afetado recebe uma
mensagem curta só com a mudança, e que nenhum outro recebe nada.

**Acceptance Scenarios**:

1. **Given** a programação do Vitor de amanhã já comunicada, **When** entra uma visita nova para ele
   (Leroy Merlin, nº 31, 08:00–18:00, dados fictícios), **Then** ele recebe "sua agenda de amanhã
   foi atualizada. Nova visita: …" com o link, e não a programação inteira.
2. **Given** uma visita já comunicada, **When** o horário muda, **Then** o técnico recebe o horário
   antigo e o novo daquela visita.
3. **Given** uma visita já comunicada, **When** ela é cancelada, **Then** o técnico recebe que a
   visita foi cancelada e não deve mais ser executada.
4. **Given** uma visita já comunicada ao Cristiano, **When** o responsável passa a ser o Alefe,
   **Then** o Cristiano recebe que não vai mais a ela e o Alefe recebe a visita.
5. **Given** uma visita remarcada de amanhã para outro dia, **When** a mudança é gravada, **Then** o
   técnico recebe a remarcação (data antiga e nova), sem programação inteira.
6. **Given** uma mudança na programação de hoje feita depois da verificação da tarde e antes das
   20:00, **When** ela é gravada no Gestor, **Then** o técnico recebe o aviso sem esperar a próxima
   verificação.
7. **Given** uma mudança feita às 22:00 na programação de amanhã, **When** chega a verificação das
   06:30, **Then** o técnico recebe a mudança nesse horário, e não às 22:00.
8. **Given** a verificação das 06:30 roda e nada mudou desde o último aviso, **When** ela termina,
   **Then** nenhuma mensagem sai.
9. **Given** a mesma mudança chega duas vezes (evento repetido ou verificação rodando junto),
   **When** as duas são processadas, **Then** o técnico recebe um aviso só.
10. **Given** uma mudança em campo que não aparece na mensagem (por exemplo, observação interna),
    **When** ela é gravada, **Then** nada é enviado.

---

### User Story 3 - Os gestores recebem às 17:00 o relatório do dia seguinte (Priority: P3)

Às 17:00, cada gestor destinatário recebe um único relatório da programação de amanhã, agrupado por
técnico, com cliente e horário, e uma linha de resumo com os totais reais de visitas, técnicos,
conflitos e pendências.

**Why this priority**: Dá ao gestor a visão do dia seguinte e as falhas de aviso, sem abrir o
Gestor. Independe das mensagens aos técnicos para ter valor, mas fica melhor com elas (pendências de
aviso).

**Independent Test**: Com a agenda fictícia de amanhã, rodar a verificação das 17:00 e conferir o
relatório: um por gestor, totais iguais aos da agenda, conflitos e pendências listados.

**Acceptance Scenarios**:

1. **Given** a agenda fictícia de amanhã com Cristiano em duas visitas, Alefe e Vitor juntos na VD
   Cariacica e Cleyson no Corais de Parati, **When** roda a verificação das 17:00, **Then** cada
   gestor recebe um relatório com uma linha por técnico (os que estão juntos na mesma visita numa
   linha só) e o resumo "X visitas | Y técnicos | Z conflitos | W pendências" com os números da
   agenda.
2. **Given** dois técnicos na mesma visita, **When** o relatório é montado, **Then** conta 1 visita
   e 2 técnicos: visita e participação nunca se confundem.
3. **Given** um técnico com duas visitas em horários que se sobrepõem, **When** o relatório é
   montado, **Then** o conflito aparece nomeado (técnico, horários e atendimentos).
4. **Given** uma visita sem técnico responsável, um técnico que não recebeu a programação (sem
   número, desativado ou falha de envio) ou um destinatário que pediu para parar, **When** o
   relatório é montado, **Then** cada um aparece como pendência nomeada.
5. **Given** o relatório já enviado, **When** a verificação das 17:00 roda de novo, **Then** nada é
   reenviado; **Given** a agenda mudou depois das 17:00, **Then** o relatório não é reenviado
   inteiro (a mudança vai aos técnicos pela US2).
6. **Given** amanhã sem nenhuma visita, **When** roda a verificação das 17:00, **Then** o gestor
   recebe uma linha só dizendo que não há programação para o dia.

---

### User Story 4 - O administrador controla quem recebe e acompanha o que saiu (Priority: P4)

Um administrador autorizado liga cada destinatário ao cadastro do Gestor, ativa e desativa a
comunicação, escolhe os horários, roda o ensaio e vê no AlfaMatriz o que foi enviado, a quem, quando,
em que situação (preparada, aceita pela Meta, entregue, lida, falha) e por quê falhou.

**Why this priority**: Governança e supervisão. A US1 funciona com a configuração inicial; esta
story dá autonomia para manter a lista e conferir os envios sem pedir a um desenvolvedor.

**Independent Test**: Cadastrar um destinatário de teste, rodar o ensaio, ativar para um número
controlado, provocar uma falha de envio e conferir a supervisão e o registro de auditoria.

**Acceptance Scenarios**:

1. **Given** um funcionário ativo com celular no cadastro, **When** o administrador o escolhe como
   destinatário, **Then** a tela mostra o nome do cadastro e o final do celular que vai receber, e
   nenhum número é digitado.
2. **Given** um funcionário sem celular no cadastro, ou com o mesmo celular de outro funcionário
   ativo, **When** o administrador o escolhe, **Then** ele fica como destinatário pendente ("sem
   número" ou "número compartilhado") e nada sai para ele até o cadastro ser corrigido no Gestor.
3. **Given** quem não é administrador autorizado, **When** tenta mudar destinatários, horários ou a
   ativação, **Then** é recusado.
4. **Given** qualquer mudança de destinatário, horário ou ativação, **When** ela é salva, **Then**
   fica registrado quem mudou, quando e o quê.
5. **Given** o envio recusado pela Meta, **When** o administrador abre a supervisão, **Then** vê o
   destinatário, o tipo da mensagem, as tentativas e o erro devolvido.
6. **Given** o modo ensaio, **When** roda qualquer verificação, **Then** a supervisão mostra o texto
   exato que sairia para cada pessoa, sem nada ter saído.
7. **Given** um técnico responde "parar" à mensagem automática, **When** a resposta chega, **Then**
   ele deixa de receber os avisos automáticos e o administrador é avisado.

---

### Edge Cases

- **Resposta do técnico à mensagem automática.** O número do técnico não está no cadastro de
  clientes: sem regra própria, o Pedro o trataria como cliente novo e, se o Pedro não responder no
  prazo, o Gestor mandaria o menu de reserva. A resposta MUST receber a resposta fixa do FR-021,
  nunca o tratamento de cliente.
- **Número de plantão no cadastro.** O final 6889, que o documento dá ao Alefe, é o número de
  plantão que o Pedro divulga aos clientes. Se ele for gravado como celular do Alefe no Gestor, a
  programação do Alefe chega a quem estiver com o aparelho do plantão, e quem escrever desse número
  recebe a resposta fixa do FR-021 em vez do atendimento de cliente. O número que entra no cadastro
  é decisão do dono, na correção do cadastro.
- **Mesmo funcionário em dois papéis** (técnico e gestor): cada papel recebe o seu, sem mensagem
  repetida do mesmo conteúdo.
- **Celular trocado no cadastro** depois de a programação sair: o aviso seguinte vai ao número novo,
  só com a diferença; a programação não é reenviada inteira por causa da troca.
- **Técnico que também é gestor**: recebe a programação dele às 16:30 e o relatório às 17:00.
- **Agenda lida com atraso.** Se a leitura da agenda que vale para a verificação estiver
  desatualizada ou indisponível, nada sai com dado velho; a verificação tenta de novo e, sem
  sucesso, a falha aparece na supervisão e no relatório dos gestores.
- **Visita sem horário de fim**: mostrada só com o início.
- **Visita "em atendimento" ou "finalizada" na véspera**: não entra como compromisso a fazer; visita
  cancelada nunca entra na programação, só no aviso de cancelamento de quem já a tinha recebido.
- **Muitas mudanças seguidas na mesma visita** em poucos minutos: o técnico recebe o estado final, e
  não uma mensagem por gravação.
- **Mudança desfeita antes do aviso** (horário mudou e voltou): nada sai.
- **Falha da Meta depois da 3ª tentativa**: a falha fica registrada e vai ao relatório; nada é
  reenviado sozinho no dia seguinte como se fosse novo.
- **Duas verificações rodando ao mesmo tempo** (repetição do agendador, reinício): uma só envia.
- **Número não cadastrado** que aparece como técnico da visita: pendência no relatório, sem envio.
- **Mensagem longa** (técnico com muitas visitas): a programação inteira chega, sem corte, dentro do
  limite da mensagem; o que não couber vai em mais de uma parte.

## Requirements

### Functional Requirements

**Destinatários**

- **FR-001**: O sistema MUST manter a lista de destinatários da comunicação automática, cada um com
  papel (técnico ou gestor), funcionário do Gestor ligado e situação (ativo ou inativo),
  administrável por configuração, sem número fixo no código. A lista MUST NOT guardar número próprio:
  o número vem do cadastro (FR-003).
- **FR-002**: Cada destinatário MUST estar ligado a um funcionário já existente e ativo no Gestor,
  escolhido pelo administrador; o sistema MUST NOT criar nem alterar funcionário ou usuário. Ao
  escolher, o administrador vê o nome do cadastro e o final do celular que vale para o envio.
- **FR-003**: O número para o qual a mensagem sai MUST ser o celular do cadastro de funcionário do
  Gestor do funcionário ligado, lido no momento de cada verificação ou aviso. Funcionário sem
  celular, ou com celular igual ao de outro funcionário ativo, MUST NOT receber nada: vira pendência
  nomeada ("sem número" ou "número compartilhado") no relatório e na supervisão, até o cadastro ser
  corrigido no Gestor. Celular que muda no cadastro passa a valer no envio seguinte; o que já foi
  comunicado e o "parar" continuam presos ao destinatário, e não ao número.
- **FR-004**: O relatório do Felipe MUST sair para o celular do cadastro dele (final 5397). O 3269,
  número de teste tratado como cliente novo desde 31/10/2026, MUST NOT ser destinatário.
- **FR-005**: Somente administradores autorizados MUST poder gerenciar destinatários, horários e a
  ativação; toda mudança MUST ficar registrada com quem, quando e o quê.
- **FR-006**: O consentimento de cada destinatário MUST ficar registrado (quem registrou e quando)
  antes do primeiro envio; o destinatário que responde "parar" MUST deixar de receber os avisos
  automáticos, e o administrador MUST ser avisado.

**Programação do técnico**

- **FR-007**: Na verificação da tarde (16:30), o sistema MUST enviar a cada técnico destinatário
  ativo com visita no dia seguinte uma mensagem só com os compromissos dele, em ordem de horário, com
  cliente, horário e nº do atendimento, o link do Portal do Funcionário e a assinatura "Pedro — Grupo
  Soluções".
- **FR-008**: O técnico sem visita no dia MUST NOT receber mensagem.
- **FR-009**: Entram na programação as visitas agendadas do dia em que o técnico é responsável ou
  adicional; para o adicional, vale o horário individual dele quando a visita o registra. Visitas
  canceladas, finalizadas ou em atendimento MUST NOT entrar como compromisso a fazer.
- **FR-010**: A mensagem do técnico MUST NOT trazer dado financeiro, observação interna, telefone de
  cliente nem compromisso de outro técnico.
- **FR-011**: O link do portal MUST ser o endereço oficial, sem credencial nem token.

**Mudanças**

- **FR-012**: O sistema MUST guardar, por destinatário e por dia, a última versão da programação
  efetivamente comunicada, e comparar a agenda atual com ela para detectar: visita nova, mudança de
  horário, de cliente ou de técnico, cancelamento e remarcação.
- **FR-013**: Programação já comunicada que muda MUST gerar ao técnico afetado uma mensagem só com a
  mudança, e não a programação inteira; cancelamento MUST dizer que a visita não deve mais ser
  executada; troca de técnico MUST avisar quem saiu e quem entrou; remarcação MUST dizer a data
  antiga e a nova.
- **FR-014**: Na verificação da manhã (06:30), o sistema MUST avisar só as mudanças da programação de
  hoje ainda não comunicadas; sem mudança, nada sai.
- **FR-015**: Mudança na programação de hoje ou de amanhã já comunicada MUST ser avisada por evento,
  sem esperar a próxima verificação, fora do período de silêncio (padrão 20:00 às 06:30), em que ela
  aguarda a verificação das 06:30. Várias gravações seguidas da mesma visita MUST virar um aviso só,
  com o estado final; mudança desfeita antes do aviso MUST NOT gerar mensagem.
- **FR-016**: Mudança em informação que não aparece na mensagem MUST NOT gerar aviso.

**Relatório dos gestores**

- **FR-017**: Na verificação das 17:00, o sistema MUST enviar a cada gestor destinatário ativo um
  único relatório do dia seguinte, agrupado por técnico (técnicos juntos na mesma visita numa linha
  só), com cliente e horário, e o resumo de visitas, técnicos, conflitos e pendências com os totais
  reais da agenda.
- **FR-018**: O relatório MUST contar visita e participação separadamente: uma visita com dois
  técnicos é 1 visita e 2 técnicos.
- **FR-019**: Conflito MUST ser o mesmo técnico em duas visitas com horários sobrepostos; pendência
  MUST ser visita sem técnico responsável, técnico com visita que não recebeu a programação (sem
  número, número compartilhado, inativo, pediu para parar ou falha de envio) e falha de leitura da
  agenda. Cada conflito e
  pendência MUST aparecer nomeado.
- **FR-020**: Dia seguinte sem visitas MUST gerar ao gestor uma linha só dizendo isso; o relatório
  MUST NOT ser reenviado inteiro por mudança posterior.

**Canal e conversa**

- **FR-021**: Mensagem que chega do número de um destinatário técnico ativo que não está na lista da
  casa MUST NOT ser tratada como mensagem de cliente nem receber o menu de reserva do Gestor. O Pedro
  MUST responder com uma resposta fixa, sem IA ("esta é uma mensagem automática da agenda; fale com
  o seu gestor"), no mesmo ritmo do aviso fixo ao cliente fora do horário (uma resposta por
  sequência de mensagens, no máximo uma a cada 2 min, a tempo de o Gestor não mandar o menu), e MUST
  avisar os gestores destinatários com quem escreveu e o texto, um aviso por sequência. "Parar"
  segue o FR-006. Destinatário que está na lista da casa (os gestores, hoje) continua com o
  atendimento interno do Pedro, como já é.
- **FR-022**: Destinatário dentro da janela de 24 h do WhatsApp MUST receber mensagem livre; fora
  dela, MUST receber pelo modelo aprovado pela Meta para aquele tipo de mensagem, com o mesmo
  conteúdo essencial (cliente, horário, nº do atendimento e link). Sem modelo aprovado, nada sai
  fora da janela e a falta aparece na supervisão.
- **FR-023**: As mensagens automáticas MUST ficar separadas do atendimento a clientes: não abrem
  atendimento, não entram na fila do suporte e não mudam a situação de conversa de cliente.

**Controle, rastro e entrega**

- **FR-024**: Cada envio MUST registrar: visita(s) envolvidas, destinatário, número (na supervisão,
  só o final), tipo (programação, mudança, relatório), momento, versão da agenda comunicada, situação
  (preparada, aceita pela Meta, entregue, lida, falha), identificador da mensagem e erro.
- **FR-025**: A comunicação automática MUST nascer desligada; ligar e desligar MUST ser ação do
  administrador autorizado; o modo ensaio MUST produzir e registrar o texto exato que sairia, sem
  enviar.
- **FR-026**: Falha de envio MUST ter até 3 tentativas espaçadas; depois disso, MUST ficar como falha
  na supervisão e como pendência no relatório.
- **FR-027**: O envio MUST NOT depender de a máquina que roda as IAs da Central estar ligada: as
  verificações e os avisos acontecem mesmo com ela desligada.
- **FR-028**: O AlfaMatriz MUST mostrar a supervisão: o que saiu, para quem, quando, em que situação,
  as falhas com o motivo, e o ensaio.
- **FR-028-INFRA-SCHED**: As verificações de 06:30, 16:30 e 17:00 MUST rodar pelo agendador que já
  existe, no fuso America/Sao_Paulo, com horários configuráveis, todos os dias; uma verificação
  perdida (sistema fora do ar) MUST rodar ao voltar, no mesmo dia, sem duplicar.
- **FR-029-INFRA-IDEMP**: A chave de idempotência de cada aviso MUST ser destinatário + dia da agenda
  + tipo + versão da agenda comunicada: a mesma versão nunca é enviada duas vezes ao mesmo
  destinatário, nem por reexecução, nem por evento repetido.
- **FR-030-INFRA-LOCK**: Verificações e avisos por evento para o mesmo destinatário e dia MUST ser
  serializados: duas execuções ao mesmo tempo produzem um envio só.

### Key Entities

- **Destinatário da agenda**: pessoa que recebe a comunicação automática. Papel (técnico ou gestor),
  funcionário do Gestor ligado (o número é o celular do cadastro dele, lido a cada envio), situação,
  consentimento, quem ligou e quando.
- **Versão comunicada**: o retrato da programação de um destinatário para um dia, como foi enviado
  por último; base para detectar mudanças.
- **Aviso de agenda**: cada mensagem preparada ou enviada. Tipo, destinatário, dia da agenda, versão,
  visitas envolvidas, situação, tentativas, identificador da mensagem, erro.
- **Configuração da comunicação**: ligada, desligada ou ensaio; horários das verificações; período
  de silêncio; quem mudou e quando.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Em 5 dias úteis seguidos de uso, 100% dos técnicos destinatários com visita no dia
  seguinte recebem a programação até 16:45, ou aparecem como pendência no relatório das 17:00.
- **SC-002**: Zero mensagem duplicada (mesma versão para o mesmo destinatário) em 5 dias de uso,
  incluindo reexecuções e reinícios.
- **SC-003**: Mudança na programação de hoje ou de amanhã já comunicada, fora do período de
  silêncio, chega ao técnico em até 10 minutos depois de gravada no Gestor.
- **SC-004**: Os totais do relatório (visitas, técnicos, conflitos, pendências) batem 100% com a
  agenda do Gestor do mesmo momento, conferidos em 5 relatórios.
- **SC-005**: Nenhuma mensagem automática contém dado financeiro, observação interna ou compromisso
  de outro técnico, conferido em todas as mensagens do ensaio.
- **SC-006**: Zero mensagem real enviada antes da ativação autorizada pelo dono; os testes usam só
  números controlados.
- **SC-007**: Nenhuma resposta de técnico a mensagem automática recebe o menu de reserva do Gestor
  ou é tratada como cliente; toda sequência de mensagens dele recebe a resposta fixa e gera um aviso
  aos gestores.

## Fora de escopo

- Acesso, login, autenticação e visualização da agenda pelo Portal do Funcionário (já funcionam).
- Lançar, corrigir ou cancelar visita pela conversa: já existe em `pedro-dia-do-tecnico`.
- Mensagem a cliente sobre a visita.
- Grupos de WhatsApp.
- Confirmação de presença ("vou", "não vou") pelo técnico, com efeito na agenda.

## Delta Requirements

**Skip**: feature nova; o repositório não tem corpus canônico em `docs/specs/current/` para alterar —
Claude, 2026-11-02
