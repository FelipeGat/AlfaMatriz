# Feature Specification: O Pedro acompanha a operação

**Feature**: `pedro-inteligencia-operacional`
**Created**: 2026-10-31
**Status**: Planned — FR-011, FR-012 e FR-016 decididos pelo Felipe em 31/10/2026, à noite, e as quatro leituras
confirmadas às 20:34; plano em `plan.md` (31/10/2026)

## Contexto

Pedido do Felipe (31/10/2026): "não quero um Pedro que apenas responda perguntas; quero um assistente
que acompanhe o que acontece na empresa". Os dois exemplos dele:

- **O técnico informa que fez um serviço.** O Pedro verifica se há uma OS relacionada, interpreta o
  relato e consulta o histórico. Com segurança e autorização suficientes, registra o andamento no
  Gestor. Se tiver dúvida sobre a OS, o responsável ou a conclusão, pede confirmação.
- **Um gestor pergunta por que um atendimento não foi concluído.** O Pedro consulta a OS, o histórico
  de andamentos e a agenda, responde com esse contexto e diz o que ainda precisa ser confirmado.

Premissas do Felipe para esta frente (31/10/2026):

- **Aproveitar o que já existe.** Nada de integração nem de deploy novos. O Pedro já lê o Gestor por
  um retrato e grava nele por um caminho próprio, sempre com prévia e "sim".
- **Nunca informar preço.**
- **Publicar só com autorização expressa.**

**Canal, decidido em 31/10/2026:** quem relata fala com o Pedro na **conversa individual** do
WhatsApp. Os grupos ficam fora desta frente, por dois motivos:

- a API oficial não lê os grupos que a equipe já usa no celular;
- a biblioteca não oficial está vetada desde 20/10/2026.

O que existe hoje, conferido no código em 31/10/2026:

- **Retrato do Gestor.** Traz de cada atendimento só a etapa, os 300 primeiros caracteres da
  descrição e os responsáveis. Ficam de fora os andamentos, as mudanças de etapa e a data da última
  atividade. O Pedro não sabe dizer por que uma OS parou.
- **Central do Gestor.** Já conta "Chamados parados +2 dias" e "atrasados". O Pedro não usa essa
  regra.
- **Andamento gravado pelo Pedro.** Só acontece quando ele liga o pedido de um cliente a uma OS
  aberta (acesso-interno FR-A8). Não existe uma ação de registrar o que alguém relatou. O autor do
  andamento no Gestor é sempre a conta do dono do Pedro (o Felipe), e quem pediu vai só no texto
  (`suporte_gestor_gravar.php`), porque `atendimento_andamentos` não tem campo de origem.
- **Equipe no Gestor.** O funcionário (`funcionarios`, com `ativo` e `is_tecnico`) pode ter um usuário
  (`users.funcionario_id`, com `ativo` e exclusão lógica), e o usuário tem perfis (`perfil_user`:
  admin, administrativo, coordenador, financeiro, comercial, tecnico, funcionario). O retrato já traz
  os funcionários ativos com telefone, mas não traz usuário nem perfil.
- **Quem é da casa.** Só quem está na lista de acesso interno é tratado como tal, por decisão do
  Felipe em 21/10/2026 (acesso-interno FR-A10).
  - O reconhecimento pelo telefone do cadastro de funcionários do Gestor foi **retirado de
    propósito**, porque vazava atendimentos de qualquer cliente citado.
  - As funções que reconheceriam o funcionário pelo telefone ficaram sem uso desde então.
- **POPs, em duas bases.**
  - **Gestor (Treinamento › Procedimentos):** a equipe usa, na tela e no app. O campo de situação
    existe (rascunho, em revisão, publicado), mas **nenhuma tela o altera**: todo POP nasce
    publicado, e só o autor ou um admin o editam.
  - **Matriz (Base do suporte):** tem rascunho → validado de verdade, e o Pedro lê.
  - O Pedro não propõe POP em nenhuma das duas.

## Clarifications

### Session 2026-10-31

- Q: Canal para o Pedro acompanhar o que a equipe relata? → A: conversa individual do WhatsApp; grupos
  fora desta frente.
- Q: Quem registra andamento (FR-011)? → A (Felipe): o técnico **responsável** registra nas OS dele; o
  técnico **participante** também, quando estiver formalmente vinculado à OS. Técnico sem vínculo com a
  OS: o Pedro **propõe** o andamento para validação, sem alterar o atendimento. Gestores autorizados
  acompanham, corrigem e registram conforme as permissões deles. Toda alteração tem rastreabilidade,
  identificação da origem e proteção contra duplicidade.
- Q: Como o colaborador é reconhecido (FR-012)? → A (Felipe): a lista da casa continua como
  reconhecimento inicial, mas a autorização considera o vínculo real com o colaborador, o usuário e o
  perfil dele no Gestor. "Não quero que a simples presença de um telefone na lista da casa conceda
  acesso administrativo ou operacional."
