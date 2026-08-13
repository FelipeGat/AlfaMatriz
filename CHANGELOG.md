# Changelog — AlfaMatriz

## AlfaMatriz — 12/08/2026 — Entradas e saídas passam a ser o caixa, e a receita bate entre as telas

### Correções

- **"Receita recorrente" mostrava valores diferentes no Financeiro e no Centro
  de Controle.** O Centro de Controle passou a exibir a receita contratada
  enquanto o mês não é fechado; o Financeiro continuou somando só o que já
  virou cobrança. No mesmo dia, na mesma competência, um dizia R$ 99,00 e o
  outro R$ 0,00. Agora os dois saem do mesmo cálculo, e o Financeiro também
  avisa quando o número é contratado. A projeção anual acompanha, em vez de
  multiplicar um zero por doze.
- **"Entradas do mês" e "Saídas do mês" agora são o caixa.** Elas contavam
  títulos baixados, enquanto o "Saldo em caixa" ao lado vinha do
  extrato — e não havia como fechar "saldo anterior + entradas − saídas"
  olhando a tela. O gráfico de seis meses passou a sair da mesma fonte, então
  o último mês dele é exatamente o número dos cards.

### Novidades

- **Os cards do Financeiro ganharam minitendência**: receita recorrente, saldo,
  entradas e saídas. A curva não aparece quando seria uma reta no zero.

> **Duas mudanças de comportamento que valem atenção.** Um título marcado como
> pago **sem conta financeira escolhida** não move o caixa — e deixou de contar
> como entrada. E o saldo inicial lançado ao cadastrar uma conta aparece como
> entrada do mês em que foi lançado, porque é dinheiro que entrou na conta.

## AlfaMatriz — 12/08/2026 — Em testes guardava dois portões, e perguntar virava bloqueio

O quadro de tarefas mudou de forma. Duas colunas saíram, três entraram, e a
conversa sobre uma tarefa passou a ter dono da vez.

### As seis etapas

**"Em testes" se abriu em três: Em revisão, Em staging e Pronta p/ produção.** A
etapa única guardava dois portões diferentes — o admin lendo o código de um PR e
o dev validando o que já subiu ao staging. Cada um tem outro revisor, outro
artefato e outro jeito de falhar; juntos no mesmo balde, o quadro não respondia
quem estava esperando quem.

**"Pronta p/ produção" é coluna porque a bola troca de mão ali.** É o único ponto
em que o trabalho sai do dev e vai para o admin subir a tag. O critério vale para
o quadro inteiro: uma coluna só se justifica se muda quem está segurando a
tarefa.

**"Concluída" agora significa EM PRODUÇÃO.** Antes, concluir a partir de Em
testes marcava como pronta uma tarefa cujo código estava só no staging. Agora o
encerramento pede a versão que subiu (`v1.4.2`), e ela aparece no histórico — é
ela que responde "desde quando o cliente tem isso", a pergunta que chega pelo
suporte.

Tarefa operacional continua fechando direto de Em andamento, sem PR, sem staging
e sem versão: exigir tag de um telefonema só ensinaria a preencher o campo por
obrigação.

### "Ajustes necessários" virou marca

A coluna tinha uma saída só — Em andamento, com o mesmo dono — e por isso nunca
respondeu à pergunta que uma coluna existe para responder. Ela também achatava
três reprovações diferentes numa só.

Agora reprovar devolve a tarefa direto para Em andamento com uma tarja que
**nomeia o portão**: *Voltou da revisão*, *Voltou do staging*, *Voltou da porta
da produção* — mais o motivo, que passou a ser obrigatório. Vindo do staging, o
texto do pedido avisa que o código **já está na main** e pergunta se é caso de
voltar a versão ou de corrigir seguindo em frente: a recuperação é materialmente
diferente de um PR reprovado.

### Perguntar na revisão

Dúvida durante a revisão não é impedimento nem correção, e agora tem lugar
próprio. Perguntar **publica o comentário e passa a vez** para o outro lado. A
tarefa não sai da etapa e não conta como travada — responder é rápido, e fingir
que ela saiu de circulação seria mentira.

- O card ganha uma tarja com quem deve a resposta, há quanto tempo, e a pergunta
- Responder abre o campo **no próprio card**, sem abrir a tarefa
- Quem tem perguntas esperando vê um contador no cabeçalho do quadro, que filtra
  ao ser clicado
- Na tarefa que ainda não tem outro lado — a sua, que ninguém comentou —, a tela
  pergunta a quem passar a vez

