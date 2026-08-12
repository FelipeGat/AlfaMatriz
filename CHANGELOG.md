# Changelog — AlfaMatriz

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
