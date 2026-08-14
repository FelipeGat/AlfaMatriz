# Spec: Tarefas de desenvolvimento

> feature: tarefas-desenvolvimento
> status: rascunho

## Contexto

O ciclo de desenvolvimento da Alfa é acompanhado hoje num app separado
(`alfadev`, Next.js + Supabase): um quadro kanban com colunas por etapa,
regras de transição que impedem concluir sem teste aprovado, e cronômetro por
etapa. Manter esse app à parte custa um segundo login, um segundo banco e um
segundo cadastro de pessoas — e o que está em desenvolvimento em cada sistema
não aparece no painel onde a Alfa já enxerga revendas, clientes e faturamento.

Esta feature traz o **núcleo** desse app para dentro do AlfaMatriz: um grupo
"Desenvolvimento" no menu, com o item "Tarefas", levando ao quadro do ciclo.
O núcleo é quadro + fluxo + tempo por etapa. Comentários, anexos, painel de
indicadores, notificações, changelog para Discord/Telegram e exportação ficam
para entregas seguintes — estão listados em "Fora de escopo".

A tarefa é vinculada a um **Sistema** já cadastrado na Matriz (AlfaGym,
AlfaControl, …), não a um "projeto" novo, e o responsável é um **usuário da
Matriz**. Quem tem escopo de revenda não enxerga nada disso.

## Histórias

### US-036 — Quadro do ciclo de desenvolvimento

Como pessoa do time da Alfa, quero um quadro com as tarefas do ciclo de
desenvolvimento dentro do AlfaMatriz, para acompanhar num lugar só o que está
aberto, em desenvolvimento, em teste e concluído.

#### AC-081 — O menu leva ao quadro pelo grupo Desenvolvimento

- **Dado** que estou autenticado como usuário da matriz com permissão de tarefas
- **Quando** abro qualquer tela do painel
- **Então** o menu lateral mostra o grupo "Desenvolvimento" com o item
  "Tarefas", e clicar nele abre o quadro (rota `tarefas.index`)

#### AC-082 — O quadro mostra as etapas do trabalho em curso, na ordem, com a contagem

- **Dado** que existem tarefas em etapas diferentes
- **Quando** abro o quadro
- **Então** vejo as colunas Aberta, Backlog, Em andamento, Em testes e Ajustes
  necessários nessa ordem, cada uma com o total de tarefas que estão nela — e
  nenhuma coluna de etapa terminal, porque tarefa encerrada não é trabalho em
  curso

> **Revisto em 11/08/2026.** A coluna "Em desenvolvimento" passou a se chamar
> **Em andamento** (US-054): ela recebe também tarefa operacional, que não é
> desenvolvida, e um telefonema parado numa coluna chamada "Em desenvolvimento"
> faria a coluna mentir. A chave do dado continua `em_desenvolvimento` — ela
> está gravada no histórico de etapas, e renomear o dado para arrumar um rótulo
> de tela reescreveria o passado.
>
> **Bloqueada chegou a ser coluna e deixou de ser no mesmo dia** (US-058): como
> coluna, ela apagava a etapa em que a tarefa estava. O contador de cada coluna
> ganhou o limite de WIP (AC-195) e o aviso de triagem pendente (AC-194), e as
> etapas passaram a ser lidas uma por vez no celular (AC-215).

#### AC-083 — Tarefa sem responsável nasce Aberta; com responsável, nasce no Backlog

- **Dado** que estou criando uma tarefa
- **Quando** salvo sem escolher responsável
- **Então** ela aparece na coluna Aberta; e quando salvo escolhendo um
  responsável, ela aparece direto na coluna Backlog

#### AC-084 — A tarefa é vinculada a um sistema já cadastrado

- **Dado** o formulário de nova tarefa
- **Quando** escolho o sistema (AlfaGym, AlfaControl, …) e salvo
- **Então** o card do quadro mostra o sistema da tarefa — o ícone da marca no
  rodapé, com o nome no `title` (AC-202) —, e só sistemas ativos são oferecidos
  na escolha

#### AC-137 — Salvar duas vezes não cria a tarefa duas vezes

- **Dado** o formulário de nova tarefa preenchido
- **Quando** clico em Salvar duas vezes seguidas
- **Então** o quadro ganha um card só; e cadastrar em série o mesmo título para
  sistemas diferentes continua criando uma tarefa para cada

#### AC-123 — As quatro prioridades do ciclo, com a crítica em destaque

- **Dado** o formulário de tarefa
- **Quando** abro a escolha de prioridade
- **Então** vejo Baixa, Média, Alta e **Crítica**; uma tarefa crítica aparece
  no quadro com a marca vermelha, distinta da Alta

#### AC-126 — Os quatro níveis de prioridade se distinguem entre si

- **Dado** quatro tarefas no quadro, uma de cada prioridade
- **Quando** comparo os selos delas
- **Então** cada nível tem uma cor própria — Baixa, Média, Alta e Crítica não
  compartilham tom entre si, e a escala sobe do mais discreto ao mais grave

#### AC-127 — Cada etapa do quadro tem a sua cor, na coluna

- **Dado** o quadro aberto
- **Quando** corro o olho pelas colunas
- **Então** cada etapa tem uma faixa de cor no topo da sua coluna e o contador
  tingido no mesmo tom, como no Funil de Vendas — e a cor da etapa não é
  repetida na borda dos cards, que continua reservada ao aviso de tarefa
  esquecida

#### AC-128 — Dentro da coluna, o que é mais grave e o que está mais parado sobem

- **Dado** uma coluna com uma tarefa crítica criada há meses e uma tarefa de
  prioridade baixa criada hoje
- **Quando** olho a coluna
- **Então** a crítica aparece acima da baixa; e entre duas tarefas de mesma
  prioridade, a que está parada há mais tempo na etapa aparece primeiro

#### AC-129 — O resumo da tarefa aparece no card

- **Dado** uma tarefa cujo resumo foi preenchido
- **Quando** olho o card dela no quadro
- **Então** leio o resumo abaixo do título, sem precisar abrir a tarefa; e o
  card de uma tarefa sem resumo não abre espaço vazio no lugar dele

#### AC-130 — Tarefa sem responsável diz que não tem

- **Dado** uma tarefa ainda não direcionada a ninguém
- **Quando** olho o card dela
- **Então** o card afirma "sem responsável" — a informação é dita, não
  deduzida da ausência do nome

#### AC-132 — O quadro ocupa a largura disponível

- **Dado** o quadro numa tela larga, com menos colunas do que caberia
- **Quando** olho a borda direita
- **Então** as colunas se dividem a largura e não sobra faixa vazia depois da
  última — e numa tela estreita elas voltam a ter a largura mínima de leitura,
  com o quadro rolando na horizontal

### US-037 — Fluxo com regras que impedem pular etapa

Como responsável pela entrega, quero que o quadro recuse movimentos fora do
fluxo, para que nada seja dado como concluído sem ter passado por teste.

#### AC-085 — Movimento fora do fluxo é recusado

- **Dado** uma tarefa na coluna Backlog
- **Quando** tento movê-la direto para Concluída
- **Então** o movimento é recusado com aviso de transição inválida e a tarefa
  continua no Backlog

#### AC-086 — Direcionar para o Backlog exige responsável

- **Dado** uma tarefa na coluna Aberta, sem responsável
- **Quando** tento movê-la para o Backlog
- **Então** o movimento é recusado com aviso de que é preciso direcionar a
  tarefa para alguém antes

#### AC-087 — Devolver para ajustes exige dizer o que corrigir

- **Dado** uma tarefa em Em testes
- **Quando** a movo para Ajustes necessários sem escrever o que precisa ser
  corrigido
