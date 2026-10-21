# Pedro — acesso interno por número

**Feature**: `suporte-pedro-whatsapp` (etapa acesso interno) · **Created**: 2026-10-21

**Pedido do Felipe (21/10/2026):** perguntou ao Pedro "resume para mim os atendimentos que estão em aberto no
Gestor" e recebeu só os do Castanheiras, porque o Pedro o tratou como funcionário. Ele quer que o Pedro saiba que
ele é o diretor do Grupo Soluções e traga tudo de que precisar, "igual o Matriz me manda no resumo diário do
Telegram". O Pedro "não pode se confundir: tem que ter certeza de que sou eu, pelo meu número".

- **Felipe:** Gestor (Grupo Soluções), Matriz (VixCard) e o pessoal dele.
- **Wallas** (+55 27 99745-8265): só o Gestor, mas completo (financeiro, comercial, suporte).
- **Alexandre** (+55 27 3109-3265): só o Gestor, e só suporte (atendimentos).

## Requisitos

- **FR-A1, identificação pelo número.** Quem tem acesso interno é decidido só pelo número que escreve, numa lista da
  tela Suporte › Pedro, com uma linha por pessoa: `telefone | nome | perfil`.
  - O número do WhatsApp vem assinado pela Meta, e o repasse do Gestor também é assinado. O nome que a pessoa diz
    ser não conta.
  - Só a conta dona do Pedro (`services.suporte_pedro.conta`) edita a lista; quem só vê o Pedro não vê os números.
  - Comparação pela chave do número (DDD + 8 últimos dígitos), a mesma do resto do Pedro.
- **FR-A2, perfis** (cada um inclui o de baixo):
  - `suporte`: atendimentos do Gestor, todos os abertos, com cliente, etapa, data e responsável; e qualquer
    atendimento citado pelo número, inclusive concluído.
  - `gestor`: o anterior mais o retrato do Gestor do Secretário: orçamentos, cobranças vencidas e de hoje, contas a
    pagar, reuniões com próximo passo e funil parado.
  - `diretor`: o anterior mais o resumo do dia do Secretário (agenda, acompanhar, e-mails importantes) e os
    orçamentos VixCard do Matriz em aberto.
- **FR-A3, só o que o perfil vê vai para a IA.** O dado que o perfil não vê não entra no pedido, em vez de entrar com
  uma regra pedindo para não falar dele. Pergunta fora do perfil recebe "isso não está no que posso consultar para
  você".
- **FR-A4, conversa interna não é atendimento.** Prompt próprio, sem roteiros de cliente, sem identificação de
  cliente e sem limite de 4 frases. A resposta sai com formatação de WhatsApp e pode chegar a 3.500 caracteres. Não
  encaminha a setor e não conta para o limite de tentativas que passa ao especialista. Abrir atendimento, só pelo
  FR-A7. Se o Pedro não entender o pedido ou não souber fazer, pede um áudio explicando (Felipe, 21/10/2026).
- **FR-A5, quem não está na lista é cliente.** Se alguém de outro número disser que é diretor, sócio ou funcionário e
  pedir dado interno, o Pedro não passa nada e explica que esse tipo de informação só sai para os números
  cadastrados.
- **FR-A6, dado velho é dito.** Cada bloco leva a hora em que foi lido; retrato do Gestor com mais de 3 h é avisado
  como desatualizado.
- **FR-A7, registrar atendimento a pedido de quem é da casa** (Felipe, 21/10/2026, depois do teste do Wallas: "abrir
  um atendimento para a Emanuelle para o Vitor amanhã"):
  1. O Pedro busca o cliente no Gestor. A busca é larga: basta uma palavra do nome. Sem cliente, ou com mais de um
     possível, ele pergunta qual é.
  2. Mostra os atendimentos daquele cliente, abertos e concluídos, com nº e etapa, e pergunta se deve criar um novo,
     vincular a um aberto ou reabrir um concluído.
  3. Confirma cliente, serviço, responsável e data, e só registra depois do "sim".
  - Só vale cliente que estava entre os citados. Vincular e reabrir só valem para atendimento daquele cliente.
  - **Como registra:** novo, com `SUPORTE_PEDRO_GESTOR_GRAVAR` ligado, vai ao Gestor pela máquina. Todo o resto vira
    tarefa no quadro, com "LANÇAR NO GESTOR" (o que fazer, cliente, assunto), responsável, data no prazo e quem
    pediu. A tarefa fica com o primeiro responsável do suporte.
  - A conversa de quem é da casa não fica presa a um atendimento: a pessoa registra quantos precisar.
- **FR-A8, tudo vai ao Gestor** (Felipe, 21/10/2026: "não registrar no Matriz; quando o pedido vier de cliente,
  Wallas ou Alexandre, sempre ir para o Gestor; conferir se não tem chamado em aberto ou anterior com o mesmo
  assunto para, em vez de abrir um novo, reabrir — garantia; e informar a quem abriu o número").
  - **Gravação:** ligada por padrão (`SUPORTE_PEDRO_GESTOR_GRAVAR`). Cliente do Gestor (equipamento ou sistema) e
    pedido de quem é da casa viram pedido de gravação. A máquina grava pela ponte com
    `deploy/central-ia/suporte_gestor_gravar.php`, com `--apply` e sem nova tentativa: a ponte pode cair depois de
    gravar.
  - **Antes de abrir** (`Atendimento::modoNoGestor`):
    - se a conversa citou o nº de um atendimento do cliente: aberto vira vincular, concluído vira reabrir;
    - se há um aberto do mesmo assunto: vincular;
    - se há um do mesmo assunto concluído dentro da garantia (`SUPORTE_PEDRO_GARANTIA_DIAS`, padrão 90 dias, contados
      da abertura): reabrir;
    - senão, novo.
    - O Gestor confere de novo ao gravar: o atendimento tem de ser daquele cliente e não pode estar cancelado.
  - **No Gestor:** vincular acrescenta um andamento. Reabrir passa o atendimento para `em_atendimento`, com andamento
    e histórico, como o botão Reabrir. O autor no Gestor é a conta do Felipe (o andamento exige usuário em produção);
    quem pediu vai no texto.
  - **O número volta a quem pediu:** "Seu atendimento é o nº X", "Registrei no atendimento nº X, que já estava
    aberto" ou "Reabri o atendimento nº X".
  - **Na máquina:** uma chamada da ponte por vez (`naPonte`), porque o script vai a um nome fixo no servidor.
  - **Se a gravação falhar:** fica a tarefa no quadro com o aviso, para ninguém ficar sem atendimento.
  - **Técnico e data** ("para o Vitor amanhã") vão só no texto. Agendar de verdade no Gestor avisa o técnico e
    lança no Google Agenda, e fica para outra decisão.
- **Fora do escopo** (Felipe, 21/10/2026: "pode deixar de fora o AlfaHome"): o pessoal do AlfaHome.
