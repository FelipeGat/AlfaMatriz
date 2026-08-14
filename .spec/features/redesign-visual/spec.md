# Spec: Redesign visual do painel

> feature: redesign-visual
> status: rascunho

## Contexto

O AlfaMatriz é o painel interno da Alfa Tecnologia: revendas, clientes finais,
sistemas licenciados, preço de atacado, faturamento mensal das revendas e o
financeiro da própria casa. O cliente resumiu o problema em uma frase — *"o
design está muito pobre, precisamos repaginar"*.

Esta entrega troca a **camada de apresentação** das 13 telas + login por um
sistema visual coerente: dois temas (escuro padrão e claro), menu que recolhe
para um rail de ícones, densidade de informação maior e hierarquia explícita.
Nenhum módulo novo, nenhuma rota nova, nenhuma tabela nova — as listas
continuam vindo dos controllers e models que já existem.

A referência é o pacote de handoff em
`Assets/design_handoff_alfamatriz_redesign/` (o `AlfaMatriz Sistema.dc.html` é
a fonte da verdade quando o texto e o HTML divergirem).

## Histórias

### US-016 — Uma linguagem visual só, declarada em um lugar só

Como pessoa que mantém o painel, quero que cor, tipografia, raio e espaçamento
venham de tokens declarados uma vez, para que trocar o tema ou ajustar a marca
não vire caça a valor solto em 20 arquivos de tela.

#### AC-032 — Os tokens do design existem e alimentam os dois temas

- **Dado** o painel construído sobre o sistema visual novo
- **Quando** alguém procura de onde vem uma cor da interface (fundo, régua,
  tinta, marca, status)
- **Então** encontra o valor declarado uma única vez como token do tema, com a
  versão escura e a versão clara lado a lado, e as telas usam o token — não o
  hexadecimal cru

#### AC-033 — O texto informativo é legível nos dois temas

- **Dado** os tokens de tinta secundária, terciária e de rótulo
  (`inkDim`, `inkMute`, `inkFaint`)
- **Quando** o contraste de cada um é medido contra o fundo do próprio tema
- **Então** todos alcançam pelo menos 4,5:1 nos dois temas — o cinza claro do
  protótipo antigo (`#95a3a8`, 2,6:1) não volta

#### AC-034 — Cada família tipográfica tem um papel e está disponível

- **Dado** uma tela qualquer do painel
- **Quando** ela é carregada
- **Então** as três famílias do desenho estão disponíveis com seus papéis
  fixos: Space Grotesk para títulos e números de destaque, Geist para corpo e
  botões, Geist Mono para rótulos em caixa alta, números de tabela e datas

### US-017 — O shell: menu que recolhe, tema que alterna

Como pessoa do time da Alfa que usa o painel o dia inteiro, quero recolher o
menu para ganhar largura e escolher entre tema claro e escuro, para trabalhar
confortável sem perder a navegação.

#### AC-035 — Toda tela autenticada usa o mesmo shell

- **Dado** qualquer uma das telas do painel
- **Quando** ela é aberta por alguém logado
- **Então** aparece a mesma moldura: sidebar com os quatro grupos de menu
  (Painéis, Comercial, Financeiro, Sistema), topbar com título da tela, linha
  de contexto e busca, e rodapé de sidebar com avatar, notificações e tema

#### AC-036 — Recolher o menu vira um rail de ícones, e a escolha sobrevive à navegação

- **Dado** o painel com o menu expandido
- **Quando** a pessoa aciona o botão de recolher no topbar e depois navega para
  outra tela
- **Então** o menu vira um rail estreito só de ícones — com divisória entre os
  grupos no lugar dos rótulos — e continua recolhido na tela seguinte, porque a
  escolha ficou guardada no navegador

#### AC-037 — O tema alterna e sobrevive à navegação

- **Dado** o painel em tema escuro
- **Quando** a pessoa aciona o botão de tema no rodapé da sidebar e depois
  navega para outra tela
- **Então** a interface inteira passa para o tema claro — todas as telas de uma
  vez, sem tela mestiça — e continua clara na navegação seguinte, sem piscar o
  tema errado ao carregar a página

#### AC-038 — O menu marca o item certo, inclusive nas telas filhas

- **Dado** uma tela filha (o formulário de um cliente, o extrato de uma conta,
  a edição de um sistema)
- **Quando** ela é aberta
- **Então** o item de menu do pai aparece marcado como ativo — com a barra de
  marca à esquerda — em vez de nenhum item aceso

