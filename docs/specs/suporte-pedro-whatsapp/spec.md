# Feature Specification: Pedro, o atendente virtual do suporte no WhatsApp

**Feature**: `suporte-pedro-whatsapp`
**Created**: 2026-10-20
**Status**: Draft (aguardando a decisão do caminho de coexistência; ver "Decisão pendente")
**Pedido**: Felipe, 20/10/2026: "integrar o número do suporte (27 3109-3265) no sistema, sem risco de ban. Criar um
agente virtual chamado Pedro que irá atender, entender e demandar o atendimento por IA direto no Matriz. Ele precisa
entender do que atuamos, quem são os clientes e o que eles têm conosco, para fazer atendimentos rápidos, dar o
primeiro atendimento e, se não conseguir, já abrir um atendimento."

## Decisões do Felipe (20/10/2026)

1. **Número:** o (27) 3109-3265 continua no app WhatsApp Business do celular. O Pedro atende pela API oficial no
   mesmo número, em coexistência.
2. **Sem risco de ban:** só a WhatsApp Cloud API oficial da Meta. Biblioteca não oficial (WhatsApp Web, Baileys
   etc.) está fora.
3. **Clientes:** os da Alfa (Gym, Control, Jornada e Gestor) e os da VixCard (NexBeer, Vix-ID, cartões, VixLink).
   Os da VixCard ainda precisam ser cadastrados no Matriz.
4. **Sem solução:** o Pedro abre uma tarefa de Suporte no quadro, no sistema do cliente, com o resumo da conversa e
   o contato, e avisa no sino e no Telegram.

## Recomendação técnica aceita para o plano

- **Onde o Pedro roda:** no servidor da Matriz, numa conta Linux própria, "outra pessoa com HOME e credenciais
  próprios" (constitution II).
- **Como pensa:** chama a API de IA direto, sem o Claude Code a cada mensagem. A resposta sai em segundos e usa
  pouca memória.
- **A chave de IA** fica só no ambiente dessa conta, nunca no banco nem no Git.
- **Não usar a máquina do WSL:** em 19/10/2026 ela ficou sem memória e travou o runner. Ela depende do Windows ligado
  e disputa vaga com os pedidos do Felipe.

## Fatos levantados (fontes)

**Dados do Matriz em produção (20/10/2026, só leitura):**
- 25 clientes ativos, 15 deles com telefone (`cliente_telefones`, 24 números ao todo);
- 12 contratos (`cliente_contratos`: sistema, plano, valor, status e vigência);
- 26 vínculos ativos de cliente com sistema (`cliente_sistema`).

**Coexistência** (documentação da Meta, "Onboarding WhatsApp Business app users"):
- **Quem pode fazer:** só Tech Provider ou Solution Partner, pelo Embedded Signup (v4; a v2 e a v3 saem em
  15/10/2026). Desenvolvedor direto, como o Gestor hoje, não pode.
- **Requisitos:** app WhatsApp Business 2.24.17 ou mais novo.
- **Sincronização:** contatos e histórico de 180 dias, uma vez só, nas 24 h seguintes à conexão. Os grupos não
  sincronizam.
- **Mensagens enviadas pelo app** chegam pelo webhook `smb_message_echoes` e precisam aparecer na conversa do Matriz.
- **Limitações:** não dá para criar listas de transmissão novas; mensagens temporárias e de visualização única ficam
  desligadas; o limite fixo é de 20 mensagens por segundo.
- **Preço:** a mensagem enviada pelo app continua grátis; a enviada pela API segue a tabela da Cloud API.

**WhatsApp Cloud API no Gestor:** `app/Services/WhatsApp/WhatsAppCloudService.php` (janela de 24 h, posse da
conversa, SLA). Serve de referência de código; não é infraestrutura compartilhada.

## Clarificações (Felipe, 20/10/2026)

- **Identidade:** ele se apresenta como "Pedro, do suporte", sem dizer que é assistente virtual. (O plano confere a
  política da Meta para IA no WhatsApp Business antes de ligar.)