- **Então** o movimento é recusado com aviso de que a descrição é obrigatória

#### AC-088 — Cancelar exige motivo

- **Dado** uma tarefa em qualquer etapa que aceite cancelamento
- **Quando** a cancelo sem informar o motivo
- **Então** o cancelamento é recusado com aviso de que o motivo é obrigatório

#### AC-089 — Concluir exige relatório de teste aprovado

- **Dado** uma tarefa em Em testes cujo último relatório de teste foi reprovado
  (ou que não tem relatório nenhum)
- **Quando** tento concluí-la
- **Então** a conclusão é recusada com aviso de que só é possível concluir após
  um relatório de teste aprovado; registrando um relatório aprovado, a mesma
  conclusão passa

> **Revisto em 11/08/2026 (AC-186).** O relatório vale para a PASSAGEM em que
> foi escrito, não para a tarefa inteira, e a exigência é do tipo
> desenvolvimento (AC-177).

#### AC-090 — Tarefa concluída pode ser reaberta para desenvolvimento

- **Dado** uma tarefa Concluída
- **Quando** a reabro
- **Então** ela volta para Em andamento

> **Revisto em 11/08/2026 (US-056).** A segunda metade deste critério dizia que
> a Cancelada não tinha saída nenhuma. A intenção era boa — cancelar é uma
> decisão, e decisão não se desfaz de leve —, mas cancelar é um clique, e o
> preço do clique errado era o histórico inteiro: a única saída viável passou a
> ser recadastrar a tarefa do zero, jogando fora a conversa e o cronômetro que
> este quadro existe para guardar. Um estado sem volta alcançável por acidente
> é uma armadilha, não uma garantia. A saída nova é AC-184.

#### AC-122 — O menu "Mover" oferece de verdade os destinos permitidos

- **Dado** o quadro aberto, com uma tarefa em Em testes
- **Quando** abro o menu "Mover" do card dela
- **Então** a lista de destinos traz Concluída, Ajustes necessários, Em
  andamento, Bloqueada e Cancelada — e nada além disso; tarefa cancelada não
  aparece no quadro, então não há card nem menu para ela ali (o caminho de volta
  dela é o do histórico, AC-184)

> **Revisto em 11/08/2026.** Os destinos passaram a depender do TIPO da tarefa
> (AC-177), e os dois novos são recuos (US-056).

#### AC-124 — Devolver do Backlog para Aberta solta o responsável

- **Dado** uma tarefa no Backlog, direcionada a alguém
- **Quando** a movo de volta para Aberta
- **Então** ela volta para a coluna Aberta sem responsável, pronta para ser
  direcionada a outra pessoa

### US-038 — Tempo por etapa

Como pessoa do time, quero ver quanto tempo cada tarefa passou em cada etapa,
para saber onde o ciclo está travando.

#### AC-091 — Cada mudança de etapa registra entrada, saída e duração

- **Dado** uma tarefa que se moveu de Em desenvolvimento para Em testes
- **Quando** consulto o histórico dela
- **Então** o registro da etapa anterior tem hora de entrada, hora de saída e a
  duração em segundos, e o registro da etapa atual está aberto (sem saída)

#### AC-092 — O card mostra há quanto tempo a tarefa está parada na etapa

- **Dado** uma tarefa que entrou na etapa atual há mais de uma hora
- **Quando** abro o quadro
- **Então** o card mostra o tempo decorrido na etapa em forma curta
  (ex.: "3h", "2d"), e tarefas que acabaram de entrar aparecem como "agora"

#### AC-093 — Tarefa esquecida em Aberta ou Em testes ganha destaque

- **Dado** uma tarefa parada há mais de 24 horas em Aberta ou Em testes
- **Quando** abro o quadro
- **Então** o card recebe destaque de atenção; passando de 48 horas o destaque
  vira crítico; tarefas em outras etapas nunca recebem esse destaque

### US-039 — Acesso restrito à matriz

Como administrador da Alfa, quero que o quadro de desenvolvimento seja visível
só para a matriz, para que revendas não enxerguem o backlog interno.

#### AC-094 — Usuário de revenda não vê o grupo Desenvolvimento no menu

- **Dado** que estou autenticado como usuário com escopo de revenda
- **Quando** abro qualquer tela do painel
- **Então** o grupo "Desenvolvimento" não aparece no menu lateral

#### AC-095 — Usuário de revenda recebe 403 nas rotas de tarefas

- **Dado** que estou autenticado como usuário com escopo de revenda
- **Quando** acesso a rota do quadro ou tento criar/mover uma tarefa pela URL
- **Então** recebo 403, sem ver conteúdo nenhum do quadro

### US-040 — Quadro enxuto, histórico inteiro

Como pessoa do time, quero que o quadro mostre só o que ainda é atual, mas sem
perder nada, para que a auditoria de qualquer tarefa antiga continue possível.

#### AC-096 — Encerrar a tarefa a tira do quadro

- **Dado** uma tarefa em Em testes
- **Quando** eu a concluo (ou cancelo)
- **Então** ela deixa de aparecer no quadro na mesma hora — sem recorte de
  data, sem coluna terminal — e passa a viver no histórico

#### AC-097 — O histórico completo continua acessível

- **Dado** uma tarefa concluída há mais de 30 dias, fora do recorte do quadro
- **Quando** abro o histórico de tarefas
- **Então** ela aparece na listagem, com sistema, responsável, etapa final e
  data, sem nenhum recorte por período aplicado

#### AC-125 — Quadro e Histórico são duas abas da mesma tela

- **Dado** que estou em Tarefas
- **Quando** olho o topo da tela
- **Então** vejo as abas Quadro e Histórico, com a atual marcada como ativa, e
  passo de uma para a outra em um clique — sem precisar digitar a URL

#### AC-131 — A tarefa concluída é reaberta pelo histórico

- **Dado** uma tarefa concluída, que não está mais no quadro
- **Quando** abro o histórico
- **Então** ela oferece reabrir, e reabrir a devolve para Em desenvolvimento,
  de volta ao quadro; tarefa cancelada não oferece esse caminho

#### AC-133 — O histórico conta quanto a tarefa custou

- **Dado** uma tarefa encerrada, que passou por várias etapas
- **Quando** abro o histórico
- **Então** a linha dela mostra, além do desfecho, a prioridade que tinha, o
  resumo do que era e **quanto tempo levou do início ao encerramento** — o
  número que só existe porque cada etapa foi cronometrada

### US-049 — Comentários na tarefa

Como pessoa do time, quero comentar na tarefa, para registrar o que o título e
o resumo não cabem — o que o cliente disse, o que falta, em que ordem — e que
esse detalhe fique datado e assinado junto da tarefa, e não num chat à parte.

#### AC-134 — O comentário é publicado junto com o Salvar da tarefa

- **Dado** uma tarefa do quadro
- **Quando** abro o card, escrevo um comentário e salvo
- **Então** o mesmo envio grava o cadastro e publica o comentário, que passa a
  aparecer na tarefa com o nome de quem escreveu e a data, do mais antigo para
  o mais novo, e o card anuncia quantos comentários existem — com o campo em
  branco, salvar não publica nada, e clicar em Salvar duas vezes seguidas
  publica o comentário uma vez só

#### AC-135 — O comentário é texto puro

- **Dado** que estou escrevendo um comentário
- **Quando** enumero itens à mão, quebro linhas ou digito HTML
- **Então** o que aparece na tela é exatamente o que foi digitado, com as
  quebras de linha preservadas: nada é interpretado — o traço continua traço e
  o HTML chega como texto

#### AC-138 — O autor corrige o próprio comentário, e a correção fica dita

