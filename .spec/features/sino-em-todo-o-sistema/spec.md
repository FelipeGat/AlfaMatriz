# Spec: Sino em todo o sistema

> feature: sino-em-todo-o-sistema
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

O sino nasceu no quadro de tarefas e ficou lá: das seis emissões existentes,
todas saem do `FluxoTarefaService` (pergunta, resposta, retorno, apontamento,
teste do staging, bloqueio). O resto do sistema produz eventos com dono e não
avisa ninguém: a tarefa direcionada não chega ao responsável, a concluída não
volta a quem pediu, o faturamento fecha no stdout de um cron, o cliente da
revenda espera licença até alguém varrer a lista, a senha trocada não fala com
o dono da conta, e um sistema fora do ciclo de sincronização por um dia não
deixa marca no banco. O dono do produto pediu em 15/08/2026 a varredura
completa e a cobertura — com o quadro de tarefas em primeiro lugar, porque ele
passa a controlar o planejamento de trabalho da empresa.

A régua continua a do desenho original (design/README.md §17 e
`app/Models/Notificacao.php`): EVENTO com dono vira linha no sino; CONDIÇÃO
recalculável fica na fila de ação do Centro de Controle; quem age não é
avisado da própria ação (`avisar` cala para o autor); destinatário se escolhe
pela capacidade que abre a tela do evento, nunca por slug de perfil — com duas
exceções nomeadas: aviso PARA revenda escolhe pela revenda, e evento de
sistema (conta mexida, sincronização caída) fala com os administradores.

## Histórias

### US-090 — O quadro de tarefas avisa do começo ao fim

Como pessoa do time, quero ser avisada quando uma tarefa entra no meu quadro,
quando ela termina e quando ela deixa de existir, para planejar meu trabalho
sem precisar vigiar o quadro.

#### AC-327 — Tarefa direcionada avisa o novo responsável

- **Dado** uma tarefa criada já com responsável, ou uma existente cujo
  responsável é trocado na edição
- **Quando** o cadastro é salvo
- **Então** o novo responsável recebe no sino "«título» foi direcionada a
  você", com a etapa em que o card ficou e quem direcionou na meta — e quem
  direciona a si mesmo não recebe nada

#### AC-328 — Tarefa sem dono avisa quem faz triagem

- **Dado** uma tarefa criada sem responsável (a fila de Aberta)
- **Quando** o cadastro é salvo
- **Então** cada conta ativa com a capacidade de triagem recebe "«título»
  aguarda triagem" — menos quem criou; a condição "N aguardando triagem"
  continua na fila de ação, recalculada

#### AC-329 — Concluir e cancelar avisam o criador e o responsável

- **Dado** uma tarefa com criador e/ou responsável
- **Quando** ela chega a Concluída ou a Cancelada
- **Então** criador e responsável recebem o aviso (uma vez só quando são a
  mesma pessoa, e nunca quem moveu); a conclusão leva a versão de produção na
  meta quando existe, o cancelamento leva o motivo

#### AC-330 — Excluir avisa antes de sumir, e o aviso sobrevive

- **Dado** uma tarefa com criador e/ou responsável
- **Quando** quem triaga a exclui (forceDelete)
- **Então** criador e responsável (menos quem excluiu) recebem o aviso — e a
  linha do sino nasce SEM `tarefa_id`, porque presa à tarefa ela apagaria em
  cascata no mesmo instante

#### AC-331 — O carimbo do painel de mover avisa como o botão de testar

- **Dado** a confirmação de Em staging → Pronta p/ produção com o carimbo de
  validação
- **Quando** o relatório de teste é gravado por essa porta
- **Então** o responsável recebe o mesmo aviso de veredito que o botão de
  testar emite (AC-307) — o fato é um só, venha por onde vier

### US-091 — O fechamento do ciclo se anuncia

Como quem acompanha o financeiro, quero saber que o faturamento de uma
competência foi gerado — inclusive quando o cron do último dia do mês o gera
sem ninguém olhando — para conferir e cobrar sem vigiar a tela.

#### AC-332 — Faturamento gerado avisa quem vê faturamento

- **Dado** contas ativas da matriz com a capacidade `faturamento:ler`
- **Quando** `gerarParaCompetencia` cria ao menos uma cobrança — pelo botão da
  tela ou pelo fechamento automático
- **Então** cada uma recebe o aviso com competência, contagem de cobranças e
  total na meta, apontando para a tela da competência; quem apertou o botão
  não recebe, e a geração que não criou nada (competência já fechada) não
  avisa ninguém

### US-092 — O pedido de licença e a resposta andam pelo sino

Como admin da matriz, quero saber na hora que um cliente de revenda nasceu
aguardando licença; como conta da revenda, quero saber o que a Alfa decidiu
sobre a licença do meu cliente.

#### AC-333 — Cliente pendente avisa a matriz

- **Dado** uma revenda que cadastra cliente em sistema provisionável
- **Quando** o provisionamento devolve o cliente pendente de licença
- **Então** as contas da matriz com `clientes:editar` recebem "«cliente»
  aguarda liberação de licença", com sistema e revenda na meta

#### AC-334 — Toda operação de licença avisa a revenda dona do cliente

- **Dado** um cliente vinculado a uma revenda
- **Quando** a matriz libera, renova, bloqueia ou desbloqueia a licença dele
- **Então** as contas ativas daquela revenda recebem o aviso da decisão, com
  rota para a lista de clientes (a tela que o perfil de revenda alcança) —
  bloqueio em nível de atenção, os demais como marca

### US-093 — A conta avisa o dono e o sistema avisa os admins

