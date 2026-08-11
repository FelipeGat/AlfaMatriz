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
- **Então** vejo as colunas Aberta, Backlog, Em andamento, Bloqueada, Em testes
  e Ajustes necessários nessa ordem, cada uma com o total de tarefas que estão
  nela — e nenhuma coluna de etapa terminal, porque tarefa encerrada não é
  trabalho em curso

> **Revisto em 11/08/2026.** A coluna "Em desenvolvimento" passou a se chamar
> **Em andamento** (US-054): ela recebe também tarefa operacional, que não é
> desenvolvida, e um telefonema parado numa coluna chamada "Em desenvolvimento"
> faria a coluna mentir. A chave do dado continua `em_desenvolvimento` — ela
> está gravada no histórico de etapas, e renomear o dado para arrumar um rótulo
> de tela reescreveria o passado. **Bloqueada** é coluna nova (US-055).

#### AC-083 — Tarefa sem responsável nasce Aberta; com responsável, nasce no Backlog

- **Dado** que estou criando uma tarefa
- **Quando** salvo sem escolher responsável
- **Então** ela aparece na coluna Aberta; e quando salvo escolhendo um
  responsável, ela aparece direto na coluna Backlog

#### AC-084 — A tarefa é vinculada a um sistema já cadastrado

- **Dado** o formulário de nova tarefa
- **Quando** escolho o sistema (AlfaGym, AlfaControl, …) e salvo
- **Então** o card do quadro mostra o sistema da tarefa, e só sistemas ativos
  são oferecidos na escolha

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

- **Dado** uma tarefa em Em andamento
- **Quando** a movo para Bloqueada sem escrever o que trava
- **Então** o movimento é recusado; com o texto, ela é bloqueada e o motivo fica
  gravado no histórico de etapas dela

#### AC-181 — A bloqueada volta para a etapa de onde parou, e o tempo parado fica medido

- **Dado** uma tarefa que foi bloqueada estando em Em testes
- **Quando** ela é destravada
- **Então** posso devolvê-la para Em testes, e não só para Em andamento — o
  código não voltou para a bancada, ele ficou esperando; e a etapa Bloqueada
  fecha com entrada, saída e duração, como qualquer outra

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

## Fora de escopo

- Anexos de imagem na tarefa (o alfadev tem; fica para depois).
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
| ASM-035 | Os dados do alfadev não são migrados: o quadro do AlfaMatriz nasce vazio e o alfadev é desligado depois, manualmente. | aberta | **Decisão pendente do dono do produto.** Enquanto o alfadev seguir em uso, os dois bancos divergem. Migrar o histórico do Supabase é feature própria; desligar o alfadev sem migrar descarta o histórico dele. |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-013 | O quadro terá arrastar-e-soltar (como o Funil de Vendas) já nesta entrega, ou só o menu "Mover" no card? | respondida | 2026-08-10: os dois — arrastar como no Funil de Vendas, e o menu "Mover ▾" no card como caminho acessível (teclado, celular) e obrigatório nas transições que pedem texto. |
| Q-014 | Tarefa cancelada e concluída ficam no quadro para sempre, ou saem da visão depois de um tempo (ex.: só as concluídas dos últimos 30 dias)? | respondida | 2026-08-10: recorte de 30 dias nas colunas terminais, com um caminho para o histórico completo sem recorte, para auditoria (US-040). |