### US-018 — O Centro de Controle responde "o que precisa de mim hoje"

Como responsável pela operação, quero abrir uma tela e ver o que pede decisão
hoje, para não ter que varrer cinco telas atrás de pendência.

#### AC-039 — A fila de ação lista o que pede decisão e leva até lá

- **Dado** o painel com receitas atrasadas, despesas vencidas, leads parados ou
  sistema sem tier de atacado
- **Quando** a pessoa abre o Centro de Controle
- **Então** cada pendência aparece como uma linha da fila de ação — com nível
  de severidade, descrição, valor e um botão que abre a tela onde o problema se
  resolve

#### AC-040 — Os cards de destaque trazem valor, variação e tendência

- **Dado** o Centro de Controle aberto
- **Quando** a pessoa olha o topo da tela
- **Então** vê os quatro números que resumem o negócio (receita recorrente,
  caixa, atrasado, clientes ativos), cada um com a variação em relação ao mês
  anterior e uma minitendência dos últimos meses, todos calculados a partir dos
  dados do banco

#### AC-041 — A régua de origem da receita desenha barras proporcionais ao valor

- **Dado** a régua "Origem do MRR", onde a venda direta vale mais que qualquer
  revenda
- **Quando** ela é desenhada
- **Então** a barra de cada origem é proporcional ao valor dela na escala comum
  da régua — a maior origem tem a maior barra, e nenhuma barra some quando o
  painel fica estreito

#### AC-070 — O painel raciocina no fuso de quem opera

- **Dado** que a operação é no Brasil
- **Quando** o painel decide o que é "hoje" — o que vence, o que atrasou,
  quanto entrou no mês, qual é a competência — e quando cumprimenta quem chega
- **Então** ele usa o horário local, e não UTC: às 22h um título que vence hoje
  ainda não está atrasado, a competência ainda é a do mês corrente, e a
  saudação diz "boa noite"

#### AC-218 — Mês sem fechamento mostra o contratado, não zero

- **Dado** que o fechamento da competência corrente ainda não foi gerado, e
  portanto não existe cobrança para somar
- **Quando** a pessoa olha a receita recorrente no Centro de Controle
- **Então** o card mostra o valor CONTRATADO — o que o fechamento cobraria se
  rodasse agora, licenciamento das revendas mais os contratos diretos — marcado
  como contratado, em vez de exibir R$ 0,00 como se a receita tivesse sumido na
  virada do mês; e a régua de origem abre esse mesmo total por origem, de forma
  que a soma das barras bata com o número acima delas
- **E** meses passados sem fechamento continuam valendo o que foi de fato
  cobrado: o contratado é a foto de hoje e não remonta histórico

### US-019 — Os painéis Financeiro e Comercial deixam comparar

Como responsável pela operação, quero que os painéis mostrem grandeza relativa
e não só números soltos, para entender de onde vem o dinheiro sem exportar nada.

#### AC-042 — O painel Financeiro mostra o mês, o histórico e o que está em aberto

- **Dado** o painel Financeiro aberto
- **Quando** a pessoa olha a tela
- **Então** vê os cinco números do mês (receita recorrente, projeção anual,
  saldo em caixa, entradas e saídas), o gráfico de entradas contra saídas dos
  últimos seis meses, e as duas listas do que está pendente de receber e de
  pagar — cada lista com atalho para a tela cheia

#### AC-043 — O painel Comercial ranqueia os produtos com grandeza comparável

- **Dado** o painel Comercial aberto
- **Quando** a pessoa olha os rankings de produto (por clientes ativos e por
  valor gerado)
- **Então** cada ranking mostra o total, o líder e a participação de cada
  produto — com a barra de cada linha proporcional ao líder e uma faixa
  segmentada onde a largura de cada segmento é a participação real do produto

#### AC-062 — O mesmo indicador não é calculado duas vezes

- **Dado** um número que aparece em mais de uma tela (clientes ativos, receita
  recorrente, valor de atacado)
- **Quando** ele é exibido no painel Financeiro, no Comercial e na tela de
  Sistemas
- **Então** os três saem do mesmo cálculo, de uma origem única — mudar o
  critério de "ativo" num lugar muda todas as telas juntas, em vez de fazê-las
  discordar em silêncio

### US-020 — Funil de vendas: mover o lead sem sair da tela

Como pessoa do comercial, quero mover o lead entre estágios direto no quadro,
para atualizar o funil enquanto falo com o cliente.

#### AC-044 — O quadro ocupa a tela e cada coluna rola por dentro