**A contagem de rodadas mede idas e voltas, não perguntas.** Cinco dúvidas
mandadas de uma vez são uma rodada; insistir sem ter recebido resposta é a mesma.
Na terceira, o quadro acende e sugere devolver para correção — três idas e voltas
costuma querer dizer que o PR está grande demais ou que a tarefa foi mal
especificada.

### O sino da sidebar

O sino deixou de ser enfeite. Ele avisa quando alguém perguntou, quando
responderam, e quando uma tarefa sua voltou para correção — com a rodada e o
tempo de espera. O painel lista o que aconteceu; o rodapé leva ao Centro de
Controle, que continua respondendo o que **exige ação**. São perguntas
diferentes: o sino conta o que mudou, a fila mostra o que está pendente.

### A tela de Tarefas

- **Seis colunas**, cada uma dizendo no cabeçalho o que examina — "PR · admin
  analisa", "na main · dev valida", "fila do admin · tag v*"
- **Colunas recolhem** e a escolha sobrevive ao F5, para quem trabalha numa etapa
  só
- **Bloquear e concluir viraram botões no card**, no lugar das duas faixas
  verticais que gastavam largura
- **O menu Mover** virou uma lista: um destino por linha, marcando os que pedem
  motivo antes do clique
- **Coluna vazia diz o que falta nela** — "Fila de triagem vazia", "Nenhum PR
  aberto" — e, sob filtro, "Nada no recorte"
- **A criação rápida chegou ao Backlog**, além de Aberta

### Correções

- **O modal da tarefa não tinha campo de Resumo.** O card mostra o resumo embaixo
  do título desde sempre, e a única forma de preenchê-lo era pelo banco.
- **O modal não dizia em que etapa a tarefa estava.** Agora o cabeçalho traz a
  etapa e há quanto tempo ela está lá, e os avisos de pergunta e bloqueio
  aparecem antes dos campos — eles explicam por que a tarefa está parada.
- **Excluir confirma em dois passos** e diz a diferença para cancelar. As duas
  palavras são sinônimas na cabeça de quem lê, e uma apaga o histórico que a
  outra existe para guardar.
- **O histórico mostra a versão** em que cada tarefa chegou à produção.
- **A busca do quadro passou a varrer sistema e responsável.** Quem digitava o
  nome do produto recebia zero resultado num quadro com seis cards dele.

> A publicação inclui atualizações do banco: as marcas de retorno e de conversa
> nas tarefas, a versão de produção, e a tabela de notificações. **Nenhuma tarefa
> existente mudou de etapa** — a conversão só age sobre tarefas nas etapas
> antigas, e não havia nenhuma.

## AlfaMatriz — 12/08/2026 — A ficha do produto, e curvas que só aparecem quando têm o que dizer

### Novidades

- **A tela de Sistemas ganhou a ficha do produto selecionado**, embaixo da
  lista: clientes ativos, receita de licença, preço médio, participação na
  base, a faixa de atacado que se aplica hoje e quais revendas o revendem. Os
  números já eram apurados a cada abertura da tela — faltava a tela mostrá-los.
  Clicar num produto da lista abre a ficha dele, e o endereço da página guarda a
  escolha: dá para mandar o link de um produto específico para alguém.
- **Agora dá para registrar desde quando uma revenda ou um produto existe.** É
  um campo novo nos dois cadastros. Até aqui o sistema só sabia o dia em que o
  registro foi criado aqui dentro — que, para tudo que veio na importação, é o
  mesmo dia para todo mundo.
- **Os cards do painel Comercial ganharam minitendência**, a linha miúda que
  mostra o caminho dos últimos meses.

### Sobre as minitendências

Uma curva só aparece quando tem o que dizer. Se tudo o que está na base entrou
no mesmo mês — o retrato de quem acabou de importar o cadastro —, a linha seria
um degrau afirmando "tudo apareceu de uma vez", o que é verdade sobre o dia da
importação e mentira sobre a história do negócio. Nesse caso o card mostra só o
número.

Conforme as datas forem sendo preenchidas, ou conforme entrarem cadastros
novos ao longo dos meses, a curva aparece sozinha.

A do MRR mostra o que foi **faturado** em cada fechamento, e precisa de pelo
menos dois para existir: mês sem fechamento não vira um ponto zero, porque zero
diria "faturamos nada" quando o que houve foi "ninguém fechou o mês".

> A publicação inclui uma atualização do banco (a data de cadastro de revendas e
> produtos). Nenhum dado existente é alterado.

## AlfaMatriz — 12/08/2026 — A tela de Sistemas mostra o resumo que já calculava

### Novidades

- **Faixa de resumo no topo de Sistemas**, com sistemas ativos, clientes
  ativos, MRR de atacado e preço médio por licença. Os quatro números já eram
  calculados a cada abertura da tela; faltava a tela desenhá-los.

