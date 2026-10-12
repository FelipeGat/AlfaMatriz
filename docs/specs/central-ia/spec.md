# Feature Specification: Central de IA

**Feature**: `central-ia`
**Created**: 2026-10-12 (retroativa: o que foi construído de 10 a 11/10/2026 na branch `Felipe`)
**Status**: Draft — em homologação local; nada em produção

Fontes: decisões do dono no chat (10–12/10), `docs/briefing.md`, `docs/central-ia/*.md`.
A interação com anexos e os avisos de 90 s já têm spec própria:
[`../central-ia-interacao-anexos/spec.md`](../central-ia-interacao-anexos/spec.md), aqui
incorporada por referência (US7).

## Clarifications

### Session 2026-10-12 (respostas do dono após a revisão)

- Q: Um admin pode alterar a conta de quem usa a Central? → A: Não. "Isso não pode ocorrer." Só Felipe e Rossini são admins; o Alexandre deixa de ser (por enquanto só na cópia local).
- Q: O runner pode obedecer ao que o servidor manda? → A: Não. "Tem que ser inviolável." A confirmação vem da própria máquina: ela manda ao Telegram do dono o pedido exato e um código que só ela sabe gerar.
- Q: Pedido destrutivo pelo Telegram exige confirmação? → A: Sim, e fica registrado no log.
- Q: O que "analisar" pode fazer? → A: Averiguar e analisar uma possibilidade, sem modificar nada.
- Q: Roteamento de 10/10 ou o do Codex de 11/10? → A: O dono quer custo baixo por padrão: pedido só de leitura, como o relatório da agenda, vai para um modelo barato ou nem passa por IA; o modelo caro fica para quando o trabalho exige.
- Q: Produção? → A: Começa vazia, e só sobe quando a Central estiver 100% funcionando.
- Q: E o trabalho em andamento do Codex? → A: O Codex para; o Claude assume.
- Pedido adicional: testar e corrigir o front e a UX, para a pessoa se achar na tela no dia a dia; respostas coerentes, fáceis de ler, de escolher e de delegar.

## User Scenarios & Testing

### User Story 1 - Conversar com as minhas IAs pela tela e pelo Telegram (Priority: P1)

A pessoa com o perfil "Central de IA" conversa num histórico único. Pode escrever na tela
do AlfaMatriz ou no Telegram pareado, e vê nos dois lugares o que disse e o que recebeu.

**Why this priority**: sem conversa privada e confiável, nada mais funciona.

**Independent Test**: parear o Telegram, mandar uma mensagem por canal e conferir que as
duas aparecem na tela, uma vez cada, e que outra conta não as vê.

**Acceptance Scenarios**:

1. **Given** uma pessoa com o perfil, **When** envia uma mensagem e a rede repete o envio, **Then** o histórico guarda uma mensagem só.
2. **Given** outra conta, mesmo administradora, **When** tenta abrir a conversa, a mensagem ou o anexo da primeira, **Then** recebe "não encontrado".
3. **Given** um link de pareamento com mais de 10 minutos ou já usado, **When** é aberto no Telegram, **Then** o pareamento é recusado.
4. **Given** o canal ativo é a tela, **When** a pessoa pede para continuar no Telegram, **Then** o atendimento passa para lá sem duplicar nem perder o histórico.

---

### User Story 2 - Pedir trabalho a um agente no meu projeto (Priority: P1)

A pessoa pede uma análise ou uma implementação num projeto da máquina dela. A IA é
escolhida automaticamente, ou pela pessoa, e responde na conversa.

**Why this priority**: é o motivo de a Central existir.

**Independent Test**: com o runner online, pedir "analise o projeto X" e receber uma
resposta do agente escolhido, com o motivo da escolha.

**Acceptance Scenarios**:

1. **Given** um pedido de resumo, relatório ou análise, **When** a IA é automática, **Then** vai ao Codex; um pedido para implementar ou corrigir vai ao Claude, e a escolha manual vence.
2. **Given** a máquina da pessoa offline, **When** ela pede, **Then** o pedido fica na fila com a máquina indicada como offline, e não falha em silêncio.
3. **Given** já existe uma execução na tarefa, **When** chega outro pedido para a mesma tarefa, **Then** ele espera; no máximo 2 execuções correm ao mesmo tempo por pessoa.
4. **Given** a sessão de 5 h do Claude em 100%, **When** a IA é automática, **Then** o pedido vai para outra IA disponível e a troca é registrada.

