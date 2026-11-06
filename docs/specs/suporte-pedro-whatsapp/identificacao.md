# Identificação antes de ajudar (T-478)

Decisão do dono do produto em 21/10/2026. Antes, o Pedro ajudava quem escrevia e só no fim pedia a
identificação, e seguia respondendo sem ela.

## A regra

- **Antes de qualquer ajuda, o Pedro pede o nome da pessoa e o nome da empresa ou do condomínio.** Se ela já
  contou o problema, ele mostra que entendeu e explica, com simpatia, que precisa disso para abrir o chamado.
- **Só um cumprimento, na primeira mensagem** (Felipe, 31/10/2026): o Pedro se apresenta numa frase curta (o
  nome e o suporte da empresa) e pergunta em que pode ajudar, sem pedir nome nem empresa ainda. A identificação
  vem quando a pessoa disser o que precisa. Quem já começa pelo problema ouve a apresentação e o pedido de
  identificação na mesma mensagem.
- **Fora do horário, quem se identifica como cliente que já existe** (Felipe, 31/10/2026): assim que o cliente é
  confirmado na conversa, a resposta da IA não sai e nada se abre; vai o aviso fixo de fora do horário com o
  plantão (spec.md, Clarificações). O nome e o cliente confirmados ficam gravados, e às 08:00 o Pedro retoma.
- **Identificada = cliente confirmado (Matriz ou Gestor) + nome de quem escreve** (`SuporteConversa::identificada`).
  Número do cadastro já traz o cliente; aí só falta o nome.
- **Sem lista de autorizados e sem aprovação humana.** A primeira proposta (lista mantida no Gestor, com
  aprovação) foi recusada pelo dono: "é inviável ficar indo no Gestor ver quem pode entrar". A identificação é
  registro, não permissão.
- **Relatar problema: qualquer pessoa identificada.** O nome dela vai como solicitante no atendimento
  (`SuporteConversa::solicitante`). Antes de abrir, o Pedro confere se já existe atendimento do mesmo assunto e do
  mesmo problema (vincular ou reabrir, `Atendimento::modoNoGestor`; ver FR-A8 em `acesso-interno.md`).
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
Se a volta não entra na hora (outro pedido da conversa ainda na fila), a resposta continua segurada: a última
mensagem segue sendo da pessoa, e o `Pedro::aguardando`, a cada minuto, retoma a conversa. Mandar o "obrigado"
ali calaria essa retomada. (Até 31/10/2026 o caso era o horário: identificou às 17:59, a IA respondeu às 18:01, e
a volta esperava a abertura. Desde então o Pedro atende a qualquer hora.)

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

## Busca do cliente novo (02/11/2026)

O Felipe escreveu "Duque de Caxias" do número de teste e o Pedro não achou o cliente, cadastrado no Gestor como
"CDC COLEGIO DUQUE DE CAXIAS". A busca do cliente novo (`DiretorioGestor::porNome`) exigia todas as palavras do
nome, e "colégio" não foi dito. A busca larga, que acha por parte do nome e por grafia aproximada, só servia a
quem é da casa. Regras do Felipe na mesma data:

- **Um cadastro só**: o Pedro pergunta "é este?" citando só o nome do cadastro, sem cidade nem nada dele, e o
  cliente vale depois do "sim". O que o cliente tem continua no nível `pelo_nome` (acima): confirmar pelo nome
  não abre o cadastro.
- **Mais de um de nome parecido, e o número fora do cadastro deles**: "nunca dizer ou falar de outro cliente;
  OBRIGATORIAMENTE pedir o CNPJ" — o exemplo dele são as duas Fit Club, em Vitória e em Cariacica. O Pedro não
  recebe nenhum dos dois: nem código, nem nome, nem cidade. O `Pedro::confirmarCliente` só aceita código que foi
  oferecido, então também não tem como confirmar um. O CNPJ que a pessoa escrever decide, e só entre eles.
- **Número do cadastro**: sem mudança. Se o número é de um dos parecidos, é ele, e os outros não aparecem.

Onde mora:

- `DiretorioGestor::candidatosPorNome` — a busca larga com as travas do cliente novo: o nome só parecido precisa
  de mais da metade do peso das palavras que o identificam (cada uma pesando pela raridade, e a cidade do próprio
  cadastro no nome não conta, para a "ACADEMIA FIT CLUB (CARIACICA)" ser a Fit Club); com um nome dito por inteiro,
  o parecido só fica se casou com as mesmas palavras ("sou a Zuleica do Residencial Atlântico": a Zuleica é a
  pessoa); só quem pesa ao menos metade do melhor; e palavra de mais de 10 cadastros não é nome ("minha academia").
  As palavras do problema e da conversa ("portão", "câmera", "quero", "falar") não são nome de cliente, e junção
  de duas palavras ("Marazul") só vale pelo som, sem letra de diferença.
- `Contexto::busca` — os candidatos, o candidato marcado `NOME PARECIDO` e o estado do CNPJ (`PEDIR_CNPJ`,
  `CNPJ_NAO_CONFERE`, `PELO_CNPJ`). O nome é procurado também no áudio transcrito. Vale igual para dois clientes
  do Matriz de mesmo nome.
- **O CNPJ válido procura em todos os clientes atendidos** (decisão do Felipe, 06/11/2026; antes, "só entre os
  parecidos"): um colégio mandou o CNPJ certo e ouviu duas vezes "não localizei", porque ele só era comparado com três
  cadastros que nada tinham a ver. Achou, é só esse: os de nome parecido não vão ao Pedro. A pessoa continua identificada
  só pelo nome, o que não abre nada do cadastro. **O CPF não entra nessa busca:** é de uma pessoa, e dizer de quem é a quem
  só digitou o número é o que a LGPD não deixa. Ele continua só desempatando entre os parecidos.
- `Contexto::documentos` — o CNPJ (ou CPF) escrito, com ou sem pontuação, só com dígito verificador certo:
  celular não vira "esse CNPJ não confere", e o dígito errado faz o Pedro pedir de novo. O CNPJ do cadastro já vem
  no retrato do Gestor (`documento`); a máquina não mudou.

Conferido no Gestor de produção, só leitura, em 02/11/2026 às 20:14 (`C:\tmp\e597\pedro_busca_cliente_novo_ro.php`,
a classe nova sobre os cadastros lidos como o retrato lê): "duque de caxias" acha só o CDC (#9, INVEST, no filtro de
empresas), e a busca antiga não achava nenhum; "fit club" pede o CNPJ, com as duas academias e dois pré-clientes
"Fitclub" entre os de nome parecido; e 11 frases comuns sem nome de cliente ("minha academia está com o portão
travado", "quero falar com o financeiro") não trazem ninguém. A busca antiga mandava ao Pedro, para essa mesma
frase da academia, o nome e a cidade de três academias. Efeito conhecido: nome de bairro que está no nome de
cadastros ("praia da costa") pede o CNPJ. Dos 141 clientes, 118 têm CNPJ, 20 têm CPF, 1 tem um documento de 12
dígitos e 2 estão sem: esses três últimos não têm como ser confirmados pelo CNPJ, e o Pedro passa a um colega.

Os testes estão em `tests/Feature/Suporte/PedroBuscaDoClienteNovoTest.php`, com dados fictícios. Com o código
anterior, 5 dos 8 falham e 1 dá erro (o método novo não existia); os outros dois ("palavra solta" e "número do
cadastro de um deles") protegem a busca nova.