### Correções

- **O preço médio por licença estava baixo demais.** Ele dividia a receita de
  atacado pelas licenças de **todos** os produtos, inclusive os desativados —
  que não geram receita nenhuma, porque o faturamento os ignora. Quanto mais
  produto aposentado no catálogo, menor o preço médio exibido, sem preço nenhum
  ter mudado. Agora o divisor conta só as licenças dos produtos ativos.

> "Por licença", e não "por cliente": um cliente com dois sistemas paga duas
> licenças. É o mesmo vocabulário do ranking de produtos no painel Comercial.

## AlfaMatriz — 12/08/2026 — O painel Comercial para de discordar de si mesmo

O Comercial mostrava, na mesma tela, dois números diferentes com o mesmo nome —
e listava produtos que o card logo acima dizia não existir mais.

### Correções

- **O total do ranking de produtos passa a se chamar "Licenças ativas".** Ele
  soma quantos clientes cada produto tem, então um cliente com dois sistemas
  entra duas vezes: nunca foi o número de clientes. Enquanto se chamava
  "Clientes ativos", a tela exibia 8 no card e 7 logo abaixo, sob o mesmo nome.
- **Produto desativado sai dos rankings e do MRR.** Ele continuava aparecendo
  na lista de produtos — sob um card que conta só os ativos — e ainda somava
  receita no "MRR de atacado", dinheiro que o fechamento nunca cobraria, porque
  o faturamento pula produto desativado.
- **Duas revendas com o mesmo nome voltam a ser duas linhas.** Em "Clientes por
  revenda" elas colapsavam numa só, somando a base das duas sob um rótulo que
  não dizia qual era qual.
- **O portfólio por categoria conta só os produtos ativos**, como o card acima
  dele.

### Novidades

- **O card "Clientes ativos" ganhou variação e minitendência**, iguais às do
  Centro de Controle — as duas telas agora desenham a mesma curva, da mesma
  origem.

> Os cards "Sistemas ativos", "Revendas ativas" e "MRR de atacado" seguem sem
> minitendência: o sistema ainda não guarda a data de entrada de revendas e
> sistemas, e uma curva tirada da data de importação mostraria um degrau falso.

## AlfaMatriz — 12/08/2026 — Os números das telas voltam a fechar

Os indicadores do Centro de Controle, do Faturamento e dos painéis mostravam
valores que não batiam entre si — e, em vários casos, não batiam nem com a
linha desenhada logo abaixo deles. Nenhuma regra de negócio mudou: o que muda é
que as contas passam a dar o mesmo resultado em toda tela que as mostra.

### Correções

- **A receita recorrente não zera mais na virada do mês.** Enquanto o
  fechamento da competência não era gerado não havia cobrança para somar, e o
  card mostrava R$ 0,00 — como se a receita tivesse evaporado no dia 1º. Agora
  ele mostra o valor **contratado**, o que o fechamento cobraria se rodasse
  agora, marcado como tal; assim que o fechamento roda, volta a ser o faturado.
- **A prévia do Faturamento passa a somar o mesmo que o botão "Gerar".** Ela
  não contava os módulos contratados, então prometia um total menor do que a
  cobrança que seria criada — numa tela cuja razão de existir é ser conferida
  antes de gerar. A conta escrita por extenso agora declara a parcela de
  módulos.
- **O MRR de atacado e o MRR dos produtos incluem os módulos.** Módulo é
  receita recorrente e entra na fatura; sem ele, os painéis anunciavam menos do
  que a casa fatura. Módulo de cliente já desativado deixou de ser somado.
- **O card de clientes parou de se contradizer.** Ele chegava a dizer "8
  clientes ativos" e, logo abaixo, "+10 no mês", porque a contagem do mês
  incluía cliente desativado que o total não conta.
- **Quem entrou é medido pela data de cadastro.** A base veio de importação, e
  o sistema usava a data em que o registro foi criado — o que fazia toda a base
  aparecer como entrada do mês e a curva de crescimento virar um degrau.
- **A minitendência do caixa parou de discordar do saldo.** Ajustes e
  transferências eram lidos ao contrário na curva histórica, e movimentação de
  conta encerrada era descontada de um saldo que não considera essas contas.
- **A folga de caixa deixou de encolher por causa de conta agendada.** A média
  de despesa incluía tudo que já estava lançado para os meses seguintes — então
  quanto mais organizada a agenda de contas, menor a folga que a tela mostrava.
- **A curva do "Atrasado" termina no valor impresso acima dela.** Ela vinha
  contando títulos que ainda nem tinham vencido.
