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
  a ação `encerrar`, quando o cliente diz que resolveu, agradece ou diz que não precisa de mais nada.
  - O cliente recebe o pedido de nota de 1 a 5 (Excelente, Bom, Regular, Ruim, Péssimo), com espaço para
    comentário.
  - A conversa fica "Aguardando avaliação" até a próxima mensagem dele. Um número de 1 a 5 é a nota, e o resto do
    texto é o comentário; o cliente recebe um agradecimento, e a nota não passa pela IA. Outra coisa é conversa nova,
    que volta ao Pedro sem nota.
  - O pedido de nota vale 24 h. Quem é da casa (acesso interno) não é avaliado.
- **FR-F5, a nota é vista.** Cada encerramento guarda quem atendeu, quem encerrou, a nota e o comentário
  (`suporte_avaliacoes`).
  - **Nota 1 ou 2:** avisa toda a equipe na hora, no sino e no Telegram.
  - **Na tela:** a média dos últimos 30 dias, a quantidade "Na fila" e a última avaliação de cada conversa.
