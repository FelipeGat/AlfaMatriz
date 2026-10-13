# Feature Specification: Chats Business — um chat contínuo por assunto

**Feature**: `chats-business`
**Created**: 2026-10-13
**Status**: Draft
**Pedido**: dono, 13/10/2026 — "eu uso aqui no VS Code um chat para cada coisa; se quero falar sobre
Proposta comercial vou no chat da proposta e ele já sabe o que precisa fazer baseado nos meus pedidos.
Quero isso no Matriz: uma área tipo a Tarefas que contenha o histórico do que eu preciso por segmento
(Comercial VixCard, Comercial Grupo Soluções…), com os chats ao lado, e que leve o histórico desses que
estão aqui."
**Relação com `central-ia`**: feature nova, e não refinamento da spec `central-ia`. Ela traz um conceito
que a Central não tem: o **assunto contínuo**, que não é pasta de projeto nem tarefa e que não termina.
Os princípios da `docs/constitution.md` valem inteiros. Os mais tocados são o I (privacidade por conta),
o IV (segredos) e o VIII (zero fabricação).

## Decisões do dono (13/10/2026)

1. **Origem**: os chats são **criados novos** na Central. A Central entende o contexto de cada conversa
   que já existe no VS Code e semeia o chat novo com ele. A conversa original não é alterada.
2. **Histórico**: o agente lê **todo o histórico** do chat, "desde que não gaste todo o uso". Acima de um
   limite, a parte antiga é condensada. A pessoa continua vendo tudo.
3. **Primeira leva** (dono, 13/10/2026: "não precisa trazer todos"): só quatro conversas viram chat
   semeado — **Proposta Comercial VixCard** e **Gestor.SG** (gravadas no WSL), **Proposta comercial
   Grupo Soluções** e **Relatório avanço de obra** (gravadas só no Mac). Os demais assuntos começam do
   zero na Central. O Gestor.VixCard também começa do zero.
   Conferido em 13/10/2026 no WSL: Proposta Comercial VixCard é uma sessão do Claude atualizada nesse
   dia (~77 MB); Gestor.SG são duas sessões do Claude (~119 MB e ~2 MB), que semeiam o mesmo chat. Pelo
   tamanho, a semente lê por partes e respeita o teto de custo da importação (ver Edge Cases).
4. **Conversas do Mac**: **cópia única** das duas pelo túnel SSH que já existe. Nada fica instalado no
   Mac, e depois da semente esses chats vivem só na Central.
5. **Quem lê**: todos os agentes **do usuário** leem os chats dele. Nada sai da conta e do perfil.
   Outro usuário, administrador inclusive, não vê.

## User Scenarios & Testing

### User Story 1 - Conversar num chat de assunto que lembra de tudo (Priority: P1)

A pessoa abre a área **Chats Business**. À esquerda vê a lista dos seus chats de assunto, agrupados
(VixCard, Alfa Tecnologia, Pessoal) e com a última atividade de cada um. Escolhe "Comercial VixCard" e
pede "monte a proposta do cliente X no padrão de sempre". O agente que atende, qualquer um dos dela,
já sabe o que foi pedido antes nesse chat, as decisões tomadas e o jeito de fazer, sem que ela repita.

**Why this priority**: é o pedido inteiro em uma frase: "ele já sabe o que precisa fazer baseado nos
meus pedidos". Sem isso as outras histórias não têm valor.

**Independent Test**: criar um chat "Comercial VixCard" vazio, fazer dois pedidos com uma decisão
explícita no primeiro ("sempre sem valores na primeira versão") e conferir que a resposta ao segundo
segue a decisão sem ela ser repetida.

**Acceptance Scenarios**:

1. **Given** um chat de assunto com pedidos e decisões anteriores, **When** a pessoa faz um pedido novo
   nele, **Then** o agente recebe a memória do assunto e todo o histórico que cabe no limite, e a
   resposta não contradiz uma decisão registrada.
2. **Given** um chat atendido até agora pelo Claude, **When** o pedido seguinte cai no Codex ou no
   Copilot (escolha manual ou automática), **Then** o novo agente recebe a mesma memória e o mesmo
   histórico.
3. **Given** a lista de chats, **When** a pessoa cria um chat com nome, grupo e, se quiser, um projeto,
   **Then** ele aparece no grupo escolhido, vazio e pronto para o primeiro pedido.