- **Revenda desativada com receita no mês voltou à régua de origem.** O dinheiro
  dela entrava no total, mas a barra sumia — e a soma das barras deixava de
  bater com o número logo acima, sem nada explicando a diferença.

## AlfaMatriz — 12/08/2026 — Quadro agrupado, teclado, celular e ordem escolhida à mão

Última leva do redesenho da tela de Tarefas.

### Novidades

- **Raias.** O quadro pode ser agrupado por **responsável** ou por **sistema**,
  com o cabeçalho das etapas fixo no topo. Diferente do filtro, a raia não
  esconde nada: mostra tudo separado. É como se responde "quem está com o quê" e
  "onde cada produto está travado", que são perguntas que somem quando se olha
  coluna por coluna com todo mundo misturado. Na raia por pessoa, quem está com
  mais de duas tarefas andando ao mesmo tempo ganha um aviso.
- **O quadro no celular.** Uma etapa por vez, trocada por uma tira de botões com
  a contagem de cada uma. Antes eram cinco colunas espremidas numa tela onde
  cabe pouco mais de uma.
- **Atalhos de teclado.** Setas andam pelos cards e pelas colunas, `⇧` com as
  setas move a tarefa de etapa, `B` bloqueia, `M` abre o menu, `Enter` abre a
  tarefa, `C` cria rápido, `N` abre o formulário, `/` vai para a busca e `?`
  mostra a lista inteira. Nada dispara enquanto você está digitando.
- **Criação rápida.** No pé da coluna Aberta há um campo: escreva o título, dê
  Enter e a tarefa está aberta. Metade do que se abre no dia é uma frase, e para
  uma frase o formulário completo é uma cerimônia que faz a pessoa deixar para
  depois.
- **Ordem escolhida à mão.** Arrastando um card sobre outro da mesma coluna, a
  ordem passa a ser a que você escolheu. O quadro ordena sozinho por gravidade,
  mas "o que é mais grave" não é a mesma pergunta que "qual eu pego primeiro" —
  entre duas tarefas altas, quem conhece o trabalho sabe que uma destrava a
  outra. A coluna que ninguém arrumou continua ordenada sozinha.
- **Excluir tarefa**, diferente de cancelar. Cancelar encerra com motivo e fica
  no histórico; excluir apaga o registro, e serve para a tarefa que nunca
  deveria ter sido aberta. Fica no rodapé do detalhe, pede confirmação em dois
  passos e é só de quem faz triagem.

### Correções

- **Duas pessoas movendo o mesmo card não se atropelam mais.** Antes o segundo
  movimento vencia em silêncio, e quem moveu primeiro só descobria se
  recarregasse a tela. Agora o segundo é recusado, dizendo para onde a tarefa já
  tinha ido.
- **Arrastar um card não abre mais a tarefa no meio do gesto.** O card faz as
  duas coisas — abre no clique e arrasta —, e o começo de qualquer arrasto
  contava como clique.

> A publicação inclui uma atualização do banco (a posição do card na coluna).
> Nenhuma tarefa muda de lugar.

## AlfaMatriz — 12/08/2026 — Atualizações deixam de ser sentidas por quem está usando

Até aqui, publicar uma versão nova mexia no sistema **enquanto ele atendia**.
Por cerca de dois minutos, quem estivesse trabalhando podia encontrar telas com
erro, botões que não respondiam ou uma página em branco — e, se a atualização
falhasse no meio, o sistema ficava quebrado até alguém arrumar à mão. Era o
motivo de as publicações ficarem para o fim do dia.

Agora a versão nova é montada por completo **ao lado** da que está no ar, testada
ali, e só entra quando está pronta e respondendo. A troca é instantânea. É o
mesmo mecanismo que o AlfaControl já usa há tempos.

### Melhorias

- **Publicar não interrompe mais ninguém.** A versão nova é preparada numa área
  separada e conferida antes de entrar. Quem estiver com uma tela aberta no
  momento da troca continua de onde parou.
- **Atualização que dá errado não chega a aparecer.** Falhando qualquer etapa do
  preparo, o sistema simplesmente continua na versão anterior — sem nenhuma
  alteração e sem ninguém perceber que houve tentativa.
- **Versão com problema volta sozinha.** Se a versão nova entrar e o sistema não
  responder, a anterior volta ao ar em cerca de um segundo, sem esperar por
  ninguém. Antes, um problema fora do horário comercial ficaria de pé até
  alguém acordar.
- **Voltar de versão passou a ser imediato.** A versão anterior fica inteira e
  pronta no servidor. Voltar deixou de significar reconstruir tudo — de ~2
  minutos para ~1 segundo.
