# Spec: Revenda autoatendimento

> feature: revenda-autoatendimento
> status: rascunho

## Contexto

O cadastro de revenda, o cadastro do cliente e o pedido de licença acontecem
hoje **dentro do painel de cada sistema**. No AlfaGym: o administrador SaaS
provisiona a revenda, o usuário da revenda cadastra a academia (que nasce
aguardando licença) e o administrador SaaS libera. A Alfa está centralizando
essa operação no AlfaMatriz — e o AlfaGym é o primeiro sistema.

Parte do caminho já existe na Matriz: a tela de Revendas provisiona a revenda
no gym, o sincronizador traz revendas, clientes e licenças de hora em hora, e o
administrador da Alfa já libera, renova, suspende e reativa a licença. Do lado
do AlfaGym, o contrato `/api/matriz/v1` acabou de ganhar o lado de escrita
(feature `api-matriz-escrita` daquele repositório), incluindo o cadastro de
cliente que nasce pendente de licença.

O que falta é o autoatendimento da revenda. Hoje o `ClienteController` **recusa**
o usuário de revenda em `create` e `store` ("Os clientes da sua revenda são
provisionados pela matriz") — exatamente o oposto do fluxo que se quer. Falta
também o caminho para a revenda ter acesso ao painel, e falta fechar a migração
do que já está cadastrado no AlfaGym.

Nesta fase os dois painéis operam ao mesmo tempo, sobre os mesmos dados: o que
a revenda fizer no painel do AlfaGym continua chegando pelo sincronizador, e o
que ela fizer na Matriz nasce lá pelo contrato.

## Histórias

### US-041 — A revenda cadastra o próprio cliente e pede a licença

Como usuário de uma revenda, quero cadastrar meu cliente pelo AlfaMatriz e já
deixá-lo aguardando licença, para não precisar entrar no painel do AlfaGym.

#### AC-098 — A revenda cadastra o cliente pela Matriz

- **Dado** um usuário de revenda na tela de Clientes
- **Quando** ele abre o cadastro de cliente
- **Então** o formulário abre normalmente (hoje a tela recusa com "provisionados
  pela matriz"), já com a revenda dele preenchida e sem opção de escolher outra

#### AC-099 — O cliente cadastrado pela revenda nasce aguardando licença

- **Dado** um usuário de revenda preenchendo o cadastro de cliente com os dados
  da academia (nome, CNPJ, telefone, cidade, UF) e do administrador dela
  (nome, e-mail, senha)
- **Quando** ele salva
- **Então** o cliente é criado na Matriz vinculado à revenda dele, é criado
  também no AlfaGym, aparece com o estado "pendente de licença" e fica ancorado
  no sistema — para o sincronizador reconhecê-lo em vez de duplicar

#### AC-100 — Recusa do AlfaGym não deixa cliente órfão na Matriz

- **Dado** um cadastro de cliente que o AlfaGym recusa (e-mail de administrador
  já usado, por exemplo)
- **Quando** a revenda salva o formulário
- **Então** a tela mostra o motivo da recusa e **nenhum cliente é gravado na
  Matriz** — não sobra um cliente que existe aqui e não existe lá

#### AC-101 — A revenda não enxerga nem cadastra para outra revenda

- **Dado** um usuário de revenda
- **Quando** ele cadastra um cliente informando outra revenda no formulário
- **Então** o cliente é gravado na revenda dele mesmo assim (a revenda vem do
  escopo do usuário, nunca do formulário), e ele continua sem ver clientes de
  outras revendas na lista

### US-042 — A revenda pede a licença e o admin libera

Como usuário de uma revenda, quero acompanhar o pedido de licença do meu
cliente, para saber o que já está liberado e o que ainda aguarda a Alfa.

#### AC-102 — A revenda vê o estado da licença mas não a libera

- **Dado** um usuário de revenda com um cliente pendente de licença
- **Quando** ele abre a lista de clientes
- **Então** vê o cliente marcado como pendente de licença, e as ações de
  liberar, renovar, suspender e reativar **não aparecem para ele** — quem
  decide sobre licença é a Alfa

#### AC-103 — A tentativa de liberar licença pela revenda é recusada

- **Dado** um usuário de revenda
- **Quando** ele tenta acionar diretamente a liberação de licença de um cliente
  (fora da tela)
- **Então** a operação é recusada e nenhuma licença é criada ou alterada

### US-043 — O admin cadastra o cliente e licencia para uma revenda

Como administrador da Alfa, quero cadastrar um cliente e apontar a revenda dele,
para atender eventualidades sem depender da revenda entrar no painel.

#### AC-104 — O admin escolhe a revenda no cadastro do cliente

- **Dado** o administrador da Alfa no cadastro de cliente
- **Quando** ele preenche o formulário
- **Então** ele escolhe entre todas as revendas ativas, e o cliente nasce
  vinculado à revenda escolhida e aguardando licença — igual ao que a revenda
  cadastraria, sem virar venda direta

### US-044 — A revenda tem acesso ao painel

Como administrador da Alfa, quero criar o acesso do usuário da revenda junto
com o provisionamento dela, para a revenda conseguir entrar e operar.

#### AC-105 — Provisionar a revenda cria também o acesso dela ao painel

- **Dado** o administrador da Alfa provisionando uma revenda pela tela de
  Revendas, informando nome, e-mail e senha do administrador dela
- **Quando** o provisionamento conclui
- **Então** além do acesso criado no AlfaGym, passa a existir um usuário do
  AlfaMatriz com esse e-mail, restrito àquela revenda, capaz de entrar no painel

#### AC-106 — O acesso da revenda enxerga só a revenda dele

- **Dado** o usuário recém-criado de uma revenda
- **Quando** ele entra no painel
- **Então** ele vê apenas a revenda dele e os clientes dela — nenhuma outra
  revenda aparece, nem no filtro

### US-045 — A migração do que já existe é conferível

Como administrador da Alfa, quero conferir o que veio do AlfaGym antes de virar
a chave, para não descobrir depois que uma revenda não entra ou que uma licença
não pode ser renovada.

#### AC-107 — As revendas migradas ganham acesso ao painel

- **Dado** revendas que chegaram à Matriz pelo sincronizador, sem usuário
- **Quando** o administrador roda o comando de criar os acessos das revendas
- **Então** cada revenda sem acesso ganha um usuário restrito a ela, com uma
  senha forte gerada e impressa na saída para o admin repassar, e o comando
  relata quais criou e quais já tinham — rodar de novo não duplica nem redefine
  a senha de quem já existe

#### AC-108 — A conferência aponta as divergências entre Matriz e AlfaGym

- **Dado** a base já sincronizada do AlfaGym
- **Quando** o administrador roda o relatório de conferência
- **Então** o relatório lista, separadamente, os clientes sem revenda, os
  clientes licenciados sem âncora de licença (que impediria renovar ou suspender
  depois) e as revendas sem acesso ao painel — e termina com sucesso apenas se
  não houver nenhuma divergência

## Fora de escopo

- Remover as telas de revenda, cliente e licença do painel do AlfaGym: nesta
  fase os dois painéis operam ao mesmo tempo. A remoção é uma fase posterior.
- Os demais sistemas (AlfaControl, AlfaHome, AlfaMed, AlfaJornada, AlfaSchool,
  Gestor): esta feature cobre o AlfaGym, que é o primeiro.
- Edição do cliente no AlfaGym a partir da Matriz: aqui se cria e se licencia;
  manutenção cadastral do lado do gym continua como está.
- Tela de gestão de usuários da revenda (criar, editar, desativar pela
  interface): o acesso nasce no provisionamento e, para as revendas migradas,
  pelo comando de migração.
- Escolha do plano SaaS pela Matriz: o AlfaGym mantém o padrão dele.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-036 | O cadastro de cliente ganha os campos que o AlfaGym exige e a Matriz ainda não pede (telefone, cidade, UF já existem; entram nome, e-mail e senha do administrador da academia), mostrados só quando o AlfaGym está entre os sistemas marcados | confirmada | Resposta do usuário: "o cadastro tem que seguir o mesmo padrão existente hoje, o mesmo formulário" |
| ASM-037 | Criar o cliente na Matriz e criá-lo no AlfaGym acontece dentro de uma transação: se o gym recusa, a gravação local é desfeita. É o que impede o cliente órfão do AC-100 | confirmada | Resolvido pelo próprio escopo: AC-100 exige que nenhum cliente seja gravado quando o gym recusa |
| ASM-038 | O usuário da revenda recebe um perfil próprio `revenda`, com acesso só a revendas e clientes (ler e incluir); o `revenda_id` continua sendo o que restringe os dados | confirmada | Resposta do usuário: perfil novo, porque `operacao` enxerga sistemas, dashboard, leads e faturamento — coisas da Alfa |
| ASM-039 | O comando de acesso das revendas migradas gera uma senha forte por revenda e imprime na saída, usando o e-mail de contato da revenda; revenda sem e-mail de contato é relatada como pendência, não recebe acesso inventado | confirmada | Resposta do usuário: senha gerada e impressa por revenda — nada compartilhado entre revendas |
| ASM-040 | Cliente cadastrado pela revenda nasce AVULSO: valor mensal e dia de vencimento não aparecem no formulário dela, e entram quando o admin da Alfa libera a licença | confirmada | Resposta do usuário: "não — só o admin da Alfa" |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-015 | Quando a revenda cadastra um cliente na Matriz, ela informa valor mensal e dia de vencimento (os campos de contrato que a Matriz já tem), ou isso é sempre do administrador da Alfa? | respondida | Só o admin da Alfa. O cliente nasce AVULSO e o comercial entra na liberação da licença — o que a Matriz fatura é a revenda, não o cliente final |
