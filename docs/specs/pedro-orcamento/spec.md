# Feature Specification: O Pedro cria orçamento no Gestor pela conversa

**Feature**: `pedro-orcamento`
**Created**: 2026-11-02
**Status**: Clarified

## Contexto

Pedido do dono em 02/11/2026, no documento "Pedro IA | Criação de Orçamentos no Gestor": Felipe ou
Wallas, proprietários do Grupo Soluções, pedem ao Pedro pelo WhatsApp, por texto ou áudio, que monte
um orçamento ("faz um orçamento para o Praia Branca pela Invest, com nota, de manutenção no motor do
portão, conforme o atendimento X"), sem abrir a tela de orçamentos do Gestor.

O que já existe e é reaproveitado (conferido no código em 02/11/2026):

- O Pedro reconhece quem é da casa pelo número, numa lista que só a conta dona edita
  (`docs/specs/suporte-pedro-whatsapp/acesso-interno.md`), e transcreve áudio na máquina.
- O Pedro já grava no Gestor depois de uma prévia e do "sim": atendimento (FR-A8) e agenda dos
  técnicos (`docs/specs/pedro-dia-do-tecnico`). É o mesmo caminho: prévia conferida pelo sistema,
  fila, máquina, Gestor, resultado na conversa.
- O retrato do Gestor que o Pedro lê traz clientes e pré-clientes, atendimentos e os orçamentos em
  aberto de cada cliente.
- O Gestor tem o módulo de orçamentos com número por empresa, itens de serviço e produto,
  descontos, NF com o percentual da empresa gravado quando o orçamento nasce, vendedor, vínculo
  com atendimento (só do mesmo cliente) e PDF. A regra completa da tela mora hoje dentro da própria
  tela, e não num serviço que outro caminho possa chamar.

O que o Pedro NÃO faz hoje: criar orçamento; e o Pedro está proibido de falar de preço com cliente
(`pedro-comunicacao`, FR-020). Esta feature é administrativa e interna: nada dela chega a cliente.

## Clarifications

### Session 2026-11-02

Respostas tiradas do documento do dono; onde ele não responde, o padrão adotado vai marcado
"(padrão)" e pode ser revisto.

- Q: Quem pode pedir orçamento? → A: só os proprietários autorizados, Felipe e Wallas, reconhecidos
  pelos mecanismos existentes, nunca pelo que a mensagem diz. (padrão) No sistema, isso é a linha da
  lista de acesso interno com o perfil de diretor E ligada ao usuário da pessoa no Gestor; o próprio
  Gestor confere, na hora de gravar, que esse usuário está ativo e pode criar orçamento pela tela.
  Isso segue a decisão do FR-012 da `pedro-inteligencia-operacional` (a lista reconhece; o vínculo
  com o usuário do Gestor autoriza).
- Q: Quem é o vendedor? → A: quem pede, automaticamente (o usuário do Gestor ligado à linha dele).
- Q: Cliente não encontrado? → A: perguntar como proceder; nunca criar cliente nem pré-cliente.
  Pré-cliente que já existe no funil vale, como na tela.
- Q: O valor que ele diz já inclui a NF? → A: (padrão) o valor de cada item é o valor do serviço ou
  do material, antes da NF, como na tela do Gestor; com NF, o Gestor acrescenta o imposto da
  empresa. A prévia mostra subtotal, NF e total. Se ele disser que o número é o total já com nota, o
  Pedro pergunta o valor sem nota em vez de converter sozinho.
- Q: Quantidade fracionada (2,5 m)? → A: (padrão) a tela do Gestor aceita quantidade inteira de 1 a
  9999; o Pedro pede um número inteiro e põe a medida na descrição do item.
- Q: Unidade do item? → A: o Gestor não tem campo de unidade no item; a unidade vai na descrição.
- Q: Em que situação o orçamento nasce? → A: "em elaboração", como na tela.
- Q: O que volta depois de criar? → A: número, empresa, total calculado pelo Gestor e os links da
  tela do orçamento e do PDF, que exigem login no Gestor (as permissões que já existem). Nada é
  enviado a cliente.

### Session 2026-11-02 (2) — ajustes do dono depois dos 7 cenários

O dono aprovou a lógica geral e pediu seis ajustes antes de publicar, preservando o que já funcionava
nos 7 cenários. Eles revisam dois padrões da sessão anterior (valor com nota e quantidade).

- Q: Valor já com NF? → A: (revisa o padrão anterior) o Pedro usa a regra do Gestor — NF =
  subtotal × percentual da empresa, arredondada no centavo, somada ao subtotal — e, quando dá para
  fazer a conta (empresa conhecida, percentual dela no retrato, orçamento com nota), calcula o valor
  sem nota e mostra a conta na prévia para ele confirmar. Escolhe o centavo que faz o total do Gestor
  bater com o valor dito; se nenhum bate, mostra a diferença. Sem como calcular, diz o que falta (a
  empresa, ou o valor sem nota quando o percentual não está no retrato). O total com nota de um
  orçamento de um item só também vale; com vários itens, ele pede o valor de cada um.
- Q: O "sim" só põe na fila? → A: o Pedro acompanha: diz que está criando e, sem ninguém perguntar,
  manda o número e o link (e o PDF) que o Gestor devolver; se falhar, diz o motivo. Qualquer falha
  pode ser repetida com "tentar de novo", que reenvia o MESMO pedido (a mesma chave): se ele já
  estiver no Gestor, volta o número, sem criar outro. Enquanto um orçamento confirmado está sem
  resposta certa do Gestor, uma prévia nova para o mesmo cliente fica travada até a repetição.
- Q: Atendimento relacionado? → A: só se sugere atendimento em aberto do MESMO assunto do orçamento
  (portão com portão, câmera com câmera), pela descrição e pelo assunto do atendimento; ser do mesmo
  cliente não basta. O atendimento citado por ele de outro assunto vira aviso, sem travar. Se ele
  disser que não é de nenhum, a pergunta não volta.
- Q: Quantidade não dita? → A: (revisa o padrão anterior) com um único item de serviço sem
  quantidade, a prévia sugere 1, marcada como sugerida, e a pergunta final diz que a confirmação
  inclui essa quantidade. Nos demais casos, pergunta.
- Q: Corrigir a prévia? → A: por mensagem simples ("muda o item 2 para 90", "é pela Delta", "sem
  nota", "a descrição é…"), o Pedro manda só o que mudou e o sistema aplica sobre a última prévia,
  sem perder o resto. Os itens são numerados na prévia para isso.
- Q: Perguntas repetidas? → A: o que falta sai agrupado (uma linha por tipo de falta), o aviso já
  dado na mesma prévia não se repete na correção, e o que já foi respondido não é perguntado de novo.

## User Scenarios & Testing

### User Story 1 - Proprietário cria orçamento pela conversa (Priority: P1)

O Felipe escreve (ou manda áudio) "Pedro, preciso de um orçamento de 3 controles de portão para o
cliente X, pela Delta, sem nota, R$ 80 cada". O Pedro acha o cliente, entende empresa, NF, itens e
valores, pergunta só o que falta e mostra a prévia: cliente, empresa, vendedor, NF, atendimento
relacionado, descrição, itens, subtotal, NF e total. Ao "sim", o orçamento nasce no Gestor e o Pedro
devolve o número e os links.

**Why this priority**: é o pedido inteiro; sem ele nada das outras histórias tem uso.

**Independent Test**: com um cliente fictício no retrato, mandar o pedido completo, receber a prévia,
responder "sim" e ver o orçamento na fila de gravação com os mesmos itens e valores da prévia; a
máquina, em ensaio, devolve número e total.

**Acceptance Scenarios**:

1. **Given** o Felipe, na lista como diretor e ligado ao usuário dele no Gestor, **When** pede um
   orçamento com cliente, empresa, NF, itens e valores, **Then** o Pedro responde com a prévia no
   formato combinado e termina com "Posso criar esse orçamento no Gestor?".
2. **Given** a prévia sem pendências, **When** ele responde "sim", **Then** o orçamento entra na fila
   de gravação uma vez, e a resposta diz que foi enviado ao Gestor e que o número chega em seguida.
3. **Given** o Gestor gravou, **When** o resultado chega, **Then** o Pedro manda número, empresa,
   total calculado pelo Gestor e os links da tela e do PDF.
4. **Given** o pedido veio por áudio, **When** a transcrição chega ao Pedro, **Then** o fluxo é o
   mesmo do texto; se a transcrição não deixa claro um dado, ele pergunta.
5. **Given** o pedido com vários serviços e materiais, **When** a prévia sai, **Then** cada item
   aparece com quantidade, tipo (serviço ou material), valor unitário e subtotal, e o total soma
   todos.
6. **Given** a empresa e o pedido com NF, **When** a prévia sai, **Then** ela mostra o percentual de
   NF da empresa como está no Gestor, o valor da NF e o total; sem NF, não há linha de NF.

---

### User Story 2 - Nada inventado, só o que falta é perguntado (Priority: P1)

O pedido natural vem incompleto ("faz um orçamento para o Praia Branca de manutenção no portão").
O Pedro pergunta o que falta — empresa, NF, itens, quantidades e valores — em poucas perguntas por
mensagem, e nunca completa por conta própria.

**Why this priority**: orçamento com valor ou cliente inventado vira proposta errada na mão do
cliente; é a regra do dono ("nunca inventar valores, quantidades, serviços, materiais ou
informações do cliente").

**Independent Test**: mandar pedidos com uma lacuna de cada vez e ver que nenhum gera prévia
confirmável; a pendência aparece na resposta.

**Acceptance Scenarios**:

1. **Given** um pedido sem valor, **When** o Pedro monta a prévia, **Then** ela lista "falta o valor
   de …" e não termina com a pergunta de criar; o "sim" a uma prévia com pendência não grava nada.
2. **Given** um pedido sem dizer se é com ou sem NF, **When** a prévia sai, **Then** ela aponta que
   falta dizer se é com ou sem nota.
3. **Given** um cliente que não está no Gestor, **When** ele pede o orçamento, **Then** o Pedro diz
   que não achou e pergunta como proceder, sem criar cliente nem pré-cliente.
4. **Given** um orçamento anterior do cliente com valor, **When** o Pedro monta a prévia, **Then** ele
   não reaproveita valor antigo: o valor vem de quem pede.
5. **Given** a quantidade "2,5", **When** a prévia sai, **Then** ela aponta que a quantidade precisa
   ser inteira e sugere pôr a medida na descrição.
6. **Given** o pedido com o valor "total com nota", a empresa e o percentual dela conhecidos,
   **When** o Pedro monta a prévia, **Then** ela mostra a conta (valor dito ÷ (1 + percentual)) e o
   valor sem nota que vai ao Gestor, com o total igual ao dito; sem empresa, pergunta a empresa
   (revisto em 02/11/2026, sessão 2).
7. **Given** um único item de serviço sem quantidade, **When** a prévia sai, **Then** ela sugere 1,
   marcada, e pergunta se pode criar com essa quantidade.

---

### User Story 3 - Orçamento ligado ao atendimento certo (Priority: P2)

"Conforme o atendimento 812": o Pedro confere que o atendimento existe e é do mesmo cliente, usa a
descrição dele na descrição e nas observações do orçamento, e confirma o vínculo na prévia. Sem
número, se o cliente tiver atendimentos em aberto, ele os lista e pergunta se há vínculo; sem
atendimento, segue sem vínculo.

**Why this priority**: o vínculo leva o orçamento à fila certa no Gestor e tira o atendimento da
fila de cobrança do comercial, como na tela; mas o orçamento sem vínculo continua válido.

**Independent Test**: com um cliente com dois atendimentos e outro cliente com um, pedir o
orçamento citando cada um deles e ver o vínculo aceito, recusado ou perguntado.

**Acceptance Scenarios**:

1. **Given** o atendimento citado é do cliente, **When** a prévia sai, **Then** ela mostra
   "Atendimento relacionado: nº …" e o orçamento é gravado ligado a ele.
2. **Given** o atendimento citado é de outro cliente, **When** a prévia sai, **Then** ela aponta que o
   atendimento é de outro cliente e não fica confirmável até ele corrigir ou tirar o vínculo.
3. **Given** o cliente tem atendimentos em aberto e o pedido não cita nenhum, **When** a prévia sai,
   **Then** ela avisa só os do mesmo assunto do orçamento; um de câmera não aparece num orçamento
   de controle de portão.
4. **Given** o cliente sem atendimento, **When** a prévia sai, **Then** ela segue sem vínculo, sem
   travar.
5. **Given** o atendimento citado não está no Gestor, **When** a prévia sai, **Then** ela aponta que
   não achou o número.
6. **Given** ele disse que o orçamento não é de nenhum atendimento, **When** a prévia sai de novo,
   **Then** o aviso dos abertos não volta.

---

### User Story 4 - Corrigir antes de criar (Priority: P2)

Depois da prévia, ele muda um valor, um item ou a NF. O Pedro manda a prévia de novo, e só a última
vale para o "sim".

**Why this priority**: o dono pediu explicitamente para corrigir itens e valores antes da conclusão.

**Independent Test**: prévia, correção do valor, nova prévia, "sim"; o que vai à fila é a segunda.

**Acceptance Scenarios**:

1. **Given** uma prévia, **When** ele diz "muda o valor para 500", **Then** sai uma prévia nova com o
   valor novo e o total recalculado, e todo o resto (descrição, nomes, observações) igual.
4. **Given** uma prévia, **When** ele diz "é pela Delta" ou "sem nota", **Then** só a empresa ou a NF
   mudam, e a conta é refeita com o percentual da empresa nova.
5. **Given** um orçamento já enviado, **When** ele pede uma mudança, **Then** o Pedro diz o número e
   que a mudança é na tela do Gestor.
2. **Given** duas prévias, **When** ele responde "sim", **Then** grava-se a última, e só ela.
3. **Given** uma prévia de mais de 3 horas, **When** ele responde "sim", **Then** nada é gravado e o
   Pedro pede para remontar.

---

### User Story 5 - Ninguém mais cria, e nada sai em dobro (Priority: P1)

Cliente, técnico, gestor, suporte, ou um diretor sem vínculo com o Gestor não criam orçamento,
mesmo pedindo e mesmo dizendo ser o Felipe. Repetir o "sim", a mensagem chegar duas vezes ou a
máquina cair no meio não gera dois orçamentos.

**Why this priority**: segurança e confiança no número que o Gestor mostra.

**Independent Test**: tentar pelos perfis que não podem e repetir o "sim" e a gravação; contar os
orçamentos.

**Acceptance Scenarios**:

1. **Given** um número fora da lista que diz ser o Felipe, **When** pede um orçamento, **Then** o
   Pedro não monta prévia nem grava nada.
2. **Given** alguém da lista com perfil gestor ou suporte, **When** pede um orçamento, **Then** o
   Pedro diz que criar orçamento é só para os proprietários, e o sistema recusa mesmo que a IA
   tente.
3. **Given** um diretor sem vínculo com o usuário do Gestor, **When** pede um orçamento, **Then** o
   Pedro diz o que falta (o vínculo na lista) e não grava.
4. **Given** a prévia confirmada, **When** ele manda "sim" de novo, **Then** nada novo entra na fila,
   e o Pedro diz em que pé está o orçamento já enviado.
5. **Given** a gravação foi feita no Gestor e a resposta se perdeu, **When** a máquina tenta de novo
   com a mesma confirmação, **Then** o Gestor devolve o orçamento que já existe, sem criar outro.
6. **Given** a máquina não pegou a gravação a tempo, **When** o prazo passa, **Then** o Pedro avisa
   que não criou e que o pedido pode ser refeito; se a máquina pegou e não respondeu, avisa para
   conferir no Gestor antes de criar à mão.
7. **Given** o Gestor recusa (usuário sem permissão, cliente inativo, falha ao salvar), **When** o
   resultado chega, **Then** o Pedro diz que não criou e o motivo, e nada fica gravado pela metade.
8. **Given** uma falha qualquer, **When** ele responde "tentar de novo", **Then** o mesmo pedido, com a
   mesma chave, volta à fila; se já estava no Gestor, o Pedro só traz o número.
9. **Given** um orçamento confirmado sem resposta certa do Gestor, **When** ele pede uma prévia nova
   para o mesmo cliente, **Then** ela não é confirmável até a repetição resolver.

---

### Edge Cases

- Dois clientes parecidos com o nome citado: o Pedro pergunta qual, mostrando cidade e se é cliente
  ou pré-cliente.
- Cliente com cadastro em mais de uma empresa do grupo: a empresa do orçamento é a que ele disse; se
  não disse, o Pedro pergunta.
- Empresa que não está entre as do Gestor ("pela Alfa" sem Alfa no Gestor): a prévia aponta.
- Item sem nome ou com valor zero ou negativo: a prévia aponta.
- Mais de 50 itens: a prévia aponta o limite da tela.
- Retrato do Gestor desatualizado: a conferência final é a do Gestor na hora de gravar.
- Percentual de NF que mudou entre a prévia e a gravação: vale o do Gestor na hora de gravar; o total
  devolvido é o do Gestor, e a mensagem final aponta a diferença se houver.
- A conversa foi assumida por um atendente enquanto o Pedro pensava: nada é enviado.
- Pedido de orçamento feito por um cliente: continua o fluxo comercial de hoje (passar ao comercial,
  sem preço); nada desta feature aparece.

## Requirements

### Functional Requirements

- **FR-001**: O sistema MUST permitir criar orçamento pelo Pedro somente a quem está na lista de
  acesso interno com o perfil de diretor E com a linha ligada ao seu usuário no Gestor; a decisão é
  do sistema, não da IA, e vale para a prévia e para a confirmação.
- **FR-002**: O Gestor MUST conferir, na hora de gravar, que o usuário ligado está ativo, é
  funcionário e tem o perfil que cria orçamento pela tela; senão recusa com o motivo.
- **FR-003**: O vendedor do orçamento MUST ser o usuário de quem pediu, sem pergunta; e o orçamento
  MUST registrar esse usuário como autor.
- **FR-004**: O Pedro MUST identificar cliente ou pré-cliente só entre os do Gestor; com mais de um
  parecido, perguntar qual; sem nenhum, perguntar como proceder; MUST NOT criar cliente nem
  pré-cliente.
- **FR-005**: O orçamento MUST ter empresa emissora entre as empresas do Gestor; sem empresa dita,
  o Pedro pergunta.
- **FR-006**: O orçamento MUST dizer explicitamente se é com ou sem NF; sem isso a prévia não é
  confirmável.
- **FR-007**: Cada item MUST ter nome, tipo (serviço ou material), quantidade inteira de 1 a 9999 e
  valor unitário maior que zero, informados por quem pede; a prévia MUST apontar o que faltar e
  MUST NOT ser confirmável com pendência. Até 50 itens.
- **FR-008**: O Pedro MUST NOT inventar nem reaproveitar de orçamento antigo nenhum valor,
  quantidade, item ou dado de cliente.
- **FR-009**: A prévia MUST ser montada pelo sistema a partir dos dados conferidos — cliente
  (cliente ou pré-cliente, cidade), empresa, vendedor, NF (com o percentual da empresa no Gestor
  quando conhecido), atendimento relacionado, descrição, itens com subtotal, subtotal, NF, total e
  observações — e terminar com "Posso criar esse orçamento no Gestor?" só quando não houver
  pendência.
- **FR-010**: O cálculo que vale MUST ser o da tela do Gestor (itens, descontos, taxas e NF com o
  percentual da empresa gravado no orçamento quando ele nasce); a mesma regra MUST servir à tela e ao
  Pedro, sem cópia.
- **FR-011**: O atendimento citado MUST existir e ser do mesmo cliente para o vínculo; de outro
  cliente ou inexistente, a prévia aponta e não é confirmável; sem número, a prévia MUST avisar só
  os atendimentos em aberto do cliente que forem do mesmo assunto do orçamento (FR-024); o citado
  de outro assunto vira aviso, sem travar; se ele disser que não é de nenhum, não pergunta de novo.
- **FR-012**: Com o atendimento vinculado, a descrição dele MUST estar disponível ao Pedro para a
  descrição e as observações do orçamento, e o vínculo MUST aparecer na prévia.
- **FR-013**: Só a última prévia, de quem a pediu, com até 3 horas e sem pendência, vale para o "sim";
  a confirmação MUST reconferir a prévia antes de pôr na fila.
- **FR-014**: Uma confirmação MUST gerar no máximo um orçamento: o mesmo "sim" repetido, a mensagem
  duplicada e a nova tentativa da máquina devolvem o orçamento já criado.
- **FR-014-INFRA-IDEMP**: A chave de idempotência é a da prévia confirmada (conversa + momento da
  prévia), guardada na fila do Matriz e no orçamento do Gestor, única nos dois, sem prazo de
  expiração.
- **FR-015**: Depois de gravado, o Pedro MUST mandar número, empresa, total calculado pelo Gestor e
  os links da tela do orçamento e do PDF no Gestor (com login); se o total do Gestor diferir do da
  prévia, a mensagem MUST dizer.
- **FR-016**: Recusa ou falha do Gestor MUST chegar a quem pediu com o motivo, sem nada gravado pela
  metade.
- **FR-017**: A fila de orçamentos MUST seguir as regras de prazo das outras gravações do Pedro: não
  pega a tempo → falha avisada; pega e não responde → aviso para conferir no Gestor antes de criar à
  mão; o resultado que chegar depois ainda vale.
- **FR-017-INFRA-SCHED**: A verificação de prazo usa a rotina de minuto a minuto que já confere as
  outras gravações do Pedro; nenhuma rotina nova.
- **FR-018**: O que foi enviado ao Gestor nesta conversa (orçamentos incluídos, com a situação) MUST
  constar do pedido à IA, para ela nunca dizer que criou o que não foi criado.
- **FR-019**: Nada desta feature MUST chegar a cliente: nem prévia, nem valor, nem a ação; o pedido de
  orçamento de cliente continua indo ao comercial, sem preço.
- **FR-020**: O pedido por áudio MUST seguir o mesmo fluxo do texto, a partir da transcrição.
- **FR-021**: O "o que o Pedro faz" de cada pessoa da casa MUST incluir criar orçamento só para quem
  pode (FR-001), e dizer o que falta ao diretor sem vínculo.
- **FR-022**: Valor dito "já com nota": com a empresa e o percentual dela conhecidos e o orçamento
  com NF, o sistema MUST calcular o valor sem nota pela regra do Gestor (NF = subtotal ×
  percentual, no centavo), escolhendo o centavo que faz o total bater com o valor dito, e MUST
  mostrar a conta na prévia; se não houver centavo que bata, MUST mostrar a diferença. Sem a
  empresa ou sem o percentual, ou com o orçamento sem nota, a prévia aponta o que falta. O total com
  nota de um orçamento de um item só vale; com vários itens, pede o valor de cada um.
- **FR-023**: Depois do "sim", o Pedro MUST dizer que está criando e mandar, sem ser perguntado, o
  resultado do Gestor (FR-015/FR-016). Toda falha MUST poder ser repetida com o MESMO pedido e a
  mesma chave ("tentar de novo"), sem criar outro; enquanto um orçamento confirmado estiver sem
  resposta certa do Gestor, uma prévia nova para o mesmo cliente MUST NOT ser confirmável.
- **FR-024**: A compatibilidade de assunto entre orçamento e atendimento MUST ser decidida pelo
  sistema (texto do orçamento contra descrição e assunto do atendimento), e não pela IA.
- **FR-025**: Com um único item de serviço sem quantidade, a prévia MUST sugerir 1, marcada como
  sugerida, e a pergunta final MUST dizer que a confirmação inclui essa quantidade; nos demais
  casos, a quantidade é perguntada.
- **FR-026**: A correção depois da prévia MUST levar só o que mudou (empresa, NF, descrição,
  observações, atendimento, item por número: nome, tipo, quantidade, valor; item novo ou removido),
  e o sistema MUST aplicá-la sobre a última prévia, mantendo o resto; orçamento já enviado não se
  corrige pelo Pedro (a resposta diz o número e que a mudança é na tela do Gestor).
- **FR-027**: O que falta MUST sair agrupado por tipo; o aviso já mostrado não se repete na correção
  da mesma prévia.

### Key Entities

- **Prévia do orçamento**: o que o Pedro entendeu e o sistema conferiu — quem pediu, cliente,
  empresa, NF, atendimento, descrição, observações, itens — com as pendências e avisos; guardada na
  conversa até o "sim".
- **Orçamento a gravar**: a prévia confirmada, na fila da máquina, com a chave única, a situação
  (pendente, gravando, gravado, falhou) e o resultado do Gestor (número, total, links).
- **Vínculo da pessoa com o Gestor**: a ligação da linha da lista de acesso interno com o usuário da
  pessoa no Gestor.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Um pedido completo vira orçamento no Gestor com no máximo duas mensagens de quem pede
  depois do pedido (a confirmação e, se houver, uma correção).
- **SC-002**: 100% dos orçamentos criados pelo Pedro têm os itens, quantidades e valores da prévia
  confirmada, e o total igual ao que a tela do Gestor calcularia com os mesmos dados.
- **SC-003**: Zero orçamentos em dobro por "sim" repetido, mensagem duplicada ou nova tentativa da
  máquina.
- **SC-004**: Zero orçamentos criados a pedido de quem não é proprietário autorizado.
- **SC-005**: Nenhuma prévia confirmável com valor, quantidade, item, cliente, empresa ou NF que quem
  pede não tenha dito.
- **SC-006**: Quem pede recebe o número e os links em até 2 minutos depois do "sim", com a máquina no
  ar, sem precisar perguntar.
- **SC-007**: Nenhum atendimento de outro assunto sugerido na prévia (bateria com a IA de verdade).
- **SC-008**: Correções simples mantêm 100% dos campos que ele não mudou.

## Delta Requirements

**Skip**: capacidade nova; não há corpus `docs/specs/current/` neste repositório para alterar — Claude
(pedido do Felipe), 2026-11-02
