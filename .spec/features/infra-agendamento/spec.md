# Spec: Infra agendamento

> feature: infra-agendamento
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

O AlfaMatriz publicado tem tarefas agendadas e uma fila configurada, mas **nada disso roda**:
o único cron criado no provisionamento é o do backup, não existe `schedule:run`, não existe
worker de fila, e o e-mail sai para arquivo de log em vez de sair para a internet. Na prática,
o fechamento mensal nunca executou sozinho e qualquer aviso automático que o painel tentasse
mandar nunca chegaria a ninguém.

Esta feature conserta o alicerce: o que está agendado passa a acontecer, o que é enfileirado
passa a ser executado, e o e-mail passa a sair de verdade. É pré-requisito das próximas três
features (integração com os sistemas, propagação de cadastro e avisos de vencimento) — todas
dependem de agendamento e de fila para funcionar.

## Histórias

<!-- História de usuário: quem precisa, o que precisa e por quê. -->

### US-032 — O que está agendado realmente acontece no servidor

Como responsável pela operação da Alfa, quero que as rotinas agendadas e os trabalhos em fila
sejam executados sozinhos no servidor publicado, para que eu não descubra tarde demais que o
fechamento do mês, uma sincronização ou um aviso simplesmente nunca rodou.

#### AC-071 — O servidor passa a executar o agendamento sozinho, a cada minuto

- **Dado** um servidor provisionado pelo script de provisionamento
- **Quando** o provisionamento termina
- **Então** existe um agendamento de sistema que dispara as rotinas do painel a cada minuto,
  com o caminho de busca de programas declarado por extenso e o interpretador chamado pelo
  caminho completo (o cron roda com um caminho mínimo e falharia em silêncio)

#### AC-072 — O painel consegue ler sua configuração e escrever nos próprios diretórios

- **Dado** um servidor provisionado
- **Quando** o provisionamento termina
- **Então** o arquivo de configuração é legível pelo usuário do aplicativo sem ficar legível
  para o resto do sistema, e as pastas de trabalho e de cache pertencem a esse mesmo usuário
  (sem isso, as rotinas de fundo quebram na primeira limpeza de cache)

#### AC-073 — Os trabalhos enfileirados têm quem os execute

- **Dado** um servidor provisionado
- **Quando** o provisionamento termina
- **Então** existe um serviço permanente que consome a fila, habilitado para subir junto com o
  servidor, que se reergue sozinho ao cair e que se recicla de tempos em tempos

#### AC-074 — Publicar uma versão faz o executor da fila pegar o código novo

- **Dado** o roteiro de publicação de uma nova versão
- **Quando** a publicação chega ao fim
- **Então** o executor da fila é avisado para reiniciar, depois de os caches serem regravados e
  antes de o servidor web ser recarregado

### US-033 — O painel consegue mandar e-mail de verdade

Como responsável pela operação da Alfa, quero que o painel publicado envie e-mail por um
serviço real e que eu consiga conferir isso com um comando, para que os avisos automáticos
cheguem à caixa de entrada de quem precisa agir.

#### AC-075 — O ambiente publicado nasce configurado para enviar, não para engavetar

- **Dado** o modelo de ambiente usado na publicação
- **Quando** ele é lido
- **Então** o envio de e-mail está apontado para um serviço de envio real, com servidor, porta
  e remetente preenchidos — e não para o arquivo de log

#### AC-076 — Os segredos novos entram como espaço a preencher, nunca com valor

- **Dado** o modelo de ambiente usado na publicação
- **Quando** ele é lido
- **Então** a senha do e-mail e o token do provedor de hospedagem aparecem como campos a
  preencher, sem nenhum valor real versionado

#### AC-077 — Um comando mostra por onde o e-mail vai sair antes de tentar enviar

- **Dado** o painel instalado
- **Quando** o comando de teste de e-mail é executado
- **Então** ele informa por qual meio, servidor e remetente o envio será feito antes de tentar,
  e falha com a mensagem do erro se o envio não completar (assim um envio engavetado por
  engano aparece na hora, em vez de sumir)

## Fora de escopo

- Escolher e contratar o serviço de e-mail: o modelo de ambiente aponta para o Google
  Workspace, mas as credenciais são preenchidas por quem publica.
- Qualquer aviso automático de vencimento — isso é da feature `hospedagens-avisos`.
- Monitorar a fila (painel de trabalhos falhados, alertas): fica para quando houver volume.
- Rodar o worker em mais de um processo ou em mais de uma máquina.

## Suposições

<!-- O que estamos ASSUMINDO sem confirmação. Status: aberta | confirmada | invalidada -->

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-027 | O fechamento mensal nunca executou sozinho em produção, porque nunca houve agendamento de sistema chamando as rotinas do painel. Ligar o agendamento vai fazê-lo rodar pela primeira vez, sobre uma competência que ninguém conferiu. | aberta | Fecha na T-053: executar à mão e conferir o resultado antes de ligar o agendamento. |
| ASM-028 | A conta do Google Workspace permite senha de aplicativo e a saída pela porta 587 do container publicado não está bloqueada. | aberta | Fecha na T-058, com o comando de teste de e-mail rodando contra a conta real. |
| ASM-029 | Tornar o arquivo de configuração legível pelo grupo do usuário do aplicativo é aceitável neste servidor (hoje ele é exclusivo do administrador, o que impede as rotinas de fundo de lê-lo). | confirmada | Decisão do plano aprovado em 07/08/2026: `640 root:www-data` é estritamente melhor que o estado atual para esta necessidade e não abre leitura para mais ninguém. |

## Perguntas em aberto

<!-- O que ainda não sabemos. Status: aberta | respondida -->

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-011 | Qual endereço do Google Workspace envia os avisos, e quem administra as senhas de aplicativo dessa conta? | aberta | — |
