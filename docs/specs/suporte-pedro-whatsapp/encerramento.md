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
  - **Sem nome, ou nome que não é da equipe:** a conversa fica com quem responde pelos chamados do Pedro
    (`services.suporte_pedro.responsavel_chamados`, o Alexandre), e só ele é avisado (Felipe, 07/11/2026: "se o Pedro
    informar que irá passar para outro atendente da equipe, não pode morrer ali, ele tem que passar para o Alexandre
    até ele perceber que ele deu seguimento"). Com a conta dele desativada ou sem `suporte` › editar, a conversa vai
    para a fila geral e toda a equipe é avisada, como era até então.
  - **O cliente ouve, na mesma mensagem,** com quem ficou ou que está na fila; fora do horário, ouve também o
    horário de atendimento.
- **FR-F2, o especialista também vai para a fila.** Quando passa do limite de tentativas (6 respostas ou 30 min),
  a conversa vai para a fila, e não mais para o quadro do Matriz (decisão de 21/10: "não registrar no Matriz").
- **FR-F3, o cliente sem resposta não fica parado** (revisto em 07/11/2026). No horário de atendimento, quem está com
  a conversa (o responsável, se ninguém está) é lembrado a cada 15 min na primeira hora de espera e depois de hora em
  hora (`suporte:pedro` → `Transferencia::lembrarFila`, a cada minuto).
  - **A espera sai das mensagens**, e não do prazo de 24 h da conversa com a pessoa: até 07/11, a cobrança parava de vez
    na primeira resposta e acabava 24 h depois da passagem, sem avisar ninguém.
  - **Para quando** alguém responde ao cliente (pela tela ou pelo celular; resposta recusada pelo Gestor ou pela Meta não
    conta), encerra ou devolve ao Pedro.
  - **Volta quando** o cliente escreve de novo depois de uma resposta, ou numa conversa assumida sem resposta. A espera
    começa na primeira mensagem dele, e o motivo passa a ser "escreveu de novo: …". Quem é da casa (acesso interno) não
    volta à cobrança sozinho: ele consulta o Pedro, não é atendimento.
  - **Cada lembrete diz até que horas o WhatsApp aceita resposta** (24 h depois da última mensagem do cliente). Acabado
    esse prazo, sai um último aviso, "agora só por ligação", e a conversa sai da cobrança. Se o prazo acaba fora do
    horário, o último aviso sai na abertura. Prazo que acabou há mais de 72 h (conversa de antes desta regra) só sai da
    cobrança, sem aviso.
  - **Uma rodada por pessoa:** quando vence o lembrete de uma conversa, as outras que esperam a mesma pessoa vão junto.
    No sino, um aviso por conversa, que substitui o anterior; no Telegram, uma mensagem só, "Para <nome>".
  - **"Na fila"** (filtro e indicador de Suporte › Conversas) passa a ser o cliente que espera uma pessoa, esteja a
    conversa com alguém ou não, e mesmo vencido o prazo da pessoa.
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
- **FR-F7, o Pedro cobra o responsável pelo WhatsApp** (Felipe, 07/11/2026, canal escolhido: WhatsApp, sino e
  Telegram). O lembrete das conversas que estão com o responsável sai também no WhatsApp dele, numa mensagem por rodada
  com todos os clientes que esperam.
  - **Pela conversa dele com o Pedro**, achada pelo e-mail do Gestor na linha dele da lista da casa (4ª coluna, ou o
    cadastro de funcionário com o número; `AcessoInterno::conversaDe`). A conta da Meta não inicia conversa, e o Gestor
    só entrega com a conversa aberta, com o Pedro e dentro das 24 h da última mensagem dele: um "bom dia" ao Pedro no
    começo do expediente abre o dia. Sem isso, o lembrete fica no sino e no Telegram.
  - **Sai como do Pedro** (`humano` falso), para não contar como primeira resposta da equipe no Gestor.
  - **Não sai enquanto o Pedro ainda vai responder a ele**, para as duas mensagens não se cruzarem; vai na rodada seguinte.
  - **Recusado pelo Gestor ou pela Meta**, fica na conversa dele marcado como falha, e a cobrança continua.
  - **Ele responde ao cliente pelo Matriz**, pelo link do aviso. Responder ao aviso no próprio WhatsApp é falar com o
    Pedro, e nada chega ao cliente.
  - **Quem foi chamado pelo nome** (outra pessoa da equipe) é cobrado no sino e no Telegram, e não no WhatsApp.
  - **Textos aprovados pelo Felipe em 07/11/2026, junto do commit** (`Transferencia::ESPERANDO` e
    `PRAZO_ACABOU`; os mesmos vão ao Telegram):
    > Cliente esperando resposta: {nome} — {motivo}. Esperando há {tempo}. O WhatsApp aceita resposta até as {HH:MM}.
    > Responda pelo Matriz: {link}

    > O prazo do WhatsApp para responder {nome} acabou às {HH:MM}: agora só por ligação. {link}

    O {HH:MM} leva o dia ("10:00 de 10/11") quando não é hoje, o que é quase sempre: o prazo é 24 h depois da última
    mensagem do cliente. O {tempo} é "15 min" ou "2 h 10 min". No sino, o último aviso aparece como "Prazo do WhatsApp
    acabou: {nome} — só por ligação".
- **FR-F5, a nota é vista.** Cada encerramento guarda quem atendeu, quem encerrou, a nota e o comentário
  (`suporte_avaliacoes`).
  - **Nota 1 ou 2:** avisa toda a equipe na hora, no sino e no Telegram.
  - **Na tela:** a média dos últimos 30 dias, a quantidade "Na fila" e a última avaliação de cada conversa.
