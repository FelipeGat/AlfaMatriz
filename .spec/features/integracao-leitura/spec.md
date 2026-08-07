# Spec: Integração — a matriz enxerga os sistemas

> feature: integracao-leitura
> status: rascunho

<!--
  Como ler este arquivo (o formato é verificado por `onp-spec audit`):
  - US-xxx = história de usuário · AC-xxx = critério de aceite
    ASM-xxx = suposição · Q-xxx = pergunta em aberto
  - Toda história de usuário precisa de pelo menos um critério de aceite.
  - Todo critério de aceite precisa de Dado/Quando/Então completos.
  - Os códigos são únicos no projeto inteiro (nunca reutilize um número).
-->

## Contexto

O AlfaMatriz é cego: todo número que ele mostra foi digitado à mão. Os cinco
sistemas da casa (AlfaControl, AlfaGym, AlfaHome, AlfaJornada, AlfaMed) rodam em
produção com base própria, cada um com seu cadastro de revendas, clientes e
licenças — e nenhum deles sabe que o AlfaMatriz existe. Ninguém consegue
responder, olhando um lugar só, se o cliente ativo dentro do AlfaGym está sendo
cobrado pela Alfa.

Esta feature faz a matriz **enxergar**. Ela ainda não manda em nada: lê o que
cada sistema tem, guarda um retrato local, mostra o que cada sistema cobra de
cada cliente, aponta onde o que os sistemas dizem não bate com o que a Alfa
faturou, e prepara o terreno para a virada — traz o cadastro que já existe lá
para cá, com uma conferência humana no meio.

É a etapa que torna seguro o passo seguinte (`matriz-dona-cadastro`), em que o
cadastro passa a nascer aqui. Sem o retrato e sem a conferência, o corte seria
feito no escuro.

O gancho já está no código e nunca foi usado: `sistemas.base_url` e
`sistemas.token` existem desde o começo, são editáveis na tela de Produtos e
**nenhuma linha do projeto os lê**.

Piloto: **AlfaGym**. Os outros quatro vêm depois, com o mesmo contrato.

## Histórias

### US-034 — Todo sistema fala com a matriz do mesmo jeito

Como responsável pela Alfa, quero um contrato único que todo sistema da casa
cumpra, para que integrar o segundo, o terceiro e o quarto seja repetir o
mesmo trabalho em vez de inventar cinco integrações diferentes.

#### AC-078 — O contrato está escrito, versionado e é a referência de todos

- **Dado** o repositório do painel
- **Quando** alguém vai integrar um sistema novo
- **Então** encontra um documento versionado que descreve os endereços a
  expor, o formato das respostas, o catálogo de erros e como a versão do
  contrato evolui — e o painel recusa uma resposta cuja versão de contrato ele
  não entende, em vez de gravar dado torto no retrato local

#### AC-079 — Sistema sem endereço ou sem chave é recusado com motivo legível

- **Dado** um sistema cadastrado sem endereço de integração ou sem chave
- **Quando** alguém manda sincronizar
- **Então** a tela diz qual dos dois falta, em vez de mostrar erro técnico, e
  nada é gravado no retrato local

#### AC-080 — Salvar o cadastro do sistema não apaga a chave de integração

- **Dado** um sistema com a chave de integração já preenchida
- **Quando** alguém salva a tela do sistema mexendo em qualquer outro campo,
  sem digitar a chave de novo
- **Então** a chave continua valendo (o campo é oculto por segurança e chega
  sempre vazio; gravá-lo assim desligaria a integração inteira em silêncio)

#### AC-081 — A chave nunca aparece em registro nem em tela

- **Dado** uma chamada a um sistema, com sucesso ou com erro
- **Quando** o painel registra o que aconteceu
- **Então** a chave não aparece em lugar nenhum do registro, nem da mensagem
  de erro mostrada na tela

### US-035 — O painel não depende de o sistema estar no ar

Como responsável pela Alfa, quero abrir o painel e ver a situação dos sistemas
mesmo quando um deles está fora do ar, para que uma queda lá não me deixe sem
informação aqui.

#### AC-082 — Sistema fora do ar mostra o último retrato e desde quando falha

- **Dado** um sistema que parou de responder
- **Quando** o painel de integração é aberto
- **Então** ele continua mostrando o último retrato obtido, sinaliza que o
  sistema está fora do ar e informa há quantas tentativas seguidas ele falha —
  e o retrato local não é alterado por causa da falha

#### AC-083 — Cada tela diz de quando é o dado que está mostrando

