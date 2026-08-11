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
- **Então** vejo as colunas Aberta, Backlog, Em desenvolvimento, Em testes e
  Ajustes necessários nessa ordem, cada uma com o total de tarefas que estão
  nela — e nenhuma coluna de etapa terminal, porque tarefa encerrada não é
  trabalho em curso

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

#### AC-090 — Tarefa concluída pode ser reaberta para desenvolvimento

- **Dado** uma tarefa Concluída
- **Quando** a reabro
- **Então** ela volta para Em desenvolvimento e a única saída da coluna
  Cancelada continua sendo nenhuma (tarefa cancelada não volta ao fluxo)

#### AC-122 — O menu "Mover" oferece de verdade os destinos permitidos

- **Dado** o quadro aberto, com uma tarefa em Em testes
- **Quando** abro o menu "Mover" do card dela
- **Então** a lista de destinos traz Concluída, Ajustes necessários e
  Cancelada — e nada além disso; o card em Cancelada não oferece menu nenhum

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

#### AC-134 — O comentário fica na tarefa, com autor e data

- **Dado** uma tarefa do quadro
- **Quando** abro o card e escrevo um comentário
- **Então** ele passa a aparecer na tarefa com o nome de quem escreveu e a data,
  do mais antigo para o mais novo, e o card anuncia quantos comentários existem
  — comentário vazio não é aceito

#### AC-135 — Marcadores viram lista de verdade

- **Dado** que estou escrevendo um comentário
- **Quando** começo linhas com `-` (ou uso o botão de marcador) ou com `1.`
  (ou o botão de numeração)
- **Então** o comentário é exibido como lista com marcador ou lista numerada,
  respeitando o número em que a contagem começou — e nada além de lista e
  parágrafo é interpretado: HTML digitado no campo aparece como texto

#### AC-136 — A conversa é do autor, e sobrevive ao encerramento

- **Dado** um comentário que escrevi
- **Quando** o apago
- **Então** ele sai da tarefa; o comentário de outra pessoa não me oferece essa
  ação e a rota a recusa. E quando a tarefa é concluída ou cancelada, a conversa
  continua legível pelo histórico — só leitura, porque escrever de novo é
  reabrir a tarefa

## Fora de escopo

- Anexos de imagem na tarefa (o alfadev tem; fica para depois).
- Editar comentário já publicado, menção a pessoa (@) e notificação de
  comentário novo.
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
| ASM-047 | O comentário é texto simples com marcadores de lista — não markdown completo, sem negrito, link ou imagem — e a conversa não notifica ninguém: quem acompanha a tarefa a abre. | aberta | Implementado assim em US-049. A conversão é lista branca (`TarefaComentario::marcadoresEmHtml`), o que permite imprimir o corpo sem abrir XSS; markdown completo pediria sanitizador de verdade. |
| ASM-048 | Só o autor apaga o próprio comentário, e ninguém edita comentário publicado. | aberta | Implementado assim em US-049 (AC-136). Apagar o alheio seria reescrever a conversa de outra pessoa; editar sem registro de edição faria a tarefa contar uma história que não aconteceu. |
| ASM-035 | Os dados do alfadev não são migrados: o quadro do AlfaMatriz nasce vazio e o alfadev é desligado depois, manualmente. | aberta | **Decisão pendente do dono do produto.** Enquanto o alfadev seguir em uso, os dois bancos divergem. Migrar o histórico do Supabase é feature própria; desligar o alfadev sem migrar descarta o histórico dele. |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-013 | O quadro terá arrastar-e-soltar (como o Funil de Vendas) já nesta entrega, ou só o menu "Mover" no card? | respondida | 2026-08-10: os dois — arrastar como no Funil de Vendas, e o menu "Mover ▾" no card como caminho acessível (teclado, celular) e obrigatório nas transições que pedem texto. |
| Q-014 | Tarefa cancelada e concluída ficam no quadro para sempre, ou saem da visão depois de um tempo (ex.: só as concluídas dos últimos 30 dias)? | respondida | 2026-08-10: recorte de 30 dias nas colunas terminais, com um caminho para o histórico completo sem recorte, para auditoria (US-040). |