- **Dado** um comentário que escrevi
- **Quando** clico no lápis, mudo o texto e confirmo
- **Então** o comentário passa a mostrar o texto novo, marcado como editado e
  guardando a data original — sem trocar de lugar na conversa. Corrigir o
  comentário de outra pessoa é recusado, e correção vazia também: quem quis
  apagar tem o botão de apagar

#### AC-136 — A conversa é do autor, e sobrevive ao encerramento

- **Dado** um comentário que escrevi
- **Quando** o apago
- **Então** ele sai da tarefa; o comentário de outra pessoa não me oferece essa
  ação e a rota a recusa. E quando a tarefa é concluída ou cancelada, a conversa
  continua legível pelo histórico — só leitura, porque escrever de novo é
  reabrir a tarefa

### US-054 — O quadro cabe o trabalho que não é desenvolvimento

Como pessoa do time da Alfa, quero abrir no mesmo quadro uma tarefa que não é
de desenvolvimento — entrar em contato com o fabricante do equipamento, renovar
um certificado —, para não ter um segundo lugar para olhar e uma dúvida a cada
cadastro sobre onde a tarefa mora.

O fluxo de hoje obrigaria essa tarefa a fingir que foi desenvolvida e testada
para poder ser concluída. O `ASM-034` já admitia trabalho que não pertence a
produto nenhum ao deixar o sistema opcional; o fluxo é que não acompanhou.

#### AC-177 — A tarefa operacional fecha direto de Em andamento

- **Dado** uma tarefa do tipo Operacional em Em andamento
- **Quando** a concluo
- **Então** ela é concluída sem passar por Em testes e sem relatório nenhum, e
  Em testes não é oferecida como destino para ela

#### AC-178 — O tipo abre um caminho, não afrouxa o outro

- **Dado** uma tarefa do tipo Desenvolvimento em Em andamento
- **Quando** tento concluí-la direto
- **Então** o movimento é recusado como transição inválida: o atalho da
  operacional não vale para ela

#### AC-179 — O tipo se anuncia no card e recorta o quadro

- **Dado** o quadro com tarefas dos dois tipos
- **Quando** olho os cards
- **Então** só a operacional traz o selo do tipo — marcar as duas encheria o
  quadro de um selo que não diz nada, e o que se precisa saber de relance é qual
  card vai pular a coluna de testes; e o filtro de tipo isola um dos dois

### US-055 — Parar o trabalho sem sumir com a tarefa

Como responsável por uma tarefa, quero poder declarar que ela travou esperando
alguém, para que ela não fique apodrecendo numa coluna que diz que estou
trabalhando nela.

Até aqui, de Em andamento só se saía para Em testes ou para o cancelamento.
Quando a tarefa travava — o cliente não responde, falta acesso, o fabricante não
retorna —, não havia para onde levar o card.

#### AC-180 — Bloquear exige dizer o que está travando

- **Dado** uma tarefa em curso
- **Quando** a bloqueio sem escrever o que trava
- **Então** o bloqueio é recusado; com o texto, ela é marcada como travada e o
  motivo fica guardado nela

#### AC-181 — A tarefa travada não sai da etapa

- **Dado** uma tarefa em Em testes
- **Quando** ela é bloqueada e depois destravada
- **Então** ela continua em Em testes o tempo todo — o bloqueio é marca, não
  lugar (US-058)

> **Revisto em 11/08/2026.** Estes dois critérios nasceram com Bloqueada sendo
> coluna e foram reescritos quando ela virou marca. O handoff de design pediu a
> mudança, e o argumento decisivo estava no próprio código: o mapa de transições
> precisava listar Em testes como volta de Bloqueada, à mão, para reconstruir a
> etapa que a coluna tinha apagado.

### US-056 — Recuar não precisa de permissão

Como pessoa do time, quero poder devolver a tarefa para a etapa anterior sem
ritual, para não ter de arrastar o card por uma etapa que não aconteceu só para
chegar onde a realidade já está.

O princípio: **restringir o avanço, liberar o recuo**. Avançar pula trabalho, e
é por isso que avançar se guarda. Um quadro que recusa a volta não impede o
erro — ele ensina a mentir para o quadro, e como cada etapa é cronometrada, a
mentira não fica na tela: ela contamina o número.

#### AC-182 — De Em andamento a tarefa volta ao Backlog

- **Dado** uma tarefa em Em andamento
- **Quando** a devolvo para o Backlog
- **Então** ela volta, sem exigência nenhuma além das que o Backlog já faz

#### AC-183 — De Em testes ela volta a Em andamento sem declarar reprovação

- **Dado** uma tarefa em Em testes que foi movida para lá por engano
- **Quando** a devolvo para Em andamento
- **Então** ela volta sem passar por Ajustes necessários e sem registrar
  relatório nenhum — obrigar toda volta a virar reprovação sujava o sinal de
  retrabalho: a coluna deixava de dizer "a qualidade está ruim" e passava a
  dizer "alguém clicou errado"

#### AC-184 — A cancelada volta para a fila, sem dono

- **Dado** uma tarefa cancelada, no histórico
- **Quando** clico em Reabrir
- **Então** ela volta para Aberta e sem responsável — retomar o que foi
  cancelado é decisão nova, provavelmente de outra pessoa; a concluída continua
  voltando para Em andamento (AC-090, AC-131)

#### AC-185 — Direcionar move a tarefa; tirar o dono a devolve para a fila

- **Dado** uma tarefa em Aberta
- **Quando** escolho um responsável e salvo
- **Então** ela vai para o Backlog no mesmo gesto, com o movimento registrado no
  histórico de etapas — e tirar o responsável de uma tarefa do Backlog a devolve
  para Aberta. Na criação isso já valia; na edição, o mesmo fato tinha
  comportamento diferente e exigia arrastar o card em seguida

### US-057 — O relatório prova a passagem, não a tarefa

Como responsável pela entrega, quero que o teste aprovado valha para o código
que foi testado, para que reabrir uma tarefa não venha com a aprovação antiga
embutida.

#### AC-186 — Reconcluir depois de reabrir exige relatório novo

- **Dado** uma tarefa concluída com relatório aprovado, reaberta e levada de
  novo até Em testes
- **Quando** tento concluí-la sem registrar um relatório novo
- **Então** a conclusão é recusada: o relatório aprovado é da passagem anterior,
  e o teste que provava o código de antes não prova o de depois. O mesmo vale
  para a tarefa que voltou de Ajustes necessários

### US-058 — O bloqueio deixa de ser lugar e vira marca

Como pessoa do time, quero que a tarefa travada continue na etapa em que está,
para que o quadro não minta sobre onde o trabalho parou.

Bloqueada foi coluna por um dia. Como coluna, ela **apagava a etapa** em que a
tarefa estava — e o fluxo tinha de reconstruir isso na mão, oferecendo Em testes
como volta só para não devolver à bancada o código que estava em teste. Era
contorno em cima de informação jogada fora. É a mudança estrutural do redesenho
(handoff de 11/08/2026).

#### AC-190 — A tarefa travada não muda de etapa, e mover destrava

- **Dado** uma tarefa em Em testes
- **Quando** eu a bloqueio
- **Então** ela continua em Em testes, ganha a marca e o motivo, e nenhum evento
  de etapa nasce disso — `tarefa_eventos` mede permanência em etapa, e uma linha
  ali faria o cronômetro contar duas passagens onde houve uma. Movê-la para
  outra etapa tira a marca: o bloqueio é sempre sobre o trabalho de uma etapa, e
  carregá-lo adiante faria o card anunciar um impedimento que já não vale

#### AC-191 — A tarja do card diz há quanto tempo e por quê