- **Dado** um retrato local obtido em algum momento do passado
- **Quando** qualquer tela de integração é aberta
- **Então** ela informa há quanto tempo aquele dado foi obtido, e destaca
  quando está velho demais para se confiar

### US-036 — O retrato local é fiel ao que o sistema disse

Como responsável pela Alfa, quero que a cópia local reflita o sistema sem
inventar nem perder registro, para que eu possa decidir em cima dela.

#### AC-084 — Sincronizar traz o cadastro e as licenças do sistema

- **Dado** um sistema configurado e no ar
- **Quando** a sincronização é executada
- **Então** revendas, clientes, planos, licenças e o financeiro da competência
  daquele sistema passam a existir no retrato local, e a execução fica
  registrada com quantos itens vieram e quanto tempo levou

#### AC-085 — Sincronizar de novo não duplica nada

- **Dado** um sistema já sincronizado
- **Quando** a sincronização é executada de novo
- **Então** nenhum registro é duplicado no retrato local — cada item continua
  aparecendo uma vez só, atualizado

#### AC-086 — Registro que sumiu na origem é marcado, nunca apagado

- **Dado** um cliente que existia no sistema e deixou de aparecer
- **Quando** a sincronização é executada
- **Então** ele é marcado como ausente na origem, com a data, e continua no
  retrato local (apagar levaria junto o vínculo com o cliente da matriz e o
  histórico do que já foi faturado)

#### AC-087 — Varredura interrompida não desativa quem nem chegou a ser lido

- **Dado** uma sincronização que falha no meio da leitura de uma lista
- **Quando** a execução termina
- **Então** nenhum registro é marcado como ausente por causa dessa falha, a
  execução é registrada como parcial e o que já entrou permanece

### US-037 — Dá para ver o que cada sistema cobra de cada cliente

Como responsável pela Alfa, quero ver, por sistema, por revenda e por cliente,
o que está sendo cobrado e o que está em atraso, para saber se o que a Alfa
fatura corresponde ao que os sistemas mostram.

#### AC-088 — O financeiro aparece por competência, revenda e cliente

- **Dado** um retrato local com o financeiro de uma competência
- **Quando** a tela de financeiro dos sistemas é aberta naquela competência
- **Então** ela mostra, por sistema e por revenda, o valor, a situação
  (pago, em aberto, vencido) e o atraso de cada cliente, com o total conferindo
  com a soma das linhas

#### AC-089 — A contagem da unidade de cobrança fica guardada por competência

- **Dado** sistemas cuja unidade de cobrança é diferente (academia ativa,
  condomínio ativo, vidas, funcionário ativo)
- **Quando** a sincronização de uma competência é executada
- **Então** a contagem daquela unidade fica guardada por sistema e por
  competência, permitindo comparar meses depois

#### AC-090 — O que os sistemas dizem é confrontado com o que a Alfa faturou

- **Dado** uma competência com retrato dos sistemas e com faturamento da Alfa
  já gerado
- **Quando** a tela de divergências é aberta
- **Então** ela lista onde a contagem do sistema não bate com a que a Alfa
  faturou daquela revenda, e onde um cliente ativo na matriz não aparece ativo
  no sistema — apontando o caso, não apenas o total

### US-038 — O cadastro que já existe nos sistemas entra sem virar bagunça

Como responsável pela Alfa, quero trazer para a matriz o cadastro que já vive
dentro de cada sistema, com uma conferência minha no meio, para que a virada
não junte cliente errado nem duplique ninguém.

#### AC-091 — O casamento automático só acontece quando não há dúvida

- **Dado** um cliente do sistema cujo documento corresponde a exatamente um
  cliente da matriz
- **Quando** a importação é executada
- **Então** os dois ficam vinculados automaticamente; e quando o documento
  corresponde a nenhum ou a mais de um, nenhum vínculo é criado

#### AC-092 — Todo caso duvidoso vira pendência com ação, não vínculo errado

- **Dado** clientes do sistema sem par na matriz, com mais de um candidato, sem
  documento na origem, ou repetidos dentro do próprio sistema
- **Quando** a tela de conferência é aberta
- **Então** cada caso aparece separado por motivo, com a ação correspondente
  ao alcance da mão, e a contagem de pendências fica visível

#### AC-093 — A importação nunca cria cliente sozinha

- **Dado** um cliente do sistema sem par na matriz
- **Quando** a importação é executada
- **Então** nenhum cliente é criado na matriz automaticamente (criar mudaria o
  faturamento da empresa sem ninguém decidir) — a criação é sempre uma ação
  explícita na tela de conferência