---

### User Story 3 - Trocar de agente sem perder o contexto (Priority: P1)

Uma tarefa guarda um dossiê com objetivo, pedidos, decisões, testes, próximos passos,
pendências, riscos e o estado do git. Quem assume a tarefa recebe o dossiê e confere o
estado real antes de mexer.

**Why this priority**: a perda de contexto na troca foi a dor que originou o pedido.

**Independent Test**: o Claude implementa parte; o pedido seguinte vai ao Codex, que
cita o dossiê e confere o git antes de alterar.

**Acceptance Scenarios**:

1. **Given** um agente que não devolveu o bloco de contexto, **When** a execução termina, **Then** o dossiê registra a lacuna em vez de inventar continuidade.
2. **Given** uma análise, **When** termina, **Then** a tarefa não é encerrada; só uma implementação que declare o objetivo atendido encerra.
3. **Given** um ponto salvo, **When** a tarefa continua, **Then** o agente recebe o resumo do ponto e a tela mostra só o que veio depois, sem apagar o anterior.

---

### User Story 4 - Ver vários projetos ao mesmo tempo (Priority: P2)

Um cockpit sem rolagem de página mostra os projetos fixados, em curso ou com atividade
recente, com estado, agente, git e atenção; busca e grupos (VixCard, Grupo Soluções,
Pessoal), em abas.

**Why this priority**: o dono trabalha em até ~5 projetos ao mesmo tempo.

**Independent Test**: fixar 3 projetos em grupos diferentes, filtrar pela busca, abrir
um e voltar sem recarregar a página.

**Acceptance Scenarios**:

1. **Given** um projeto com execução em curso, **When** o cockpit atualiza, **Then** ele aparece com o agente e o estado.
2. **Given** a troca de projeto durante uma atualização automática, **When** chegam respostas antigas, **Then** elas não aparecem na nova tela.

---

### User Story 5 - Continuar pelo celular o que comecei no VS Code (Priority: P2)

A pessoa escolhe uma sessão recente do VS Code (Claude, Codex ou Copilot) e continua no
AlfaMatriz ou no Telegram ("continuar gestor de onde paramos"). A sessão original fica
intacta.

**Why this priority**: pedido explícito do dono para trabalhar longe do computador.

**Independent Test**: continuar uma sessão do Claude e conferir que a original não
ganhou mensagens.

**Acceptance Scenarios**:

1. **Given** uma sessão usada há menos de 5 minutos, **When** a pessoa pede para continuar, **Then** é recusado, porque ela ainda está em uso.
2. **Given** a IA da sessão no limite, **When** a pessoa continua, **Then** outra IA assume com o histórico de texto da conversa.

---

### User Story 6 - Saber quanto gastei e quanto falta (Priority: P2)

A aba Consumo mostra, por IA, o gasto do dia e dos 7 dias e os limites (sessão de 5 h e
semana do Claude e do Codex; premium do mês do Copilot), com gráficos e filtros.

**Independent Test**: comparar os números da tela com os registros locais da IA no mesmo minuto.

**Acceptance Scenarios**:

1. **Given** uma IA sem registro de limite, **When** a aba abre, **Then** mostra "sem dado", nunca uma estimativa.

---

### User Story 7 - Mandar anexos e ver que está andando (Priority: P2)

Coberto em [`central-ia-interacao-anexos`](../central-ia-interacao-anexos/spec.md):
imagem, PDF e vídeo; confirmação imediata; avisos a cada 90 s; `/status` sem gastar IA.

---

### User Story 8 - Saber quem fez o quê (Priority: P2)

Com acesso total, a aba Atividade registra pedido, início, conclusão, falha (com o erro),
falta de resposta, troca de agente e recusa: quem, qual agente, qual projeto e a origem.

**Independent Test**: provocar a falha de um agente e achar o evento com o erro.

**Acceptance Scenarios**:

1. **Given** qualquer execução, **When** muda de estado, **Then** existe um evento que não pode ser editado nem apagado pela tela.

---

### User Story 9 - Ajustar as regras da minha conta (Priority: P3)

A aba Ajustes define, por conta, quais IAs estão habilitadas, a preferência de roteamento,
o fallback e um teto opcional de pedidos. Também arquiva tarefas sem apagar nada.