- **Dado** uma tarefa travada no quadro
- **Quando** olho o card dela
- **Então** leio uma tarja âmbar com o tempo travado e o motivo em até duas
  linhas — o motivo ocupa a largura inteira, porque truncado ele só existiria no
  tooltip, e ele viajar junto da etapa é o argumento inteiro da mudança — com o
  botão de destravar ao lado, onde está quem acabou de ler que o motivo não vale

#### AC-192 — A faixa Bloquear recebe o card e conta as travadas

- **Dado** o quadro com tarefas travadas
- **Quando** arrasto um card até a faixa Bloquear
- **Então** o painel de motivo abre; e a faixa mostra quantas tarefas do recorte
  estão travadas, como os contadores das colunas

### US-059 — O quadro mede o que trava e o que ainda não foi decidido

Como pessoa do time, quero que o quadro avise sozinho quando uma etapa está
cheia, quando uma tarefa envelheceu e quando ninguém priorizou, para que essas
três coisas não dependam de alguém reparar nelas.

#### AC-193 — O envelhecimento tem régua própria por etapa

- **Dado** uma tarefa parada há dois dias em Em andamento
- **Quando** olho o card
- **Então** ela ainda não acende — três dias escrevendo código é trabalho —, e
  passa a acender depois de 72h; em Em testes e em Aberta bastam 24h, em
  Ajustes 48h, e o Backlog nunca envelhece, porque lá ficar parada é o que a
  tarefa deve fazer

> **Revisto em 11/08/2026.** O AC-093 media só Aberta e Em testes, com 24h para
> as duas. A tarefa que mais apodrece é a de Em andamento parada há dias, e ela
> não era medida por ninguém.

#### AC-194 — "A definir" é prioridade, e o cabeçalho conta a triagem

- **Dado** uma tarefa sem prioridade escolhida
- **Quando** olho a coluna dela
- **Então** o selo diz "A definir" em âmbar, o card fica no fim da ordem — ela
  não é o grau mais baixo, é a decisão que não foi tomada, e passar na frente do
  que alguém chamou de crítico seria mentira — e o cabeçalho da coluna conta
  quantas aguardam triagem

#### AC-195 — O limite de WIP conta só o que anda

- **Dado** Em andamento com quatro tarefas, uma delas travada
- **Quando** olho o contador
- **Então** ele diz 3/3 e não acusa excesso: vaga ocupada por tarefa travada não
  é trabalho em curso, e somá-la faria o quadro reclamar justamente quando o
  time está impedido de produzir. Destravando a quarta, o contador vira 4/3 em
  âmbar. Fila não tem limite: encher o Backlog não atrapalha ninguém

#### AC-202 — O rodapé do card traz a marca do sistema e o nome de quem responde

- **Dado** um card com responsável e sistema
- **Quando** olho o rodapé
- **Então** vejo o ícone do sistema no círculo e o nome do responsável ao lado,
  primeiro e último — os dois nomes disputavam a mesma linha e saíam truncados,
  e a marca do produto é reconhecida sem ler, enquanto duas iniciais no círculo
  pediam decorar quem é "JR". Cada um guarda o valor inteiro no `title`. Um card
  sem sistema traz o círculo tracejado, e um sem responsável diz a frase por
  extenso (AC-130): contorno vazio é símbolo, e a fila de triagem não pode
  depender de quem já o aprendeu

### US-060 — Checklist dentro da tarefa

Como pessoa do time, quero uma lista de conferência dentro da tarefa, para
registrar os passos dela sem abrir uma tarefa para cada passo.

É **checklist, não subtarefa**: o item não tem responsável nem etapa, não entra
no limite de WIP e não vai para o histórico. Subtarefa obrigaria a responder em
que coluna ela mora, se conta no WIP e se o pai anda sozinho quando a filha
trava. Trabalho que precisa de dono próprio vira tarefa irmã.

#### AC-196 — O item entra no fim, e a ordem é de quem escreve

- **Dado** um checklist com itens
- **Quando** incluo um item novo
- **Então** ele entra no fim da lista; e arrastar um item reordena a lista, que
  é gravada inteira — um checklist é uma sequência, e o item lembrado depois
  pode ser o primeiro passo

#### AC-197 — Marcar, corrigir e remover são de qualquer um

- **Dado** um item escrito por outra pessoa
- **Quando** eu o marco, corrijo o texto ou o removo
- **Então** todos funcionam: diferente do comentário, o item não é do autor —
  checklist é combinado do time, e quem confere um passo raramente é quem o
  escreveu. Texto em branco não apaga o item; quem quis remover tem o remover

#### AC-198 — O card mostra o progresso, e só quando há checklist

- **Dado** uma tarefa com três itens, um feito
- **Quando** olho o card
- **Então** leio o progresso 1/3; e a tarefa sem checklist não mostra "0/0", que
  anunciaria como pendência uma lista que não existe

#### AC-199 — Reordenar não alcança checklist de outra tarefa

- **Dado** a lista de ids chegando do navegador
- **Quando** ela traz um id que não é desta tarefa
- **Então** esse item é ignorado e a ordem dele fica intacta

#### AC-200 — O item morre com a tarefa

- **Dado** uma tarefa com checklist
- **Quando** a tarefa é apagada
- **Então** os itens vão junto, sem deixar linha órfã

#### AC-201 — Revenda não alcança o checklist

- **Dado** que estou autenticado como usuário com escopo de revenda
- **Quando** acesso qualquer rota de item pela URL
- **Então** recebo 403, como no resto do quadro (AC-095)

### US-061 — Triagem é capacidade, não cargo

Como administrador da Alfa, quero que abrir e tocar tarefa não exija a
capacidade de organizar o trabalho dos outros, para que quem executa não
precise de acesso de quem prioriza.

A regra é sobre **capacidade**, e a tela nunca nomeia cargo. Ela entra no
esquema de permissões que já existe, como o recurso `tarefas_triagem` — e o
perfil "Membro do time" é simplesmente quem tem `tarefas` sem ele.

#### AC-203 — Quem não triaga não decide prioridade nem responsável

- **Dado** que não tenho a capacidade de triagem
- **Quando** abro uma tarefa
- **Então** o formulário não mostra prioridade nem responsável, diz uma vez que
  essas duas coisas são decididas na triagem, e a tarefa nasce **A definir** e
  sem dono — e mandar os campos à mão, por formulário guardado ou POST forjado,
  não passa por cima disso: esconder na tela é sugestão, não regra. Ao salvar
  uma tarefa já existente, o que a triagem decidiu fica como está

#### AC-204 — Quem não triaga move só o que está com ele, e a recusa é dita

- **Dado** uma tarefa que está com outra pessoa
- **Quando** tento movê-la
- **Então** a recusa diz de quem é a tarefa e que só a triagem move o trabalho
  alheio; a minha própria anda normalmente. A fila de triagem fica fora do
  alcance sem regra própria, porque entrar em Aberta solta o responsável
  (AC-130) e nada de lá está com alguém

#### AC-205 — O quadro não oferece o que vai recusar

- **Dado** o quadro visto por quem não triaga
- **Quando** olho um card que está com outra pessoa
- **Então** ele não arrasta, não traz o menu de mover e explica o porquê ao
  passar o mouse; reabrir pelo histórico segue a mesma regra

#### AC-206 — Não se restringe o que é trabalho

- **Dado** qualquer tarefa do quadro, mesmo de outra pessoa
- **Quando** comento, bloqueio ou mexo no checklist dela
- **Então** funciona. Travar isso não impede ninguém de trabalhar em algo não
  pedido — impede de **registrar**, e aí o quadro passa a mentir sobre o que
  está acontecendo

#### AC-207 — Quem entra chega numa tela que abre