### US-039 — O corte é uma decisão consciente, sistema por sistema

Como responsável pela Alfa, quero declarar explicitamente o momento em que a
matriz passa a mandar em cada sistema, para que a virada não aconteça por
acidente nem em todos ao mesmo tempo.

#### AC-094 — O corte só pode ser aplicado com a conferência zerada

- **Dado** um sistema com pendências de conferência em aberto
- **Quando** alguém tenta aplicar o corte
- **Então** o corte é recusado, dizendo quantas pendências faltam; e com zero
  pendências, o corte é aplicado e fica registrado quando e por quem

#### AC-095 — O painel mostra, por sistema, se o corte já valeu

- **Dado** sistemas em estágios diferentes da virada
- **Quando** o painel de integração é aberto
- **Então** cada um mostra se ainda está apenas sendo observado ou se a matriz
  já é a dona do cadastro dele, com a data

### US-040 — O AlfaGym responde ao contrato

Como responsável pela Alfa, quero o AlfaGym atendendo o contrato como piloto,
para provar que ele funciona com um sistema de verdade antes de replicar nos
outros quatro.

#### AC-096 — O AlfaGym só atende quem apresenta a chave da matriz

- **Dado** o AlfaGym com a integração ligada
- **Quando** chega um pedido sem a chave da matriz, ou com chave errada
- **Então** o pedido é recusado sem revelar qual dos dois estava errado; e com
  a integração desligada, todo pedido é recusado

#### AC-097 — Os dados do AlfaGym chegam à matriz no formato do contrato

- **Dado** respostas reais do AlfaGym, capturadas do sistema em execução
- **Quando** a matriz as processa
- **Então** o retrato local fica correto: academia vira cliente com a situação
  certa, a licença traz início, fim e se ela barra o acesso, e a contagem da
  unidade de cobrança é o número de academias ativas

## Fora de escopo

- **Escrever nos sistemas**: criar, alterar, liberar licença, bloquear ou pôr
  em manutenção é a feature `matriz-dona-cadastro`.
- O financeiro interno do produto (mensalidade de aluno, conta a receber de
  condômino): só entra o que o sistema cobra do cliente pela licença.
- Os outros quatro sistemas: aqui só o AlfaGym expõe o contrato.
- O produto `gestor` (categoria crm) — ver ASM-035.
- Aplicar permissão de perfil nas telas novas: entra junto com as ações de
  escrita, na feature seguinte.
- Encolher o painel SaaS do AlfaGym: última tarefa da feature seguinte, e só
  com aval do dono.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-030 | A unidade de cobrança do AlfaGym é a academia ativa; a tabela de unidades (filiais) é subdivisão interna e não conta para o faturamento | aberta | Confirmar com o dono do produto e conferir contra o que a Alfa fatura hoje da Invest Soluções |
| ASM-031 | O AlfaGym não tem título de cobrança próprio, então o financeiro é derivado da licença e marcado como derivado — a tela de divergências não pode acusar diferença em cima de linha derivada | aberta | Fecha ao capturar as respostas reais do AlfaGym (ver Q-012) |
| ASM-032 | O documento (CNPJ/CPF) é identificador suficiente para casar a maioria dos clientes; os casos sem documento serão minoria tratável à mão | aberta | Só a importação real diz o tamanho da minoria |
| ASM-033 | A chave de integração é gerada pela matriz e o sistema guarda apenas o resumo criptográfico dela, nunca a chave em claro | aberta | Confirmar ao implementar o lado do AlfaGym |
| ASM-034 | Uma sincronização sob demanda cabe no tempo de resposta do servidor web, com teto de páginas; a varredura completa fica para a execução agendada | aberta | Medir na primeira sincronização real do AlfaGym |
| ASM-035 | O produto `gestor` fica fora da integração por ser de outra categoria; o filtro por categoria é o que o mantém fora | confirmada | Decisão do dono do produto em 07/08/2026: "esqueça o alfagestor por enquanto" |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-012 | O AlfaGym deve ganhar título de cobrança de verdade, ou o financeiro derivado da licença basta para o que a Alfa precisa enxergar? | aberta | — |
| Q-013 | De quanto em quanto tempo a sincronização deve rodar sozinha? O desenho parte de uma leve por hora e uma completa de madrugada | aberta | — |
| Q-014 | Cliente do sistema sem documento na origem: vincular sempre à mão, ou vale sugerir par por semelhança de nome para o humano confirmar? | aberta | — |
