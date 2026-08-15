# Spec: Modal historico da tarefa

> feature: modal-historico-da-tarefa
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

A aba de histórico é o caminho de auditoria das tarefas encerradas (AC-097), mas
mostra só o desfecho: a linha do tempo inteira — por quais etapas a tarefa
passou, quando, por quanto tempo e por quê — já existe em `tarefa_eventos` e
nenhuma tela a exibe. Quem audita hoje lê o resultado sem o caminho. Esta
feature abre esse caminho: clicar numa tarefa do histórico abre um modal com o
histórico completo dela — linha do tempo, conversa, anexos, checklist e
relatórios de teste — e, daqui pra frente, cada movimento passa a registrar
quem o fez.

## Histórias

### US-082 — Abrir o histórico completo de uma tarefa encerrada

Como quem audita o histórico, quero clicar numa tarefa da aba de histórico e
ver num modal tudo o que aconteceu com ela, para entender o caminho até o
desfecho sem sair da listagem.

#### AC-293 — A linha da tabela abre o modal de histórico completo

- **Dado** a aba de histórico com tarefas encerradas
- **Quando** o usuário clica em qualquer ponto da linha de uma tarefa (fora
  dos controles que já agem, como o botão Reabrir)
- **Então** abre um modal com o histórico completo daquela tarefa, e a linha
  se anuncia clicável (cursor de ponteiro)

#### AC-294 — Os controles da linha continuam agindo sem abrir o modal

- **Dado** uma linha do histórico com o botão Reabrir visível
- **Quando** o usuário clica no Reabrir
- **Então** a tarefa é reaberta como hoje e o modal de histórico não abre

#### AC-295 — A linha do tempo mostra cada movimento da tarefa

- **Dado** uma tarefa encerrada que passou por mais de uma etapa
- **Quando** o modal de histórico é aberto
- **Então** a linha do tempo lista os movimentos em ordem cronológica, cada um
  com a etapa de destino, a data/hora de entrada, a duração na etapa e o
  motivo quando houver (bloqueio, cancelamento, retorno)

#### AC-296 — Etapas aposentadas aparecem com o nome, nunca a chave crua

- **Dado** uma tarefa antiga cujo histórico passou por etapa que saiu do fluxo
  (`bloqueada`, `em_testes`, `ajustes_necessarios`)
- **Quando** a linha do tempo é exibida
- **Então** a etapa aparece com o nome por extenso (via
  `Tarefa::ETAPAS_APOSENTADAS`), nunca a chave do banco

#### AC-297 — A conversa e os anexos vivem dentro do mesmo modal

- **Dado** uma tarefa encerrada com comentários e anexos
- **Quando** o modal de histórico é aberto
- **Então** a conversa e os anexos aparecem em modo somente leitura, e o modal
  separado de comentários que existia antes deixa de existir — um modal só por
  tarefa

#### AC-298 — O checklist aparece com o estado final dos itens

- **Dado** uma tarefa encerrada com itens de checklist
- **Quando** o modal de histórico é aberto
- **Então** os itens aparecem com o estado em que ficaram (feito / não feito),
  somente leitura

#### AC-299 — Os relatórios de teste aparecem com veredito e notas

- **Dado** uma tarefa encerrada que teve relatórios de teste
- **Quando** o modal de histórico é aberto
- **Então** cada relatório aparece com o veredito (aprovado / reprovado), as
  notas e a data

#### AC-300 — Seção sem conteúdo não aparece

- **Dado** uma tarefa encerrada sem comentários, sem anexos, sem checklist e
  sem relatórios de teste
- **Quando** o modal de histórico é aberto
- **Então** só a linha do tempo aparece (ela sempre existe); nenhuma seção
  vazia ocupa a tela

### US-083 — Saber quem moveu a tarefa daqui pra frente

Como quem audita o histórico, quero que cada movimento novo registre quem o
fez, para que a linha do tempo responda "quem" além de "o quê e quando".

#### AC-301 — Movimento novo grava quem o fez

- **Dado** um usuário autenticado movendo uma tarefa (criar, mover, concluir,
  cancelar, reabrir)
- **Quando** o movimento acontece
- **Então** o evento criado guarda o usuário que agiu (coluna nova
  `user_id` em `tarefa_eventos`, nula para o passado)

#### AC-302 — A linha do tempo mostra o autor quando ele existe

- **Dado** uma tarefa com eventos novos (com autor) e antigos (sem autor)
- **Quando** o modal de histórico é aberto
- **Então** os movimentos novos mostram o nome de quem moveu e os antigos
  aparecem sem autor, sem quebrar a exibição

## Fora de escopo

- O quadro (aba Tarefas): o card continua abrindo o modal de edição de hoje.
  O modal de histórico completo é só da aba de histórico.
- Preencher o autor dos eventos passados: o dado nunca foi gravado e não há de
  onde recuperá-lo.
- Editar qualquer coisa pelo modal: é leitura de auditoria. Para voltar a
  escrever, reabre-se a tarefa (AC-131).
- Congelar o nome do autor no evento (padrão de `auditorias`): o evento segue
  o padrão de `responsavel_id` — vínculo com `users`, exibição nula se a conta
  sumir (ver ASM-073).

## Suposições

<!-- O que estamos ASSUMINDO sem confirmação. Status: aberta | confirmada | invalidada -->

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-072 | O modal reaproveita os componentes e tokens que já existem (`x-modal`, `x-badge`, `corDaEtapa`, `duracaoCurta`); nenhum valor visual novo é inventado. O pacote de design não desenha esta tela — se um valor faltar, a regra do CLAUDE.md manda parar e perguntar. | aberta | — |
| ASM-073 | O nome do autor NÃO é congelado no evento (diferente de `auditorias.usuario_nome`): segue o padrão de `tarefas.responsavel_id` — FK com `nullOnDelete`, conta excluída aparece sem autor. O histórico de tarefa não é o rastro legal do sistema; o rastro legal já é a auditoria. | aberta | — |
| ASM-074 | Sem endpoint novo: o modal nasce renderizado com a página, um por linha, como os modais que já existem no histórico. O controller já carrega `eventos`, `comentarios`, `itens` e `anexos`; acrescenta-se `relatoriosTeste` e o autor dos eventos ao mesmo `with()`. | aberta | — |
| ASM-075 | O resumo "N comentários · N anexos" continua na linha como texto informativo, sem ser botão — a linha inteira já abre o modal completo. | aberta | — |

## Perguntas em aberto

<!-- O que ainda não sabemos. Status: aberta | respondida -->

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-029 | O que entra no "histórico completo"? | respondida | 2026-08-15, dono do produto: tudo — linha do tempo de etapas, conversa e anexos, checklist e relatórios de teste. |
| Q-030 | Qual é o alvo do clique na tabela? | respondida | 2026-08-15, dono do produto: a linha inteira é clicável, exceto os controles que já existem (Reabrir). |
| Q-031 | A linha do tempo mostra QUEM moveu, sendo que os eventos nunca gravaram o autor? | respondida | 2026-08-15, dono do produto: registrar daqui pra frente (coluna nova em `tarefa_eventos`); movimentos antigos aparecem sem autor. |
