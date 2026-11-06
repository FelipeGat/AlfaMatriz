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
  - O usuário da pessoa no Gestor vem do cadastro de funcionário com o mesmo celular (`equipe.usuario` do retrato:
    um só funcionário com o número, com um só usuário ativo — bugfix de 02/11/2026, quando o Pedro pediu ao
    Felipe o e-mail que o Gestor já sabia). A 4ª coluna, opcional, é o e-mail desse usuário e vale quando
    preenchida (`pedro-orcamento`, 02/11/2026). Com o usuário, o diretor cria orçamento pelo Pedro, como
    vendedor, e diretor e gestor lançam visitas em nome próprio (`pedro-dia-do-tecnico`, FR-014). O número reconhece; o vínculo com o usuário do Gestor
    autoriza — o começo do FR-012 da `pedro-inteligencia-operacional`, por enquanto só para o orçamento. O e-mail
    não vai ao pedido à IA, e o Gestor confere na gravação que o usuário está ativo e cria orçamento pela tela.
  - O número do WhatsApp vem assinado pela Meta, e o repasse do Gestor também é assinado. O nome que a pessoa diz
    ser não conta.
  - Só quem administra o Pedro edita a lista: a conta dona (`services.suporte_pedro.conta`), sempre, e
    `services.suporte_pedro.administradores` (padrão: o Felipe e o Alexandre; Felipe, 06/11/2026). Quem só vê o Pedro
    não vê os números. Toda gravação entra na auditoria (recurso `suporte`) com quem mudou, o antes e o depois, e do
    número só o final: a lista dá acesso ao financeiro e liga número a usuário do Gestor.
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
     - **CNPJ ou CPF escrito acha o cliente** (Felipe, 03/11/2026: o Pedro não achou a POWER-FIT ACADEMIA, que
       estava no Gestor, ativa e da INVEST). Pelo nome, vão os 10 mais parecidos. Num pedido de orçamento, os
       itens ("mega", "tera", que soa como "Terra") puxavam outros cadastros e ela ficava de fora; o CNPJ que o
       Felipe mandou depois não ajudava, porque a busca era só pelo nome. Agora o cliente do CNPJ ou CPF citado
       nas últimas mensagens entra primeiro, fora do limite dos 10, marcado "achado pelo CNPJ". Só entre os
       clientes das empresas que o Pedro atende, como a busca pelo nome.
     - **O Pedro diz por que não achou**, em vez de "não apareceu na lista":
       - o CNPJ não é de nenhum cliente ou pré-cliente que ele atende ("não achei esse CNPJ no Gestor");
       - o CNPJ é de cliente de empresa que ele não atende (a ALFA), e ele diz qual;
       - o número tem a forma de CNPJ, mas o dígito verificador não confere;
       - a lista dos parecidos foi cortada: ele pede o CNPJ, e nunca diz que o cliente não existe no Gestor;
       - nenhum parecido com o nome: ele pede o CNPJ ou o nome como está no Gestor.
     - CPF só vira motivo quando foi escrito como CPF (com a pontuação, ou com a palavra "CPF"): um celular de 11
       dígitos às vezes passa no dígito verificador.
     - A recusa do registro e da prévia de orçamento pede "o CNPJ dele ou o nome como está cadastrado".
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
- **FR-A9, perguntas certas e prévia** (Felipe, 21/10/2026, depois do teste do Wallas):
  - **Nome parecido:** o Pedro oferece o cadastro parecido para a pessoa escolher ("Não encontrei Emanuelly, mas tem
    Emanuelle Ceolin Pianna, seria esse?"). Uma letra diferente basta (duas em nome de 8 ou mais letras).
  - **Empresa:** são três empresas no Gestor. Vale a do cliente, e o Pedro pergunta quando há dúvida. O assunto tem de
    ser da empresa escolhida.
  - **Técnico e agenda:** o técnico vem da equipe do retrato, pelo nº. O Pedro pergunta se o atendimento já vai ser
    agendado. Se sim, data e período (manhã, tarde, dia todo, noite); se não, a prévia diz "sem agendamento".
  - **Prévia antes de abrir**, sempre neste formato: Empresa, Cliente, Assunto, Serviço, Técnico, Agendamento e
    Atendimento (novo, vincular ou reabrir). Abre só depois do "sim".
  - **No Gestor:** a visita entra pelo `AgendaTecnicaService::adicionarVisitas`, com conflito de agenda e Google
    Agenda, no início do período e pela duração dele. Agenda recusada (técnico ocupado) não desfaz o atendimento: a
    pessoa recebe o número e o motivo. Sem agendar, o técnico fica como responsável do atendimento novo.
- **FR-A10, ninguém é da casa sem estar na lista** (Felipe, 21/10/2026, depois do teste do Alexandre de um celular
  pessoal: "ele não sabe que o Alexandre é o nosso, poderia ser qualquer um; isso tem que tomar muito cuidado").
  - **Vazamento encontrado:** o telefone do cadastro de funcionários do Gestor ligava o "modo equipe" de 20/10, que
    listava os atendimentos da pessoa e os de qualquer cliente que ela citasse.
  - **Agora:** o modo equipe saiu. Telefone de funcionário fora da lista é cliente como qualquer outro. Dado da casa,
    só pela lista de acesso interno.
  - **Cliente confirmado só pelo nome** ("sou do Castanheiras"), de um número que não está no cadastro dele, não vê
    atendimentos, etapas nem contratos (`Contexto::numeroDoCadastro`; desde 31/10/2026, o nível de
    `Identificacao`, em identificacao.md). O Pedro diz que essas informações só vão para
    os telefones cadastrados do cliente e oferece registrar o problema. O que nasceu na própria conversa pode ser
    informado. O login do portal já seguia essa regra.
- **FR-A11, a qualquer hora vale também no Gestor** (Felipe, 31/10/2026, sábado: mandou "Boa tarde Pedro" do
  número dele e recebeu o menu do Gestor).
  - **Causa:** o Gestor recebe o WhatsApp primeiro e decide sozinho entre o Pedro e o menu, pelo horário (dias
    úteis, 07:00–18:00), antes de o repasse chegar aqui. A isenção de horário de `Pedro::enfileirar` nunca rodava.
  - **Agora:** o Matriz manda ao Gestor só as chaves dos telefones da lista (DDD + 8 últimos dígitos, sem nome
    nem perfil), ao salvar a lista em Suporte › Pedro e a cada 15 minutos (`suporte:acessos-ao-gestor`). Lá elas
    servem só para entregar a conversa ao Pedro a qualquer hora (Gestor: `docs/specs/whatsapp-pedro`, FR-016). A
    lista continua sendo uma só, a daqui: a do Gestor é cópia e é substituída inteira a cada envio.
  - Se o Gestor não recebe, a tela avisa e a rotina tenta de novo; até lá, fora do horário, quem é da casa
    recebe o menu, como antes. O tempo limite do Gestor vale igual: sem resposta do Pedro em 5 min, sai o menu.
  - **Desde 31/10/2026 à noite o Pedro atende todo mundo a qualquer hora** (spec.md, Clarificações; Gestor
    FR-012). A lista no Gestor continua servindo para devolver ao Pedro, na mensagem seguinte, a conversa da casa
    que recebeu o menu de reserva sem ter ouvido o Pedro antes; o cliente só volta se o Pedro já respondeu.
- **Fora do escopo** (Felipe, 21/10/2026: "pode deixar de fora o AlfaHome"): o pessoal do AlfaHome.