**Acceptance Scenarios**:

1. **Given** uma IA desabilitada pela pessoa, **When** a IA é automática, **Then** ela nunca é escolhida.
2. **Given** uma tarefa arquivada, **When** a pessoa desarquiva, **Then** volta com o histórico intacto.

---

### User Story 10 - Nada roda na minha máquina sem eu saber (Priority: P1)

Mesmo que alguém controle o servidor ou o banco, a máquina do dono só modifica algo
depois de mostrar a ele, pelo Telegram, o pedido exato e receber de volta o código
que ela mesma gerou.

**Why this priority**: o agente tem acesso total; sem isso, quem controla o servidor
controla a máquina.

**Independent Test**: criar direto no banco uma execução de implementação para o
dono e conferir que a máquina não a executa sem o código.

**Acceptance Scenarios**:

1. **Given** uma implementação pedida, **When** a máquina recebe o pedido, **Then** envia o pedido exato e o código de confirmação ao Telegram do dono e espera, registrando no log.
2. **Given** um código errado, digitado três vezes, **When** a máquina confere, **Then** a execução é recusada, nada é modificado e a recusa fica no log.
3. **Given** um pedido de analisar, **When** o agente tenta gravar um arquivo ou ler `~/.ssh`, **Then** a ferramenta do agente nega.
4. **Given** o servidor manda acesso total ou uma pasta fora dos projetos, **When** a configuração local da máquina não permite, **Then** a máquina recusa, e o servidor não consegue mudar o nível de acesso nem as pastas permitidas.

---

### User Story 11 - Ninguém mexe na minha conta (Priority: P1)

**Acceptance Scenarios**:

1. **Given** Rossini, admin, **When** tenta redefinir a senha, o e-mail ou os perfis da conta do Felipe, **Then** é recusado, e a tentativa fica registrada.
2. **Given** um admin que não é o dono da Central, **When** abre Atividade ou Ajustes, **Then** não vê nem administra a Central de nenhuma outra conta.

---

### User Story 12 - Gastar só o necessário (Priority: P2)

**Acceptance Scenarios**:

1. **Given** "quais são meus compromissos de hoje?", **When** a IA está em automático, **Then** a lista sai da agenda oficial sem acionar nenhuma IA (nível sem IA).
2. **Given** "faça um resumo da agenda da semana", **When** em automático, **Then** vai ao nível leve, e a tela mostra o modelo escolhido e o motivo.
3. **Given** "implemente o filtro de status", **When** em automático, **Then** vai ao nível forte, e a pessoa pode baixar o nível antes de enviar.

---

### User Story 13 - Entender, escolher e delegar sem me perder (Priority: P2)

**Acceptance Scenarios**:

1. **Given** um agente com dúvida e opções, **When** a resposta chega, **Then** as opções aparecem como botões e um clique responde.
2. **Given** uma resposta concluída, **When** a pessoa clica em "delegar a outra IA", **Then** nasce um pedido na mesma tarefa, com o dossiê, para a IA escolhida.
3. **Given** qualquer aba aberta, **When** há confirmação ou dúvida esperando, **Then** um aviso fixo leva até ela em um clique, e a página não rola.

---

### Edge Cases

- Produção recebe a Central com as tabelas vazias, e só depois da homologação concluída.

- Dois consumidores do mesmo bot ligados ao mesmo tempo: o segundo recua sem ler mensagens.
- O bot reinicia no meio de uma leitura: retoma sem perder nem repetir mensagem.
- A entrega ao Telegram tem resultado incerto (queda, 5xx): marcada "incerta" e não reenviada sozinha.
- Mensagem com mais de 4096 caracteres: dividida em partes.
- O runner cai no meio de uma execução: a execução expira como "sem resposta" e a tarefa pode ser retomada.
- Execução longa de implementação (o gestor passou dos 15 min): não é cortada antes do teto do modo.
- Cópia de produção restaurada no local: as tabelas da Central somem e precisam ser salvas antes.
- Pedido vindo do Telegram que manda apagar dados, forçar push ou mexer em produção.
- Muitos envios em sequência: o limite de taxa de uma rota não bloqueia o envio de outra.
- Consumo e limites: o gasto e os limites de cada IA vêm dos registros locais; o conteúdo das conversas nunca é lido para contar.
- Tela em abas numa janela de notebook: a página não ganha rolagem; só as listas internas rolam.
- Regras individuais: uma conta muda habilitação, roteamento, fallback ou teto de pedidos, e o roteamento de outra conta não muda.

