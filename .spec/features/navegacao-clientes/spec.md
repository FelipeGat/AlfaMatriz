# Spec: Navegação clientes

> feature: navegacao-clientes
> status: rascunho

## Contexto

As features `clientes-via-revenda` e `revenda-autoatendimento` entregaram o
cadastro de cliente e o ciclo de vida da licença com o backend provado: a
revenda cadastra o próprio cliente, o admin da Alfa libera, renova, suspende e
reativa. Os testes passavam.

Só que **nada disso era alcançável pela interface**. A rota `clientes.create`
não era referenciada por view nenhuma — tela órfã, acessível só digitando a URL.
O único caminho de menu até clientes é a aba "Clientes" da tela de Revendas, e
o botão do cabeçalho dela dizia "+ Nova revenda" mesmo com a aba de clientes
aberta. O dono do produto abriu o painel e relatou: "não vi em lugar nenhum
interface para licenciar clientes ou cadastrar clientes, somente revendas".

A causa da lacuna é uma classe de teste que faltava. Os testes existentes
provavam que `GET /clientes/create` responde 200 — o endpoint. Nenhum provava
que existe um **caminho de navegação** até ele. Endpoint que responde e tela que
ninguém alcança são coisas diferentes, e só a segunda importa para quem usa.

Ao investigar, apareceram outros defeitos da mesma família — coisas que quem usa
encontra no primeiro minuto e que teste de endpoint nunca vê: filtrar dentro da
aba de clientes devolvia o usuário para a aba de revendas; a recusa do AlfaGym
no cadastro voltava com o modal fechado, deixando a tela aparentemente inerte;
o cabeçalho contava revendas na aba de clientes; e havia botões oferecidos a
quem toma 403 ao clicar.

## Histórias

### US-048 — O caminho até o cliente existe e se mantém

Como administrador da Alfa ou usuário de uma revenda, quero chegar aos clientes
e ao cadastro deles a partir do menu, para conseguir trabalhar sem que alguém me
diga qual URL digitar.

#### AC-115 — A aba de clientes traz o cadastro junto com a lista

- **Dado** que estou na tela de Revendas com a aba "Clientes" aberta
- **Quando** a tela carrega
- **Então** vejo o botão "+ Novo cliente" e o formulário de cadastro na mesma
  página — gatilho e formulário juntos, porque um sem o outro não cadastra nada

#### AC-116 — A aba de revendas não oferece cadastro de cliente

- **Dado** que estou na aba "Revendas"
- **Quando** a tela carrega
- **Então** o botão é "+ Nova revenda" e não há formulário de cadastro de
  cliente na página — nada de formulário sem gatilho esperando para reaparecer

#### AC-117 — Partindo do menu se chega à lista e ao cadastro

- **Dado** um administrador recém-logado, que só conhece o menu
- **Quando** ele segue os links do menu lateral
- **Então** em no máximo dois saltos chega a uma página que mostra os clientes
  e permite cadastrar um novo — sem precisar de URL digitada

#### AC-118 — A revenda cai direto na carteira dela

- **Dado** um usuário de revenda entrando no painel
- **Quando** ele abre a raiz do sistema ou clica no item de menu dele
- **Então** cai na lista de clientes da própria revenda, com o botão de cadastrar
  disponível, sem ver nome de outra revenda

#### AC-119 — Filtrar na aba de clientes não expulsa da aba

- **Dado** que estou na aba "Clientes" e busco por um cliente
- **Quando** submeto o filtro
- **Então** continuo na aba "Clientes", com o resultado da busca — a busca não
  me devolve para a aba de revendas

#### AC-120 — Recusa no cadastro reabre o formulário com o motivo

- **Dado** um cadastro de cliente que o AlfaGym recusa
- **Quando** volto para a tela
- **Então** o formulário reabre com a mensagem do erro visível — a tela não
  simplesmente "não faz nada"

#### AC-121 — Botão só aparece para quem pode usá-lo

- **Dado** um usuário sem permissão de incluir cliente (o perfil financeiro) ou
  um usuário de revenda diante do cadastro de revenda
- **Quando** ele abre a tela
- **Então** o botão correspondente não aparece — oferecer um caminho que termina
  em 403 é pior que não oferecer

## Fora de escopo

- Voltar o item "Clientes" ao menu lateral: decisão do produto na feature
  anterior foi tirá-lo, e ela se mantém. O acesso é pela aba.
- Tela de detalhe do cliente (`clientes.show`): nunca teve método no controller;
  a rota gerada foi removida junto com a tela órfã de cadastro.
- Voltar para a aba de origem depois de cadastrar (o `store` sempre redireciona
  para a lista de clientes).

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-044 | O cadastro de cliente passa a ter UM lugar só (o modal da lista); a página inteira que duplicava o formulário é apagada, não linkada | confirmada | Decisão do usuário: "apagar, ficar só com o modal" |
| ASM-045 | O item de menu passa a se chamar "Revendas e clientes" em vez de ganhar subitens ou devolver "Clientes" ao menu | confirmada | Decisão do usuário: botão na aba, sem mexer no menu. O rótulo é o mínimo que anuncia o conteúdo sem reabrir a decisão |
| ASM-046 | Dois saltos a partir do menu é o limite aceitável para chegar ao cadastro (menu → aba → cadastrar) | aberta | — |

## Perguntas em aberto

Nenhuma.