- **O ambiente de teste passou a usar o mesmo caminho da produção.** Além de
  ensaiar o mecanismo antes de ele valer para o faturamento, o ambiente de teste
  deixou de ficar servindo código ainda não verificado enquanto a verificação
  rodava.
- **Anexos de cobranças e contas a pagar saíram de dentro da versão.** Eles
  passam a viver num lugar próprio no servidor: uma troca de versão não tem mais
  como fazer arquivo anexado sumir da vista.

> Nenhuma ação é necessária da sua parte. A mudança é no servidor; as telas e os
> dados são exatamente os mesmos.

## AlfaMatriz — 11/08/2026 — Entrar no quadro deixou de exigir acesso de administrador

Até aqui, quem trabalhava no quadro de Tarefas precisava do mesmo acesso de quem
organiza o trabalho de todo mundo. Não havia meio-termo: ou a pessoa entrava
como administradora, ou não entrava.

Agora existe o perfil **Membro do time**, e a diferença é sobre **o que a pessoa
decide**, não sobre cargo. Quem é membro trabalha no quadro igual: abre tarefa,
comenta, marca checklist, bloqueia o que travou e move as próprias tarefas. O
que ele não faz é priorizar, escolher quem faz e mexer no que está com outra
pessoa.

### Novidades

- **Perfil "Membro do time".** Para dar acesso a alguém, é só atribuir esse
  perfil em vez do de administrador.
- **Tarefa aberta por um membro entra como "A definir" e sem responsável.** Os
  dois campos nem aparecem no formulário dele, com uma linha explicando que essa
  decisão é da triagem. É a razão de a prioridade "A definir" existir: sem ela,
  a tarefa cairia em "Média" por omissão e o padrão viraria uma classificação
  que ninguém fez.
- **O quadro não oferece o que vai recusar.** Um card que está com outra pessoa
  não arrasta e não mostra o botão de mover — e, passando o mouse, ele diz o
  porquê: *"Esta tarefa está com Camila Reis. Só quem faz triagem move o
  trabalho de outra pessoa."*

### Correções

- **Entrar com um perfil restrito levava a uma tela de acesso negado.** O
  sistema mandava todo mundo para o Centro de Controle depois do login, o que
  funcionava enquanto todo perfil o enxergava. Quem só tem o quadro de tarefas
  batia num 403 logo depois de digitar a senha certa — o que parece conta
  quebrada, e não tela que não é sua. Agora o login leva à primeira tela que a
  conta realmente abre. Para quem tem o Centro de Controle, nada muda.

> A publicação não exige nada da sua parte: o perfil novo é criado sozinho, e
> nenhuma conta existente muda de acesso.

## AlfaMatriz — 11/08/2026 — Checklist na tarefa, limite por coluna e triagem visível

Quatro mudanças no quadro de Tarefas, seguindo o novo desenho da tela.

### Novidades

- **Checklist dentro da tarefa.** Abrindo a tarefa, agora há uma lista de
  conferência: itens que se marcam, com barra de progresso, texto editável no
  lugar, reordenação arrastando e remoção. O card mostra o progresso ("✓ 2/4"),
  então dá para ver de fora quanto ainda falta.
  É lista de conferência, não subtarefa: o item não tem responsável nem etapa e
  não aparece no quadro. Trabalho que precisa de dono próprio continua sendo uma
  tarefa à parte.
- **Prioridade "A definir".** Quem abre uma tarefa nem sempre é quem decide a
  urgência dela — e, sem essa opção, o cadastro caía em "Média" por omissão,
  fazendo o padrão virar uma classificação que ninguém fez. As tarefas assim
  ficam no fim da coluna e o cabeçalho conta quantas estão **aguardando
  triagem**.
- **Limite de tarefas por coluna.** "Em andamento" e "Em testes" passam a
  mostrar "2/3", e o contador fica âmbar com o aviso "acima do limite" quando
  passa de três. Tarefa travada não ocupa vaga: o limite existe para conter o
  que está sendo tocado ao mesmo tempo, e quem está esperando terceiro não está
  produzindo.

### Melhorias

- **O aviso de tarefa esquecida passou a valer em todas as colunas**, com prazo
  próprio para cada uma: 24h em Aberta e em Em testes, 48h em Ajustes e 72h em
  Em andamento. Antes só Aberta e Em testes acendiam, com o mesmo prazo — e a
  tarefa que mais apodrece, a que está há dias em andamento, não era vigiada por
  ninguém. O Backlog continua sem prazo, porque ali esperar é o esperado.
