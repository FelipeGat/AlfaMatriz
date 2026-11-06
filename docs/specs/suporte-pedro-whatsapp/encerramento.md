# Pedro — fila de atendentes, encerramento e avaliação

**Feature**: `suporte-pedro-whatsapp` (etapa fila e avaliação) · **Created**: 2026-10-21

**Pedido do Felipe (21/10/2026)**, ao passar os clientes para o (27) 4042-4157:
- "Os atendimentos não podem ficar agarrados sempre com o Pedro. Quando ele não conseguir resolver algo ou o
  cliente informar que deseja falar com um atendente específico, ele tem que direcionar para uma fila ou
  comunicar o usuário."
- "Como funciona nos outros chats, o da Intelbras por exemplo, no final ter uma forma de avaliação e encerramento
  do atendimento."

## Requisitos

- **FR-F1, passar para uma pessoa.** Nova ação do Pedro, `transferir`, usada em dois casos: quando o cliente pede
  um atendente (ou alguém pelo nome) e quando o Pedro não consegue resolver. Comercial e financeiro continuam indo ao
  setor no Gestor.
  - **Nome que bate com alguém da equipe** de atendimento (conta ativa com `suporte` › editar): a conversa já vai
    para essa pessoa. Ela é avisada no sino, e o grupo do Telegram também.
  - **Sem nome, ou nome que não é da equipe:** a conversa vai para a fila (Suporte › Conversas › "Na fila"), e toda
    a equipe é avisada.
  - **O cliente ouve, na mesma mensagem,** com quem ficou ou que está na fila; fora do horário, ouve também o
    horário de atendimento.
- **FR-F2, o especialista também vai para a fila.** Quando passa do limite de tentativas (6 respostas ou 30 min),
  a conversa vai para a fila, e não mais para o quadro do Matriz (decisão de 21/10: "não registrar no Matriz").
- **FR-F3, a fila não fica parada.** No horário de atendimento, conversa que espera uma pessoa sem resposta é
  lembrada à equipe a cada 15 min (`suporte:pedro` → `Transferencia::lembrarFila`). Os lembretes param quando
  alguém responde ao cliente.
- **FR-F4, encerrar com avaliação.** Encerra quem atende, pelo botão "Encerrar e pedir avaliação", ou o Pedro, com
  a ação `encerrar`, quando o cliente diz que resolveu ou que não precisa de mais nada.
  - **Agradecer não é encerrar** (06/11/2026: "Obrigado! Ficamos no aguardo" recebeu o pedido de nota, com as
    catracas paradas). Com o chamado desta conversa em aberto, a despedida do Pedro sai sem o pedido de nota, mesmo que
    a IA devolva `encerrar` (`Atendimento::chamadoEmAberto`, conferido em `Pedro::concluir`). Vale o chamado da conversa,
    e não qualquer um do cliente. O botão de quem atende não tem essa trava: ali uma pessoa decidiu.
  - O cliente recebe o pedido de nota de 1 a 5 (Excelente, Bom, Regular, Ruim, Péssimo), com espaço para
    comentário.
  - A conversa fica "Aguardando avaliação" até a próxima mensagem dele. Um número de 1 a 5 é a nota, e o resto do
    texto é o comentário; o cliente recebe um agradecimento, e a nota não passa pela IA. Outra coisa é conversa nova,
    que volta ao Pedro sem nota.
  - O pedido de nota vale 24 h. Quem é da casa (acesso interno) não é avaliado.
  - **A escolha no menu do Gestor não é nota.** O Pedro não vê o menu; o "3" chegava a ele como um número solto e virava
    "Obrigado pela avaliação" (06/11/2026). Se o Gestor passou sozinho a conversa a `suporte`, a mensagem que é só o
    número fica registrada como `[escolheu Suporte Técnico no menu]` (`CanalWhatsApp::aplicarEstadoDoGestor`).
- **FR-F6, o menu não sai no meio do atendimento** (06/11/2026). Conversa com uma pessoa daqui passa a "atendida no
  Matriz" no Gestor (`CanalWhatsApp::marcarSuporte`), que aí não manda o menu de reserva. Avisa-se em dois momentos:
  - quando a conversa passa a uma pessoa (`Transferencia::transferir`);
  - quando chega mensagem do cliente com a conversa com uma pessoa aqui e com o Pedro lá: alguém respondeu pela tela, ou o
    Gestor abriu conversa nova depois de 6 h parada, enquanto a pessoa aqui vale por 24 h. É a mensagem do cliente que
    dispara o menu, então esse momento pega todos os caminhos a tempo.
- **FR-F5, a nota é vista.** Cada encerramento guarda quem atendeu, quem encerrou, a nota e o comentário
  (`suporte_avaliacoes`).
  - **Nota 1 ou 2:** avisa toda a equipe na hora, no sino e no Telegram.
  - **Na tela:** a média dos últimos 30 dias, a quantidade "Na fila" e a última avaliação de cada conversa.
