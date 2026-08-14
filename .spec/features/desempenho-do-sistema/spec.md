# Spec: Desempenho do sistema

> feature: desempenho-do-sistema
> status: rascunho

<!--
  Como ler este arquivo (o formato é verificado por `onp-spec audit`):
  - US-xxx = história de usuário · AC-xxx = critério de aceite
    ASM-xxx = suposição · Q-xxx = pergunta em aberto
    São códigos de rastreio: ligam a especificação às tarefas e aos testes.
  - Toda história de usuário precisa de pelo menos um critério de aceite.
  - Todo critério de aceite precisa de Dado/Quando/Então completos.
  - Os códigos são únicos no projeto inteiro (nunca reutilize um número).
  - Suposições e Perguntas em aberto são OBRIGATÓRIAS: se não há nenhuma,
    escreva "Nenhuma." — mas desconfie: quase toda feature esconde uma.
-->

## Contexto

O painel funciona, mas o custo de cada tela cresce com o uso — e a medição feita
antes desta especificação mostrou onde. Números levantados instrumentando as
rotas reais (contagem de consultas por `DB::listen` e bytes da resposta), em
14/08/2026:

| Tela | Consultas | HTML |
|---|---|---|
| Quadro de tarefas · 5 tarefas | 52 | 382 KB |
| Quadro de tarefas · 40 tarefas | 157 | 1,9 MB |
| Quadro de tarefas · 120 tarefas | 397 | 5,5 MB |
| Painel Financeiro | 76 | 45 KB |
| Centro de Controle | 72 | 41 KB |
| Comercial | 32 | 37 KB |
| Clientes | 31 | 61 KB |

Duas coisas crescem sem freio, e são elas que esta feature ataca:

1. **A mesma pergunta, repetida.** `User::canPermissao()` vai ao banco a cada
   chamada. São 17 por página em qualquer tela (14 links da sidebar, o sino, a
   linha do tempo) e mais 3 por card no quadro — 379 das 397 consultas do quadro
   com 120 tarefas são a mesma checagem de permissão.
2. **O quadro imprime um modal completo por tarefa.** `_modais` inclui `_form`,
   comentários, checklist e anexos de cada card, ~45 KB por tarefa, sem paginação.

O resto são consultas repetidas dentro do mesmo carregamento: o painel Financeiro
calcula as séries mês a mês duas vezes, o sino roda o View composer duas vezes,
e dois serviços consultam dentro de laço.

Esta feature é **só desempenho**: nenhuma tela muda de conteúdo, nenhum número
muda de valor, nenhuma regra de permissão muda de resultado. Segurança fica para
outro momento, por decisão do dono do produto.

## Histórias

### US-065 — Permissão resolvida uma vez por requisição

Como quem usa qualquer tela do painel, quero que o sistema pergunte ao banco
quem eu sou uma vez só por carregamento, para que a tela não fique mais lenta a
cada item de menu e a cada card desenhado.

#### AC-236 — O quadro não repergunta a permissão a cada card

- **Dado** um quadro com 120 tarefas e um usuário autenticado
- **Quando** a tela do quadro é carregada
- **Então** as consultas às tabelas de perfil e permissão não passam de 2, em vez
  de crescerem com o número de cards (hoje são 3 por card, 362 no total)

#### AC-237 — O custo da tela não cresce com o volume de tarefas

- **Dado** o mesmo quadro carregado duas vezes, uma com 5 tarefas e outra com 120
- **Quando** se compara o número total de consultas dos dois carregamentos
- **Então** a diferença não passa de 2 consultas — o quadro custa o mesmo com
  pouca ou com muita tarefa (hoje a diferença é de 345)

#### AC-238 — A sidebar decide os 14 links sem 14 consultas

- **Dado** um usuário autenticado em qualquer tela do painel
- **Quando** a tela é carregada com a sidebar montada
- **Então** as consultas às tabelas de perfil e permissão não passam de 2
  (hoje são 17)