- **O card ficou mais legível.** O responsável virou um círculo com as iniciais
  (o nome completo aparece ao passar o mouse) e o botão "Mover" virou uma seta.
  Os dois nomes — o da pessoa e o do sistema — disputavam a mesma linha e saíam
  cortados. Tarefa sem responsável continua dizendo isso com todas as letras, ao
  lado de um círculo tracejado.

> A publicação inclui uma atualização do banco: a tabela do checklist e a nova
> opção de prioridade. Nenhuma tarefa existente muda de lugar ou de prioridade.

## AlfaMatriz — 11/08/2026 — A tarefa travada não muda mais de lugar

A coluna "Bloqueada", criada hoje mais cedo, saiu do quadro. No lugar dela, a
tarefa travada **fica na coluna em que está** e ganha uma tarja âmbar com o
motivo, o tempo parado e um botão de destravar.

O problema da coluna era que ela apagava a informação mais útil: com o card
dentro dela, ninguém sabia se aquele trabalho tinha parado no meio do
desenvolvimento ou já em teste — e o sistema tinha que adivinhar para onde
devolver a tarefa quando ela destravasse. Agora não há o que adivinhar.

### Novidades

- **Tarja de bloqueio no card.** O motivo aparece embaixo do título, em duas
  linhas, junto do tempo travado ("Bloqueada há 2d"). Antes era preciso abrir a
  tarefa para descobrir o que estava esperando.
- **Faixa "Bloquear" no fim do quadro**, ao lado da faixa "Concluir". Arraste o
  card até ela e escreva o que trava. A faixa também mostra quantas tarefas
  estão travadas.
- **Painel de motivo com nome e explicação.** Quando o movimento pede um texto
  — bloquear, cancelar, devolver para ajustes, concluir —, abre um painel que
  diz o que vai acontecer ("Bloqueando a tarefa"), explica numa linha por que o
  texto está sendo pedido, e cujo botão nomeia o resultado ("Bloquear tarefa")
  em vez de dizer "Confirmar", que é o que se aperta sem ler. A coluna de
  destino fica destacada enquanto você escreve.

### Melhorias

- **Mover uma tarefa travada a destrava.** O bloqueio é sempre sobre o trabalho
  de uma etapa — "esperando o cliente validar" é uma frase sobre a fase de
  testes. Ao mudar de etapa, a marca sai, porque alguém agiu.
- **A tarefa travada continua contando o tempo da etapa em que está**, sem
  ganhar uma passagem extra no histórico só por ter sido bloqueada.

> A publicação inclui uma atualização do banco. As tarefas que estiverem na
> coluna Bloqueada voltam sozinhas para a etapa de onde saíram, mantendo o
> motivo e o tempo travado.

## AlfaMatriz — 11/08/2026 — O quadro passou a caber o trabalho que não é desenvolvimento

Até aqui, o quadro de Tarefas só sabia acompanhar um tipo de trabalho: o ciclo
de desenvolvimento. Toda tarefa tinha de passar por "Em desenvolvimento" e por
"Em testes", e só fechava com um relatório de teste aprovado. Uma tarefa como
**"entrar em contato com o fabricante do equipamento"** não tem nada disso — e
para poder ser concluída, ela precisava fingir que foi desenvolvida e testada.

Agora a tarefa tem **tipo**, e é o tipo que decide o caminho dela. Junto veio a
etapa **Bloqueada**, para a tarefa que travou esperando alguém, e a liberdade de
devolver um card para a etapa anterior sem cerimônia.

### Novidades

- **Tipo de tarefa: Desenvolvimento ou Operacional.** A de desenvolvimento segue
  o ciclo inteiro e continua só fechando com teste aprovado. A operacional —
  falar com fornecedor, renovar certificado, resolver algo de infraestrutura —
  vai de "Em andamento" direto para "Concluída", sem passar por testes e sem
  relatório. As duas dividem o mesmo quadro; o filtro de tipo isola uma delas
  quando você quiser ver só o ciclo de desenvolvimento.
- **Coluna "Bloqueada", para o trabalho que travou.** Quando a tarefa para por
  causa de alguém de fora — o cliente não responde, falta acesso, o fornecedor
  não retorna —, ela sai de "Em andamento" e vai para Bloqueada, dizendo o que
  está travando. Antes não havia para onde levar esse card: ele ficava parado
  numa coluna que dizia que alguém estava trabalhando nele. E como cada etapa é
  cronometrada, o tempo que se perde esperando os outros passou a ser um número
  que dá para ver.
- **Reabrir tarefa cancelada.** Cancelar é um clique, e cancelar por engano
  custava caro: a única saída era recadastrar a tarefa do zero, perdendo a
  conversa e todo o histórico dela. Agora a cancelada volta pelo histórico, para
  a fila de Aberta e sem responsável — porque retomar o que foi cancelado
  costuma ser uma decisão nova, e muitas vezes de outra pessoa.

