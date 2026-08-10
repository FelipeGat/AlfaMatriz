# Spec: Sync contato do cliente

> feature: sync-contato-do-cliente
> status: rascunho

## Contexto

O AlfaGym envia o contato de cada cliente no contrato `/api/matriz/v1/clientes`:
o DTO `MatrizCliente` traz `email` e `telefone` da academia. O
`SincronizadorSistemaService` monta esses dois campos no array que passa para
`Cliente::create()` / `update()` — e eles **desaparecem ali**.

Não é um caso de dado sobrescrito: a tabela `clientes` da Matriz não tem coluna
`email` nem `telefone`. O contato do cliente mora em duas tabelas próprias,
`cliente_emails` e `cliente_telefones`, que o formulário da tela já usa (com
marcação de principal e de e-mail financeiro). O sincronizador simplesmente
não sabe disso: entrega os campos para a atribuição em massa, o Eloquent
descarta o que não é `$fillable`, e ninguém é avisado.

O efeito é medível na base de hoje: **7 clientes vieram do AlfaGym pelo sync e
o banco inteiro tem 0 e-mails e 0 telefones cadastrados.** Quem abre a ficha de
um cliente migrado não encontra como falar com ele; a cobrança não tem para
onde mandar; e o cadastro de cliente pela Matriz, que envia o telefone de volta
ao AlfaGym, manda vazio para todo cliente que veio de lá.

O lado da revenda não tem esse problema: `contato_email` e `contato_telefone`
são colunas de verdade em `revendas` e estão no `$fillable` — chegam e ficam.

## Histórias

### US-046 — O contato do cliente sobrevive à sincronização

Como administrador da Alfa, quero que o e-mail e o telefone que o AlfaGym
informa cheguem à ficha do cliente na Matriz, para conseguir falar com o
cliente e cobrar sem ter que abrir o painel do gym.

#### AC-109 — O contato vindo do AlfaGym aparece na ficha do cliente

- **Dado** um cliente no AlfaGym com e-mail e telefone preenchidos
- **Quando** o sincronizador processa esse cliente
- **Então** o e-mail e o telefone passam a aparecer na ficha do cliente na
  Matriz, marcados como principais (gravados em `cliente_emails` e
  `cliente_telefones`, não descartados)

#### AC-110 — Sincronizar de novo não duplica o contato

- **Dado** um cliente já sincronizado, com e-mail e telefone gravados
- **Quando** o sincronizador roda outra vez com os mesmos dados
- **Então** o cliente continua com um e-mail e um telefone — a repetição não
  cria uma segunda linha igual

#### AC-111 — Contato editado na Matriz não é apagado pelo sync

- **Dado** um cliente cujo contato foi complementado na Matriz (um segundo
  e-mail, marcado como financeiro)
- **Quando** o sincronizador roda de novo
- **Então** o e-mail acrescentado na Matriz continua lá, e a marcação de
  financeiro é preservada — o sync atualiza o contato que veio do gym sem
  varrer o que foi cadastrado aqui

#### AC-112 — Cliente sem contato no AlfaGym não ganha registro vazio

- **Dado** um cliente no AlfaGym sem e-mail e sem telefone
- **Quando** o sincronizador o processa
- **Então** nenhum e-mail ou telefone em branco é criado para ele — a ficha
  fica sem contato, que é a verdade

#### AC-114 — Contato trocado no AlfaGym entra sem apagar o anterior

- **Dado** um cliente já sincronizado cujo telefone mudou no AlfaGym
- **Quando** o sincronizador roda de novo
- **Então** o telefone novo passa a constar na ficha, e o antigo continua
  listado — a Matriz não apaga contato sozinha; quem limpa a lista é o time,
  pela tela

### US-047 — O contato que já se perdeu é recuperado

Como administrador da Alfa, quero que os clientes já sincronizados sem contato
recebam o contato que o AlfaGym tem, para não precisar refazer o cadastro à mão
dos que migraram antes da correção.

#### AC-113 — Uma sincronização completa preenche o contato dos já migrados

- **Dado** clientes que já vieram do AlfaGym sem e-mail e sem telefone (os 7
  da base de hoje)
- **Quando** o administrador roda a sincronização completa depois da correção
- **Então** cada um passa a ter o contato que o AlfaGym informa, sem que
  nenhum cliente seja duplicado nem perca a âncora que já tinha

## Fora de escopo

- Enviar contato da Matriz de volta para o AlfaGym: aqui o fluxo é só de
  leitura, o gym continua sendo a origem do contato do cliente.
- Os demais campos que o contrato traz e a Matriz ainda não guarda (se houver):
  esta feature corrige e-mail e telefone, que são os que se perdem hoje.
- Contato de revenda: já funciona, `contato_email` e `contato_telefone` são
  colunas reais e chegam normalmente.
- Impedir que atribuição em massa descarte campo em silêncio de forma genérica
  (ex.: `preventSilentlyDiscardingAttributes` do Eloquent): mudaria o
  comportamento de todos os modelos do projeto e merece decisão própria — fica
  registrado como pergunta em aberto.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-041 | O e-mail e o telefone vindos do AlfaGym entram como **principais** quando o cliente ainda não tem nenhum; se já houver um principal cadastrado na Matriz, o do gym entra como adicional | confirmada | Resposta do usuário: o do gym entra como adicional — o sync nunca rebaixa uma decisão tomada por gente |
| ASM-042 | O sync casa o contato por VALOR (mesmo e-mail ou telefone já gravado = não recria), sem coluna de origem no contato | confirmada | Resposta do usuário: pelo valor. Consequência aceita e registrada em AC-114: telefone alterado no gym entra como novo e o antigo permanece na lista |
| ASM-043 | A recuperação dos já migrados (US-047) não precisa de comando novo: `php artisan app:sincronizar-alfagym` varre todos os clientes e, corrigido, preenche o contato de quem estava sem | confirmada | O sincronizador já percorre a coleção inteira a cada execução (`todasPaginas('/clientes')`), sem recorte incremental — verificado no código |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-016 | O Eloquent descartar em silêncio um campo fora do `$fillable` foi o que escondeu este bug. Vale ligar `Model::preventSilentlyDiscardingAttributes()` no projeto inteiro? | respondida | Não nesta feature. Ligar isso mexe em todos os modelos e pode quebrar pontos que hoje passam despercebidos — merece varredura e decisão próprias. Fica registrado como dívida conhecida |
