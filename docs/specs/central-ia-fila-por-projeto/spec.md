# Feature Specification: Fila por projeto sem espera parada

**Feature**: `central-ia-fila-por-projeto`
**Created**: 2026-11-07
**Status**: Draft
**Pedido**: Felipe, 07/11/2026, com a captura de "Claude · Na fila — aguardando o agente · 3 min 33 s":

> "preciso disponibilizar mais de um agente ou algo assim pois aparentemente, a central de ia está aguardando a
> conclusão de uma tarefa para iniciar a outra. se um agente está ocupado, ele pode iniciar com outro fazendo os
> levantamentos ou disponibilizar outro agente da mesma ia. ou então eu puder pausar uma implementação para dar
> seguimento com outra que é mais urgente..."

## O problema

A máquina dele roda até 4 pedidos ao mesmo tempo (`CENTRAL_IA_PARALELO=4`), mas o servidor só entrega **uma
implementação por projeto** (`AgentesService::proxima`, regra do dono de 20/10/2026: dois agentes na mesma pasta
escreveriam um por cima do outro). Em 07/11 o #823 (chat Matriz, `alfamatriz`) ficou 24 min parado com três vagas
livres, esperando o #822 (chat Pedro(IA), também `alfamatriz`), e começou 1 s depois dele. A tela dizia "aguardando
o agente", que não é o motivo.

A trava continua certa: duas IAs escrevendo na mesma pasta é o que ela evita. O que muda é o que acontece durante a
espera, e quem decide a ordem.

## Escopo

1. **O motivo da espera na tela.** No lugar de "aguardando o agente", a linha do pedido diz o motivo real: o projeto
   ocupado (e por qual chat), o limite de pedidos ao mesmo tempo, ou o pedido anterior do mesmo chat.
2. **Levantamento enquanto espera.** Uma implementação parada só pela trava do projeto, com vaga livre na máquina,
   começa já em **só leitura**: a mesma IA lê o código, acha a causa e escreve o plano, e a resposta aparece no chat.
   Quando a pasta libera, a implementação **continua a mesma conversa do agente** (sem reler tudo) e confere o
   `git status` antes de editar, porque o outro agente mexeu na pasta. Se o levantamento terminar com uma pergunta,
   a implementação espera a resposta, em vez de seguir sem ela.
3. **Passar na frente.** Na linha do pedido que espera o projeto, o botão **Passar na frente** pausa a implementação
   que está ocupando a pasta (o agente para entre um passo e outro, em até 30 s, e a conversa dele fica salva), roda
   o pedido urgente e, quando ele termina, **retoma a pausada sozinha, de onde parou**. Se o urgente estava no meio do
   levantamento, o levantamento também para e a implementação começa já, continuando a conversa dele.

**Fora desta etapa**: duas implementações na mesma pasta ao mesmo tempo (o risco que a trava evita), worktree
separada por pedido (os testes e a 8096 servem a pasta do projeto), e prioridade entre projetos diferentes (eles já
rodam juntos).

## User Scenarios & Testing

### User Story 1 - Saber por que está esperando (Priority: P1)

1. **Given** o Pedro(IA) implementando em `alfamatriz`, **When** ele pede uma implementação no chat Matriz (mesmo
   projeto), **Then** a linha diz "Na fila — o projeto alfamatriz está ocupado com «Pedro(IA)»", e não "aguardando o
   agente".
2. **Given** a vaga da pessoa toda ocupada, **Then** a linha diz "Na fila — N pedidos rodando (limite N)".

### User Story 2 - Levantamento sem esperar (Priority: P1)

1. **Given** o projeto ocupado e vaga livre, **When** a máquina pede trabalho, **Then** ela recebe o levantamento do
   pedido em espera: mesmo agente, modo análise, acesso só leitura, e a nota "fase 1 de 2"; o pedido original
   continua na fila.
2. **Given** o levantamento concluído, **When** a pasta libera, **Then** a implementação vai com a sessão do
   levantamento para continuar, e a nota curta "a pasta liberou, confira o git status" no lugar do histórico inteiro.
3. **Given** o levantamento termina com dúvida, **Then** a implementação sai da fila com aviso no chat, e a resposta
   da pessoa vira o pedido seguinte.
4. **Given** o levantamento falha, **Then** nenhuma troca de IA nem escalada é disparada por ele: a implementação
   segue na fila e começa do zero.
5. Pedido que já teve levantamento, retomada de pausa e pedido sem projeto (Geral) não ganham levantamento.

### User Story 3 - Passar na frente (Priority: P1)

1. **Given** o pedido B esperando o projeto ocupado pelo A (máquina que sabe pausar), **When** ele clica "Passar na
   frente", **Then** B ganha prioridade, A recebe o pedido de pausa, e o chat de A avisa que ele vai pausar.
2. **When** a máquina manda o andamento de A, **Then** a resposta diz `pausar: true`; a máquina para o agente e
   conclui A como `pausada`, com a sessão e o estado do git.
3. **Then** nasce a retomada de A (pendente, mesma IA, mesma instrução, prioridade), B é entregue antes dela, e a
   retomada vai com a sessão de A para continuar.
4. Máquina antiga, que não sabe pausar, não oferece o botão. Pedido de outra conta dá 404; pedido que não espera
   projeto dá 422.

## Requisitos

- **FR-001**: a trava de uma implementação por projeto continua; nada passa a escrever ao mesmo tempo na mesma pasta.
- **FR-002**: o levantamento roda com `acesso` de leitura MESMO na máquina com análise total (`CENTRAL_IA_ANALISE=total`).
- **FR-003**: levantamento e retomada não disparam troca de provedor, escalada nem delegação.
- **FR-004**: a fila entrega por prioridade e depois por ordem de chegada.
- **FR-005**: só a própria conta vê e mexe nos próprios pedidos.
- **FR-006**: runner antigo com servidor novo e runner novo com servidor antigo continuam funcionando (o botão só
  aparece quando a máquina diz que sabe pausar).

## Success Criteria

- **SC-001**: com o projeto ocupado e vaga livre, o pedido seguinte começa a trabalhar (levantamento) na consulta
  seguinte da máquina, e não quando o outro termina.
- **SC-002**: "Passar na frente" põe o pedido urgente para rodar em até ~30 s mais a consulta seguinte da máquina.
