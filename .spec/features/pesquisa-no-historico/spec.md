# Spec: Pesquisa no historico

> feature: pesquisa-no-historico
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

O histórico de tarefas já tem busca por palavra-chave, mas ela só varre título,
resumo, detalhes, comentários, sistema, responsável e o número do card. Metade
do que se escreve numa tarefa fica fora do alcance: itens de checklist, motivos
de bloqueio/retorno, motivos registrados na linha do tempo, notas de relatório
de teste, nome de anexo e versão de produção. Quem lembra de "qualquer texto ou
número" que viu na tarefa — um número de chamado no checklist, uma versão, o
nome de um arquivo — digita na busca e recebe tela vazia, num acervo que só
cresce. Esta feature fecha a lacuna: a busca passa a alcançar TODO texto ou
número gravado na tarefa.

## Histórias

### US-096 — Encontrar a tarefa por qualquer texto ou número escrito nela

Como membro da equipe, quero pesquisar no histórico (e no quadro) por qualquer
texto ou número que tenha sido escrito em qualquer parte da tarefa, para
encontrá-la mesmo quando a palavra só aparece no checklist, num motivo, num
relatório de teste, no nome de um anexo ou na versão de produção.

#### AC-343 — A busca acha a tarefa pelo item do checklist

- **Dado** uma tarefa encerrada cujo checklist tem um item com um texto que não
  aparece em nenhum outro campo dela
- **Quando** eu busco esse texto no histórico
- **Então** a tarefa aparece na lista de resultados

#### AC-344 — A busca acha a tarefa pelo motivo registrado na linha do tempo

- **Dado** uma tarefa encerrada cuja linha do tempo tem um evento com motivo
  (ex.: uma devolução) contendo um texto que não aparece em nenhum outro campo
- **Quando** eu busco esse texto no histórico
- **Então** a tarefa aparece na lista de resultados

#### AC-345 — A busca acha a tarefa pelo motivo de bloqueio ou de retorno

- **Dado** uma tarefa com motivo de bloqueio (ou de retorno) contendo um texto
  que não aparece em nenhum outro campo dela
- **Quando** eu busco esse texto na aba onde a tarefa está
- **Então** a tarefa aparece na lista de resultados

#### AC-346 — A busca acha a tarefa pelas notas do relatório de teste

- **Dado** uma tarefa encerrada com um relatório de teste cujas notas contêm um
  texto que não aparece em nenhum outro campo dela
- **Quando** eu busco esse texto no histórico
- **Então** a tarefa aparece na lista de resultados

#### AC-347 — A busca acha a tarefa pelo nome do anexo

- **Dado** uma tarefa encerrada com um anexo cujo nome original contém um texto
  que não aparece em nenhum outro campo dela
- **Quando** eu busco esse texto no histórico
- **Então** a tarefa aparece na lista de resultados

#### AC-348 — A busca acha a tarefa pela versão de produção

- **Dado** uma tarefa encerrada com versão de produção registrada (ex.: um
  número de versão que não aparece em nenhum outro campo)
- **Quando** eu busco esse número no histórico
- **Então** a tarefa aparece na lista de resultados

#### AC-349 — O alcance novo não vaza tarefa encerrada para o quadro

- **Dado** uma tarefa encerrada cujo checklist casa com o termo buscado
- **Quando** eu busco esse termo no QUADRO
- **Então** a tarefa encerrada não aparece (as condições novas ficam dentro do
  mesmo grupo aninhado da busca, sem escapar do recorte de status)

#### AC-350 — O campo de busca anuncia o alcance novo

- **Dado** a barra de busca das abas de Tarefas
- **Quando** a tela é aberta
- **Então** o texto de ajuda do campo diz que a busca é por qualquer texto ou
  número da tarefa, em vez de enumerar uma lista de campos que ficou incompleta

## Fora de escopo

- Índice full-text ou ranking de relevância — a busca continua sendo o mesmo
  `LIKE %termo%` dos campos atuais, só que alcançando mais campos.
- Buscar dentro do CONTEÚDO de arquivos anexados (só o nome do anexo entra).
- Destacar (highlight) onde o termo casou na linha do resultado.

## Suposições

<!-- O que estamos ASSUMINDO sem confirmação. Status: aberta | confirmada | invalidada -->

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-088 | O pedido "pesquisar por qualquer texto ou número no histórico" significa AMPLIAR o alcance da busca que já existe (título, resumo, detalhes, comentários, sistema, responsável e número do card já são cobertos) para os campos que faltam — checklist, motivos, relatórios de teste, nome de anexo e versão de produção — e não criar uma segunda busca. | aberta | — |
| ASM-089 | A ampliação vale para as DUAS abas (quadro e histórico): a busca é o mesmo formulário e o mesmo recorte, e quem não achou no quadro repete a mesma pergunta no histórico. | aberta | — |
| ASM-090 | `LIKE %termo%` sem índice segue bastando: o histórico é paginado, as tabelas satélite são pequenas por tarefa, e desempenho vira assunto só se doer em produção. | aberta | — |

## Perguntas em aberto

<!-- O que ainda não sabemos. Status: aberta | respondida -->

| ID | Pergunta | Status | Resposta |
|---|---|---|---|

Nenhuma.