### Melhorias

- **O quadro mostra para onde o card pode ir, enquanto você arrasta.** Ao pegar
  um card, as colunas que não aceitam aquela tarefa ficam apagadas. Antes o
  quadro deixava você arrastar para qualquer lugar e só respondia depois, com um
  aviso de "transição inválida" — o caminho parecia existir até o fim. Isso vale
  especialmente para a tarefa operacional, que não tem como ir para "Em testes".
- **Arrastar para uma coluna que pede explicação abre a explicação.** Bloquear,
  cancelar e devolver para ajustes precisam de um texto, e no meio de um arrasto
  não há onde escrever. Antes, soltar o card nessas colunas simplesmente não
  fazia nada — nem movia, nem avisava, o que parecia defeito. Agora o arrasto
  abre a caixinha do card já com o destino escolhido, e é só escrever e
  confirmar.
- **Uma faixa "Concluir ✓" no fim do quadro.** Como Concluída não tem coluna,
  encerrar uma tarefa só era possível pelo menu do card — a ação mais importante
  do fluxo era a única sem gesto. Agora dá para arrastar o card até a faixa, que
  abre a confirmação: pedindo o relatório de teste, se for tarefa de
  desenvolvimento, ou só confirmando, se for operacional.
- **Escolher o responsável já move a tarefa.** Direcionar uma tarefa da coluna
  Aberta a leva para o Backlog no mesmo salvar, e tirar o responsável de uma
  tarefa do Backlog a devolve para Aberta. Antes isso só valia na criação: na
  edição era preciso escolher a pessoa e depois arrastar o card à mão.
- **Devolver um card ficou simples.** De "Em andamento" dá para voltar ao
  Backlog, e de "Em testes" dá para voltar a "Em andamento" sem precisar
  declarar uma reprovação que não houve. Isso não é só conforto: como toda volta
  precisava virar "Ajustes necessários", aquela coluna acabava contando erro de
  clique junto com defeito de verdade, e deixava de servir para medir
  retrabalho.
- **"Em desenvolvimento" agora se chama "Em andamento".** A coluna passou a
  receber também tarefa operacional, e um telefonema parado numa coluna chamada
  "Em desenvolvimento" faria a coluna dizer o que não é. As tarefas e o
  histórico não mudaram de lugar — mudou só o nome na tela.

### Correções

- **Reabrir uma tarefa concluída não vem mais com o teste antigo aprovado.** Uma
  tarefa que já tinha sido concluída, quando reaberta e mexida, podia ser
  concluída de novo **sem nenhum teste novo**: o sistema aceitava o relatório
  aprovado do ciclo anterior como prova. Ou seja, o teste que provava o código
  de antes valia como prova do código de depois. Agora cada passagem por "Em
  testes" pede o seu próprio relatório — o que vale também para a tarefa que
  voltou de "Ajustes necessários".

> A publicação inclui uma atualização do banco. As tarefas que já existem
> continuam exatamente onde estão, e todas elas entram como tipo
> **Desenvolvimento**.

## AlfaMatriz — 11/08/2026 — Busca e conversa nas tarefas, telas mais rápidas

### Novidades

- **Busca e filtros no quadro de tarefas**: achar uma tarefa dependia de percorrer coluna por coluna, ou página por página no histórico. Agora dá para buscar por texto — título, resumo, detalhes e também o que foi escrito nos comentários — e filtrar por sistema, responsável, prioridade e desfecho. O mesmo formulário serve o quadro e o histórico, e o recorte fica no endereço da página: dá para guardar nos favoritos ou mandar o link pronto para alguém.
- **Comentários na tarefa**: o que não cabia no título nem no resumo passa a ter lugar. Dá para escrever, corrigir e remover. Corrigir mantém a data original e o lugar da frase na conversa — até agora a única saída para um comentário errado era apagar e reescrever, perdendo as duas coisas.

### Melhorias

- **Listagens abrem mais rápido**: Revendas, Sistemas, Produtos, Despesas fixas e Histórico de tarefas carregavam a base inteira numa resposta só. Agora vêm de 20 em 20. Os totais e indicadores continuam somando a lista completa, não a página — o número no rodapé não muda ao virar de página.
- **Nova identidade nos ícones**: o símbolo da Matriz passa a valer no ícone do navegador e no atalho instalado no celular, com o desenho preenchido, mais legível em tamanho pequeno.
- **O aviso não empurra mais a tela**: quando uma confirmação aparecia, a página inteira descia um degrau e voltava ao sumir — quem estava lendo perdia a linha e quem estava mirando um botão acabava clicando no de baixo.