- **Dado** um estágio com muitos leads e outro com poucos
- **Quando** a pessoa abre o funil
- **Então** o quadro ocupa a altura disponível da janela, todas as colunas têm
  a mesma altura, e a coluna cheia rola por dentro em vez de esticar a página

#### AC-045 — Arrastar move o lead, e o menu faz o mesmo sem mouse

- **Dado** um lead no estágio "Contato feito"
- **Quando** a pessoa arrasta o card para "Proposta" — ou usa o menu de mover
  do próprio card, sem mouse
- **Então** o lead passa a pertencer ao novo estágio, pelos dois caminhos, e a
  contagem e o valor em jogo das duas colunas se ajustam

### US-021 — As listas operacionais continuam operáveis

Como pessoa que trabalha nas listas todo dia, quero densidade e ações sempre
alcançáveis, para não perder o botão de editar quando a janela é estreita.

#### AC-046 — Nenhuma tabela esconde a coluna de ações

- **Dado** uma tela de tabela (revendas, clientes, produtos, receitas,
  despesas, extrato, faturamento) numa janela estreita
- **Quando** a tabela é mais larga que o espaço disponível
- **Então** ela rola na horizontal dentro do próprio painel e a coluna de ações
  continua alcançável — em vez de ser cortada pelo canto arredondado do painel

#### AC-047 — As linhas de total não quebram em duas alturas

- **Dado** a linha de totais de uma tabela, com rótulos em caixa alta e
  espaçamento largo
- **Quando** a tela é renderizada
- **Então** cada célula de total fica em uma linha só, sem quebrar o rótulo no
  meio e sem desalinhar a altura da faixa

#### AC-048 — Revendas, Clientes e Produtos trazem resumo, filtro, tabela e contagem

- **Dado** cada uma das três telas de cadastro operacional
- **Quando** ela é aberta
- **Então** traz os quatro números de resumo no topo, os filtros de busca e
  recorte, a tabela com os dados do banco e o rodapé declarando quantos
  registros estão sendo mostrados de quantos existem

#### AC-049 — Produtos abre como lista comparável, e o modo escolhido persiste

- **Dado** a tela de Produtos
- **Quando** ela é aberta pela primeira vez
- **Então** os sistemas aparecem como lista ordenada por receita recorrente —
  com participação, base ativa na unidade de cobrança real e churn — e o
  alternador permite voltar para cartões, com a escolha guardada para a próxima
  visita

#### AC-221 — O seletor de páginas obedece ao botão de tema

- **Dado** qualquer listagem paginada do painel (revendas, clientes, produtos,
  receitas, despesas, extrato, contas, usuários, auditoria, histórico de
  tarefas)
- **Quando** alterno entre tema claro e escuro
- **Então** o seletor de páginas acompanha, porque desenha com os tokens do
  sistema — e não com as variantes `dark:` do Tailwind, que sem `darkMode`
  declarado seguem o sistema operacional em vez da classe `.theme-light` do
  `<html>` que comanda o tema aqui
- **E** ele fica no rodapé do painel da tabela, à direita da contagem, no
  formato do handoff: controles de 26px, raio 4px e fio `btn-line`, com
  Anterior, os números de página e Próxima. Em Produtos, cujo paginador é
  único para os modos lista e cartões, ele fica logo abaixo do painel

#### AC-222 — O seletor de páginas é pré-compilável pelo `view:cache`

- **Dado** o deploy, que roda `php artisan view:cache` como root e serve o app
  por um usuário que não escreve em `storage/framework/views`
- **Quando** o cache de views é montado
- **Então** a view do seletor de páginas entra nele — o que exige que ela NÃO
  more sob um diretório `vendor/`, porque o `ViewCacheCommand` monta o Finder
  com `->exclude('vendor')` e nada dali é pré-compilado
- **E** a consequência de errar isso não aparece em desenvolvimento, onde a
  compilação sob demanda funciona: aparece só no servidor, como 500 em toda
  listagem paginada, com a página de erro do Laravel falhando junto (ela também
  compila sob demanda) e escondendo a causa atrás de um aviso de `tempnam()`

### US-022 — Faturamento auditável antes de gerar

Como responsável pelo faturamento, quero conferir de onde saiu cada valor antes
de gerar as cobranças, para não emitir cobrança errada para uma revenda.

#### AC-050 — A barra do ciclo resume o que será gerado, e o botão diz quanto

- **Dado** uma competência selecionada
- **Quando** a pessoa abre o faturamento
- **Então** vê o total do ciclo, quantas revendas e quantas linhas entram,
  quantas pendências existem, e um botão de gerar que declara no rótulo quantas
  cobranças serão criadas — não um "gerar" genérico