- **Dado** uma conta que só alcança o quadro de tarefas
- **Quando** faço login
- **Então** caio no quadro, e não num 403: o destino fixo do login valia
  enquanto todo perfil enxergava o Centro de Controle, e a senha certa levando a
  uma parede se lê como conta quebrada. Para quem alcança o Centro de Controle,
  ele continua sendo a casa

### US-062 — Duas mãos no mesmo quadro

Como pessoa do time, quero que o quadro não perca movimento de outra pessoa e
que a ordem da coluna possa ser escolhida à mão, para que o que está na tela
seja o que está acontecendo.

#### AC-208 — Movimento sobre movimento alheio é recusado

- **Dado** um card que outra pessoa já moveu enquanto o meu menu estava aberto
- **Quando** confirmo o meu movimento
- **Então** ele é recusado dizendo para onde a tarefa já foi. Antes o segundo
  envio ganhava em silêncio, e quem moveu primeiro só descobria ao recarregar

#### AC-209 — A coluna arrumada à mão fica como foi arrumada

- **Dado** uma coluna cuja ordem alguém escolheu arrastando
- **Quando** volto a ela
- **Então** a ordem escolhida manda; a coluna que ninguém tocou segue a régua
  automática (AC-128), e o card que chega depois entra no fim, sem posição.
  Mudar de etapa apaga a posição, que é sempre dentro de UMA coluna. Posicionar
  é organizar trabalho alheio, então pede a mesma capacidade de triagem

#### AC-210 — Excluir apaga; cancelar encerra

- **Dado** uma tarefa aberta por engano
- **Quando** uso o excluir do rodapé do detalhe, em dois passos
- **Então** o registro some de verdade — e a tela declara ali mesmo que, para
  encerrar mantendo o histórico, o caminho é cancelar. Excluir é só de quem
  triaga

#### AC-211 — O card separa o clique do arrasto

- **Dado** que o card abre no clique e também arrasta
- **Quando** começo um arrasto curto
- **Então** o detalhe não abre: o clique só vale se o ponteiro andou menos de
  4px e não houve arraste nos últimos 300ms

#### AC-212 — Criação rápida no pé da coluna Aberta

- **Dado** que quero abrir uma tarefa que cabe numa frase
- **Quando** escrevo no campo do pé da coluna e dou Enter
- **Então** a tarefa é criada com esse título; o campo existe só em Aberta,
  porque é lá que a tarefa sem responsável nasce

### US-063 — O mesmo quadro em três leituras

Como pessoa do time, quero ler o quadro agrupado, no celular e pelo teclado,
para que a tela sirva à pergunta do momento e ao aparelho que estiver na mão.

#### AC-213 — As raias agrupam sem esconder

- **Dado** o quadro com tarefas de várias pessoas
- **Quando** escolho a raia por Responsável (ou por Sistema)
- **Então** o quadro se separa em faixas, com o cabeçalho das etapas fixo no
  topo, e nada é escondido — filtro esconde, raia separa. "Sem responsável" é a
  última faixa: é uma pergunta em aberto, não um grupo

#### AC-251 — O cabeçalho fixo das raias esconde o que passa por baixo

- **Dado** o quadro em raias, no tema ESCURO, com cards suficientes para rolar
- **Quando** rolo a lista
- **Então** os cards somem atrás do cabeçalho das etapas em vez de aparecerem
  através dele. O fundo da barra fixa empilha o véu do quadro sobre a base da
  página: sozinho, o véu é translúcido no escuro (`rgba(0,0,0,0.28)`) e sólido no
  claro — e foi por isso que o defeito existiu só num dos temas

#### AC-258 — O cabeçalho fixo das raias se lê como camada, não como corte

- **Dado** o quadro em raias, com cards passando por baixo do cabeçalho ao rolar
- **Quando** olho a linha onde eles somem
- **Então** o cabeçalho tem borda inferior de 1px, e o card desliza para trás de
  algo em vez de ser cortado no meio por nada. É a regra que o Topbar e o
  cabeçalho do próprio quadro já seguem — esta era a única barra fixa sem ela

#### AC-257 — Em raias, a coluna não engole a rolagem do quadro

- **Dado** o quadro em raias, com o cursor parado sobre um card
- **Quando** giro a roda do mouse
- **Então** o quadro rola. A coluna só contém a rolagem onde ela é quem rola —
  no quadro sem raias —, porque em raias quem rola é a tela inteira e conter em
  cada coluna transformava a maior parte dela em barreira: rolar virava caçar um
  vão entre os cards

#### AC-214 — A raia de quem está com trabalho demais se anuncia

- **Dado** alguém com mais de duas tarefas andando ao mesmo tempo
- **Quando** olho a raia dessa pessoa
- **Então** ela traz o selo de trabalho em paralelo; tarefa travada não conta,
  porque quem espera terceiro não está tocando nada. O selo não existe nas raias
  por sistema

#### AC-215 — No celular, uma etapa por vez

- **Dado** o quadro num telefone
- **Quando** abro a tela
- **Então** vejo uma etapa por vez, trocada por uma tira de chips com a contagem
  e o limite, sem as faixas de solto — no toque não há arrastar. A troca é regra
  de CSS: decidida em JavaScript, ela mostraria as cinco colunas por um quadro
  antes de esconder quatro

#### AC-216 — O quadro anda pelo teclado

- **Dado** o quadro aberto
- **Quando** uso as setas
- **Então** ando pelos cards e pelas colunas, `⇧+←/→` move a tarefa de etapa
  recusando o que o fluxo não permite, `B` bloqueia, `M` abre o menu, `Enter`
  abre a tarefa, `C` e `N` criam, `/` busca, `Esc` fecha e `?` lista tudo — e
  nada dispara enquanto se digita, senão escrever "backlog" na busca moveria
  cards pelo caminho

### US-070 — Raia com filtro não é grade, é lista

Como pessoa do time, quero que o quadro agrupado deixe de ser uma grade quando
eu aplico um filtro, para parar de rolar nos dois eixos atrás de meia dúzia de
tarefas espalhadas em células vazias.

Raia é uma grade de duas dimensões: pessoas (ou sistemas) na vertical, seis
etapas na horizontal. Sem filtro ela se paga — é o retrato do time. Com filtro,
não: o recorte esvazia as células mas o custo de layout de cada faixa continua
inteiro (6 × 272px de largura, 180px de altura mínima por faixa), e sobra rolar
muito para ver pouco. Encolher coluna não resolve — o problema é a dimensão a
mais, não a largura dela.

#### AC-252 — Raia com filtro vira tabela agrupada

- **Dado** o quadro em raias (por responsável ou por sistema) com um filtro aplicado
- **Quando** a tela é carregada
- **Então** no lugar da grade vem uma tabela: uma seção por raia, uma linha por
  tarefa, e a etapa como coluna do registro em vez de posição no espaço

#### AC-253 — Sem filtro, a raia continua sendo a grade de sempre

- **Dado** o quadro em raias sem nenhum filtro aplicado
- **Quando** a tela é carregada
- **Então** as faixas e as colunas continuam como estão hoje — a troca é resposta
  ao recorte, e não uma mudança de opinião sobre raias

#### AC-254 — A coluna que a seção já diz não se repete

- **Dado** a tabela agrupada por responsável (ou por sistema)
- **Quando** olho as colunas
- **Então** a que a seção já nomeia não aparece de novo em cada linha — agrupar
  por responsável e repetir o nome em toda linha é gastar largura para dizer o
  que o cabeçalho da seção acabou de dizer

#### AC-255 — Da tabela se trabalha, não só se lê