- Q: Onde ficam os POPs (FR-016)? → A (Felipe): os oficiais ficam no Gestor. O Pedro consulta,
  interpreta, acha lacunas e propõe POPs novos ou revisões; a proposta passa por aprovação humana antes
  de virar procedimento oficial. O AlfaMatriz continua sendo o ambiente de supervisão e inteligência do
  Pedro.
- **Leituras das decisões** (Claude, 31/10/2026; se o Felipe discordar de alguma, muda antes do plano):
  - **"Formalmente vinculado à OS"** são os três vínculos que o Gestor grava: responsável
    (`atendimentos.funcionario_id`), técnico adicional (`atendimento_tecnicos`) e técnico de visita não
    cancelada da OS (`atendimento_visitas.funcionario_id` e `atendimento_visita_tecnicos`).
  - **"Corrigir" andamento** é registrar um andamento de correção que cita o anterior. O Gestor não tem
    edição de andamento (só criação e fotos), e reescrever ou apagar o histórico tiraria a
    rastreabilidade que a mesma decisão pede.
  - **O vínculo vale para toda linha da lista** (diretor, gestor, suporte e técnico), e não só para o
    técnico novo: a frase do Felipe fala de acesso "administrativo ou operacional". Isso muda a regra
    em vigor desde 21/10/2026, em que a linha da lista bastava (acesso-interno FR-A1).
  - **As propostas esperam no AlfaMatriz.** A proposta de POP e a de andamento sem vínculo ficam
    pendentes no AlfaMatriz, e só o aprovado chega ao Gestor. Motivo: o app do técnico lista todos os
    POPs da pasta sem olhar a situação (`Api/V1/TreinamentoController`), então um POP gravado no Gestor
    como rascunho apareceria para a equipe como se fosse oficial.

## User Scenarios & Testing

### User Story 1 - Saber por que uma OS não andou (Priority: P1)

Diretor ou gestor pergunta ao Pedro sobre uma OS: "por que a OS 812 não foi concluída?" ou "o que
aconteceu no chamado do Castanheiras?". O Pedro responde com o histórico da OS:

- etapa atual e desde quando;
- andamentos em ordem, com data, autor e resumo;
- mudanças de etapa;
- visitas da agenda, as feitas e as que estão por vir;
- quanto tempo faz desde a última atividade.

