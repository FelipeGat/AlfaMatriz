# Pedro — conhecimento do negócio, prioridade e registro obrigatório

**Feature**: `suporte-pedro-whatsapp` (etapa 3)
**Created**: 2026-10-20
**Pedido**: Felipe, 20/10/2026: "vamos manter nesse momento sem gravar no gestor, apenas consulta e obrigar a quem
atender registrar o atendimento. quero também ter um ambiente onde eu configuro e padronizo mensagens e dou uma
inteligência do nosso negócio para o Pedro (pode ser mesmo até no POP)."

Exemplos dados por ele:
- portão parado é urgente: prazo de até 3 horas para atender;
- todo atendimento crítico tem que ser passado e cobrado constantemente dos técnicos;
- pedido de boleto: indicar o portal do cliente, passar o usuário e onde baixar o boleto;
- atendimento comercial: apresentar a empresa, os ramos de atuação, o site e os folders, até encaminhar para um
  atendente humano.

## Decisões

1. **Gestor só para consulta.** O Pedro lê o retrato do Gestor a cada 10 min (só leitura) e **não grava**
   (`SUPORTE_PEDRO_GESTOR_GRAVAR` continua desligado). Atendimento de cliente do Gestor vira tarefa no Matriz com
   "LANÇAR NO GESTOR".
2. **Registro obrigatório.** Quem atende informa o nº do atendimento aberto no Gestor:
   - a tarefa **não pode ser concluída** sem esse número, quando o destino é o Gestor;
   - a conversa **não volta ao Pedro** com o número pendente;
   - os lembretes continuam até o número ser informado.
3. **Onde mora o conhecimento: tela própria**, Suporte › Pedro, e não os POPs da Central. Os POPs são das IAs, por
   sistema, e elas mesmas os mantêm. Este conteúdo é escrito pelo Felipe e vale para todas as conversas.

## A tela Suporte › Pedro

**Apresentação da empresa:** quem somos, ramos de atuação, site e folders (um link por linha). O Pedro só manda
link que está aqui.

**Roteiros**, um por situação, com:
- **Quando usar:** como o Pedro reconhece a situação ("portão não abre ou não fecha").
- **O que fazer:** orientação livre.
- **Mensagem padrão:** o texto que o Pedro usa, adaptando só o necessário.
- **Prioridade** do atendimento: baixa, média, alta ou crítica.
- **Prazo de atendimento, em horas:** opcional; por exemplo, 3 para o portão parado.
- **Abrir atendimento?** Se sim, o Pedro abre o atendimento quando o roteiro se aplica. O comercial, por exemplo,
  apresenta a empresa e passa para um humano.
- **Ativo** (sim/não) e **ordem**.

**Cobrança dos críticos:** de quanto em quanto tempo cobrar (padrão: 60 min).

Só os editores (`services.suporte_pedro.editores`, padrão: o Felipe) alteram. Quem tem `suporte` vê.

**Roteiros que já nascem**, editáveis:
- **Portão parado:** crítica, 3 h, abre atendimento.
- **Boleto / segunda via:** portal do cliente, sem abrir atendimento.
- **Comercial:** apresentação e encaminhamento para um atendente.

## Boleto: o usuário do portal

O retrato do Gestor passa a trazer, por cliente, os e-mails de login do portal:
- só usuário-cliente ativo;
- só quem tem a área Financeiro liberada (`portal_escopos` vazio ou com `financeiro`).

**Segurança:** o Pedro só informa o e-mail de login quando o número que escreve **é um telefone cadastrado daquele
cliente no Gestor**. Cliente identificado só pelo nome recebe o caminho do portal e a orientação "Esqueci minha
senha", sem o e-mail.

## Prioridade, prazo e cobrança

**Cada atendimento aberto pelo Pedro grava um registro** (`suporte_atendimentos`):
- tarefa e conversa;
- roteiro;
- prioridade e prazo (data e hora);
- cliente do Gestor;
- nº do Gestor, quem informou e quando.

**A tarefa** recebe a prioridade do roteiro e, no campo prazo, a data do prazo. O prazo em horas fica no registro e
aparece na conversa.

**Atendimento crítico em aberto**, a cada intervalo configurado:
- sino e Telegram para os responsáveis (Rossini e Alexandre), com o tempo que falta ou quanto já passou do prazo, e
  a etapa no Gestor, se o número foi informado e aparece no retrato;
- os técnicos de campo estão no Gestor, sem conta no Matriz: **a cobrança chega aos responsáveis, que cobram os
  técnicos**.

**A cobrança para quando:**
- a tarefa é encerrada; ou
- o atendimento do Gestor informado aparece concluído ou cancelado no retrato.

**Número do Gestor pendente:** todo atendimento com destino Gestor e sem número também é lembrado, no mesmo
intervalo, até o número ser informado. Vale para qualquer prioridade.