- **Horário:** das 07:00 às 18:00. Fora dele, o Pedro **não responde**; a mensagem fica para o dia seguinte, e às
  07:00 ele atende a fila da noite. (A confirmar: sábado, domingo e feriado contam como "fora do horário"?)
  **Mudou em 31/10/2026 à noite** (Felipe, depois de escrever num sábado de um número fora da lista da casa e
  receber o menu do Gestor: "não quero saídas pelo gestor, todas aqui pelo Pedro"): o Pedro atende a qualquer
  hora, cliente ou da casa, e na primeira mensagem se apresenta e pergunta em que pode ajudar. O horário
  (`Horario::aberto`) passa a ser só o da equipe: o retorno de uma pessoa, os lembretes da fila, a cobrança dos
  técnicos e o plantão para urgência. O menu do Gestor fica de reserva, para o Pedro sem resposta em 5 min
  (Gestor: `docs/specs/whatsapp-pedro`, FR-012).
  **Refinado na mesma noite** (Felipe, 31/10/2026, 18:23: "24 h para clientes novos perguntando em que pode
  ajudar e direcionando corretamente, e para quem já é cliente somente em horário comercial, das 08:00 às 18:00;
  após esse horário, informar que estamos fora do horário e, se for plantão, direcionar para o número do
  plantão"; aprovadas as três recomendações em 31/10/2026):
  - **Horário único das 08:00 às 18:00, de segunda a sexta**, para o cliente e para a equipe
    (`SUPORTE_PEDRO_INICIO`/`_FIM`). Feriado ainda conta como dia útil.
  - **Cliente novo** (número fora de qualquer cadastro, ou pré-cliente do funil): o Pedro atende a qualquer hora,
    tira as dúvidas com o showroom, sem preço, e passa ao comercial avisando que o retorno é no próximo horário.
  - **Cliente** (telefone no cadastro do Matriz ou do Gestor, contato do cliente gravado pelo Pedro, ou conversa
    já ligada a um cliente): fora do horário recebe um **aviso fixo, sem IA e sem dado nenhum**: o horário, que a
    mensagem ficou registrada e o plantão para urgência. O código decide (`Identificacao::ehCliente`), não o
    pedido à IA. A cada nova sequência de mensagens sai um aviso curto (no máximo um a cada 2 min), porque o
    Gestor manda o menu a quem fica 5 min sem resposta.
  - **Número desconhecido que diz ser de um cliente**, fora do horário: assim que o cliente é confirmado na
    conversa, vale a regra do cliente — o aviso, e nada se abre.
  - **Às 08:00 o Pedro retoma sozinho** o que o cliente mandou à noite, se ainda estiver na janela de 24 h do
    WhatsApp; a mensagem de sexta à noite já não pode ser respondida na segunda e fica para a equipe.
  - **Os avisos automáticos dizem a verdade a cada público:** quem é da casa, com a máquina parada, ouve que o
    Pedro está sem acesso às consultas; o cliente só ouve "fora do horário" fora do horário; e a falha da IA de
    madrugada não promete um colega "em instantes".
- **Quem recebe os atendimentos abertos:** sempre o Alexandre (id 3) e o Rossini (id 2), os dois avisados.
- **Ações da primeira entrega:**
  - responder;
  - abrir atendimento;
  - **consultar a situação do sistema** do cliente (no ar ou não, pelo Monitor, e manutenção programada);
  - **status do atendimento** ("e o meu chamado?"), pela etapa da tarefa.
  - Financeiro fica fora.

## O que o Pedro faz (escopo da primeira entrega)

1. **Recebe a mensagem** e reconhece o cliente pelo telefone (`cliente_telefones`). Número desconhecido: pergunta o
   nome da empresa ou o CNPJ e confere no cadastro.
2. **Contexto da resposta:**
   - o que o cliente tem conosco (`cliente_sistema` e `cliente_contratos`);
   - o que cada sistema faz e o que não faz (`docs/specs/orcamentos-vixcard/modelos-por-sistema.md`, a mesma base
     dos orçamentos);
   - os atendimentos anteriores dele.
3. **Primeiro atendimento:** dúvidas de uso, como fazer, situação do sistema. Responde **sem inventar**
   (constitution VIII): o que não está na base, ele não afirma.
4. **Sem solução, pedido de humano ou assunto sensível** (financeiro, cancelamento, reclamação): abre a tarefa de
   Suporte no quadro, avisa no sino e no Telegram e diz ao cliente o número do atendimento.
5. **O humano assume** pelo celular (app) ou pelo Matriz. Quando um humano responde, o Pedro para de falar naquela
   conversa.

## Recomendação para a coexistência (20/10/2026; o Felipe respondeu "siga")

**Caminho A, a Alfa como Tech Provider, com o Pedro construído em paralelo sobre o número de teste da Meta.**

- O B (parceiro, por exemplo a 360dialog, na faixa de €49 a €99 por mês por número segundo comparadores; preço a
  confirmar com o fornecedor) fica de reserva, caso a Meta recuse ou demore.
- **Etapas do A, segundo a documentação de parceiros:**
  - verificação da empresa: de 2 a 5 dias úteis, ou pronta se a conta já estiver verificada;
  - revisão do app: com vídeos de uma mensagem enviada e de um modelo de mensagem criado;
  - verificação de acesso.
  - Prazo oficial: não encontrado.
- **Pendências do Felipe, no painel da Meta:**
  - qual empresa é dona do (27) 3109-3265;
  - se essa empresa está verificada no Business Manager;
  - se o app da Meta usado pelo Gestor é dessa mesma empresa.

## Opções que estavam em aberto

Como conectar o número em coexistência, já que a Meta exige Tech Provider ou Solution Partner:

- **A. Tornar a Alfa Tech Provider da Meta.** Sem mensalidade de terceiro, mas exige verificação da empresa, revisão
  do app pela Meta e o Embedded Signup v4. O prazo depende da Meta (a confirmar).
- **B. Usar um Solution Partner que já tem coexistência** (exemplo encontrado: 360dialog). É o mais rápido, mas tem
  mensalidade do parceiro, e o Matriz fala com a API dele (a confirmar preço e contrato).
- **C. Tirar o número do app** e deixar só a Cloud API direta, sem coexistência. O humano atende só pelo Matriz.
  Contraria a decisão 1.
