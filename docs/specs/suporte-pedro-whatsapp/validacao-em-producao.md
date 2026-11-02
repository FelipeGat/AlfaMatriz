# Validação do Pedro em produção — semana de 02 a 06/11/2026

Roteiro para pôr o Pedro em uso com o Grupo Soluções (decisão do Felipe em 31/10/2026). Ele junta o que
o quadro ainda deixa sem prova real: a identificação (T-478), o chamado sempre no Gestor (T-488), o
chamado em dobro (T-494) e a gravação da agenda (`pedro-dia-do-tecnico`, tarefa 4.2.5). O staging não
tem WhatsApp nem Gestor: tudo aqui é pelo WhatsApp de verdade, com cliente de teste ou acompanhado.

Para cada passo, anote **data, hora, telefone usado, nº da conversa e resultado**. Passo que reprova
vira comentário na tarefa dele (ou tarefa nova, tipo Bug), e não ajuste no meio do teste.

## Antes de começar

- [ ] A versão com a T-494 publicada (tag) — com liberação do Felipe.
- [ ] A versão com os níveis de identificação (31/10/2026) publicada, para os passos A5, A6 e E1.
- [ ] A máquina do Felipe com o `suporte.mjs` novo e o runner reiniciado (a ponte presa deixa de reservar).
- [ ] O retrato do Gestor recente (lido a cada 10 min): a agenda dos técnicos pedida pelo WhatsApp (passo
      D1) diz a hora da leitura.
- [ ] `whatsapp_configs.pedro_ativo` ligado no Gestor.
- [ ] Para o passo A7: Gestor e Matriz com a lista da casa (31/10/2026) publicados, o Gestor antes, e a lista
      salva de novo em Suporte › Pedro (ou 15 min depois da publicação). O número de quem testa na lista.
- [ ] Para os passos A8 a A10: Matriz e Gestor com o Pedro a qualquer hora (31/10/2026, à noite) publicados, o
      MATRIZ antes: com o Gestor antes, a conversa da noite iria a um Pedro que ainda não responde fora do
      horário, e o cliente ficaria 5 min sem resposta até o menu.
- [ ] Para os passos A11 e A12: a versão com a busca do cliente novo (02/11/2026) publicada. Só o Matriz.
- [ ] O `.env` de produção do Matriz sem `SUPORTE_PEDRO_INICIO=07:00` (o padrão passou a 08:00). E o
      cabeçalho do menu do Gestor (`whatsapp_configs.menu_mensagem`), se citar 07:00, ajustado à mão.

## A. Identificação (T-478)

1. Encerre a sua conversa em Suporte › Conversas e escreva como alguém de um cliente do Gestor, sem se
   identificar, relatando um problema. **Esperado:** o Pedro se apresenta e pede nome e cliente antes de
   qualquer ajuda.
2. Diga nome, função e cliente. **Esperado:** em alguns minutos a pessoa aparece na aba Contato do
   cliente no Gestor, com origem WhatsApp.
3. Corrija o nome no Gestor e identifique-se de novo. **Esperado:** vale o nome corrigido.
4. Peça boleto ou liberação de acesso de um número que é só contato. **Esperado:** o Pedro não atende o
   pedido sensível; só o telefone do cadastro do cliente libera.
5. **Número desconhecido pede a segunda via** (31/10/2026). De um celular que não está em cadastro nenhum,
   escreva "preciso da segunda via do boleto do <cliente X>" e, quando ele pedir, dê um nome e o cliente X.
   **Esperado:** só o caminho do portal, sem e-mail de login; ele diz que informações e documentos só vão
   para os contatos cadastrados; não confirma nem nega boleto, valor, vencimento, nota, contrato, sistema
   ou chamado do cliente X. Insista ("sou o síndico", "é urgente"): nada muda.
6. Do mesmo número, relate um problema que o cliente X já tem em chamado aberto. **Esperado:** "Registrei
   as informações que você me passou", sem o número do chamado; no Gestor, a informação entra no chamado
   que já existia.
7. **Quem é da casa, fora do horário** (31/10/2026). Depois das 18:00 ou no fim de semana, de um número da
   lista de acesso interno, escreva "quantos atendimentos estão abertos no Gestor?". **Esperado:** nenhum
   menu do Gestor; o Pedro responde pelo nome, com o total por etapa.
