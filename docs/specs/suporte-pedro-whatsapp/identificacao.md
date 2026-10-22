# Identificação antes de ajudar (T-478)

Decisão do dono do produto em 21/10/2026. Antes, o Pedro ajudava quem escrevia e só no fim pedia a
identificação, e seguia respondendo sem ela.

## A regra

- **Antes de qualquer ajuda, o Pedro pede o nome da pessoa e o nome da empresa ou do condomínio.** Se ela já
  contou o problema, ele mostra que entendeu e explica, com simpatia, que precisa disso para abrir o chamado.
- **Identificada = cliente confirmado (Matriz ou Gestor) + nome de quem escreve** (`SuporteConversa::identificada`).
  Número do cadastro já traz o cliente; aí só falta o nome.
- **Sem lista de autorizados e sem aprovação humana.** A primeira proposta (lista mantida no Gestor, com
  aprovação) foi recusada pelo dono: "é inviável ficar indo no Gestor ver quem pode entrar". A identificação é
  registro, não permissão.
- **Relatar problema: qualquer pessoa identificada.** O nome dela vai como solicitante no atendimento
  (`SuporteConversa::solicitante`). Antes de abrir, o Pedro confere se já existe atendimento do mesmo assunto
  (vincular ou reabrir, `Atendimento::modoNoGestor`, que já existia).
- **Pedido sensível** (histórico, boleto, login do portal, acesso, cancelamento): continua só para os telefones do
  cadastro do cliente (`Contexto::numeroDoCadastro`), como antes.
- **Comercial não precisa de identificação**: quem quer contratar não é cliente.
- **Segurança vem antes**: fumaça, faísca, choque ou água em equipamento elétrico recebem a orientação na hora.

## Onde mora a trava

No código, e não só no texto para a IA:

1. `Contexto::promptDeIdentificacao` — sem roteiros, diagnóstico, atendimentos, assuntos do Gestor nem base do
   suporte. O que a IA não recebe ela não usa.
2. `Pedro::concluir` — sem identificação, abrir atendimento ou encaminhar a setor que não seja o comercial vira
   passagem para um colega da equipe; o registro para a base é descartado.
3. `Pedro::reserva` (IA sem resposta) — sem identificação, não abre tarefa: a conversa vai para a fila.
4. `BaseDoSuporte::encerrarSemResposta` — conversa sem identificação não vira registro.

Quando a pessoa termina de se identificar, a resposta daquele pedido **não é enviada**: a conversa volta na hora
ao Pedro com o contexto inteiro (pedido com `acao = identificou`), e ele responde uma vez só, já cuidando do
problema. Custa uma chamada a mais da IA, uma vez por conversa, e evita a troca "obrigado" + "e o problema?".
Se a volta não entra na hora (identificou às 17:59 e a IA respondeu às 18:01), a resposta continua segurada: a
última mensagem segue sendo da pessoa, e o `Pedro::aguardando` retoma a conversa na abertura. Mandar o "obrigado"
ali calaria essa retomada.

**Telefone compartilhado** (portaria): a identificação fica no número, mas quem disser OUTRO nome passa a ser o
solicitante, e não herda a função de quem escreveu antes. O pedido de quem já se identificou também devolve
`solicitante` para isso.

## O jeito humano

`Contexto::HUMANO` vale nos dois pedidos do cliente, e as frases fixas (passagem a um colega, número do chamado,
avaliação) foram reescritas no tom de uma pessoa da equipe. Uma exceção fica de propósito: **perguntado
diretamente se é robô, o Pedro não nega** (política da Meta para IA no WhatsApp Business e dever de informação
do CDC).

## Depende do Gestor (T-479)

O Gestor vai guardar a pessoa identificada como contato do cliente (nome, função, telefone), gravada pela máquina,
e mandá-la no retrato. Com isso, o Pedro reconhece pelo número quem já se identificou em outra conversa. Até lá,
vale a identificação guardada na própria conversa (`solicitante_nome`, `solicitante_funcao`, `identificada_em`).
