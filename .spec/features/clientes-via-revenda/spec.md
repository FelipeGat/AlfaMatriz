# Spec: Clientes via revenda

> feature: clientes-via-revenda
> status: rascunho

## Contexto

A comercialização dos sistemas da Alfa para o cliente final é **somente por
meio de revenda**. Um cliente final nunca é "venda direta da Alfa": ele
pertence a uma revenda, que é o único canal de venda.

No AlfaGym o ciclo de vida da licença já existe: a revenda cadastra a academia
(tenant) e ela nasce com status `PENDING_LICENSE`; o administrador SaaS vê as
pendentes e libera a licença (`POST /api/licencas/liberar`, com tipo
mensal/anual, valor e observação), que cria a licença ATIVA e deixa a academia
`ACTIVE`. Esse mesmo contrato já está exposto para a Matriz em
`/api/matriz/v1` (liberar, renovar, bloquear, desbloquear) com `X-Matriz-Key`.

A Matriz é o painel único (o painel do AlfaGym só é mantido nesta fase de
implementação). Ela sincroniza revendas, clientes e licenças do AlfaGym. Esta
feature muda a Matriz para refletir o modelo "só revenda vende": revenda
obrigatória no cadastro de cliente, clientes dentro da tela de revendas, e a
liberação da licença feita pelo admin da Matriz — sem contar como avulso.

## Histórias

### US-033 — Cliente final só é cadastrado vinculado a uma revenda

Como administrador da Alfa, quero que todo cliente final pertença a uma
revenda (nunca venda direta), para que o único canal de venda seja a revenda.

#### AC-072 — Cadastro de cliente exige revenda

- **Dado** que estou no formulário de cadastro de cliente
- **Quando** submeto o formulário sem escolher uma revenda
- **Então** o cadastro é recusado com aviso de que a revenda é obrigatória
  (validação `revenda_id` obrigatório), e a opção "Venda direta da Alfa" não
  existe mais no formulário

#### AC-073 — Listagem de clientes não oferece mais o recorte "venda direta"

- **Dado** a tela de listagem de clientes
- **Quando** abro os filtros de revenda
- **Então** a opção "Venda direta" não aparece — todo cliente da lista
  pertence a alguma revenda

#### AC-074 — Todo cliente sincronizado chega vinculado à revenda

- **Dado** o sincronizador processando um cliente do AlfaGym com
  `revenda_id_externo`
- **Quando** o cliente é gravado na Matriz
- **Então** o cliente fica vinculado à revenda correspondente (o AlfaGym não
  possui clientes sem revenda, então nunca existe cliente direto vindo do sync)

### US-034 — Clientes dentro da tela de Revendas

Como administrador da Alfa, quero ver os clientes dentro da tela de Revendas,
para ter o portfólio por revenda no mesmo lugar.

#### AC-075 — A tela de Revendas mostra a aba "Clientes"

- **Dado** que estou na tela de Revendas
- **Quando** abro a aba "Clientes"
- **Então** vejo a lista de clientes da revenda selecionada (filtro por
  revenda), com a coluna de revenda mostrando a qual revenda cada cliente
  pertence

#### AC-076 — Usuário de revenda vê só a própria revenda na aba

- **Dado** um usuário com escopo de revenda
- **Quando** ele abre a aba "Clientes" na tela de Revendas
- **Então** ele só vê os clientes da própria revenda, e a lista de revendas do
  filtro mostra apenas a dele

### US-035 — Admin da Matriz libera a licença do cliente

Como administrador da Alfa (o admin SaaS), quero liberar a licença do cliente
que a revenda solicitou, informando tipo, valor e observação, para que o
cliente ative o sistema no AlfaGym — sem que isso conte como venda avulsa.

#### AC-077 — Cliente pendente de licença aparece com ação de liberar

- **Dado** um cliente sincronizado do AlfaGym com status pendente
  (`PENDING_LICENSE`) vinculado a uma revenda
- **Quando** abro a lista de clientes daquela revenda
- **Então** vejo o cliente com status "pendente de licença" e o botão
  "Liberar licença" disponível

#### AC-078 — Liberar licença envia tipo, valor e observação ao AlfaGym

- **Dado** um cliente pendente de licença com âncora no AlfaGym
- **Quando** o admin clica em "Liberar licença" e confirma com tipo
  (mensal/anual), valor e observação
- **Então** a Matriz chama `POST /api/matriz/v1/licencas` com
  `cliente_id_externo`, `tipo`, `valor` e `obs`, e o cliente passa a exibir a
  licença ativa retornada (o vínculo `cliente_sistema` guarda a vigência)

#### AC-079 — Liberar licença não conta como avulso

- **Dado** um cliente vinculado a uma revenda
- **Quando** a licença dele é liberada pela Matriz
- **Então** o cliente permanece vinculado à revenda (não vira venda direta) e
  o faturamento dele continua sendo da revenda, não avulso

#### AC-080 — Recusa do AlfaGym aparece como erro sem gravar nada

- **Dado** um cliente pendente de licença
- **Quando** o AlfaGym recusa a liberação (ex.: cliente já ativo, erro de
  validação, contrato incompatível)
- **Então** a Matriz mostra o erro ao admin e não grava nenhuma alteração no
  vínculo do cliente

## Fora de escopo

- Cadastro/edição de revenda pelo gym (continua como está, nesta fase os dois
  painéis operam).
- Bloquear/desbloquear/renovar licença pela tela da Matriz (o contrato já
  expõe, mas a tela só libera por ora).
- Migração de clientes diretos existentes: no AlfaGym todo cliente pertence a
  uma revenda, então não existe cliente sem revenda a vincular.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-027 | O item "Clientes" do menu lateral some: o acesso aos clientes passa a ser pela tela de Revendas (aba "Clientes"), mantendo a rota `clientes.index` como implementação da aba | confirmada | Resposta do usuário: "Some do menu" |
| ASM-028 | O status do cliente vindo do AlfaGym (pendente/ativo/bloqueado) passa a ser gravado na Matriz para a tela saber quem está aguardando liberação (coluna nova no vínculo `cliente_sistema`) | aberta | — |
| ASM-029 | A liberação pelo admin usa os mesmos campos do contrato: tipo (`mensal`/`anual`), valor e observação; plano fica opcional (o gym mantém o atual da subscription quando ausente) | confirmada | Resposta do usuário: observação livre |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-011 | Clientes diretos existentes (sem revenda) devem poder ser vinculados a uma revenda pela própria aba de clientes, ou isso é feito por comando/edição do cliente? | respondida | Não existe cliente sem revenda no AlfaGym — pergunta encerrada, removida |
| Q-012 | Ao liberar a licença pela Matriz, o admin informa a observação livremente ou há valores predefinidos (ex.: contrato, proposta, trial)? | respondida | Livre |