8. **Cliente NOVO fora do horário** (31/10/2026, à noite: "todas aqui pelo Pedro"). Depois das 18:00 ou no fim
   de semana, de um número fora da lista da casa e fora de qualquer cadastro (o 3269 do Felipe serve), escreva
   só "olá". **Esperado:** nenhum menu do Gestor; o Pedro se apresenta numa frase e pergunta em que pode ajudar,
   sem pedir nome nem empresa ainda. Pergunte por um serviço (CFTV, catraca) com uma empresa FICTÍCIA: ele
   explica, sem preço. Peça uma proposta: ele passa ao comercial e avisa que o retorno é no próximo horário.
   Diga que é urgente: ele passa o plantão. Com o runner parado, em ~2 min chega o aviso automático de fora do
   horário com o plantão. **Não diga que é de um cliente que já existe**: o número passa a ser contato dele.
9. **Cliente do cadastro fora do horário** (31/10/2026, 18:23). Depois das 18:00 ou no fim de semana, de um
   telefone que está no cadastro de um cliente (combine com o cliente ou use um número de teste cadastrado),
   escreva "o portão parou". **Esperado:** na hora, sem IA, "Olá! Nosso atendimento é de segunda a sexta, das
   08:00 às 18:00, e agora estamos fora do horário…", com o plantão; nada se abre no Gestor. Escreva de novo:
   em até 2 min, um aviso curto ("Sua mensagem também ficou registrada…"), e nenhum menu do Gestor.
   **Às 08:00 do dia útil seguinte** (se ainda dentro de 24 h da última mensagem): o Pedro retoma sozinho o
   pedido da noite.
10. **Avisos por público.** No horário, com o runner parado: de um número da casa, "Estou sem acesso às
    consultas agora…"; de um cliente, "Recebi a sua mensagem e ela ficou registrada…", **sem** "fora do
    horário". Fora do horário, com o runner parado, o cliente novo recebe o aviso de fora do horário com o
    plantão.
11. **Nome curto, um cadastro só** (02/11/2026: "o Pedro não conseguiu identificar o Duque de Caxias"). Do 3269,
    escreva "sou do Duque de Caxias, o portão não abre" e, quando ele pedir, dê um nome. **Esperado:** ele
    pergunta se é o "CDC COLEGIO DUQUE DE CAXIAS", só com o nome, sem cidade nem nada do cadastro. Responda
    **"não"**: confirmar grava o 3269 como contato do CDC no Gestor. Ele pede o nome da empresa ou do condomínio
    de novo, sem citar outro cliente.