## Requirements

### Functional Requirements

- **FR-001**: O sistema MUST manter, por conta, um histórico único de conversa alimentado pela tela e pelo Telegram, com envio idempotente.
- **FR-002**: O sistema MUST negar ("não encontrado") a qualquer outra conta, inclusive administradora, o acesso a conversa, tarefa, execução, anexo, preferência e consumo de uma conta.
- **FR-003**: O acesso à Central MUST depender do perfil "Central de IA", concedido conta a conta e nunca de fábrica ao administrador.
- **FR-004**: O pareamento com o Telegram MUST usar código de uso único válido por 10 minutos, com um chat por conta e uma conta por chat.
- **FR-005**: O sistema MUST ter exatamente um leitor do bot do Telegram ativo; um segundo leitor MUST recuar sem consumir mensagens.
- **FR-006**: O atendimento MUST estar ativo em um canal por vez (tela ou Telegram), com troca explícita e histórico preservado.
- **FR-007**: Os agentes MUST executar somente na máquina da própria pessoa, com as credenciais de IA dela, que nunca são guardadas pelo AlfaMatriz.
- **FR-008**: O sistema MUST escolher a IA automaticamente por regras declaradas (resposta → Codex; implementar/corrigir → Claude; apoio rápido → Copilot), mostrar o motivo, aceitar a escolha manual e desviar de IAs no limite ou desabilitadas.
- **FR-009**: O sistema MUST permitir no máximo 2 execuções simultâneas por pessoa, uma por tarefa e uma implementação por projeto.
- **FR-010**: Cada tarefa MUST manter um dossiê com o relato do agente separado da medida do git feita pela máquina, e registrar a lacuna quando o agente não relatar.
- **FR-011**: Só uma execução de implementação que declare o objetivo atendido MUST encerrar a tarefa.
- **FR-012**: A pessoa MUST poder salvar um ponto da tarefa, que resume o contexto e esconde da vista, sem apagar, o histórico anterior.
- **FR-013**: A pessoa MUST poder continuar uma sessão recente do VS Code sem alterar a sessão original, com recusa se ela foi usada há menos de 5 minutos.
- **FR-014**: Pedidos sobre agenda MUST receber a fonte oficial do período pedido, para que IAs diferentes deem a mesma resposta.
- **FR-015**: A aba Consumo MUST mostrar o gasto e os limites por IA lidos dos registros locais, sem ler o conteúdo das conversas, e "sem dado" quando não houver registro.
- **FR-016**: O sistema MUST registrar em log, só por acréscimo, todo pedido, início, conclusão, falha, falta de resposta, troca de agente e recusa, com quem, agente, projeto e origem.
- **FR-017**: Os agentes MUST operar com acesso total na máquina da pessoa (decisão do dono), e commit, push e deploy MUST ocorrer só quando o pedido disser.
- **FR-018**: A tela MUST caber na janela, sem rolagem de página, organizada em abas.
- **FR-019**: As regras de habilitação, roteamento, fallback e teto de pedidos MUST ser individuais por conta.
- **FR-020**: A falha reconhecida de um provedor (limite, autenticação) MUST gerar uma única tentativa por alternativa, na mesma tarefa, preservando instrução, anexos e dossiê.
- **FR-021**: Uma execução sem sinal da máquina além do prazo do seu modo MUST ser marcada "sem resposta" e liberar a fila.
- **FR-022**: Toda execução que pode modificar algo (implementar, ou qualquer pedido classificado como destrutivo, venha da tela ou do Telegram) MUST esperar a confirmação do dono. A máquina dele envia, direto ao Telegram dele, o pedido exato e um código de uso único preso àquele pedido. O dono digita o código na tela ou no Telegram, e a máquina confere sozinha. Pedido, envio do código, confirmação, recusa e expiração ficam no log.
- **FR-023**: A Central MUST ir a produção com todas as suas tabelas vazias e só depois de o dono declarar a homologação concluída.
- **FR-024**: O nível de acesso, as pastas permitidas e a exigência de confirmação MUST ser decididos pela máquina da pessoa, numa configuração local. Nada vindo do servidor pode ampliá-los, nem apontar a execução para fora da pasta de projetos.
- **FR-025**: Analisar MUST ser só leitura, imposto pela própria ferramenta do agente e não por instrução no texto. Na análise, também não se leem chaves e credenciais da máquina (por exemplo `~/.ssh`, `~/.config/alfa`, arquivos `.env`).
- **FR-026**: Senha, e-mail, perfis e situação de uma conta com o perfil "Central de IA" MUST ser alteráveis só pelo próprio dono da conta. Nenhum administrador vê conversas, tarefas, execuções ou consumo da Central de outra pessoa. A exceção é o dono da Central, que administra os limites dos outros usuários, vendo só contagens e não conteúdo; ninguém administra a conta dele.
- **FR-027**: Cada pedido MUST ser atendido no menor nível de custo que resolve: **sem IA** (dados oficiais mostrados direto, como a lista de compromissos), **leve** (leitura, resumo, relatório), **médio** (análise, revisão, planejamento) ou **forte** (implementação). A tela mostra a IA, o modelo e o motivo; a pessoa pode trocar o nível, e os modelos de cada nível são ajustáveis por conta.
- **FR-028**: A resposta de um agente MUST chegar à tela legível e com ação: o essencial primeiro; dúvida com opções clicáveis; e os botões "aprovar", "pedir ajuste" e "delegar a outra IA" no próprio balão.
- **FR-029**: A tela MUST deixar claro, sem rolagem de página, onde a pessoa está (projeto, tarefa, canal ativo), o que está rodando e o que espera por ela (confirmação, dúvida), em no máximo um clique a partir de qualquer aba.