Ele separa o que o histórico mostra do que **ninguém registrou** ("o último andamento é de 24/10, a
visita de 28/10 continua agendada e não tem registro de execução; quem pode confirmar é o Vitor,
responsável"). Ele nunca inventa o motivo.

**Why this priority**: é o primeiro exemplo do Felipe e a base de tudo o que vem depois. Sem o
histórico, o Pedro não acha a OS de um relato com segurança (story 3) nem explica um atraso (story 2).
Sozinha, já poupa ao gestor abrir o Gestor para entender uma OS.

**Independent Test**: escolher uma OS com andamentos, mudança de etapa e visita no Gestor, perguntar
por ela de um número da lista com perfil diretor ou gestor e conferir datas, autores e etapas contra a
tela do Gestor.

**Acceptance Scenarios**:

1. **Given** uma OS em atendimento com três andamentos e uma visita vencida sem registro de execução,
   **When** o gestor pergunta por que ela não foi concluída, **Then** o Pedro lista os três
   andamentos com data e autor, aponta a visita vencida e diz quem pode confirmar o que falta.
2. **Given** uma OS sem nenhum andamento desde a abertura, **When** o diretor pergunta por ela,
   **Then** o Pedro diz que não há registro desde a abertura, com a data, e não supõe motivo.
3. **Given** uma pergunta citando o cliente e não o número, com mais de uma OS aberta desse cliente,
   **When** o gestor pergunta, **Then** o Pedro lista as OS do cliente com número e etapa e pergunta
   qual é.
4. **Given** um número fora da lista de acesso interno, **When** ele pergunta por uma OS, **Then** o
   Pedro não mostra histórico nenhum e segue as regras de identificação de cliente de sempre.
5. **Given** um retrato com mais de 3 h, **When** o gestor pergunta por uma OS, **Then** a resposta
   diz a hora da leitura e avisa que pode estar desatualizada.

---

### User Story 2 - Ver o que está parado ou atrasado (Priority: P1)

Diretor ou gestor pergunta "o que está parado?", "quais chamados estão atrasados?" ou "o que está
parado com o Cristiano?". O Pedro responde pela **mesma regra da Central do Gestor**:

- **Parado:** OS que não está concluída nem em finalização e não tem atividade há mais de 2 dias.
  Atividade é a abertura, um andamento ou uma mudança de etapa.
- **Atrasado:** OS aberta, em orçamento, em atendimento ou em garantia, sem execução em curso, com
  visita vencida e nenhuma visita agendada para hoje ou depois.

A lista vem com número, cliente, etapa, responsável e dias sem atividade, das mais antigas para as
mais novas, 5 por vez. Ao fim, o Pedro pergunta se a pessoa quer o resto.

**Why this priority**: é o "identificar pendências e possíveis atrasos" do pedido. Usar a regra da
Central faz o número do Pedro bater com o da tela que o gestor já conhece. Independe das stories 3 a 6.

**Independent Test**: no mesmo momento, comparar a lista do Pedro com "Chamados parados +2 dias" e
"atrasados" da Central do Gestor. Elas têm de bater, descontados os cancelados (ver Edge Cases) e o
atraso do retrato.

**Acceptance Scenarios**:

1. **Given** 12 OS paradas no Gestor, **When** o gestor pergunta o que está parado, **Then** o Pedro
   diz o total de 12, lista as 5 mais antigas e pergunta se quer o resto.
2. **Given** um filtro por pessoa ("parado com o Cristiano"), **When** o gestor pergunta, **Then** o
   Pedro lista só as OS em que o Cristiano é responsável ou técnico.
3. **Given** nenhuma OS parada, **When** o diretor pergunta, **Then** o Pedro diz que não há OS
   parada, com a hora da leitura.
4. **Given** um número com perfil suporte, **When** ele pergunta o que está parado, **Then** o Pedro
   responde "isso não está no que posso consultar para você" (acesso-interno FR-A3).

---

### User Story 3 - Registrar um andamento relatado (Priority: P2)

Quem é da casa relata ao Pedro um serviço feito, por texto, áudio ou foto. Exemplo: "troquei a
placa do portão do Castanheiras, ficou funcionando, segue foto". Quem pode registrar direto (FR-011)
grava com o "sim"; o técnico sem vínculo com a OS gera uma **proposta**, que só vira andamento depois
de validada por um gestor (FR-011a). O Pedro:

1. acha a OS relacionada, pelo número citado ou pelo cliente, assunto e responsável;
2. confere o histórico (story 1);
3. mostra uma **prévia do andamento**: OS, cliente, texto que será gravado, quantas fotos vão junto
   e quem relatou;
4. só grava no Gestor depois do "sim".

Se a OS não for certa (nenhuma, mais de uma, de outro responsável ou concluída), ele pergunta antes de
montar a prévia. O andamento **não muda a etapa nem conclui a OS**: concluir continua com o técnico,
pelo app (decisão de 23/10/2026). Quando o relato diz "terminei", o Pedro lembra disso.

**Why this priority**: é o segundo exemplo do Felipe e o que transforma o Pedro de consulta em
acompanhamento. Depende do histórico (story 1) para achar a OS com segurança. Testável sozinha a
partir de um diretor ou gestor relatando, mesmo antes de o técnico falar com o Pedro (story 4).

**Independent Test**: relatar, de um número autorizado, um serviço numa OS aberta com uma foto,
confirmar a prévia e conferir no Gestor um andamento só, com o texto, a foto e a identificação de
quem relatou, e a etapa da OS inalterada.

**Acceptance Scenarios**:

1. **Given** a OS 812 aberta do Castanheiras, **When** o relato cita "OS 812" com texto e uma foto e
   a pessoa responde "sim" à prévia, **Then** o Gestor ganha um andamento na 812 com o texto, a foto
   e quem relatou, e o Pedro responde com o número da OS.
2. **Given** um relato que cita o cliente, que tem duas OS abertas, **When** o Pedro monta a resposta,
   **Then** ele mostra as duas com número, assunto e responsável e pergunta qual é, sem gravar.
3. **Given** um relato de serviço numa OS concluída, **When** o Pedro monta a resposta, **Then** ele
   diz que a OS está concluída e pergunta se é outra OS ou se deve abrir/reabrir pelo caminho que já
   existe (acesso-interno FR-A7), sem gravar andamento na concluída.
4. **Given** o relato "terminei o serviço", **When** a prévia é mostrada, **Then** ela diz que o
   andamento será registrado e que a conclusão é feita pelo app, pelo técnico.
5. **Given** a prévia mostrada e nenhum "sim", **When** a pessoa manda outra coisa ou some, **Then**
   nada é gravado.
6. **Given** o mesmo "sim" repetido, **When** o Pedro processa, **Then** o andamento é gravado uma
   vez só.
7. **Given** a máquina que grava no Gestor fora do ar, **When** a prévia é confirmada, **Then** o
   Pedro avisa que a gravação ficou pendente, sem dizer que gravou, como já faz com atendimentos.
8. **Given** um técnico reconhecido que não é responsável, adicional nem técnico de visita da OS 812,
   **When** ele relata um serviço na 812 e confirma a prévia, **Then** nada é gravado na OS, a proposta
   fica pendente de validação com o relato, as fotos e quem relatou, e o Pedro diz a ele que um gestor
   vai validar.
9. **Given** uma proposta pendente, **When** um gestor autorizado a valida, **Then** o Gestor ganha o
   andamento com o autor (quem relatou), quem validou e a origem; **When** ele a recusa, **Then** nada
   é gravado e o motivo fica registrado.
10. **Given** um andamento gravado com um erro, **When** o gestor pede ao Pedro para corrigir,
    **Then** o Pedro mostra a prévia de um andamento de correção que cita o original (data e autor) e,
    com o "sim", grava esse andamento novo, sem editar nem apagar o original.
11. **Given** um andamento gravado pelo Pedro, **When** alguém o abre no Gestor, **Then** ele mostra o
    autor real (o usuário de quem relatou, e não a conta do dono do Pedro) e a origem "Pedro, pelo
    WhatsApp".

---

### User Story 4 - O técnico fala com o Pedro (Priority: P2)

O técnico escreve ao Pedro do próprio número. O Pedro o reconhece pela lista da casa e confere o
vínculo dele no Gestor: usuário ativo, com perfil técnico, ligado a um funcionário ativo (FR-012). A
partir daí:

- mostra a ele as OS abertas em que ele é responsável ou técnico, e as visitas dele;
- recebe os relatos de serviço dele, pela story 3.

Ele não vê financeiro, comercial nem OS de outros técnicos. Também não passa dado de cliente que não
seja das OS dele.

**Why this priority**: é quem faz o serviço que relata melhor e mais cedo. Depende do vínculo com o
Gestor (FR-012), que é a mesma trava das outras linhas da lista.

**Independent Test**: de um número reconhecido como técnico, pedir "minhas OS abertas" e conferir
contra o Gestor. Depois pedir a OS de outro técnico e o financeiro, e conferir que ambos são negados.

**Acceptance Scenarios**:

1. **Given** um técnico reconhecido com 4 OS abertas, **When** ele pergunta "o que tenho em aberto?",
   **Then** o Pedro lista as 4 com número, cliente, etapa e a próxima visita de cada uma.
2. **Given** o mesmo técnico, **When** ele pergunta por uma OS de outro técnico, **Then** o Pedro diz
   que isso não está no que pode consultar para ele.
3. **Given** um número que diz ser técnico mas não é reconhecido, **When** ele relata um serviço,
   **Then** o Pedro o trata como cliente e não grava nada (acesso-interno FR-A5).
4. **Given** um número na lista da casa sem vínculo com um usuário ativo do Gestor, **When** ele pede
   as OS dele ou relata um serviço, **Then** o Pedro não mostra nada interno, não grava nada e diz que
   o acesso dele ainda não está liberado, sem citar o motivo técnico.
5. **Given** um técnico cujo usuário foi desativado no Gestor, **When** o retrato seguinte chega,
   **Then** ele perde o acesso no Pedro sem ninguém mexer na lista da casa.

---

### User Story 5 - Consultar e propor POP (Priority: P3)

Quando alguém da casa pergunta "como faz X?", o Pedro responde a partir dos POPs **publicados** e diz
qual POP usou. Quando ele percebe um procedimento que não existe ou que o relato contradiz, propõe um
POP novo ou uma alteração e mostra o texto proposto. Exemplos:

- o técnico descreveu um passo que o POP não tem;
- a pergunta não tem POP.

Com o "sim" de quem está na conversa, a proposta vai para **aprovação** no AlfaMatriz, onde a supervisão
do Pedro já mora. Ela **não vale** como POP até ser aprovada, não chega ao Gestor antes disso, e o POP
publicado não muda até lá. Aprovada, ela é publicada no Gestor: um POP novo ou a alteração do
existente, com quem aprovou como autor ou editor.

**Why this priority**: é o "identificar melhorias nos POPs e submeter à aprovação" do pedido. Vale
depois que o Pedro já acompanha a operação (stories 1 a 3), que é de onde nascem as propostas.

**Independent Test**: perguntar um procedimento que tem POP publicado e conferir que a resposta segue
o POP e o cita. Depois relatar um passo novo, confirmar a proposta e conferir que ela aparece como
pendente de aprovação, sem mudar o POP publicado.

**Acceptance Scenarios**:

1. **Given** um POP publicado sobre reset de central de portão, **When** o técnico pergunta como
   resetar, **Then** o Pedro responde com os passos do POP e o nome dele.
2. **Given** uma pergunta sem POP, **When** o Pedro responde, **Then** ele diz que não há POP para
   isso e oferece propor um, sem inventar procedimento como se fosse oficial.
3. **Given** uma proposta confirmada, **When** quem aprova abre a lista de pendentes, **Then** ela está
   lá com o texto proposto, o POP afetado (se houver), quem relatou e a OS de origem.
4. **Given** uma proposta pendente, **When** alguém pergunta o procedimento, **Then** o Pedro segue o
   POP publicado, não a proposta.
5. **Given** uma proposta aprovada, **When** a equipe abre a pasta no Gestor (tela ou app), **Then** o
   POP aparece publicado com o texto aprovado e quem aprovou; a proposta recusada nunca aparece lá.
6. **Given** uma proposta de alteração do POP X, **When** alguém edita o POP X no Gestor antes da
   aprovação, **Then** a aprovação mostra que o POP mudou e não sobrescreve: a proposta volta para
   revisão.

---

### User Story 6 - Aprender com o que se repete (Priority: P3)

Quando o mesmo problema volta, o Pedro avisa quem acompanha e sugere a proposta de POP da story 5.
"O mesmo" quer dizer o mesmo assunto, no mesmo cliente ou equipamento, ou com a mesma solução. Exemplo:
"é a terceira vez em 30 dias que a central do portão do Castanheiras trava; quer que eu proponha um
POP de prevenção?". O aviso sai na conversa de quem pergunta ou relata. Não é mensagem por iniciativa
do Pedro (ver Fora de escopo).

**Why this priority**: fecha o ciclo de aprendizado, mas só tem matéria depois de semanas de
andamentos registrados (story 3).

**Independent Test**: com três andamentos do mesmo assunto no mesmo cliente dentro da janela, relatar
o quarto e conferir que o Pedro aponta a repetição, com os números das OS.

**Acceptance Scenarios**:

1. **Given** três OS do mesmo assunto no mesmo cliente em 30 dias, **When** o gestor relata a
   quarta, **Then** o Pedro aponta a repetição com os números das três anteriores e oferece a
   proposta de POP.
2. **Given** duas ocorrências só, **When** a terceira ainda não aconteceu, **Then** o Pedro não aponta
   repetição.

---

### Edge Cases

- **A regra da Central conta cancelados.** A contagem "Chamados parados +2 dias" da Central do Gestor
  inclui os atendimentos **cancelados** (só tira concluído e finalização). O Pedro **não** lista
  cancelado como parado. A divergência fica registrada para correção no Gestor, numa frente
  separada.
- **Andamento recém-gravado ainda fora do retrato.** O retrato é renovado a cada 10 min. O andamento
  que o próprio Pedro acabou de gravar conta como atividade, e ele não o propõe de novo.
- **Dois relatos do mesmo serviço** (o técnico e o gestor relatam a mesma troca). O Pedro mostra o
  andamento parecido já registrado nas últimas 24 h e pergunta se é outro serviço, em vez de gravar em
  dobro.
- **Relato que cita outro dia** ("ontem troquei"). A prévia mostra a data do serviço no texto. O
  andamento é gravado na hora da confirmação, porque o Gestor registra quando foi lançado.
- **Relato de OS de outro responsável.** O Pedro mostra quem é o responsável e pergunta antes de
  gravar.
- **Relato só por foto, sem texto.** O Pedro descreve o que viu e pede uma frase de quem relatou. O
  texto do andamento nunca é só a interpretação dele.
- **Documento (PDF, planilha) no relato.** O conteúdo não é lido hoje. O Pedro diz isso e pede foto ou
  texto do que importa.
- **Mais de 10 fotos.** Vão as 10 mais recentes, que é o limite do andamento no Gestor, e a prévia diz
  quantas ficaram de fora.
- **Relato com valor em reais** (peça, deslocamento). O valor vai no andamento como está no relato e
  aparece na prévia para confirmação. A regra de nunca informar preço a cliente não muda (FR-019).
- **Pergunta sobre OS de cliente por quem não é da casa.** Nada é mostrado (stories 1 e 4, cenário de
  número fora da lista).
- **Retrato indisponível ou com mais de 3 h.** A resposta diz a hora da leitura. Sem retrato, o Pedro
  diz que não consegue consultar agora e não responde de memória.
- **O vínculo muda entre a prévia e o "sim".** A permissão é conferida de novo na hora de gravar. Se o
  técnico deixou de ser responsável ou participante, o relato vira proposta (FR-011a) em vez de
  andamento, e o Pedro diz isso.
- **Visita cancelada.** O técnico de uma visita cancelada da OS não conta como participante.
- **OS concluída ou cancelada antes da validação.** A validação da proposta não grava e diz por quê.
- **Linhas da lista que já existem.** Hoje as linhas não têm vínculo com o Gestor. Sem ele, perdem o
  acesso interno quando a regra nova for publicada (FR-012a); o vínculo de cada uma tem de ser feito
  antes da publicação.
- **Quem aprova também relatou.** A pessoa pode aprovar a própria proposta de POP só se for a única
  aprovadora possível; o registro mostra que foi a mesma pessoa.

## Fora de escopo

- **Grupos de WhatsApp** (decisão de 31/10/2026). Ficam para uma frente própria se a Meta liberar
  grupos para o número do Pedro.
- **Mensagem por iniciativa do Pedro.** Exemplos: avisar o gestor às 08:00 o que parou, cobrar o
  técnico. Fora da janela de 24 h da última mensagem da pessoa, o WhatsApp não deixa iniciar conversa
  livre. Os avisos diários continuam no resumo do Secretário.
- **Concluir OS, mudar etapa, apagar ou editar andamento.** A correção pedida pelo Felipe é um
  andamento novo que cita o anterior (FR-011b), e não uma edição.
- **Conversa com o Pedro dentro do AlfaMatriz.** A tela Suporte › WhatsApp continua sendo onde a
  equipe vê e assume as conversas.
- **Ler o conteúdo de documentos** (PDF, planilha).

## Requirements

### Functional Requirements

**Histórico e pendências**

- **FR-001**: O Pedro MUST responder, a quem tem perfil diretor ou gestor, sobre uma OS citada pelo
  número ou pelo cliente:
  - etapa atual e desde quando;
  - andamentos (data, autor, resumo);
  - mudanças de etapa;
  - visitas (feitas e futuras);
  - tempo desde a última atividade.
- **FR-002**: O Pedro MUST separar, na resposta, o que está registrado do que falta confirmar, dizer
  quem pode confirmar (responsável ou técnico da OS) e MUST NOT afirmar motivo que não esteja no
  histórico.
- **FR-003**: O Pedro MUST listar OS paradas e atrasadas pela regra da Central do Gestor (story 2),
  sem os cancelados. A lista MUST poder ser filtrada por pessoa e vir das mais antigas para as mais
  novas, 5 por vez, com o total.
- **FR-004**: Toda resposta com dado do Gestor MUST trazer a hora da leitura e avisar quando ela
  passar de 3 h (acesso-interno FR-A6). Sem leitura disponível, o Pedro MUST dizer que não consegue
  consultar, em vez de responder de memória.

**Registro de andamento**

- **FR-005**: O Pedro MUST transformar um relato (texto, áudio transcrito, fotos) num andamento
  proposto para uma OS, achando a OS pelo número citado ou por cliente, assunto e responsável.
- **FR-006**: Quando a OS não for única e aberta, o Pedro MUST perguntar antes de montar a prévia, sem
  escolher sozinho. Os casos são: nenhuma, mais de uma, de outro responsável, concluída ou cancelada.
- **FR-007**: A prévia MUST mostrar a OS, o cliente, o texto exato a gravar, quantas fotos vão e quem
  relatou. O Pedro MUST gravar somente depois do "sim" explícito, e somente o que estava na última
  prévia.
- **FR-008**: O andamento gravado MUST levar o texto e até 10 fotos do relato. O autor no Gestor MUST
  ser o usuário de quem relatou, pelo vínculo do FR-012, e não a conta do dono do Pedro. O andamento
  MUST marcar a origem (o Pedro, pelo WhatsApp) e, quando não for o próprio autor, quem confirmou ou
  validou. MUST NOT mudar a etapa nem concluir a OS: concluir continua com o técnico, pelo app
  (decisão de 23/10/2026).
- **FR-009**: A gravação MUST passar pelo mesmo caminho que hoje grava os pedidos do Pedro no Gestor
  (máquina do dono, sem nova tentativa automática), com os mesmos avisos de pendência e falha. Ela
  MUST ser idempotente: cada prévia confirmada tem uma chave única, e a mesma chave nunca grava duas
  vezes, mesmo com o "sim" repetido, a máquina reiniciando ou o pedido reenviado.
- **FR-010**: Antes de propor, o Pedro MUST conferir se há andamento parecido na mesma OS nas últimas
  24 h, inclusive os que ele mesmo gravou e o retrato ainda não trouxe, e perguntar se é outro serviço.
- **FR-011**: Pode registrar andamento direto, com prévia e "sim" (Felipe, 31/10/2026):
  - o técnico **responsável** pela OS;
  - o técnico **participante formalmente vinculado** à OS: técnico adicional ou técnico de visita não
    cancelada da OS;
  - o **gestor autorizado**: colaborador cujo usuário no Gestor pode registrar andamento em qualquer
    OS. O plano mapeia quais perfis do Gestor são esses.

  Quem não é nenhum dos três nem técnico reconhecido recebe "isso não está no que posso fazer para
  você", e o relato não se perde: o Pedro oferece repassá-lo ao responsável da OS.
- **FR-011a**: O técnico reconhecido sem vínculo com a OS MUST receber a mesma prévia, mas o "sim" dele
  MUST gerar uma **proposta de andamento**, sem gravar nada na OS. A proposta leva a OS, o texto, as
  fotos, quem relatou e quando. Um gestor autorizado a valida ou recusa no AlfaMatriz. Validada, ela é
  gravada pelo FR-008, com quem validou; recusada, nada é gravado e o motivo fica registrado.
- **FR-011b**: O gestor autorizado MUST poder corrigir um andamento com um **andamento de correção**,
  que cita o original (data e autor), com prévia e "sim". Nenhum andamento é editado nem apagado.
- **FR-011c**: A permissão MUST ser conferida de novo na hora de gravar, e não só na prévia.

**Técnico**

- **FR-012**: Quem é da casa MUST ser reconhecido em dois passos (Felipe, 31/10/2026):
  1. **Reconhecimento:** a lista da casa reconhece o número, como hoje, com um perfil novo
     **técnico** além de diretor, gestor e suporte.
  2. **Autorização:** a linha da lista MUST estar vinculada a um usuário **ativo** e não excluído do
     Gestor. Para o técnico, esse usuário MUST ter o perfil técnico e estar ligado a um funcionário
     ativo.

  O que o Pedro mostra e faz MUST ser o menor dos dois: o que o perfil da lista permite **e** o que
  esse usuário pode ver e fazer no Gestor. Vale para toda linha da lista, não só para o técnico. O
  nome que a pessoa diz ser MUST NOT contar.
- **FR-012a**: Linha sem vínculo, ou com usuário desativado ou excluído no Gestor, MUST NOT receber
  dado interno nem fazer ação. O Pedro diz que o acesso ainda não está liberado, sem citar o motivo
  técnico. A tela Suporte › Pedro MUST mostrar quais linhas estão sem vínculo.
- **FR-012b**: O vínculo e o perfil MUST ser conferidos a cada retrato do Gestor: usuário desativado
  ou perfil retirado no Gestor tira o acesso no Pedro sem ninguém mexer na lista da casa.
- **FR-013**: O técnico reconhecido MUST ver somente:
  - as OS abertas em que é responsável ou técnico;
  - as próprias visitas;
  - os POPs publicados.

  Financeiro, comercial, OS de outros técnicos e dado de cliente fora das OS dele MUST NOT entrar no
  que o Pedro recebe para responder a ele. A trava vale no dado, não só na instrução (acesso-interno
  FR-A3).

**POPs**

- **FR-014**: Ao responder procedimento a quem é da casa, o Pedro MUST usar somente POPs publicados,
  citar o nome do POP usado e dizer quando não há POP, sem apresentar procedimento próprio como
  oficial.
- **FR-015**: O Pedro MUST propor um POP novo ou a alteração de um existente quando o relato trouxer
  passo ausente ou contrário ao POP, ou a pergunta não tiver POP. Ele mostra o texto proposto e o envia
  à aprovação só depois do "sim" de quem está na conversa.
- **FR-016**: Os POPs oficiais MUST continuar no Gestor (Treinamento › Procedimentos), e o Pedro MUST
  consultar só os publicados (Felipe, 31/10/2026). A proposta MUST ficar pendente de aprovação **no
  AlfaMatriz**, com o texto proposto, o POP afetado e a versão dele na hora da proposta, quem relatou e
  a OS de origem. Enquanto pendente ou recusada, ela MUST NOT chegar ao Gestor nem valer como POP.
  Aprovada, ela MUST ser publicada no Gestor como POP novo ou alteração do existente, com quem aprovou
  como autor ou editor.
- **FR-016a**: Aprova quem tem, no Gestor, permissão de editar qualquer POP. Hoje é o perfil admin
  (`TreinamentoProcedimento::podeEditar`: o autor ou um admin).
- **FR-016b**: Se o POP afetado mudou no Gestor depois da proposta, a aprovação MUST mostrar isso e
  MUST NOT sobrescrever: a proposta volta para revisão.
- **FR-017**: O Pedro MUST apontar repetição quando um mesmo assunto voltar no mesmo cliente ou
  equipamento pela terceira vez em 30 dias, citando as OS, e oferecer a proposta de POP (FR-015).
  Esses números são o padrão inicial e podem ser ajustados no clarify.

**Regras que não mudam**

- **FR-018**: Nada desta frente MUST reaparecer para quem não é da casa: número fora da lista segue o
  atendimento de cliente e os níveis de identificação. Número na lista sem vínculo segue o FR-012a.
- **FR-019**: O Pedro MUST NOT informar preço a cliente nem mostrar boleto ou NF na conversa, também
  quando a informação vier do histórico de uma OS.

**Rastreabilidade**

- **FR-020**: Toda gravação desta frente MUST deixar registro auditável no AlfaMatriz, para andamento,
  correção, proposta, validação, recusa e POP publicado. O registro guarda quem relatou, quem
  confirmou ou validou, a conversa e a mensagem de origem, o texto e as fotos enviados, a chave de
  idempotência e o resultado no Gestor (número do andamento ou do POP, ou o erro).

### Key Entities

- **OS (atendimento do Gestor)**: chamado de cliente ou pré-cliente, com número, etapa, responsável e
  técnicos. Tem andamentos, mudanças de etapa e visitas.
- **Andamento**: registro do que foi feito numa OS, com data, autor, texto e fotos.
- **Mudança de etapa**: passagem da OS de uma etapa para outra, com data.
- **Visita**: ida de um técnico a uma OS num horário, com situação (pedro-dia-do-tecnico).
- **Última atividade**: a mais recente entre abertura, andamento e mudança de etapa. É a base de
  "parado".
- **Relato**: a mensagem livre de quem fez ou acompanhou o serviço (texto, áudio, fotos).
- **Prévia do andamento**: o que será gravado. É o que o "sim" confirma.
- **Técnico**: funcionário do Gestor que atende OS, reconhecido pela lista da casa e autorizado pelo
  usuário dele no Gestor (FR-012).
- **Vínculo do colaborador**: a ligação de uma linha da lista da casa com um usuário do Gestor. É o
  vínculo que autoriza, e não o telefone.
- **Vínculo com a OS**: responsável, técnico adicional ou técnico de visita não cancelada.
- **Proposta de andamento**: relato confirmado por técnico sem vínculo com a OS, à espera de validação
  no AlfaMatriz.
- **Andamento de correção**: andamento novo que corrige um anterior e o cita.
- **Registro de origem**: a trilha de cada gravação (FR-020).
- **POP**: procedimento publicado, com título e passos. Pertence a uma empresa e a um segmento.
- **Proposta de POP**: texto novo ou alteração de um POP, pendente de aprovação, com origem (quem
  relatou, OS).
- **Repetição**: o mesmo assunto voltando no mesmo cliente ou equipamento dentro da janela.

> **Decisões de infraestrutura.**
>
> - Sem agendamento novo. A leitura continua no ciclo atual do retrato, a cada 10 min, e fica maior.
>   A repetição é avaliada no momento do relato ou da pergunta.
> - A gravação reusa a fila de gravação no Gestor que já existe: máquina do dono, só com o "sim".
> - A idempotência é requisito (FR-009).
> - Sem chave nova nem credencial nova.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Em 10 OS escolhidas pelo Felipe na validação, a resposta a "por que não foi concluída"
  traz a data da última atividade igual à do Gestor em 10 de 10, e não afirma nenhum motivo ausente do
  histórico.
- **SC-002**: A lista de parados e atrasados do Pedro bate com a da Central do Gestor, descontados os
  cancelados, em todas as conferências da semana de validação feitas com retrato de até 10 min.
- **SC-003**: Um relato com OS identificável vira andamento gravado em até 3 mensagens de ida e volta.
- **SC-004**: Zero andamentos gravados sem "sim", e zero andamentos em dobro, nos primeiros 30
  relatos (auditável pelo registro de quem relatou em cada andamento).
- **SC-005**: Zero dado de OS ou de cliente mostrado a número fora da lista, ou a técnico fora das OS
  dele, nos testes provocados do roteiro de validação.
- **SC-006**: Toda proposta de POP fica pendente até a aprovação, e nenhum POP publicado muda sem
  ela.
- **SC-007**: Em 4 de cada 5 dias da semana de validação, o gestor responde a uma pergunta sobre OS
  parada sem abrir o Gestor.
- **SC-008**: Zero dado interno ou ação para número da lista sem vínculo ativo com o Gestor, nos
  testes provocados do roteiro de validação.
- **SC-009**: Todo andamento gravado pelo Pedro traz no Gestor o autor real e a origem, e tem no
  AlfaMatriz o registro de quem confirmou ou validou, em 100% dos casos auditados.

## Delta Requirements

**Skip**: o corpus canônico `docs/specs/current/` ainda não existe neste repositório. A capacidade é
nova e não altera comportamento ativo documentado nele. As regras do Pedro que ela toca estão em
`docs/specs/suporte-pedro-whatsapp/` e `docs/specs/pedro-dia-do-tecnico/`, citadas por FR — Claude
(pedido do Felipe), 2026-10-31

**Muda regra em vigor:** o FR-012 altera o acesso-interno FR-A1 ("quem tem acesso interno é decidido
só pelo número"). Depois desta frente, o número reconhece e o vínculo com o Gestor autoriza. O
`acesso-interno.md` precisa ganhar essa nota quando o plano for aprovado.

## Fontes (conferidas em 31/10/2026)

- Retrato: `deploy/central-ia/suporte_gestor_ler.php` (atendimentos com 300 caracteres, abertos ou dos
  últimos 120 dias; visitas de -7 a +14 dias).
- Regra de parados e atrasados: Gestor, `app/Http/Controllers/CentralController.php`, linhas 120 a 145
  (última atividade = maior entre abertura, `atendimento_andamentos` e `atendimento_status_historicos`;
  parados tira só `concluido` e `finalizacao`).
- Andamento com fotos: Gestor, `POST api/v1/admin/atendimentos/{id}/andamentos`
  (`AtendimentoController::andamentoStore`: descrição obrigatória, até 10 fotos de até 8 MB).
- POP do Gestor: `TreinamentoProcedimento` (situação com padrão "publicado", criada pela migração
  `2026_07_11_190000`; nenhuma tela a altera; `podeEditar` = autor ou admin).
- Base do Matriz: `SuporteBaseController` (rascunho → validado).
- Reconhecimento por funcionário retirado: `docs/specs/suporte-pedro-whatsapp/acesso-interno.md`,
  FR-A10. As funções `DiretorioGestor::daEquipe` e `abertosDoFuncionario` estão sem chamador.
- Janela do WhatsApp: `SuporteConversa::JANELA_HORAS = 24`.
- Vínculos com a OS no Gestor: `atendimentos.funcionario_id` (responsável), `atendimento_tecnicos`
  (adicional), `atendimento_visitas` e `atendimento_visita_tecnicos` (visita). O retrato junta
  responsável e adicionais num campo só (`funcionarios`), sem dizer quem é o responsável.
- Equipe e permissão no Gestor: `users.funcionario_id`, `users.ativo`, exclusão lógica em `users`;
  perfis em `perfil_user` (`User::PERFIS_INTERNOS`); `User::canPermissao(recurso, acao)`.
- Andamento no Gestor: `atendimento_andamentos` (atendimento, usuário, descrição; sem origem nem chave)
  e fotos em `atendimento_andamento_fotos`. Não há rota para editar nem apagar andamento.
- App do técnico: `Api/V1/AtendimentoController::index` lista só as OS em que ele é o responsável.
  `Api/V1/TreinamentoController` lista os POPs da pasta sem filtrar a situação.
