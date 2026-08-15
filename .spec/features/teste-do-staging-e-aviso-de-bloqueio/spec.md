# Spec: Teste do staging e aviso de bloqueio

> feature: teste-do-staging-e-aviso-de-bloqueio
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

No processo real do time, quem testa o staging é o testador (perfil Membro),
não o dev responsável — mas o quadro não dá voz a ele: o carimbo de validação
só viaja no movimento Em staging → Pronta p/ produção, que só o responsável ou
quem triaga pode fazer, e o relatório de teste nem grava quem testou. Além
disso, o bloqueio — a representação combinada para "o staging quebrou, o
problema não é da tarefa" — não avisa ninguém no sino, e não acontece sozinho
quando o portão do deploy reprova (a tarefa "pensa que está no staging e não
está", como o design/README.md §16.6 aponta). Esta feature dá registro a quem
testa, ouvido a quem conserta e reflexo automático ao portão. Nenhuma aresta
nova entra no fluxo: infra = bloquear (o card fica), defeito da funcionalidade
= devolver para Em andamento.

## Histórias

### US-084 — Registrar o teste do staging sem mover o card

Como quem testa no staging, quero registrar aprovado/reprovado com notas
direto na tarefa, para que meu teste vire a prova que o portão da produção lê
— sem depender de eu poder mover o card.

#### AC-303 — Qualquer pessoa do quadro registra o teste do staging

- **Dado** uma tarefa de desenvolvimento em Em staging, com responsável, e um
  usuário do quadro que não é o responsável nem faz triagem
- **Quando** ele registra o teste do staging como aprovado
- **Então** o relatório fica gravado na tarefa, e a tarefa continua em Em
  staging, com o mesmo responsável — registrar não move o card

#### AC-304 — O relatório diz quem testou

- **Dado** um teste do staging registrado por alguém
- **Quando** o relatório é gravado
- **Então** ele carrega quem testou, e o modal de histórico da tarefa mostra o
  nome dessa pessoa ao lado do veredito (relatórios antigos, sem autor,
  continuam aparecendo sem nome)

#### AC-305 — O teste aprovado libera a ida para Pronta p/ produção

- **Dado** uma tarefa em Em staging com teste aprovado registrado nesta
  passagem pelo staging
- **Quando** o responsável ou quem triaga move a tarefa para Pronta p/
  produção, sem carimbar a validação de novo no painel de mover
- **Então** o movimento passa — o portão lê o relatório registrado (e a tarefa
  devolvida que reentra no staging continua precisando de teste novo, como
  hoje)

#### AC-306 — Reprovar exige dizer o que falhou

- **Dado** uma tarefa em Em staging
- **Quando** alguém tenta registrar o teste como reprovado sem escrever notas
- **Então** o registro é recusado com a frase que explica — reprovação sem o
  quê mandaria o dev adivinhar, como no retorno de portão

#### AC-307 — Registrar o teste avisa o responsável

- **Dado** uma tarefa em Em staging com responsável
- **Quando** outra pessoa registra o teste (aprovado ou reprovado)
- **Então** o responsável recebe notificação no sino com o veredito; quem
  registra teste na própria tarefa não recebe aviso de si mesmo

#### AC-308 — Fora do staging não há o que registrar

- **Dado** uma tarefa de desenvolvimento fora de Em staging, ou uma tarefa
  operacional
- **Quando** alguém tenta registrar teste do staging nela
- **Então** o registro é recusado com a frase que explica — o teste do staging
  é sobre o trabalho da etapa Em staging

### US-085 — Ser avisado quando uma tarefa trava

Como responsável ou admin, quero ser avisado no sino quando uma tarefa é
bloqueada, para agir sem depender de estar olhando o quadro na hora certa.

#### AC-309 — Bloquear avisa o responsável e quem triaga

- **Dado** uma tarefa com responsável, e usuários com a capacidade de triagem
- **Quando** alguém bloqueia a tarefa com motivo
- **Então** o responsável e quem faz triagem recebem notificação com o título
  da tarefa e o motivo do bloqueio — menos quem bloqueou

#### AC-310 — Ninguém é avisado duas vezes nem se auto-avisa

- **Dado** um responsável que também faz triagem, ou um bloqueio feito pelo
  próprio responsável
- **Quando** o bloqueio acontece
- **Então** cada pessoa recebe no máximo um aviso, e quem bloqueou não recebe
  nenhum

### US-086 — O portão reprovado se declara no quadro sozinho

Como admin que mantém o deploy, quero que a reprovação do portão do staging
bloqueie sozinha as tarefas em Em staging — e que a aprovação seguinte
destrave o que o portão travou —, para o card não dizer "em staging" quando o
código nunca chegou lá.

#### AC-311 — O portão reprovado bloqueia quem está em Em staging

- **Dado** tarefas de desenvolvimento em Em staging, desbloqueadas
- **Quando** o comando do portão roda com o veredito de reprovação
- **Então** cada uma fica bloqueada com o motivo padrão do portão, e os avisos
  de bloqueio saem como no bloqueio manual

#### AC-312 — Bloqueio manual fica intacto

- **Dado** uma tarefa em Em staging já bloqueada por uma pessoa, com outro
  motivo
- **Quando** o comando do portão roda, com qualquer veredito
- **Então** o bloqueio original permanece como está — motivo e relógio não são
  tocados

#### AC-313 — O portão que passa destrava o que ele mesmo travou

- **Dado** tarefas bloqueadas com o motivo padrão do portão e tarefas
  bloqueadas à mão
- **Quando** o comando do portão roda com o veredito de aprovação
- **Então** só as do motivo padrão destravem; os bloqueios manuais permanecem

#### AC-314 — O deploy chama o comando nos dois vereditos

- **Dado** o script `deploy/deploy-staging-alfamatriz.sh`
- **Quando** o portão reprova, ou a publicação passa
- **Então** o script chama o comando do portão com o veredito correspondente,
  no quadro de produção, sem derrubar o deploy se a chamada falhar (o teste
  confere o contrato: o nome do comando aparece no script nos dois ramos)

## Fora de escopo

- Nenhuma aresta nova no `FLUXOS`: Em staging → Em revisão continua não
  existindo. A convenção do time é: problema de infra = bloquear (o card fica
  em Em staging); defeito da funcionalidade = devolver para Em andamento com
  motivo.
- O painel de mover Em staging → Pronta p/ produção continua com o carimbo
  próprio — registrar pelo card é um caminho a mais, não substituto.
- Aviso no destravar (manual ou automático): o sino avisa o bloqueio; o
  destravamento aparece no quadro.
- A cópia do script atualizado para o host do Proxmox é passo de deploy manual
  (os scripts do host não se instalam sozinhos).

## Suposições

<!-- O que estamos ASSUMINDO sem confirmação. Status: aberta | confirmada | invalidada -->

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-076 | O quadro que o time usa é o AlfaMatriz de PRODUÇÃO (LXC 115): o gancho do deploy chama o artisan lá, porque bloquear no banco do staging seria invisível para o time. | confirmada | Dono do produto confirmou em 15/08/2026. |
| ASM-077 | Na produção, o artisan vive em `/var/www/alfamatriz/atual` (symlink da versão azul/verde ativa), e o host alcança via `pct exec 115`. | confirmada | Conferido no servidor em 15/08/2026: `pct exec 115 -- readlink /var/www/alfamatriz/atual` → `versoes/azul`. |
| ASM-078 | O motivo padrão do portão é assinatura suficiente para o destravar automático: nenhuma rota edita motivo de bloqueio depois de criado. | confirmada | Conferido no código em 15/08/2026: `bloqueio_motivo` só é escrito por `FluxoTarefaService` (bloquear grava, destravar/mover apagam); nenhuma rota o edita. |

## Perguntas em aberto

<!-- O que ainda não sabemos. Status: aberta | respondida -->

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-032 | Quem recebe o aviso quando uma tarefa é bloqueada? | respondida | Responsável + quem triaga, menos quem bloqueou (dono, 15/08/2026). |
| Q-033 | Quem pode registrar o teste do staging? | respondida | Qualquer pessoa do quadro; o relatório grava quem testou (dono, 15/08/2026). |
| Q-034 | O gancho automático do deploy entra agora? | respondida | Sim: portão reprovado bloqueia todas as desbloqueadas em Em staging (dono, 15/08/2026). |
| Q-035 | Onde roda o comando do gancho? | respondida | Na produção (LXC 115), onde o quadro do time vive (dono, 15/08/2026). |
| Q-036 | O portão que passa destrava sozinho o que travou? | respondida | Sim, só as tarefas com o motivo padrão do portão; bloqueios manuais ficam (dono, 15/08/2026). |