**FR-INFRA-SCHED**: o trabalho periódico (leitura de consumo a cada 2 min, `/usage` do Claude a cada 10 min, git dos projetos a cada minuto) é disparado pela máquina da pessoa; o AlfaMatriz não usa agendador para a Central.
**FR-INFRA-LOCK**: a exclusividade do leitor do Telegram e a escolha da próxima execução são serializadas no banco.
**FR-INFRA-IDEMP**: mensagem com o mesmo identificador do cliente, conclusão repetida e reentrega do Telegram (mesmo update) não duplicam registro.
**FR-INFRA-BACKUP**: antes de restaurar a cópia de produção no local, as tabelas da Central e a conta local MUST ser salvas e devolvidas depois.
**FR-INFRA-KEY**: N/A — a Central não cifra dado persistente com chave própria. Os tokens de máquina ficam guardados só pelo hash.

### Key Entities

- **Conversa / Mensagem**: o histórico de uma conta; a mensagem tem origem (tela, Telegram, agente, sistema), projeto e tarefa.
- **Vínculo do Telegram / Código de pareamento / Entrega**: o chat de uma conta, o código de uso único e o resultado de cada envio.
- **Runner**: a máquina de uma pessoa, com os agentes, os projetos e o último sinal.
- **Tarefa**: a unidade de trabalho de um projeto, com o dossiê, o canal ativo e a situação de arquivamento.
- **Execução**: um pedido a um agente, com modo, estado, sessão, roteamento, prazo e resultado.
- **Projeto / Grupo**: o projeto informado pela máquina; o grupo é a organização da pessoa.
- **Evento**: o registro só-acréscimo de quem fez o quê.
- **Preferência**: as regras individuais da conta.
- **Anexo**: ver a spec de anexos.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Em 100% dos testes de isolamento, uma conta não acessa nenhum dado da Central de outra.
- **SC-002**: Uma mensagem enviada pelo Telegram aparece na tela em até 10 segundos, e vice-versa.
- **SC-003**: O mesmo pedido de "resumo da agenda de hoje" a duas IAs diferentes traz os mesmos compromissos.
- **SC-004**: Ao trocar de agente numa tarefa, o novo agente cita o dossiê e confere o estado real em 100% das trocas observadas na homologação.
- **SC-005**: Nenhuma execução fica "em andamento" mais que o prazo do seu modo sem ser marcada.
- **SC-006**: Durante a homologação, zero ações em produção e zero mensagens perdidas ou duplicadas no Telegram.
- **SC-007**: A suíte isolada do projeto roda verde no estado commitado.

## Delta Requirements

**Skip**: feature nova; não existe corpus `docs/specs/current/` neste repositório, e nada ativo em produção é alterado — Claude (spec retroativa), 2026-10-12