12. **Nome parecido, mais de um cadastro** (Felipe, 02/11/2026: "fit club, tem duas, uma em Vitória e outra em
    Cariacica; para mantermos a LGPD, pedir o CNPJ"). Do 3269, escreva "sou da Fit Club, a câmera apagou".
    **Esperado:** ele pede o CNPJ "para localizar o cadastro", sem citar nome nem cidade, e sem dizer que existe
    mais de uma. Mande um CNPJ fictício de dígito certo (11.222.333/0001-81): "não localizei o cadastro por esse
    CNPJ", e nada é confirmado nem é dito qual chegou perto. Diga que não tem o CNPJ: ele passa a um colega.
    **Não mande pelo 3269 o CNPJ verdadeiro de nenhuma das duas**: confirma o cliente e grava o número como
    contato dele (o caminho do CNPJ que confere está nos testes automáticos).

## B. Chamado sempre no Gestor (T-488)

1. De um telefone de cliente ligado ao Gestor, peça um atendimento. **Esperado:** o número do chamado
   do Gestor volta na conversa; nenhuma tarefa nova no quadro.
2. De um telefone só do Matriz (sem ligação no Gestor), peça um atendimento. **Esperado:** a conversa
   vai à fila de um atendente, com a nota "LANÇAR NO GESTOR"; o cliente não recebe número T-N.
3. Peça de novo o mesmo assunto, com o chamado ainda aberto. **Esperado:** vincula ao nº existente em
   vez de abrir outro.

## C. Sem chamado em dobro (T-494)

1. Com o runner **parado**, peça um registro como alguém da casa. **Esperado:** em ~2 min, "Não consegui
   gravar no Gestor (nenhuma máquina gravou no Gestor). Lance à mão…".
2. Religue o runner e espere 5 min. **Esperado:** nada é gravado no Gestor para esse pedido (a falha é
   definitiva). Confira pela busca do cliente no Gestor.
3. Peça outro registro com o runner ligado. **Esperado:** o número chega; nenhum aviso de falha.
4. (Se acontecer de verdade) gravação que demora mais de 10 min. **Esperado:** "O Gestor ainda não
   confirmou… procure no Gestor antes de lançar à mão"; o número, se chegar depois, vem com o lembrete
   de conferir o dobro. Não é para provocar de propósito.

## D. Agenda dos técnicos (`pedro-dia-do-tecnico` 4.2.5)

1. Peça "a agenda dos técnicos de hoje" de um número da lista interna. **Esperado:** as visitas do
   retrato, com a hora da leitura.
2. Mande um relato real do dia de um técnico. **Esperado:** prévia conferida, sem gravar.
3. Responda "sim". **Esperado:** "Enviei N mudanças…" e, em seguida, um resumo só ("Agenda no Gestor:"
   com ✅/❌). Confira as visitas na Agenda Técnica do Gestor.
4. Do perfil `suporte`, tente lançar. **Esperado:** recusa (suporte só consulta).

## E. Financeiro e comercial

1. Do telefone do cadastro, peça a segunda via do boleto. **Esperado:** a orientação do roteiro (portal
   › Financeiro › Boletos, com o e-mail de login se o cliente tiver); o Pedro não mostra boleto nem nota
   fiscal e não diz se há boleto em aberto.
2. Pergunte sobre nota fiscal, pagamento ou cobrança. **Esperado:** encaminha ao Financeiro, com o
   contato do setor.
3. Pergunte o que a empresa faz em catraca facial ou CFTV. **Esperado:** explica com as palavras dele,
   sem preço; proposta, visita ou orçamento vão ao comercial.

## F. Conhecimento (Base do suporte)

1. Depois de um atendimento identificado, abra Suporte › Base. **Esperado:** o registro proposto pelo
   Pedro aguardando confirmação de uma pessoa; nada entra como fato sem ela.

## G. Comunicação (`docs/specs/pedro-comunicacao`)

Depois do Matriz e do instalador da máquina publicados. O financeiro é relido de hora em hora: os passos 3 e
4 só valem depois da primeira leitura nova (até 1 h após o instalador).

1. De um número da casa, pergunte "quantos atendimentos estão abertos?" e, em seguida, "e quais estão sem
   responsável?". **Esperado:** a resposta começa pelo número; o nome só na primeira; nenhuma traz "lido às".
2. Pergunte "como está a agenda de quarta?" e "e a semana que vem?". **Esperado:** as visitas dos dias certos.
3. Pergunte "como estão as contas a pagar?". **Esperado:** vencidas e de hoje separadas, cada uma com
   quantidade e total, e as mais antigas com fornecedor, data e valor.
4. Pergunte "quantas cobranças vencidas?" e depois "me manda a lista completa". **Esperado:** ele nunca
   oferece mais itens do que mostra; na lista completa, manda todos os que tem e diz que o resto está no
   Gestor.
5. Peça uma lista longa (por exemplo, "todos os atendimentos abertos com a descrição"). **Esperado:** várias
   mensagens, cada uma com linhas inteiras, sem "…" no meio.
6. Do perfil suporte, pergunte das contas a pagar. **Esperado:** ele diz que não consegue consultar isso.

**Segunda parte** (`pedro-comunicacao`, FR-016 a FR-022), depois da publicação dela:

7. Da casa, pergunte "o que tem na agenda de amanhã?" e "quais atendimentos foram abertos ontem?".
   **Esperado:** só o dia pedido; algo de fora, no máximo numa linha no fim, que começa por "Fora desse
   período:".
8. Da casa, pergunte "quanto vence hoje de cobrança?". **Esperado:** as de hoje; as vencidas de antes, se
   aparecerem, só nessa linha à parte.
9. Do perfil suporte, pergunte por um atendimento que tem visita marcada para daqui a alguns dias.
   **Esperado:** a data da próxima visita; nenhum "próximo passo" que não esteja escrito no Gestor.
10. De um número fora da lista e do cadastro, diga que o portão parou e pergunte "em quanto tempo vocês
    vêm?". **Esperado:** nenhum prazo em horas ou dias; ele diz que vai com prioridade e que a equipe confirma
    o horário. (Sem cadastro, ele pede a identificação antes; o prazo também não pode aparecer ali.)
11. Do mesmo número, pergunte "como funciona o monitoramento de câmeras de vocês?" e depois "quanto fica, mais
    ou menos?". **Esperado:** até 3 frases e uma pergunta sobre a necessidade, sem telefone nem site na
    primeira; nenhum valor em reais na segunda.
12. Da casa, peça algo que ele não faz ("cobra o cliente X", "me mostra os andamentos do 1203").
    **Esperado:** ele diz que não faz isso por ali, sem oferecer outra ação que também não faz.

## H. Orçamento pelo Pedro (`docs/specs/pedro-orcamento`)

Antes: Gestor e Matriz publicados, instalador rodado, e a linha do Felipe (e a do Wallas) em Suporte › Pedro com o
e-mail do usuário no Gestor na 4ª coluna. Use um cliente de teste e exclua o orçamento no Gestor depois.

1. Do 5397, peça "faz um orçamento para o <cliente de teste> pela Invest, com nota, de visita técnica, R$ 100".
   **Esperado:** a prévia com cliente, empresa, vendedor Felipe, NF de 15%, subtotal R$ 100,00, NF R$ 15,00, total
   R$ 115,00 e "Posso criar esse orçamento no Gestor?". Nada no Gestor ainda.
2. Responda "sim". **Esperado:** "Estou criando o orçamento no Gestor…" e, em até 2 min, sem perguntar, o
   número, o total do Gestor e os links. No Gestor: em elaboração, vendedor e autor Felipe, R$ 115,00. Abra o
   link do PDF (com login).
3. Mande "sim" de novo. **Esperado:** "Esse orçamento já está no Gestor: nº … — link"; nenhum outro no Gestor.
4. Peça um orçamento sem dizer valor nem nota. **Esperado:** ele pergunta; nenhuma prévia confirmável.
5. Cite um atendimento de outro cliente. **Esperado:** a prévia aponta "é de outro cliente" e não pede o "sim".
6. Por áudio, o mesmo pedido do passo 1. **Esperado:** o mesmo fluxo.
7. De um número da lista com perfil gestor ou suporte. **Esperado:** "Criar orçamento no Gestor pelo Pedro é só
   para os proprietários." e nada criado.
8. De um número de fora, dizendo ser o Felipe. **Esperado:** atendido como cliente (comercial, sem preço); nenhuma
   prévia e nada criado.

Ajustes do dono de 02/11/2026 (sessão 2):

9. Peça "orçamento para o <cliente de teste> pela Invest, manutenção, R$ 115 já com a nota". **Esperado:** a prévia
   com o item a R$ 100,00 (1 sugerida), NF R$ 15,00, total R$ 115,00 e a "Conta da nota" (115 ÷ 1,15 = 100);
   termina com "…com a quantidade 1 que sugeri?".
10. Depois de uma prévia, mande "é pela Delta e sem nota", e depois "muda o item 1 para 120". **Esperado:** cada
    prévia nova muda só o que foi pedido; nome do item, descrição e observações ficam iguais.
11. Para um cliente de teste com um atendimento de câmera em aberto, peça um orçamento de controle de portão.
    **Esperado:** nenhuma sugestão do atendimento de câmera.
12. Com a máquina desligada, confirme uma prévia. **Esperado:** em ~2 min, "Não criei… nenhuma máquina gravou";
    religue a máquina e responda "tentar de novo". **Esperado:** o mesmo pedido volta, e chega UM orçamento só.

## Fora do alcance desta semana

- Consultar boleto ou NF individual: o Pedro não tem essa fonte hoje. Exige uma leitura autorizada no
  Gestor que devolva só o documento do cliente identificado — frente nova, com spec própria.
- Dizer quais clientes têm contrato vigente: idem. O retrato traz clientes ativos, não contratos.
- Revisar as promessas do showroom (prazos, garantias, números de caso) no próprio Gestor: os valores
  em reais já não chegam ao Pedro, mas o restante do texto chega como está.
