# Spec: Quem revisa e quem testa

> feature: quem-revisa-e-quem-testa
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

O processo do time tem três atores — dev, revisor, testador — e os papéis
rodam por tarefa: às vezes o Felipe revisa, às vezes o Alexandre testa, às
vezes outro. O quadro só conhecia dois lados (responsável × interlocutor), e o
interlocutor ficava preso: apontado o revisor na revisão, a pergunta do
staging continuava indo para ele, e não havia como apontar o testador. Esta
feature põe a designação no gesto que já existe — o painel do movimento: quem
move para Em revisão ou Em staging pode apontar, opcionalmente, quem fica com
a bola; o apontado vira o interlocutor, é avisado no sino, e cada portão
recomeça a conversa. Sem apontamento, vale o que é hoje: a coluna como fila.

## Histórias

### US-087 — Apontar quem fica com a bola ao mover para um portão

Como quem move a tarefa para a revisão ou para o staging, quero apontar
opcionalmente quem revisa ou quem testa, para a pessoa certa ficar sabendo na
hora — sem depender de cargo fixo, porque o papel muda de tarefa para tarefa.

#### AC-315 — O painel do movimento oferece a escolha nas entradas dos portões

- **Dado** uma tarefa de desenvolvimento sendo movida — pelo menu Mover, pelo
  arrasto ou pelo teclado
- **Quando** o destino é Em revisão ou Em staging
- **Então** o painel de confirmação abre com um seletor opcional de pessoa
  ("Quem revisa?" / "Quem testa?") — e as demais transições seguem como eram

#### AC-316 — O apontado vira o outro lado e fica sabendo

- **Dado** uma tarefa de desenvolvimento com responsável
- **Quando** ela é movida para Em revisão ou Em staging com alguém apontado
- **Então** o apontado vira o interlocutor da tarefa, recebe aviso no sino
  dizendo que a tarefa entrou no portão e está com ele, e o card mostra a
  bola ("Revisão com Fulano" / "Teste com Fulano") — apontar sem rastro no
  card se lê como apontamento que não gravou

#### AC-317 — A pergunta seguinte já aponta para a pessoa certa

- **Dado** uma tarefa do próprio responsável, em um portão, com alguém apontado
- **Quando** o responsável pergunta na tarefa
- **Então** a pergunta vai para o apontado, sem oferecer escolha — o quadro já
  sabe o outro lado

#### AC-318 — Entrar num portão sem apontar recomeça a conversa

- **Dado** uma tarefa cujo interlocutor era o revisor da etapa anterior
- **Quando** ela entra em Em staging (ou Em revisão) sem ninguém apontado
- **Então** o interlocutor é esquecido e a contagem de rodadas zera — a
  pergunta seguinte do responsável volta a oferecer a escolha do destinatário,
  em vez de apontar para o revisor de ontem

#### AC-319 — Apontar o próprio responsável vale — é o "dev valida" de sempre

- **Dado** uma tarefa de desenvolvimento com responsável
- **Quando** outra pessoa a move para um portão apontando o próprio
  responsável
- **Então** o apontamento vale como qualquer outro: ele vira o interlocutor,
  o sino avisa que a bola está com ele, e a tarja nomeia a espera — a
  primeira versão recusava essa escolha, e o uso real a desmentiu no mesmo
  dia (Q-039)

#### AC-320 — A tarja do teste diz de quem se espera o teste

- **Dado** uma tarefa em Em staging com testador apontado e sem teste desta
  passagem
- **Quando** o painel da tarefa é aberto
- **Então** a tarja do teste diz "aguardando o teste de Fulano"; sem
  apontado, mantém o texto genérico de hoje

## Fora de escopo
- **Nenhum campo novo de "revisor"/"testador"**: a bola usa o interlocutor,
  que já existe e já é "o outro lado" da conversa.
- **O chip "N p/ você" continua contando só perguntas abertas** — o
  apontamento avisa pelo sino; o chip é sobre dever uma resposta.
- Tarefa operacional: não entra em portões, nada muda para ela.

## Suposições

<!-- O que estamos ASSUMINDO sem confirmação. Status: aberta | confirmada | invalidada -->

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-079 | "Cada portão recomeça" inclui zerar a contagem de rodadas, não só o interlocutor: o alerta de 3ª rodada é sobre a conversa atual, e rodadas da revisão contando no staging fariam o alerta disparar sobre um impasse que não existe mais. | confirmada | Dono confirmou em 15/08/2026. |
| ASM-080 | O seletor lista as mesmas pessoas do seletor de responsável do formulário da tarefa — uma lista só; duas listas de "quem pode" divergiriam. | confirmada | Padrão do repositório (a lista de pessoas do quadro já é uma só); conferido em 15/08/2026. |

## Perguntas em aberto

<!-- O que ainda não sabemos. Status: aberta | respondida -->

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-037 | Entrar num portão sem apontar ninguém apaga a lembrança do interlocutor anterior? | respondida | Sim — cada portão recomeça; dentro do portão a memória segue como hoje (dono, 15/08/2026). |
| Q-038 | Onde a escolha aparece? | respondida | Em todo movimento para os portões de exame — menu, arrasto e teclado abrem o mesmo painel. Revisada pelo dono em 15/08/2026, no primeiro dia de uso: a resposta original ("só no menu; arrasto direto") fez o arrasto passar reto, e arrasto que passa reto se lê como feature que não existe. |
| Q-039 | O responsável pode ser o apontado? | respondida | Sim (dono, 15/08/2026, no primeiro dia de uso). A recusa original assumia que apontar só existia para criar um "outro lado" de conversa — mas apontar o dev para validar o próprio staging é o modo "dev valida" da spec do redesign, e o aviso "está com você" é informação, não eco. |