Como dona de uma conta, quero saber quando alguém redefine a minha senha;
como administrador, quero saber quando uma conta muda de mãos, de status ou
de papel — e quando alguém tenta o que a regra recusa.

#### AC-335 — Senha redefinida fala com o dono da conta

- **Dado** uma conta cuja senha é redefinida por outra pessoa
- **Quando** a redefinição acontece
- **Então** o dono recebe "«quem» redefiniu a sua senha" com o alerta de
  avisar um administrador caso não tenha pedido

#### AC-336 — A tentativa recusada contra um admin acorda os admins

- **Dado** alguém com a permissão `usuarios` que não é administrador
- **Quando** tenta redefinir a senha de um administrador e a regra recusa
- **Então** todos os administradores ativos recebem o aviso crítico da
  tentativa — o alvo entre eles

#### AC-337 — Entrar ou sair do papel de administrador é anunciado

- **Dado** uma troca de perfis que muda a condição de administrador de alguém
- **Quando** os perfis são sincronizados
- **Então** os administradores ativos (menos quem mexeu) recebem "«alvo» virou
  administrador" ou "deixou de ser administrador"; troca entre perfis comuns
  fica só na auditoria

#### AC-338 — Desativar, reativar e excluir conta avisam os admins

- **Dado** uma conta desativada, reativada ou excluída por um administrador
- **Quando** a ação acontece
- **Então** os demais administradores ativos recebem o aviso com quem fez — e
  a conta recém-desativada já não está entre os destinatários

### US-094 — A conversão do funil é notícia

Como quem acompanha o comercial, quero saber quando um lead vira cliente sem
estar com o funil aberto.

#### AC-339 — Lead convertido avisa quem vê o painel comercial

- **Dado** contas da matriz com `dashboard_comercial:ler`
- **Quando** um lead é movido para cliente ativo
- **Então** cada uma recebe "«lead» virou cliente" com quem fechou na meta —
  menos o próprio vendedor que converteu

### US-095 — A sincronização que cai deixa marca e faz barulho uma vez

Como administrador, quero saber quando um sistema integrado sai do ciclo de
sincronização e quando ele volta — sem uma linha idêntica por hora enquanto
estiver fora.

#### AC-340 — A primeira falha depois de um sucesso é o evento

- **Dado** um sistema configurado que sincronizava
- **Quando** um ciclo falha (erro de leitura ou de conexão)
- **Então** o sistema ganha a marca `sincronizacao_caiu_em` + motivo, os
  admins ativos recebem o aviso crítico apontando para o cadastro do sistema —
  e os ciclos seguintes que continuam falhando NÃO geram aviso novo

#### AC-341 — O primeiro sucesso depois da queda fecha o incidente

- **Dado** um sistema com a marca de queda
- **Quando** um ciclo completa
- **Então** a marca é apagada e os admins recebem "voltou a sincronizar" com
  desde quando estava fora; sistema sem endereço ou chave nunca ganha marca —
  é o estado normal entre publicar a integração e configurá-la, não uma queda

## Fora de escopo

- **Comentário comum e anexo novo não notificam** — ASM-047 (confirmada pelo
  dono em 11/08/2026) continua valendo: "a conversa não notifica ninguém; quem
  acompanha a tarefa a abre". O canal que notifica é a pergunta/resposta, que
  já existe. Reverter isso é decisão do dono, não desta feature.
- **Destravar não notifica** — decisão registrada na feature
  teste-do-staging-e-aviso-de-bloqueio: o sino avisa o bloqueio; o
  destravamento aparece no quadro.
- Checklist marcado por terceiro, mudança de prioridade e reordenação: ruído
  em relação ao valor; o card já mostra.
- Criação de usuário (a senha nasce na tela de quem criou; o novo usuário
  ainda não tem sino para ler).
- Baixa de receita (quem baixa está olhando a tela; a revenda não tem a
  permissão da rota que o aviso apontaria).
- Entrega por e-mail/Slack: o §17 já diz que sai do mesmo evento e é backend
  futuro — o registro no sino é o ponto único de onde os canais lerão.
- Sistema que SOME do ciclo porque a configuração foi removida (deixa de ser
  integrável e o cron nem o visita): detectar ausência é outra mecânica.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-085 | O pedido do dono em 15/08/2026 ("cobrir todo o sistema; tarefas completas") reabre os itens de sino que a spec de tarefas listou como fora de escopo em 11/08 (ticket aberto / direcionada / cancelada) — eles eram adiamento, não recusa. | aberta | ASM-047 (comentário não notifica), que É recusa com motivo, fica de pé. |
| ASM-086 | Destinatário por capacidade (`idsDeQuemVe`) sempre exclui conta com escopo de revenda: os eventos que usam capacidade são da matriz, e um aviso apontando para tela 403 ensinaria a ignorar o sino. | aberta | Aviso para revenda (AC-334) escolhe por `revenda_id`, não por capacidade. |
| ASM-087 | Evento de segurança/infra (conta, sincronização) fala com o papel `admin`, não com uma capacidade: interessa a quem responde pelo painel, não a quem abre uma tela. | aberta | Molde `idsDeAdminsAtivos`, com `ativo = true` pelo mesmo motivo de `idsDeQuemTriaTarefas`. |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-042 | O aviso de comentário comum na tarefa deve voltar (revertendo ASM-047), agora que o quadro vira o planejamento da empresa? | aberta | Hoje o canal com aviso é perguntar/responder; comentário segue silencioso. |
| Q-043 | O fechamento mensal deveria avisar também as despesas fixas geradas, ou só o faturamento? | aberta | Implementado só o faturamento (a fonte que o §17 nomeia); despesas fixas ficam na auditoria e na tela. |
