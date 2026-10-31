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
  cadastro do cliente (`Identificacao::doCadastro`, antes `Contexto::numeroDoCadastro`). Ver "Níveis de
  identificação", abaixo.
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

O Gestor guarda a pessoa identificada como contato do cliente (`cliente_contatos_identificados`, T-479 do Gestor),
gravada pela máquina, e a manda no retrato. Feito em 26/10/2026 (`ContatosDoGestor`):

- **Gravar.** Toda resposta da IA em que a conversa fica identificada com um cliente do Gestor (id positivo:
  pré-cliente não tem contato lá) põe a pessoa na fila `suporte_gestor_contatos`, uma vez por (cliente, telefone,
  nome, função). A máquina pega em `suporte/gestor/contatos` e grava com `suporte_gestor_contato.php`, junto das
  aberturas e da agenda. Quem é da casa não vira contato.
- **O upsert é o da central do Gestor** e não muda sem ela: chave única (cliente, `telefone_chave`); linha que já
  existe só ganha telefone, `visto_em` e `updated_at`, **nunca nome nem papel** (decisão do dono, 26/10/2026: a
  correção feita à mão na tela do Gestor vale); o apagado volta, com o nome que tinha.
- **A chave do telefone** (`ContatosDoGestor::chave`) é a do Gestor, e não a do WhatsApp: DDD + 8 últimos dígitos,
  só para 55 + 10 ou 11 dígitos. Sem o 55 não grava (o Gestor recusaria ou cortaria a chave de 10 caracteres).
- **Esperar a tabela.** O retrato só traz `contatos` nos clientes quando a tabela existe no Gestor; até lá a fila
  não sai para a máquina, e o que se identificar nesse meio-tempo é gravado quando ela chegar.
- **Ler.** Os contatos vêm em cada cliente do retrato, SEPARADOS de `telefones`: contato identifica, mas **não
  libera pedido sensível** (`porTelefone` não os vê). Conversa ainda não identificada cujo número é contato de UM
  cliente só entra identificada antes do prompt, com o nome e a função do Gestor. De dois clientes (o porteiro de
  dois condomínios), segue perguntando. Outra pessoa no mesmo telefone se apresenta, como antes.

## Níveis de identificação (31/10/2026)

Pedido do dono: "o Pedro não pode passar informação nenhuma, nem mesmo NF nem boleto, para quem ele não conhece;
ele tem que sempre consultar o telefone cadastrado dentro do cadastro do cliente". A regra já existia, mas cada
trecho do pedido à IA decidia sozinho, e três deles tinham ficado de fora: quem só disse o nome recebia os
**sistemas ativos** do cliente, a **memória do cliente e os atendimentos de outras conversas** na base do suporte e
os **alertas** do cliente. O pedido de identificação, antes de a pessoa dizer quem é, já levava o e-mail de login do
portal quando o número era do cadastro. E a regra de boleto e nota vivia só no texto.

Agora o nível é um só, calculado em código (`App\Services\Suporte\Identificacao::nivel`), e o `Contexto` monta o
pedido inteiro a partir dele:

| Nível | Quando | O que o Pedro recebe do cliente |
|---|---|---|
| `interno` | número da lista de acesso interno | outra conversa (acesso-interno.md) |
| `desconhecido` | ainda não disse nome e cliente | só o nome do cliente, se o número já o traz |
| `pelo_nome` | identificado, número fora do cadastro | o nome do cliente e o que nasceu nesta conversa; nada do cadastro |
| `cadastro` | o número é telefone do cadastro do cliente | sistemas, contratos, histórico, memória, alertas e login do portal |

- **Número do cadastro** (`Identificacao::doCadastro`): o telefone está no cadastro de TODO cliente confirmado.
  Matriz e Gestor ligados (`clientes.gestor_cliente_id`) são a mesma organização, e basta um dos dois cadastros.
  Sem a ligação são duas, e o número precisa estar nas duas: o síndico profissional que está no cadastro do
  condomínio A e diz falar pelo B não vê nada do B. O contato gravado pelo Pedro no Gestor (T-479) não conta.
- **O rótulo diz como o cliente foi confirmado**: `CONFIRMADO PELO TELEFONE DO CADASTRO` ou `IDENTIFICADO SÓ PELO
  NOME`. O "CONFIRMADO" sozinho tratava o nome dito na conversa como prova.
- **Boleto, nota e cobrança** (`Contexto::FINANCEIRO`, em todo nível): o Pedro não tem documento nem situação de
  pagamento de ninguém, não diz se existe, e documento só sai pelo financeiro, para o contato do cadastro, nunca na
  conversa — nem para o número do cadastro, porque telefone de portaria passa de mão em mão. Ele orienta o caminho
  do portal (o roteiro "Boleto / segunda via") e, se precisar de mais, é com o financeiro. Para quem só disse o
  nome (`Contexto::FINANCEIRO_PELO_NOME`), o caminho do portal vai sem login, e ele diz que informações e documentos
  do cliente só vão para os contatos cadastrados.
- **Chamado vinculado ou reaberto** (`Atendimento::concluirNoGestor`): para quem não escreve do cadastro, a
  resposta é "Registrei as informações que você me passou", sem o número nem a existência do chamado anterior, que
  é histórico do cliente. O chamado é vinculado ou reaberto do mesmo jeito; a equipe recebe o número.
- **Consulta futura de boleto ou NF**: só no nível `cadastro`, e o documento vai ao contato do cadastro (WhatsApp ou
  e-mail), nunca à conversa. Frente nova, com spec própria e uma leitura no Gestor que devolva só o documento do
  cliente. Destino natural, conferido no esquema em 31/10/2026 (não nos dados): o **e-mail financeiro** do cadastro
  — `cliente_emails.financeiro` no Matriz (`Cliente::emailsFinanceiros`, que cai no principal) e `emails.eh_financeiro`
  no Gestor. Telefone não tem marca de cobrança em nenhum dos dois, e o retrato do Gestor ainda não lê os e-mails:
  traz `telefones` e os e-mails de login do portal com o financeiro liberado.
- **Os testes** (`tests/Feature/Suporte/PedroNivelDeIdentificacaoTest.php`) conferem cada nível contra a lista do
  que é do nível de cima: trecho novo do pedido que esquecer a regra quebra ali.
