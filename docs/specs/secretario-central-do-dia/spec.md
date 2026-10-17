# Feature Specification: Secretário, Etapa 8 (Central do dia e de gestão)

**Feature**: `secretario-central-do-dia`
**Created**: 2026-10-17
**Status**: Draft
**Pedido**: Felipe, 17/10/2026, depois da publicação das Etapas 3 a 7 e do Secretário liberado para os perfis de
pessoas:

> "na tela meu dia, quero que traga as informações do flamengo, dolar, clima, principais notinhas do brasil e do
> mundo. em acompanhamentos, traga informações como se fosse a central do gestor, mostrando os principais pontos de
> gestão que tenho que tomar, para o Grupo Soluções e VixCard. quantidade de agendas do dia, emails não lidos dos 3
> dias. regras não pode ficar aqui, tem que ficar em ajustes, configurações. minha agenda ficou péssimo, quero que
> traga igual do gestor. em emails, já pegue as caixas de emails que estão no gestor e traga aqui. não entendi o que é
> a tela memorias"

**Respostas (17/10/2026)**:
- A VixCard ainda não tem sistema: ele lança os pontos um a um.
- Agenda: visual de calendário, como o do Gestor.
- Clima: Vitória-ES.
- Memória: sai do menu e continua pelo chat.

## Escopo

1. **Meu Dia, "Lá fora"**: dólar, clima de Vitória, Flamengo (manchetes do ge), principais notícias do Brasil e do
   mundo (g1). Tudo vem de fontes públicas, sem chave, buscadas pelo servidor a cada 30 min, com a fonte e o horário
   de cada bloco. Fonte fora do ar aparece como "indisponível", sem número velho apresentado como atual.
2. **Acompanhamentos vira a Central de gestão**:
   - números do topo: compromissos de hoje, e-mails não lidos dos últimos 3 dias, tarefas vencidas e alertas
     ativos;
   - bloco **Grupo Soluções**, com os pontos do Gestor (orçamentos a aprovar, cobranças, contas a pagar, reuniões
     com próximo passo e funil parado), com valores;
   - bloco **VixCard**, com os pontos de gestão que ele lança;
   - depois, a lista de alertas por regra (Etapa 7).
3. **Regras** saem da tela de Acompanhamentos e vão para Secretário → Configurações.
4. **Minha agenda** vira calendário, com as visões Dia, Semana e Mês, grade de horas, linha do "agora" e
   navegação.
5. **E-mails**: as caixas de `adm@investsolucoesdigitais.com.br`, `felipegat@vixcard.com.br` e
   `felipehenriquegat@gmail.com` são trazidas do Gestor para o Secretário do Felipe. A senha nunca passa pela
   conversa nem fica legível em arquivo. O Secretário passa a saber o que está **não lido**, lendo a marca sem
   alterá-la.
6. **Memória** sai do menu. As perguntas pelo chat continuam.

**Fora desta etapa**: placar ao vivo e próximo jogo do Flamengo (a fonte gratuita testada devolveu dados de outro
time, então não há fonte confiável sem chave paga), sistema da VixCard e WhatsApp.

## User Scenarios & Testing

### User Story 1 - Meu Dia com o que acontece lá fora (Priority: P1)

O Meu Dia mostra, num bloco compacto:
- **dólar**: compra, variação do dia e horário;
- **Vitória**: agora, máxima e mínima, chance de chuva;
- **Flamengo**: as 3 manchetes mais recentes do ge;
- **Brasil** e **Mundo**: as 5 manchetes mais recentes do g1, cada uma com o link.

**Acceptance Scenarios**:

1. **Given** a última busca de há 10 min, **When** ele abre o Meu Dia, **Then** vê os 5 blocos com o horário da busca.
2. **Given** uma fonte fora do ar, **When** a busca falha, **Then** o bloco mostra o último dado com "de HH:MM" se
   tiver menos de 6 h; mais velho que isso, mostra "indisponível agora".
3. **Given** uma manchete com HTML ou script no título, **Then** ela aparece como texto puro, e o link só vale se
   for https.
4. **Given** o comando a cada 30 min, **When** ele busca dólar (AwesomeAPI), clima de Vitória (Open-Meteo),
   Flamengo (RSS do ge) e Brasil e Mundo (RSS do g1), **Then** cada bloco guarda a fonte e o horário, sem chave e
   sem IA.
5. **Given** outra conta, **Then** vê o mesmo "Lá fora", porque o dado é público e não leva nada da conta.

### User Story 2 - Central de gestão (Priority: P1)

Em Acompanhamentos, primeiro os números do dia, depois um bloco por empresa e por fim os alertas.
- **Grupo Soluções**: o último retrato do Gestor, com quantidade, valor e os 5 itens mais antigos de cada bloco.
- **VixCard**: os pontos que ele lançou ("Lançar ponto": empresa, o quê, prazo, prioridade), com Concluir.

**Acceptance Scenarios**:

1. **Given** 3 compromissos hoje e 7 e-mails não lidos desde anteontem, **Then** os números mostram 3 e 7, cada um
   com o link para a tela dele.
2. **Given** o retrato do Gestor lido há 1 h, **Then** o bloco Grupo Soluções mostra os blocos com valor e o "lido às
   HH:MM". Se o retrato for de mais de 3 h, aparece o aviso de desatualizado.
3. **Given** um ponto lançado para a VixCard com prazo vencido, **Then** ele aparece no bloco VixCard marcado como
   atrasado, e a regra de tarefa vencida o considera.