- **Dado** uma linha da tabela
- **Quando** a uso
- **Então** clicar abre a tarefa no mesmo modal do quadro, e o menu "Mover ▾"
  move de etapa com as mesmas regras de fluxo e o mesmo pedido de motivo — sem
  isso a tabela seria um relatório, e o recorte é justamente onde se trabalha

#### AC-256 — A porta de volta fica à vista

- **Dado** a tabela aberta por causa de um filtro
- **Quando** quero o quadro mesmo assim
- **Então** um link no cabeçalho devolve a grade sem tirar o filtro — trocar o
  layout de alguém sem deixar como voltar é decidir por ela

### US-064 — Anexo na tarefa

Como pessoa que revisa, quero anexar arquivos à tarefa — print, log, planilha ou
PDF, escolhendo o arquivo ou colando da área de transferência —, para mostrar o
defeito em vez de descrevê-lo.

"O botão saiu do lugar" é uma frase que só quem viu a tela entende. Na revisão,
descrever por escrito custa rodadas que uma captura encerra de uma vez — e é a
conversa empacada (três idas e voltas, AC-190) que essa captura evita. O log do
erro e a planilha do cliente encerram a mesma dúvida pelo mesmo motivo: a
história nasceu só com imagem em 13/08/2026 e foi generalizada no mesmo dia,
quando ficou claro que "ser figura" nunca foi a razão pela qual se anexa algo.

O anexo passou a caber na CRIAÇÃO no mesmo dia (AC-234), pelo mesmo motivo: a
tarefa quase sempre nasce de algo que já está na tela de quem a abre, e a
seção só existia depois de salvar — o que transformava anexar num segundo
gesto, que se deixa para depois.

#### AC-223 — O anexo entra por arquivo ou por Ctrl+V

- **Dado** uma tarefa aberta
- **Quando** escolho um arquivo, ou colo um print com a tarefa aberta
- **Então** ele entra na seção de anexos da tarefa, com nome, tamanho e quem
  anexou. O anexo é da TAREFA, e não do comentário: a prova não depende de achar
  em qual comentário ela foi parar. O que a área de transferência entrega sem
  nome ("image.png") vira `captura-AAAA-MM-DD-HHMMSS`, senão três prints colados
  viram três legendas idênticas — e é justamente a legenda que distingue o que a
  miniatura, pequena, já não distingue

#### AC-224 — O print grande entra mesmo assim

- **Dado** uma captura de tela cheia, acima do teto por arquivo
- **Quando** a anexo
- **Então** o navegador a encolhe para 1920px no maior lado antes de enviar, e
  ela entra. O arquivo que já cabe é enviado INTACTO — recodificar por precaução
  borraria o texto de todo print por um problema que aquele arquivo não tinha.
  Só FIGURA é encolhida: um log cortado pela metade não é um log menor, é outro
  arquivo. Os limites são do PHP de produção, não de produto: 12 MB por arquivo
  (`upload_max_filesize`) e três por envio, com a SOMA do lote limitada a 15 MB
  porque estourar `post_max_size` faz o PHP descartar o corpo inteiro do POST e
  chegar como erro de CSRF, sem relação nenhuma com tamanho

#### AC-225 — Anexar não descarta o que está escrito

- **Dado** um comentário sendo escrito no modal, ainda não publicado
- **Quando** colo um print no meio da frase
- **Então** a imagem sobe sem recarregar a tela e o texto continua onde estava.
  O gesto que a galeria serve é justamente esse — colar a prova do que se está
  escrevendo —, e o caminho do checklist (enviar e voltar) apagaria o rascunho
  no momento exato em que ele importa

#### AC-226 — Quem anexou apaga; o anexo morre com a tarefa e sobrevive ao autor

- **Dado** um anexo de outra pessoa
- **Quando** tento removê-lo
- **Então** a rota recusa e o botão nem aparece — mesma regra do comentário, e
  não a do checklist: o item é combinado do time, mas o anexo é o que ALGUÉM
  mostrou para sustentar um argumento. Excluir a tarefa leva os anexos junto; a
  saída de quem anexou não leva — a legenda passa a dizer "Autor removido" e a
  prova fica

#### AC-227 — O card conta, e o anexo só sai pela rota

- **Dado** uma tarefa com anexos
- **Quando** olho o card no quadro
- **Então** vejo um selo com a contagem, ao lado dos de checklist e comentários,
  e o card não fica mais alto — a miniatura no card custaria ~46px de altura
  justamente na etapa em que mais se anexa arquivo. Um selo só para print e log:
  a distinção entre eles não muda nada de fora do card. O arquivo mora no disco
  `public` porque é o único que sobrevive à publicação azul/verde, mas quem o
  entrega é rota com `auth` e `permissao:tarefas`, e não o `/storage` do disco —
  que o nginx passa a recusar, para que a frase anterior seja verdade

#### AC-228 — Revenda não alcança o anexo

- **Dado** um usuário com escopo de revenda
- **Quando** ele pede qualquer rota de anexo — anexar, ver ou remover
- **Então** recebe 403, como no resto do quadro (AC-095): sem isso o backlog
  interno vazaria por uma porta lateral, agora levando capturas de tela e log de
  cliente junto

#### AC-229 — Mexer na tarefa aberta não recarrega a tela

- **Dado** que estou com uma tarefa aberta e um comentário escrito, mas ainda
  não publicado, no campo de baixo
- **Quando** marco um item do checklist, corrijo um comentário já publicado,
  passo a vez ao outro lado ou travo a tarefa
- **Então** a página não recarrega: o que escrevi continua escrito, a rolagem
  do quadro e da coluna fica onde estava, e o cursor não sai do campo. Voltam
  do servidor o quadro redesenhado — porque travar muda o contador de WIP da
  coluna e os chips do cabeçalho, não só o card — e só as regiões do modal que
  aquela ação mexeu. O formulário da tarefa não volta em região nenhuma, e é
  essa ausência que preserva o que ainda não foi salvo
- **E** sem `Accept: application/json` a rota responde o redirect de antes, com
  `tarefa-aberta` reabrindo o modal: é o caminho do `<form>` puro, que é como
  estas ações continuam funcionando se o `fetch` falhar
#### AC-230 — Mexer no quadro também não recarrega

- **Dado** que estou com o quadro rolado até a quinta coluna
- **Quando** arrasto um card de etapa, reordeno dentro da coluna, crio uma
  tarefa, salvo o formulário ou excluo
- **Então** a página não recarrega e a rolagem fica onde estava. Arrastar é o
  gesto mais repetido desta tela, e era o que mais custava: cada arrasto
  repintava tudo e devolvia as seis colunas ao começo
- **E** a tarefa recém-criada abre ao clique — o bloco de modais volta
  redesenhado junto, senão o card apareceria no quadro sem ter modal
- **E** salvar, criar e excluir terminam com o modal fechado, como terminavam
  quando a página recarregava: quem fecha é a troca do bloco de modais, porque
  cada `x-modal` nasce fechado. O "nova tarefa" é fechado e esvaziado pelo
  nome, por viver fora desse bloco
- **E** a guarda de concorrência não muda de contrato ao mudar de transporte: o
  `de_status` continua sendo conferido (AC-208), e "Alguém já moveu esta
  tarefa" vira aviso em vez de troca de tela
- **E** o botão que se tranca no clique DESTRANCA quando a viagem acaba, dê no
  que der. Enquanto o Salvar recarregava a página, isso era de graça: voltava
  um formulário novo. Sem a recarga, ninguém desfazia — nem o modal de edição
  nem o de nova tarefa são redesenhados pela resposta do Salvar, e reabrir a
  tarefa mostrava "Salvando…" num botão morto. Na recusa é pior: ela deixa o
  modal aberto, e era justamente ali que faltava o botão para tentar de novo

