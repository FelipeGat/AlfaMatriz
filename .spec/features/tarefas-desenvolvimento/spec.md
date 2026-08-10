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

#### AC-082 — O quadro mostra as etapas do ciclo na ordem, com a contagem de cada uma

- **Dado** que existem tarefas em etapas diferentes
- **Quando** abro o quadro
- **Então** vejo as colunas Aberta, Backlog, Em desenvolvimento, Em testes,
  Ajustes necessários, Concluída e Cancelada nessa ordem, cada uma com o total
  de tarefas que estão nela

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

#### AC-110 — As quatro prioridades do ciclo, com a crítica em destaque

- **Dado** o formulário de tarefa
- **Quando** abro a escolha de prioridade
- **Então** vejo Baixa, Média, Alta e **Crítica**; uma tarefa crítica aparece
  no quadro com a marca vermelha, distinta da Alta

#### AC-113 — Os quatro níveis de prioridade se distinguem entre si

- **Dado** quatro tarefas no quadro, uma de cada prioridade
- **Quando** comparo os selos delas
- **Então** cada nível tem uma cor própria — Baixa, Média, Alta e Crítica não
  compartilham tom entre si, e a escala sobe do mais discreto ao mais grave

#### AC-114 — Cada etapa do quadro tem a sua cor, na coluna

- **Dado** o quadro aberto
- **Quando** corro o olho pelas colunas
- **Então** cada etapa tem uma faixa de cor no topo da sua coluna e o contador
  tingido no mesmo tom, como no Funil de Vendas — e a cor da etapa não é
  repetida na borda dos cards, que continua reservada ao aviso de tarefa
  esquecida

#### AC-115 — Dentro da coluna, o que é mais grave e o que está mais parado sobem

- **Dado** uma coluna com uma tarefa crítica criada há meses e uma tarefa de
  prioridade baixa criada hoje
- **Quando** olho a coluna
- **Então** a crítica aparece acima da baixa; e entre duas tarefas de mesma
  prioridade, a que está parada há mais tempo na etapa aparece primeiro

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

#### AC-109 — O menu "Mover" oferece de verdade os destinos permitidos

- **Dado** o quadro aberto, com uma tarefa em Em testes
- **Quando** abro o menu "Mover" do card dela
- **Então** a lista de destinos traz Concluída, Ajustes necessários e
  Cancelada — e nada além disso; o card em Cancelada não oferece menu nenhum

#### AC-111 — Devolver do Backlog para Aberta solta o responsável

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

#### AC-096 — Concluídas e canceladas antigas saem do quadro

- **Dado** uma tarefa concluída (ou cancelada) há mais de 30 dias e outra
  concluída ontem
- **Quando** abro o quadro
- **Então** só a de ontem aparece na coluna, e a coluna avisa quantas tarefas
  mais antigas ficaram fora do recorte

#### AC-097 — O histórico completo continua acessível

- **Dado** uma tarefa concluída há mais de 30 dias, fora do recorte do quadro
- **Quando** abro o histórico de tarefas
- **Então** ela aparece na listagem, com sistema, responsável, etapa final e
  data, sem nenhum recorte por período aplicado

#### AC-112 — Do quadro se chega ao histórico, e o aviso do recorte é legível

- **Dado** o quadro com uma tarefa concluída fora dos últimos 30 dias
- **Quando** olho o cabeçalho da coluna Concluída
- **Então** o nome da etapa aparece inteiro, o aviso de quantas ficaram fora do
  recorte aparece em linha própria (sem cortar), e clicar nele abre o histórico
  completo — sem precisar digitar a URL

## Fora de escopo

- Comentários e anexos de imagem na tarefa (o alfadev tem; fica para depois).
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
| ASM-032 | O acesso é controlado por um recurso de permissão novo, `tarefas`, no mesmo esquema de perfis/permissões já usado (`permissao:tarefas`), somado ao bloqueio por escopo de revenda. | aberta | — |
| ASM-033 | O relatório de teste é registrado no próprio momento da transição (como no alfadev: a confirmação de "Em testes → Concluída" pede as notas do teste), sem tela separada de relatórios. | aberta | — |
| ASM-034 | O vínculo com sistema é opcional: tarefa interna que não pertence a nenhum produto (ex.: infraestrutura) pode ficar sem sistema. | aberta | — |
| ASM-035 | Os dados do alfadev não são migrados: o quadro do AlfaMatriz nasce vazio e o alfadev é desligado depois, manualmente. | aberta | — |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-013 | O quadro terá arrastar-e-soltar (como o Funil de Vendas) já nesta entrega, ou só o menu "Mover" no card? | respondida | 2026-08-10: os dois — arrastar como no Funil de Vendas, e o menu "Mover ▾" no card como caminho acessível (teclado, celular) e obrigatório nas transições que pedem texto. |
| Q-014 | Tarefa cancelada e concluída ficam no quadro para sempre, ou saem da visão depois de um tempo (ex.: só as concluídas dos últimos 30 dias)? | respondida | 2026-08-10: recorte de 30 dias nas colunas terminais, com um caminho para o histórico completo sem recorte, para auditoria (US-040). |