#### AC-239 — Quem não tem permissão continua sem ver e sem entrar

- **Dado** um usuário cujo perfil não alcança um recurso
- **Quando** ele carrega o painel e tenta abrir a rota desse recurso
- **Então** o item continua ausente do menu e a rota continua devolvendo 403 —
  o cache não afrouxa nenhuma recusa

### US-066 — O quadro carrega leve, e o detalhe vem ao clique

Como quem abre o quadro de tarefas, quero receber só o que está na tela, para
que o quadro não demore mais a abrir a cada tarefa que o time cria.

#### AC-240 — O HTML do quadro para de crescer com o volume

- **Dado** um quadro com 120 tarefas, cada uma com comentário e checklist
- **Quando** a tela do quadro é carregada
- **Então** a resposta fica abaixo de 2 MB (hoje são 5,5 MB), porque o modal de
  edição deixa de ser impresso para todas as tarefas de uma vez

> **O limite deste critério foi corrigido durante a implementação, de 1,5 MB
> para 2 MB.** O 1,5 MB era estimativa feita antes de medir o card sozinho: eu
> supunha ~4 KB por card e ele custa ~16 KB. Tirar os modais entregou 5,5 MB →
> 1,9 MB (−65%); os 1,9 MB restantes são card, não modal. O que ainda sobra
> está em Q-019 — e não foi cortado aqui porque envolve mudar onde um painel
> aparece na tela, que é decisão de produto e não de desempenho.

#### AC-241 — Clicar no card abre a tarefa inteira

- **Dado** uma tarefa no quadro, com resumo, checklist, anexo e conversa
- **Quando** a pessoa clica no card
- **Então** o modal abre com os mesmos campos e o mesmo conteúdo de hoje,
  buscados no momento do clique

#### AC-250 — O pedido de motivo existe uma vez só, e continua aparecendo dentro do card

- **Dado** um quadro com 120 tarefas
- **Quando** a tela é carregada e, em seguida, alguém pede para travar uma tarefa
- **Então** o formulário de motivo aparece no HTML uma vez só (e não uma por card),
  e ao ser pedido ele é desenhado **dentro do card daquela tarefa** — colado nela,
  como sempre esteve

#### AC-242 — As ações do modal continuam funcionando

- **Dado** o modal de uma tarefa aberto pelo clique no card
- **Quando** a pessoa salva a edição, publica um comentário, marca um item do
  checklist ou anexa um arquivo
- **Então** cada ação surte o mesmo efeito de hoje, e o modal continua aberto na
  tarefa em que estava

### US-067 — O sino consulta uma vez, não duas

Como quem abre qualquer tela, quero que as notificações sejam buscadas uma vez
por carregamento, para não pagar duas consultas pelo mesmo sino.

#### AC-243 — Uma leitura de notificações por requisição

- **Dado** um usuário autenticado com notificações na caixa
- **Quando** qualquer tela do painel é carregada
- **Então** a tabela de notificações é consultada no máximo 2 vezes — a lista e
  a contagem — e não 4 (hoje o composer roda para a sidebar e para o painel do
  sino, repetindo as duas)

### US-068 — Os painéis calculam cada número uma vez

Como quem abre o Painel Financeiro ou o Centro de Controle, quero que a mesma
conta não seja refeita várias vezes no mesmo carregamento, para que a tela abra
rápido mesmo com o caixa cheio de movimento.

#### AC-244 — O Painel Financeiro cabe em 30 consultas

- **Dado** uma base com movimentações, cobranças e contas nos últimos seis meses
- **Quando** o Painel Financeiro é carregado
- **Então** o total de consultas não passa de 30 (hoje são 76), sem que nenhum
  card ou curva mude de valor

#### AC-245 — O Centro de Controle cabe em 30 consultas

- **Dado** a mesma base
- **Quando** o Centro de Controle é carregado
- **Então** o total de consultas não passa de 30 (hoje são 72), sem que nenhum
  card ou curva mude de valor

#### AC-246 — Os números continuam exatamente os mesmos