#### AC-231 — Cada ação manda só o que ela mudou

- **Dado** um quadro com sessenta tarefas
- **Quando** marco um item do checklist
- **Então** o servidor NÃO devolve o quadro nem os modais. Eles custam 906 KB e
  2,2 MB — juntos, quase a página inteira —, e marcar um item mudou um "3/5"
  num card. Mandá-los por clique é trocar a recarga por outra recarga com
  outro nome
- **E** no lugar deles vêm alvos nomeados: o card que mudou, os seis cabeçalhos
  de etapa e a tira de chips, que somam ~27 KB. Os contadores entram sempre
  porque travar uma tarefa a tira da conta de trabalho em curso
- **E** o quadro inteiro só volta quando o card muda de LUGAR (mover, criar,
  excluir, salvar); os modais, só quando o conjunto de tarefas muda. Reordenar
  dentro da coluna não devolve nenhum dos dois: o navegador já reordenou antes
  de enviar, e é dele que a ordem sai
- **E** ao soltar, o card muda de coluna na hora, sem esperar a resposta. Sem
  isso ele ficava meio segundo na coluna de origem e o gesto parecia ter
  falhado — a resposta chega depois e corrige a posição, ou devolve o card com
  a frase que explica a recusa

#### AC-232 — O que não é figura entra pela mesma porta

- **Dado** uma tarefa aberta
- **Quando** anexo o log do erro, a planilha que o cliente mandou ou o PDF do
  boleto
- **Então** entram na mesma seção das imagens, e não numa segunda lista: quem
  anexa não distingue os dois no gesto, e separá-los custaria uma terceira caixa
  num modal de 620px. O que ainda os separa é a FORMA — figura vira miniatura na
  grade, arquivo vira linha com ícone, nome e tamanho —, porque um log de 800 KB
  não tem miniatura que se olhe e um print reduzido a uma linha de texto perde o
  que veio mostrar
- **E** entram imagem, PDF, texto, log, CSV e planilha do Excel; SVG e HTML não,
  porque são documento com script dentro e esta rota os devolveria no mesmo
  domínio da sessão de quem abre a tarefa; ZIP não, porque a validação não
  alcança o que está dentro dele
- **E** um `.log` entra sem `log` constar da lista de extensões aceitas: a regra
  do Laravel deduz a extensão do MIME do conteúdo, e texto puro deduz `txt`

#### AC-233 — O nome no disco sai do conteúdo, e o anexo só sai pela rota

- **Dado** um arquivo cujo conteúdo e cuja extensão discordam
- **Quando** o anexo
- **Então** o nome com que ele é gravado no disco vem do CONTEÚDO, nunca do nome
  enviado — o nome de origem fica só como legenda que a tela mostra. Sem isso, o
  nome de um arquivo dentro da pasta publicada seria escolhido por quem envia
- **E** só figura é entregue embutida (`inline`), porque a grade precisa dela
  dentro de um `<img>`; todo o resto sai como download. Não é medo do PDF: é que
  a lista de tipos é conferida uma vez só, no envio, e quem a ampliar daqui a um
  ano não vai reler a rota
- **E** o nginx recusa `/storage/` por inteiro. O symlink `public/storage` é
  criado por toda publicação e deixava qualquer anexo — de tarefa, de cobrança e
  de conta a pagar — legível por quem adivinhasse o nome, sem sessão nenhuma,
  enquanto o código dizia que o arquivo só sai por rota com `auth`. Nenhuma tela
  do sistema monta URL de `/storage` (conferido em 13/08/2026): a recusa não
  tira nada de ninguém, só faz a frase do código virar verdade

#### AC-234 — A prova entra junto com a tarefa

- **Dado** o modal de nova tarefa aberto
- **Quando** escolho um arquivo, ou colo um print, antes de salvar
- **Então** ele aparece na mesma seção de anexos, com a mesma cara que teria na
  tarefa já aberta — miniatura para figura, linha para o resto —, e vai junto no
  Salvar. Quem abre uma tarefa quase sempre está olhando para o print que a
  motivou; pedir para salvar primeiro e anexar depois é pedir um segundo gesto,
  e o segundo gesto se deixa para depois — aí a tarefa nasce descrevendo por
  escrito o que já estava na tela
- **E** os arquivos viajam no MESMO POST que cria a tarefa, e não num envio
  depois dela: não existe id a que prendê-los antes disso, e criar primeiro para
  anexar em seguida deixaria a tarefa gravada e a prova perdida se o segundo
  envio falhasse
- **E** o teto de três vale para a LISTA INTEIRA na criação, e não por lote:
  antes de a tarefa existir não há próxima leva. O quarto arquivo entra com ela
  já aberta, e a tela diz isso em vez de recusar em silêncio
- **E** o arquivo recusado (tipo, tamanho, quantidade) recusa a criação INTEIRA.
  O envio é parcial, então a recusa chega com o modal aberto e o texto todo no
  lugar, e corrigir custa um clique — enquanto nascer sem o anexo seria a pior
  das duas saídas: a tarefa fica, a prova some, e ninguém é avisado
- **E** o que é colado ganha o nome datado já na tela, e não só depois de gravar:
  na criação a legenda aparece antes de o servidor ver o arquivo, e três
  "image.png" que trocam de nome sozinhos depois de salvar não distinguem nada

#### AC-235 — A miniatura não é baixada de novo a cada abertura

- **Dado** uma tarefa com prints anexados, que já abri antes
- **Quando** abro a tarefa outra vez
- **Então** as miniaturas vêm do navegador, e não da rede. Sair por rota com
  `auth` (AC-227) fazia o anexo herdar o `no-cache, private` de toda página com
  sessão, e sem `ETag` nem `Last-Modified` nem a revalidação teria como
  responder 304: cada abertura rebaixava todos os prints, um pedido de PHP
  inteiro por miniatura — sessão (que é no banco), `auth`, `permissao:tarefas` e
  mais duas consultas
- **E** a conta chegava toda no pior momento: a grade só pede as figuras quando
  o modal ABRE, porque `loading="lazy"` dentro de um modal fechado
  (`display:none`) não pede nada. A espera acontecia exatamente ao olhar
- **E** guardar é seguro porque o arquivo é imutável — cada envio cria linha e
  nome de disco novos, e o id nunca passa a apontar para outro conteúdo. É
  `private`, nunca `public`: o anexo está atrás de `auth`, e um cache
  compartilhado no caminho passaria a servir print e log de cliente a quem não
  tem sessão

## Fora de escopo

> O handoff de design de 11/08/2026 foi entregue por inteiro. O que segue
> abaixo continua fora — são itens do escopo original da feature, não do
> redesenho.
- Excluir tarefa (diferente de cancelar), com confirmação em dois passos.
- Imagem presa ao COMENTÁRIO, em vez de à tarefa (ver ASM-052), e imagem dentro
  do corpo do comentário.
- Visualizador próprio de imagem (zoom, girar): a miniatura abre em aba nova,
  onde o navegador já faz as três coisas.
- Prévia de PDF ou de planilha dentro do modal: a linha diz nome e tamanho, e
  abrir é baixar. Renderizar documento no painel de 620px custaria biblioteca
  nova para um arquivo que quase sempre se quer levar embora, não espiar.
- ZIP, executável, SVG e HTML como anexo (ver AC-232).
- Qualquer formatação no comentário: marcador de lista, numeração, markdown.
- Histórico de versões do comentário: a tela diz QUE foi corrigido, não o que
  dizia antes.