#### AC-051 — Cada linha mostra o cálculo, e o subtotal é a soma das linhas

- **Dado** o painel de uma revenda no faturamento
- **Quando** a pessoa confere o valor
- **Então** cada linha mostra a conta que gerou o valor (unidades vezes preço,
  valor fixo do tier, fixo com teto) e o total do painel é exatamente a soma
  dos valores das linhas que entram no ciclo

#### AC-052 — Linha sem tier fica de fora e aparece explicada

- **Dado** um sistema ativo numa revenda sem tier de atacado configurado
- **Quando** o ciclo é calculado
- **Então** essa linha aparece marcada como pendência, com valor vazio, fica
  fora do subtotal da revenda, e a tela avisa quantas linhas ficaram fora e por
  quê — com atalho para configurar o tier

### US-023 — Receitas e Despesas: enxergar o atraso e dar baixa em massa

Como pessoa do financeiro, quero ver onde o dinheiro está travado e dar baixa
em vários títulos de uma vez, para fechar o dia sem repetir o mesmo clique.

#### AC-053 — A faixa de atraso distribui o que está em aberto por vencimento

- **Dado** títulos em aberto com vencimentos diferentes
- **Quando** a pessoa abre Receitas ou Despesas
- **Então** vê o total em aberto repartido nas quatro faixas (a vencer, 1 a 15
  dias, 16 a 30 dias, mais de 30 dias), com barra proporcional a cada faixa

#### AC-054 — Selecionar títulos mostra o quanto e dá baixa de uma vez

- **Dado** a lista de títulos
- **Quando** a pessoa marca dois ou mais
- **Então** aparece a barra de seleção com a contagem e a soma dos
  selecionados, e a baixa em massa conclui todos os marcados de uma vez

#### AC-055 — Atrasado e a vencer se distinguem à primeira vista

- **Dado** uma lista com título atrasado, título vencendo em poucos dias e
  título em dia
- **Quando** a pessoa olha a lista
- **Então** o atrasado aparece marcado em vermelho e o que está por vencer em
  âmbar, cada um mostrando o prazo real ("atraso 26d", "em 4d") junto da data

### US-024 — Caixa e extrato

Como responsável pelo caixa, quero ver o saldo consolidado e a conta a conta,
para saber quanto tempo de folga a operação tem.

#### AC-056 — O caixa mostra o consolidado, cada conta e o mês

- **Dado** as contas financeiras cadastradas
- **Quando** a pessoa abre o Caixa
- **Então** vê o saldo total consolidado em destaque, um card por conta com
  saldo, variação no mês, participação no caixa e minitendência, e o resumo de
  entradas, saídas e resultado do mês corrente

#### AC-057 — O extrato mostra o saldo resultante linha a linha

- **Dado** o extrato de uma conta
- **Quando** a pessoa percorre as movimentações
- **Então** cada linha traz data, descrição, tipo, valor com sinal e o saldo da
  conta depois daquele lançamento

### US-025 — Cadastros auxiliares que dá para manter sem medo

Como pessoa que administra o sistema, quero saber o que está em uso antes de
remover, para não apagar um centro de custo que carrega histórico.

#### AC-058 — Cada item mostra quantos lançamentos dependem dele

- **Dado** a tela de cadastros auxiliares
- **Quando** a pessoa olha um centro de custo ou fornecedor
- **Então** vê quantos lançamentos usam aquele item ao lado do botão de
  remover — o dado que decide se remover é seguro

#### AC-059 — O plano de contas lê-se na horizontal

- **Dado** o plano de contas com categorias, subcategorias e contas
- **Quando** a pessoa abre a tela
- **Então** cada categoria é um bloco marcado pelo tipo (receita ou despesa) e
  cada subcategoria é uma linha com as contas como etiquetas ao lado — em vez
  de quatro níveis de indentação descendo a tela

### US-026 — Entrar por uma porta com a cara do sistema

Como pessoa da equipe, quero uma tela de entrada que já mostre que o sistema
está no ar, para saber se o problema é a minha senha ou o servidor.

#### AC-060 — O login traz a marca e os campos, sem ruído

- **Dado** alguém não autenticado
- **Quando** abre a tela de entrada
- **Então** vê o card centrado com a marca centralizada nele, os campos de
  e-mail e senha (com mostrar/ocultar senha) e lembrar-me — e nada além disso:
  a porta de entrada não explica a si mesma

