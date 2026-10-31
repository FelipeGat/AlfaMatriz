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

## A. Identificação (T-478)

1. Encerre a sua conversa em Suporte › Conversas e escreva como alguém de um cliente do Gestor, sem se
   identificar, relatando um problema. **Esperado:** o Pedro pede nome e cliente antes de qualquer ajuda.
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

## Fora do alcance desta semana

- Consultar boleto ou NF individual: o Pedro não tem essa fonte hoje. Exige uma leitura autorizada no
  Gestor que devolva só o documento do cliente identificado — frente nova, com spec própria.
- Dizer quais clientes têm contrato vigente: idem. O retrato traz clientes ativos, não contratos.
- Revisar as promessas do showroom (prazos, garantias, números de caso) no próprio Gestor: os valores
  em reais já não chegam ao Pedro, mas o restante do texto chega como está.