4. **Given** um chat com projeto vinculado, **When** um pedido pede para alterar arquivos, **Then** o
   trabalho acontece na pasta desse projeto, com as mesmas regras de confirmação e auditoria da Central.
   **Given** um chat sem projeto, **Then** ele funciona como o chat "Geral" de hoje.
5. **Given** um chat em que o agente terminou ou está esperando a pessoa, **Then** a lista sinaliza o
   chat (pulso, aviso de novidade), como em Chats Ativos.

---

### User Story 2 - Começar a partir das conversas do VS Code (Priority: P2)

A pessoa já tem, no VS Code, conversas com nome próprio ("Proposta Comercial VixCard", "Gestor.SG",
"English"…). Na Central ela vê essas conversas e, para cada uma, escolhe **criar o chat de assunto a
partir dela**. A Central lê a conversa e grava no chat novo uma **memória de partida**: o assunto, o que
foi pedido, as decisões, o jeito de fazer e o que ficou pendente. Nada é copiado de volta para o VS Code.

**Why this priority**: é o "que levem o histórico desses que estão aqui". Sem a semente, o chat novo
começa do zero e a pessoa teria de reexplicar cada assunto.

**Independent Test**: escolher uma conversa do VS Code com nome, criar o chat a partir dela e conferir
que a memória de partida cita decisões que estão na conversa original e não inventa nenhuma.

**Acceptance Scenarios**:

1. **Given** conversas do VS Code com nome dado pela pessoa, guardadas numa máquina com runner,
   **When** a pessoa abre "Trazer do VS Code", **Then** vê cada conversa com o nome, o agente, o projeto
   (pasta) e a data da última mensagem, e as que já viraram chat aparecem marcadas.
2. **Given** uma conversa escolhida, **When** a pessoa confirma, **Then** a Central gera a memória de
   partida com um modelo de nível econômico, mostra o custo e cria o chat. O nome e o grupo sugeridos
   podem ser trocados antes de confirmar.
3. **Given** uma conversa com segredo no texto (token, senha, chave), **Then** a memória de partida não
   o contém.
4. **Given** uma conversa que segue sendo usada no VS Code depois da importação, **When** a pessoa pede
   "atualizar do VS Code" no chat, **Then** só o trecho novo é lido e acrescentado à memória.
5. **Given** uma conversa que não tem nome dado pela pessoa, **Then** ela aparece com o primeiro pedido
   como título provisório, num bloco separado "sem nome".

---

### User Story 3 - Histórico inteiro sem estourar o uso (Priority: P3)

O chat guarda tudo. Enquanto o histórico cabe no limite, o agente lê tudo. Quando passa, a parte mais
antiga é condensada num resumo que mantém decisões e pendências, e o agente recebe resumo e parte
recente. A pessoa vê quanto do histórico foi ao agente no último pedido e quanto custou.

**Why this priority**: é a condição do dono ("desde que não gaste todo o uso"). Os chats de assunto
crescem sem fim, e sem limite cada pedido ficaria mais caro que o anterior.

**Independent Test**: num chat com histórico acima do limite, fazer um pedido e conferir que o agente
recebeu o resumo e a parte recente, que nenhuma decisão registrada sumiu e que a tela informa o
tamanho e o custo.

**Acceptance Scenarios**:

1. **Given** um histórico abaixo do limite, **When** a pessoa pede, **Then** o histórico vai inteiro.
2. **Given** um histórico acima do limite, **When** a pessoa pede, **Then** a parte antiga vai condensada,
   as decisões e as pendências vão inteiras e a parte recente vai literal.
3. **Given** o uso do agente perto do limite da cota da janela, **Then** a Central reduz o histórico
   enviado ao mínimo (memória e parte recente) e avisa a pessoa, em vez de gastar o resto da cota num
   pedido só.
4. **Given** qualquer pedido, **Then** a conversa mostra quanto do histórico foi enviado (inteiro ou
   condensado) e o custo, como já mostra hoje.

---

### User Story 4 - Memória do assunto visível e corrigível (Priority: P4)

A pessoa abre a **Memória** do chat e vê, em linguagem simples, o que o agente sabe do assunto:
objetivo, decisões, jeito de fazer, pendências. Ela pode corrigir ou apagar um item. Os agentes
atualizam a memória ao fim de cada pedido.