<!--
  A recuperação de senha saiu desta lista em 14/08/2026, junto com as rotas
  (ver AC-260). Ela nunca chegou a funcionar em produção — o `MAIL_MAILER` é
  `log` — e uma tela que promete um e-mail que não sai é pior que uma tela que
  não promete nada. Quem perde a senha pede a um administrador, que gera uma
  nova na tela de usuários.
-->


### US-027 — Mobile funcional

Como pessoa que precisa consultar o painel fora da mesa, quero que a tela
funcione no celular, para conferir um dado sem abrir o notebook.

#### AC-061 — Em tela estreita o menu vira gaveta e nada estoura a largura

- **Dado** o painel aberto numa janela estreita (celular ou tablet)
- **Quando** a pessoa navega
- **Então** o menu some da lateral e passa a abrir como gaveta sobreposta pelo
  botão do topbar, e o conteúdo se reorganiza sem provocar rolagem horizontal
  da página inteira

### US-028 — O mesmo commit produz o mesmo CSS

Como quem publica, quero que compilar o front duas vezes do mesmo código dê o
mesmo resultado, para que "por que o CSS mudou sem ninguém mexer em CSS" nunca
precise ser respondido.

#### AC-217 — A varredura do Tailwind não depende do cache de views

- **Dado** o mesmo commit
- **Quando** compilo o front com o cache de views quente e depois com ele frio
- **Então** o CSS gerado é o mesmo. O cache de views compiladas
  (`storage/framework/views`) não entra na varredura: ele é estado da máquina,
  não código, e por ele o bundle mudava de tamanho conforme as telas que alguém
  tivesse aberto antes de compilar
- **E** view de vendor que precise deste CSS entra pelo caminho dela, explícita.
  A paginação já foi esse caso; hoje o seletor de páginas é nosso e mora em
  `resources/views/paginacao`, então nenhum caminho de vendor está listado
  (ver [AC-221] e [AC-222])

## Fora de escopo

- **Nenhum módulo, rota, tabela ou regra de negócio nova.** Muda a
  apresentação; controllers e models só evoluem para entregar à tela dado que
  já existe no banco (agrupamento, soma, série histórica).
- **Paleta de comandos do ⌘K**: o campo de busca é âncora visual; a busca em si
  fica para outra entrega.
- **Dropdown de notificações**: o botão e o contador entram; o painel que abre
  não foi desenhado.
- **Gestão de usuários e permissões**: continua pela linha de comando
  (ver [gestao-acessos]).
- **Dados de exemplo do protótipo**: os números do handoff são plausíveis, não
  reais — a tela mostra o que está no banco.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-017 | O redesign das 14 telas sai numa entrega só, com o shell (tokens, sidebar, topbar) como primeira tarefa e as telas em seguida. | confirmada | Confirmado pelo usuário em 06/08/2026: "tudo numa feature só". |
| ASM-018 | Os dois temas (escuro padrão e claro) entram agora, com alternador persistido no navegador. | confirmada | Confirmado pelo usuário em 06/08/2026. |
| ASM-019 | O funil ganha arrastar-e-soltar de verdade, mantendo o menu "Mover" como caminho acessível. | confirmada | Confirmado pelo usuário em 06/08/2026. |
| ASM-020 | As fontes Geist e Geist Mono podem ser servidas por CDN externo (Google Fonts), como já é feito hoje com a Space Grotesk pelo fonts.bunny.net. Se a política de rede do servidor barrar CDN, viram fontes locais. | aberta | — |
| ASM-021 | Os números que hoje não existem no banco (série de 6 meses, variação mês a mês, base ativa por unidade de cobrança, churn) podem ser derivados dos lançamentos e contratos já gravados, sem tabela nova. | aberta | — |
| ASM-022 | O `AlfaMatriz Sistema.dc.html` do handoff é a referência final e não haverá nova rodada de desenho durante a implementação. | aberta | — |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-008 | Qual tema é o padrão para quem entra pela primeira vez — o escuro (padrão do handoff) ou o do sistema operacional da pessoa? | respondida | Escuro sempre, em 06/08/2026. A preferência do sistema operacional é ignorada; vale a escolha guardada da pessoa. |
| Q-009 | O wordmark `uploads/alfamatriz.png` do handoff substitui em definitivo a logo "Baseline Ascendente" aplicada no commit b4a57d7? | respondida | Sim, em 06/08/2026. O wordmark e o `icon-matriz.svg` do handoff substituem a logo anterior e o `public/logo-tile.svg`. |