4. **Given** outra conta, **Then** não vê nenhum ponto nem número desta.

### User Story 3 - Regras em Configurações (Priority: P2)

As regras e a política de aviso ficam em Secretário → Configurações, numa seção "Avisos de acompanhamento".
Acompanhamentos não mostra mais o formulário.

**Acceptance Scenarios**:

1. **Given** Configurações, **When** ele muda o formulário de regras e salva, **Then** os valores valem, e
   Acompanhamentos não tem mais o formulário, só um link "Regras dos avisos".
2. **Given** o menu, **Then** Memória não aparece, e "o que combinamos com o cliente X?" no chat continua
   respondendo.

### User Story 4 - Minha agenda em calendário (Priority: P1)

As visões são Dia, Semana (padrão) e Mês, com Hoje, Anterior e Próximo. Os eventos aparecem como blocos na grade de
horas, coloridos pela origem (Google, Secretário, Agenda do Matriz). Os de dia inteiro ficam numa faixa acima da
grade, e os conflitos ganham marca. A linha do "agora" aparece no dia de hoje.

**Acceptance Scenarios**:

1. **Given** eventos às 09:00 e às 14:30, **When** ele abre a semana, **Then** os blocos ficam na altura das horas
   certas.
2. **Given** a visão Mês, **Then** cada dia mostra até 3 eventos e "+N".
3. **Given** o Google desconectado, **Then** o aviso continua, e o calendário mostra o que há nas outras fontes.

### User Story 5 - Caixas de e-mail do Gestor e não lidos (Priority: P1)

As três caixas do Felipe no Gestor são copiadas para o Secretário dele, com a mesma configuração de IMAP. A senha
vai de cofre para cofre: o Gestor decifra só dentro do container dele, e o Matriz cifra com a chave dele. Nada é
impresso nem fica em arquivo depois da cópia. A leitura passa a trazer a marca de lido de cada mensagem, ainda só
com EXAMINE e sem alterar nada.

**Acceptance Scenarios**:

1. **Given** as 3 caixas no Gestor, **When** a cópia roda, **Then** surgem 3 caixas no Secretário do Felipe, que
   testam a conexão e leem.
2. **Given** uma mensagem não lida no servidor, **Then** ela conta como não lida no Secretário. Lida em outro
   lugar, na próxima leitura (até 10 min) deixa de contar.
3. **Given** a leitura, **Then** a caixa continua sem nenhuma marca alterada.

### Edge Cases

- **Fonte externa lenta**: o tempo de espera é de 8 s por fonte, e uma fonte não atrasa as outras.
- **Retrato do Gestor ausente**: o bloco Grupo Soluções diz isso, sem zerar números.
- **Caixa do Gestor com OAuth em vez de senha**: não é copiada, e a resposta diz qual ficou de fora.
- **Memória fora do menu** (FR-009): a tela some do menu, mas as perguntas pelo chat continuam ("o que ficou decidido
  ontem?").
- **Conta dona**: pontos, números, caixas e não lidos valem só para a conta dona. Os dados públicos ("Lá fora") são
  os mesmos para todos e não levam nada da conta.

## Requirements

### Functional Requirements

- **FR-001**: O sistema MUST buscar a cada 30 min, sem chave e sem IA, os dados do Meu Dia, cada um com a fonte e o
  horário:
  - dólar: AwesomeAPI USD-BRL;
  - clima de Vitória-ES: Open-Meteo;
  - Flamengo: RSS do ge;
  - Brasil e Mundo: RSS do g1.
- **FR-002**: Dado com mais de 6 h MUST NOT aparecer como atual. Títulos MUST ser texto puro, e links MUST ser https.
- **FR-003**: Acompanhamentos MUST mostrar estes números do dia, cada um com link:
  - compromissos de hoje;
  - e-mails não lidos dos últimos 3 dias;
  - tarefas vencidas;
  - alertas ativos.
- **FR-004**: Acompanhamentos MUST mostrar o bloco Grupo Soluções (retrato do Gestor, com valores e a hora da
  leitura) e o bloco VixCard (pontos lançados à mão, com empresa, prazo e prioridade).
- **FR-005**: O formulário de regras MUST ficar em Secretário → Configurações.
- **FR-006**: Minha agenda MUST oferecer as visões Dia, Semana e Mês em grade de horas, com navegação, origem por
  cor, faixa de dia inteiro, marca de conflito e linha do agora.
- **FR-007**: As caixas do Gestor MUST ser copiadas para a conta do Felipe sem a senha aparecer em saída, log ou
  arquivo que permaneça.
- **FR-008**: A leitura de e-mail MUST guardar a marca de lido (FLAGS por FETCH, sem STORE) e atualizá-la nas
  mensagens dos últimos 3 dias.
- **FR-009**: A Memória fora do menu: a tela MUST sair do menu, e as perguntas pelo chat MUST continuar.
- **FR-010**: Tudo MUST valer só para a conta dona. Os dados públicos do Meu Dia são iguais para todos e não levam
  nada da conta.

## Success Criteria

### Measurable Outcomes

- **SC-001**: O Meu Dia abre em menos de 1 s, porque os dados externos vêm do cache, nunca de uma busca na hora.
- **SC-002**: As 3 caixas são copiadas, e a senha não aparece em nenhuma saída nem em nenhum arquivo.
- **SC-003**: O número de não lidos confere com o webmail das caixas.

## Delta Requirements

**Skip**: feature nova sobre as Etapas 1 a 7; não há `docs/specs/current/` neste repositório. — Claude (pedido do
Felipe), 2026-10-17