**Why this priority**: memória errada contamina todos os pedidos seguintes do assunto. Corrigir na
fonte custa menos que repetir a correção a cada pedido.

**Independent Test**: apagar uma decisão da memória e conferir que o pedido seguinte não a recebe.

**Acceptance Scenarios**:

1. **Given** um pedido concluído, **Then** a memória ganha as decisões e pendências que o agente
   relatou, com autor e data, sem duplicar o que já estava lá.
2. **Given** uma correção feita pela pessoa, **Then** ela prevalece sobre o que um agente escreveu antes
   e fica registrada no histórico da memória.

---

### Edge Cases

- **Conversa do VS Code que só existe no Mac** (dono, 13/10/2026): as duas da primeira leva são
  copiadas uma vez do Mac pelo túnel SSH e semeadas a partir da cópia. Nada fica instalado no Mac, e
  "atualizar do VS Code" (FR-009) não vale para elas. A cópia é apagada da máquina depois da semente.
- **Grupos**: o padrão é o grupo é sugerido pela regra que a Central já usa (nome Vix* → VixCard, o resto →
  Alfa Tecnologia), mais o grupo **Pessoal** para English, AlfaHome e Painel Avanço. A pessoa troca à
  vontade.
- **Duas conversas do VS Code para o mesmo assunto** (ex.: "VIX-ID Patrimonio" e "VIX-ID - Controle de
  Patrimonio"): a pessoa pode semear um chat com mais de uma conversa, e a memória junta as duas.
- **Conversa gigante** (milhares de mensagens): a semente lê por partes e, se o custo estimado passar
  do teto da importação, pergunta antes de seguir.
- **Chat arquivado**: sai da lista, fica pesquisável e volta com a memória intacta.
- **Pedido de deploy num chat de assunto**: mesmas regras da Central (código de confirmação, autorização
  explícita, Princípio VII). O chat de assunto não amplia nenhum acesso.
- **Mesmo chat aberto na tela e no Telegram**: o Telegram pode apontar para um chat de assunto, e as
  duas portas escrevem no mesmo histórico, como já acontece com os chats de hoje.
- **Pedido de outro usuário com o ID de um chat alheio**: recusado como inexistente.

## Requirements

### Functional Requirements

- **FR-001**: A Central MUST ter a área **Chats Business**, com a lista de chats de assunto agrupada e
  recolhível à esquerda e a conversa do chat escolhido à direita.
- **FR-002**: A pessoa MUST poder criar, renomear, mudar de grupo, vincular ou desvincular um projeto,
  arquivar e reabrir um chat de assunto.
- **FR-003**: Um chat de assunto MUST NOT terminar quando um pedido termina. Cada pedido nele fica no
  histórico, e o chat continua aberto até a pessoa arquivá-lo.
- **FR-004**: Todo pedido feito num chat de assunto MUST levar ao agente a memória do assunto e o
  histórico, nos limites do FR-010 a FR-012, qualquer que seja o agente escolhido.
- **FR-005**: Ao fim de cada pedido, a memória MUST ser atualizada com o que o agente relatou: decisões,
  jeito de fazer, pendências. Item repetido não duplica.
- **FR-006**: A pessoa MUST poder ver, corrigir e apagar itens da memória. A correção dela prevalece e
  fica registrada.
- **FR-007**: A Central MUST listar as conversas dos agentes do VS Code guardadas nas máquinas da pessoa
  que têm runner, com nome, agente, pasta e data da última mensagem. As que já semearam um chat
  aparecem marcadas.
- **FR-008**: A pessoa MUST poder criar um chat de assunto a partir de uma ou mais conversas do VS Code.
  A Central gera a memória de partida com um modelo de nível econômico e mostra o custo. A conversa
  original não é alterada.
- **FR-009**: A pessoa MUST poder atualizar um chat a partir da conversa de origem. Só o trecho novo
  desde a última leitura é lido.
- **FR-010**: Enquanto o histórico do chat couber no limite de contexto, o agente MUST recebê-lo
  inteiro.
- **FR-011**: Acima do limite, a parte antiga MUST ir condensada, com decisões e pendências inteiras, e a
  parte recente literal. A pessoa continua vendo o histórico completo na tela.
- **FR-012**: Com o uso do agente perto do limite da cota da janela, a Central MUST reduzir o histórico
  enviado à memória mais a parte recente e avisar a pessoa.
- **FR-013**: A conversa MUST mostrar, a cada pedido, quanto do histórico foi enviado e o custo.
- **FR-014**: Chats de assunto, memória, sementes e histórico MUST ser visíveis só para a conta dona e
  para os agentes dela. Nenhum administrador nem outro usuário os vê ou altera (Princípio I).
- **FR-015**: Memória e semente MUST passar pela proteção de segredos antes de serem gravadas ou
  enviadas a um agente (Princípio IV).
- **FR-016**: A memória de partida MUST conter só o que está na conversa de origem. O que não está lá
  não é inventado (Princípio VIII).
- **FR-017**: Um pedido feito num chat de assunto MUST seguir todas as regras da Central: roteamento,
  nível de custo, escalada, confirmação, auditoria, delegação, prévias e POPs.
- **FR-018**: A lista MUST sinalizar o chat que terminou ou está esperando a pessoa, como Chats Ativos.
- **FR-019**: O Telegram MUST poder apontar para um chat de assunto, e o que entra por ele cai no mesmo
  histórico.
- **FR-020**: As duas conversas da primeira leva que só existem no Mac MUST ser trazidas por uma cópia
  única, sem nada permanente instalado no Mac. A cópia local é apagada depois da semente.
- **FR-021**: A primeira leva MUST semear exatamente quatro chats: Proposta Comercial VixCard (grupo
  VixCard), Gestor.SG, Proposta comercial Grupo Soluções e Relatório avanço de obra (grupo Alfa
  Tecnologia). Nenhuma outra conversa é importada sem a pessoa pedir.

### Key Entities

- **Chat de assunto**: nome, grupo, projeto opcional, situação (aberto ou arquivado), última atividade
  e dono. Tem uma memória e um histórico.
- **Memória do assunto**: objetivo, decisões, jeito de fazer e pendências. Cada item tem autor (agente
  ou pessoa) e data. As correções da pessoa ficam versionadas.
- **Semente**: o vínculo entre o chat e as conversas do VS Code de origem. Guarda a máquina, o agente,
  a conversa, até onde foi lida e o custo da leitura.
- **Grupo**: rótulo de organização (VixCard, Alfa Tecnologia, Pessoal), por conta.

> Decisões de infraestrutura: nenhum trabalho periódico novo. A semente e a condensação acontecem sob
> demanda, num pedido da pessoa ou antes de um pedido que passa do limite. O limite de contexto e o
> teto de custo da importação são configuráveis por conta (padrões definidos no `/plan`).

## Success Criteria

### Measurable Outcomes

- **SC-001**: Num chat com decisões registradas, 9 em cada 10 pedidos seguintes respeitam as decisões
  sem que a pessoa as repita.
- **SC-002**: Um chat semeado de uma conversa do VS Code fica pronto em menos de 2 minutos, e a memória
  de partida não contém nenhuma decisão ausente da conversa original (conferido por amostra).
- **SC-003**: Em qualquer chat de assunto, o contexto enviado por pedido nunca passa do orçamento
  configurado da conta, qualquer que seja o tamanho do histórico (ajustado no `/plan`, research
  Decision 11).
- **SC-004**: Nenhum chat, memória ou semente de uma conta aparece para outra conta, administrador
  inclusive (teste de isolamento).
- **SC-005**: A pessoa troca de assunto em no máximo 2 cliques a partir de qualquer tela da Central.

## Delta Requirements

### Capability: central-ia-chats-de-assunto

#### ADDED

- **FR-001**: A Central MUST ter a área **Chats Business**, com a lista de chats de assunto agrupada e
  recolhível à esquerda e a conversa do chat escolhido à direita.
- **FR-004**: Todo pedido feito num chat de assunto MUST levar ao agente a memória do assunto e o
  histórico, nos limites do FR-010 a FR-012, qualquer que seja o agente escolhido.
- **FR-008**: A pessoa MUST poder criar um chat de assunto a partir de uma ou mais conversas do VS Code.
  A Central gera a memória de partida com um modelo de nível econômico e mostra o custo. A conversa
  original não é alterada.