- Menção a pessoa (@) e notificação de comentário novo.
- Comentar direto pelo histórico, sem reabrir a tarefa.
- Modal de detalhe com histórico completo e linha do tempo visual.
- Painel de indicadores do ciclo (tempo médio, taxa de aprovação, timeline).
- Notificações (sino) de ticket aberto / tarefa direcionada / cancelada.
- Changelog automático para Discord e Telegram ao concluir.
- Exportação CSV/JSON do quadro.
- Barra de filtros (texto, sistema, responsável, prioridade, status) e as
  visões alternativas Compacto e Tabela.
- Cadastro de "projetos" separado do cadastro de Sistemas.
- Abertura de ticket por revenda ou por cliente final.
- Migração dos dados que já existem no Supabase do alfadev.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-030 | O responsável de uma tarefa é um usuário da Matriz sem escopo de revenda — não existe cadastro separado de "dev". | confirmada | Confirmado pelo usuário em 2026-08-10: responsável mínimo (select de usuário da matriz) entra no núcleo. |
| ASM-031 | A tarefa é vinculada a um Sistema já cadastrado, no lugar do "projeto" do alfadev. | confirmada | Confirmado pelo usuário em 2026-08-10. |
| ASM-032 | O acesso é controlado por um recurso de permissão novo, `tarefas`, no mesmo esquema de perfis/permissões já usado (`permissao:tarefas`), somado ao bloqueio por escopo de revenda. | confirmada | Implementado e provado: AC-095 (403 para revenda) e AC-094 (some do menu). O recurso está no `PerfilPermissaoSeeder`. |
| ASM-033 | O relatório de teste é registrado no próprio momento da transição (como no alfadev: a confirmação de "Em testes → Concluída" pede as notas do teste), sem tela separada de relatórios. | confirmada | Implementado e provado por AC-089: o relatório é gravado na própria confirmação do movimento, e um aprovado libera a conclusão na hora. |
| ASM-034 | O vínculo com sistema é opcional: tarefa interna que não pertence a nenhum produto (ex.: infraestrutura) pode ficar sem sistema. | confirmada | Implementado: `sistema_id` é anulável e o card mostra "Sem sistema" quando falta (AC-084, AC-116). |
| ASM-047 | O comentário é texto puro — sem markdown, lista, negrito, link ou imagem — e a conversa não notifica ninguém: quem acompanha a tarefa a abre. | confirmada | Confirmado pelo usuário em 2026-08-11: a formatação de lista foi entregue e retirada em seguida. O corpo sai pelo escape normal do Blade, sem conversão nenhuma — não há marcação para auditar nem sanitizador para manter. |
| ASM-048 | Só o autor corrige e apaga o próprio comentário, e toda correção fica marcada na tela. | confirmada | Confirmado pelo usuário em 2026-08-11, que pediu a edição depois da primeira entrega. Mexer no comentário alheio seria reescrever a conversa de outra pessoa; a marca de editado existe porque reescrever em silêncio faria a tarefa contar uma história que não aconteceu. O carimbo é coluna própria (`editado_em`), e não `updated_at`, que se move por qualquer gravação futura na linha. |
| ASM-049 | Os dois tipos dividem o mesmo quadro, em vez de cada um ter a sua tela. | confirmada | Confirmado pelo usuário em 2026-08-11. Separar criaria dois lugares para olhar e uma dúvida a cada cadastro ("isso é dev ou não?"), e a maior parte do que se pergunta ao quadro — o que está travado, o que está esquecido, quem está com o quê — é a mesma pergunta para os dois. Quem quiser o quadro do ciclo puro tem o filtro de tipo (AC-179). |
| ASM-050 | O tipo pode ser trocado depois do cadastro, e trocá-lo não prende a tarefa. | confirmada | Implementado: o fluxo operacional guarda saídas para Em testes e Ajustes necessários mesmo sem oferecer entrada nelas. Sem isso, trocar para Operacional uma tarefa que já estava em teste deixaria o card numa coluna sem nenhum caminho de volta. |
| ASM-051 | A etapa Bloqueada NÃO recebe o destaque de tarefa esquecida (AC-093). | aberta | Decisão de projeto, tomada em 2026-08-11: a escala de 24h/48h existe para etapa em que ninguém deveria estar parado, e a Bloqueada é a etapa em que estar parado é o esperado — esperar um fornecedor por uma semana é normal. Ela já se anuncia pela coluna própria e pelo motivo escrito. **Rever quando houver uso real:** se o time começar a esquecer tarefa bloqueada, o destaque volta, com uma régua mais larga. |
| ASM-052 | A imagem se prende à TAREFA, e não ao comentário: uma galeria só, cronológica, irmã do checklist no modal. | confirmada | Escolhido pelo dono do produto em 2026-08-13, entre prender ao comentário, prender à tarefa e as duas coisas. O que se compra: a prova não depende de achar em qual comentário ela foi parar, e a mesma imagem não aparece duas vezes na tela. O que se paga: a imagem não sabe de que fala — quem quiser amarrar uma captura a um argumento escreve o comentário do lado. **Rever se aparecer tarefa com muitas imagens de assuntos diferentes:** aí a galeria vira um monte e o vínculo com o comentário passa a valer o custo. |
| ASM-053 | O teto por arquivo e por envio é limite de INFRAESTRUTURA, não decisão de produto. | confirmada | Eram os padrões do Debian (`upload_max_filesize` 2M, `post_max_size` 8M) que o `deploy/provisionar.sh` não alterava — o nginx já aceitava 20M, quem barrava era o PHP. Os 2M cabiam enquanto só entrava imagem, que o navegador encolhe (AC-224); log, planilha e PDF não podem ser encolhidos, e por isso o provisionamento passou a escrever **12M/16M** num `conf.d` em 13/08/2026. **Pendência de operação:** o `provisionar.sh` não alcança máquina já provisionada — o `.ini` precisa ser aplicado à mão na produção atual, senão a validação aceita 12M e o PHP continua cortando em 2M. De quebra, é o que faz cobrança e conta a pagar pararem de prometer 10M (`CobrancaController:213`, `ContaPagarController:167`) e entregar 2M. |
| ASM-054 | O disco de upload não é servido pelo nginx; todo arquivo sai por rota com `auth`. | confirmada | O código sempre disse isso, e até 13/08/2026 não era verdade: o symlink `public/storage`, recriado por toda publicação, deixava qualquer anexo de tarefa, cobrança e conta a pagar legível por quem adivinhasse o nome — e o nome é `uniqid()` mais `time()`, que são o relógio, não segredo. O `location ^~ /storage/ { deny all; }` fecha isso (AC-233). Conferido no mesmo dia que nenhuma tela do sistema monta URL de `/storage`. **Rever se alguma feature futura precisar de arquivo público de verdade** (logo de revenda em e-mail, por exemplo): aí o caminho é um disco à parte, e não reabrir este. |
| ASM-035 | Os dados do alfadev não são migrados: o quadro do AlfaMatriz nasce vazio e o alfadev é desligado depois, manualmente. | aberta | **Decisão pendente do dono do produto.** Enquanto o alfadev seguir em uso, os dois bancos divergem. Migrar o histórico do Supabase é feature própria; desligar o alfadev sem migrar descarta o histórico dele. |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-013 | O quadro terá arrastar-e-soltar (como o Funil de Vendas) já nesta entrega, ou só o menu "Mover" no card? | respondida | 2026-08-10: os dois — arrastar como no Funil de Vendas, e o menu "Mover ▾" no card como caminho acessível (teclado, celular) e obrigatório nas transições que pedem texto. |
| Q-014 | Tarefa cancelada e concluída ficam no quadro para sempre, ou saem da visão depois de um tempo (ex.: só as concluídas dos últimos 30 dias)? | respondida | 2026-08-10: recorte de 30 dias nas colunas terminais, com um caminho para o histórico completo sem recorte, para auditoria (US-040). |