### Correções

- **Login em aba parada dava erro sem saída.** A tela de login costuma ficar aberta horas numa aba de fundo; ao clicar em "Entrar", o sistema respondia com uma página de erro sem nenhum caminho de volta, e só abrindo outra aba dava para entrar.
- **O estado do cliente era gravado diferente do que a tela mostrava.** No cadastro, a UF aparecia em maiúscula no campo — "ES" — e chegava ao banco em minúscula. A tela concordava com a intenção e discordava do que ficava guardado.
- **Cadastrar uma despesa fixa lançava o mês inteiro junto.** Salvar uma despesa gerava as parcelas de todas as despesas vigentes, e não apenas a que estava sendo cadastrada.
- **Empresa duplicada ao integrar com outro sistema.** Quando o CNPJ estava cadastrado com máscara — `52.638.029/0001-05` — a comparação com o outro sistema nunca reconhecia o mesmo documento, e o registro entrava de novo. Era a duplicação que a integração existe justamente para evitar, com risco de cobrar a mesma empresa duas vezes.
- **Clique duplo criava registro em dobro.** Em "Nova tarefa" nascia um segundo card no quadro, e no "Salvar" do comentário a mesma frase era publicada duas vezes.
- **A tela de tarefa pedia dois envios para uma só passada.**
- **Menu da tabela abria sem borda no tema claro**, colado no conteúdo e sem recorte visível.
- **O botão "Mover" ficava fora da borda do card** no quadro de tarefas.

## AlfaMatriz — 11/08/2026 — Publicação de novas versões destravada

Uma publicação pedida no meio da tarde não chegou ao sistema, e a apuração mostrou que nada vinha chegando havia 1h20 — enquanto o painel de acompanhamento mostrava tudo normal. As correções abaixo restabelecem a publicação e tiram do escuro as três formas de falha que a mantinham invisível. **Nenhum dado foi perdido e o sistema esteve no ar o tempo todo**: o que ficou parado foi a chegada das versões novas.

### Correções

- **As atualizações pararam de chegar por 1h20, sem nenhum aviso.** Uma marcação de versão antiga foi reaproveitada apontando para outro ponto do histórico. A partir daí, o programa que leva as versões novas ao servidor passou a desistir logo no primeiro passo — e desistia antes de olhar qualquer versão, inclusive a nova, que não tinha relação nenhuma com a marcação alterada. Sem a correção, a próxima notícia seria "publiquei ontem e não está no ar".
- **O painel dizia que estava tudo certo com a publicação parada.** O indicador mostrava o resultado da última publicação bem-sucedida, e não a tentativa em curso. Sistema parado aparecendo como saudável é pior que sistema parado: agora a falha é sinalizada, e o motivo fica registrado em vez de descartado.
- **Recursos novos podiam nascer invisíveis.** A publicação não aplicava as permissões da versão nova, então o ambiente de produção e o de teste chegaram a rodar o mesmo código com funcionalidades diferentes — telas somem do menu e respondem "sem acesso", sem erro nenhum que denuncie a causa.
- **O botão "Publicar" do painel não fazia nada.** Para o AlfaMatriz, o clique caía num "sistema desconhecido" e nada acontecia. Passou despercebido porque o sistema se atualiza sozinho de 5 em 5 minutos: ele andava, só não obedecia a quem mandava.

### Melhorias

- **Duas publicações ao mesmo tempo não se atropelam mais.** Com o botão do painel voltando a funcionar, o clique e a atualização automática podiam cair na mesma janela e trabalhar sobre os mesmos arquivos. Agora a segunda espera a primeira terminar. Aconteceu na prática três minutos depois da correção, e foi tratado como devia.
- **A verificação automática de saúde passou a incluir o AlfaMatriz.** De 5 em 5 minutos o sistema é consultado junto com os demais — até então ele estava fora dessa lista, e uma indisponibilidade só apareceria quando alguém percebesse.
- **O ambiente de teste passou a rodar as tarefas automáticas**, como o fechamento mensal de competência e o retrato dos sistemas integrados. Elas nunca haviam rodado lá: qualquer defeito nesse tipo de rotina estreava direto em produção. O ambiente de teste também ganhou a cópia diária do banco.
- **O acompanhamento do painel deixou de acusar alteração indevida onde não havia** — um alerta fixo e falso que treinava a ignorar justamente o indicador que denuncia servidor rodando versão velha.

> Nenhuma ação é necessária da sua parte. Tudo o que está descrito aqui já está no ar, com exceção do último item, que entra na próxima publicação.