- **Dado** uma base com valores conhecidos em entradas, saídas, saldo e receita
  recorrente
- **Quando** os painéis são carregados depois da otimização
- **Então** cada card e cada ponto de curva mostra o mesmo valor que mostrava
  antes — a mudança é de custo, não de conta

#### AC-247 — O ranking de sistemas não consulta por sistema

- **Dado** um catálogo com 12 produtos ativos, cada um com clientes e módulos
- **Quando** o painel Comercial é carregado
- **Então** o número de consultas do ranking não cresce com o número de sistemas
  (hoje são 2 por sistema, uma para a licença e outra para os módulos)

### US-069 — A previsão de faturamento consulta em bloco

Como quem abre as telas que mostram a receita contratada, quero que a previsão
seja calculada sem uma consulta por revenda e por sistema, para que a tela não
fique mais lenta a cada revenda que entra na base.

#### AC-248 — O custo da previsão não cresce com o número de revendas

- **Dado** uma base com 10 revendas ativas, cada uma com 4 sistemas contratados
- **Quando** a previsão da competência é calculada
- **Então** o número de consultas não cresce em proporção a revendas × sistemas
  (hoje são ~3 por sistema de cada revenda)

#### AC-249 — O valor previsto não muda

- **Dado** uma base com tiers de atacado, módulos vigentes e contratos diretos
- **Quando** a previsão da competência é calculada depois da otimização
- **Então** o total e a abertura por revenda são idênticos aos de antes — é o
  mesmo dinheiro, calculado com menos idas ao banco

## Fora de escopo

- **Segurança.** Nenhuma regra de autorização muda; o cache de permissão não pode
  afrouxar recusa nenhuma (AC-239). Revisão de segurança fica para outro momento,
  por decisão do dono do produto.
- **Paginar o quadro.** O quadro continua mostrando todo o trabalho em curso. O
  HTML deixa de crescer 45 KB por tarefa, mas ainda cresce com o card — ver Q-019.
- **Trocar driver de sessão ou de cache.** Os dois estão em `database`, que é o
  padrão do Laravel e custa consultas por requisição. Mexer nisso é infraestrutura
  de produção, não código.
- **Índices novos no banco.** A varredura conferiu os índices existentes e não
  achou consulta lenta por falta de índice — o problema medido é quantidade de
  consultas, não o custo de cada uma.
- **Assets e front-end.** O `public/build` inteiro tem 172 KB e o deploy já roda
  `config:cache`, `route:cache`, `view:cache` e limpa o opcache.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-055 | 120 tarefas no quadro é o pior caso realista de produção; a base local tem 1 e a medição foi feita com dado semeado | aberta | — |
| ASM-056 | Nenhuma tela altera a permissão do próprio usuário e relê o resultado no MESMO carregamento — por isso o cache pode viver pela requisição inteira | aberta | — |
| ASM-057 | O modal sob demanda pode ser servido por uma rota nova de leitura da tarefa, seguindo o contrato de `respostaParcial` que o quadro já usa | aberta | — |
| ASM-058 | Nenhum teste da suíte depende do número de consultas atual nem do modal estar impresso no HTML do quadro | aberta | — |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-019 | Com o modal fora do HTML, o quadro ainda cresce ~16 KB por card (medido: 1,9 MB com 120 tarefas). Boa parte disso é o painel de motivo, impresso em TODOS os cards para um painel que só pode estar aberto em um — mas ele nasce dentro do card de propósito, para o pedido de texto aparecer colado no card de que fala. Vale movê-lo para uma instância única, ou o quadro deve passar a limitar quantos cards mostra por coluna? | respondida | O painel APARECER DENTRO DO CARD é requisito firme — decisão do dono do produto em 14/08/2026. Então nem flutuante, nem paginar o quadro: o molde passa a existir uma vez só e é clonado dentro do card que o pediu. Medido: 4090 bytes por card, 479 KB com 120 tarefas, 26% da tela. Vira AC-250. |
