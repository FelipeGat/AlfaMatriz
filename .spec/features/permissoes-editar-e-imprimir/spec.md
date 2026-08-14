# Spec: Permissoes editar e imprimir

> feature: permissoes-editar-e-imprimir
> status: rascunho

## Contexto

A grade de perfis tem quatro caixas — Ler, Incluir, Imprimir, Excluir — e duas
delas não descrevem o sistema:

- **Imprimir não é conferida em lugar nenhum.** Nenhuma rota, nenhuma tela. O
  administrador marca e desmarca a caixa e nada muda. Uma permissão que não
  recusa nada é pior que uma permissão ausente: ela faz a tela de perfis
  afirmar um controle que não existe.
- **Incluir também quer dizer editar.** `ChecarPermissao` lê o verbo HTTP e
  mapeia `PUT`/`PATCH` para `incluir`. Quem pode cadastrar um lead pode
  reescrever todos os leads existentes, e a grade não tem como dizer o
  contrário.

As duas saíram da varredura de segurança de 14/08/2026 como perguntas em aberto
(Q-020 e Q-021) e o dono do produto decidiu: **imprimir precisa valer**, e
**poder total é só do perfil Administrador**.

O que a decisão custa, medido antes de escrever:

- `imprimir` guarda o que TIRA dado do sistema: a exportação em CSV do
  Faturamento (a única exportação que existe) e o download de boleto, nota
  fiscal e comprovante. **Ninguém perde acesso na publicação** — o
  `PerfilPermissaoSeeder` já concede `imprimir` a todo perfil em todo recurso.
  A caixa passa a ser desmarcável de verdade, e é isso que muda.
- `editar` é coluna nova em `perfil_permissao`, ação nova nos quatro lugares que
  listam as ações, e **~20 rotas** a revisar. Metade das edições deste sistema
  não usa `PUT`: mover tarefa, mover lead, dar baixa em cobrança e bloquear
  licença são `POST`, e sem marcá-las uma a uma a separação ficaria pela
  metade — quem só cadastra continuaria movendo e dando baixa em tudo.

## Histórias

### US-077 — Imprimir guarda o que tira dado de dentro do sistema

Como dono do painel, quero que a caixa "Imprimir" da grade de perfis recuse
alguma coisa, para que ela pare de prometer um controle que não existe e eu
possa decidir quem leva boleto, nota fiscal e planilha para fora.

#### AC-272 — Sem "imprimir", a exportação do faturamento é recusada

- **Dado** alguém que enxerga a tela de Faturamento mas não tem a ação imprimir nesse recurso
- **Quando** pede a exportação em CSV (o botão "Exportar prévia")
- **Então** o painel recusa (403), e a tela em si continua abrindo normalmente

#### AC-273 — Sem "imprimir", baixar boleto e nota fiscal é recusado

- **Dado** alguém que enxerga cobranças e contas a pagar mas não tem a ação imprimir nesses recursos
- **Quando** pede o download de um anexo (boleto, nota fiscal, comprovante)
- **Então** o painel recusa (403), e a lista de anexos continua sendo exibida

#### AC-274 — Quem tem "imprimir" continua exportando e baixando

- **Dado** os perfis como o seeder os entrega hoje, todos com a ação imprimir
- **Quando** qualquer um deles exporta o faturamento ou baixa um anexo
- **Então** funciona como antes — ligar a permissão não tira acesso de ninguém na publicação

### US-078 — Cadastrar e editar são permissões diferentes

Como dono do painel, quero conceder "cadastrar" sem conceder "editar", para que
só o Administrador possa mexer em tudo, e um perfil que registra o que chega
não consiga reescrever o que já está registrado.

#### AC-275 — A grade de perfis passa a ter cinco caixas

- **Dado** a segunda aba da tela de usuários
- **Quando** o administrador abre a grade de um perfil
- **Então** vê Ler, Incluir, **Editar**, Imprimir e Excluir — e o que ele marcar em Editar é salvo e relido

#### AC-276 — Sem "editar", alterar um registro existente é recusado

- **Dado** alguém com a ação incluir num recurso, e sem a ação editar nele
- **Quando** envia a alteração de um registro que já existe (por exemplo, salvar um lead ou um cliente já cadastrado)
- **Então** o painel recusa (403), e o registro continua como estava

#### AC-277 — Sem "editar", cadastrar continua funcionando

- **Dado** a mesma pessoa do critério anterior
- **Quando** cadastra um registro novo
- **Então** o cadastro é aceito — tirar a edição não tira o cadastro

#### AC-278 — As edições que usam POST também exigem "editar"

- **Dado** alguém com a ação incluir e sem a ação editar
- **Quando** move uma tarefa, move um lead, dá baixa numa cobrança ou bloqueia uma licença
- **Então** o painel recusa (403) em todas — mover e dar baixa mexem no que já existe, e o verbo `POST` não muda isso

#### AC-279 — Ninguém perde a edição na publicação

- **Dado** os perfis que hoje têm a ação incluir num recurso
- **Quando** a migração roda
- **Então** eles passam a ter também a ação editar nesse mesmo recurso — a separação começa a existir sem tirar acesso de ninguém, e o administrador desmarca depois quem não deve editar

#### AC-280 — O Administrador continua podendo tudo

- **Dado** o perfil Administrador, que é imutável por definição
- **Quando** a ação editar passa a existir
- **Então** ele a tem em todos os recursos, sem o administrador precisar marcar nada

## Fora de escopo

- **Os anexos de tarefa** (`tarefas.anexos.ver`) continuam sob a ação ler. Eles
  não são "tirar dado de dentro": são como a grade PINTA o print de um defeito
  dentro do modal, e exigir imprimir para renderizar a tela transformaria uma
  permissão de saída de dado numa permissão de abrir o quadro. Boleto, nota e
  comprovante são o caso do dono do produto, e são financeiros.
- **Rever quem tem o quê.** Esta feature cria a separação; escolher qual perfil
  perde a edição é decisão de operação, feita na grade depois de publicada.
- **Uma ação "aprovar" ou "publicar".** Não foi pedida.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-062 | Ligar `imprimir` não tira acesso de ninguém hoje, porque o `PerfilPermissaoSeeder` concede a ação a todo perfil em todo recurso | confirmada | `database/seeders/PerfilPermissaoSeeder.php` linhas 64–143 |
| ASM-063 | `revendas.provisionar`, `faturamento.gerar` e `contas-fixas-pagar.gerar` continuam sob `incluir`: os três CRIAM registros, não alteram os que existem | aberta | conferir com o dono do produto ao revisar a lista rota a rota |
| ASM-064 | Nenhuma tela decide o que mostrar perguntando por `incluir` como sinônimo de "pode editar" — se alguma decidir, o botão apareceria para quem a rota vai recusar | aberta | varrer as views por `canPermissao(..., 'incluir')` antes de fechar a T-003 |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-022 | A ação `imprimir` deve guardar a exportação e o download de anexo financeiro, ou só a exportação? | respondida | Os dois — exportação em CSV e download de boleto, nota fiscal e comprovante (dono do produto, 14/08/2026) |
| Q-023 | Ao separar `editar`, quem hoje edita continua editando ou começa sem? | respondida | Continua: a migração concede `editar` a quem tem `incluir`, e o administrador desmarca depois (dono do produto, 14/08/2026) |
| Q-024 | As edições que usam `POST` (mover, dar baixa, bloquear) também exigem `editar`? | respondida | Sim, marcadas rota a rota (dono do produto, 14/08/2026) |
