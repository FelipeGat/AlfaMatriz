# Research: Chats Business

**Feature**: `chats-business` | **Data**: 2026-10-13 | **Spec**: [spec.md](spec.md)

Fontes conferidas no código deste worktree e nas sessões gravadas no WSL em 13/10/2026.

## Decision 1 — O chat de assunto é uma tarefa da Central de outro tipo

**Decision**: o chat de assunto é uma linha de `central_ia_tarefas` com `tipo = 'assunto'`. Não é uma
tabela nova.

**Rationale**: a tarefa já tem tudo o que o chat de assunto precisa:
- histórico (`central_ia_mensagens.tarefa_id`);
- dossiê que sobrevive à troca de agente (`Dossie`);
- fila e uma execução por vez (`AgentesService::proxima`);
- aviso de novidade e pulso (`CockpitService`);
- confirmação, auditoria, delegação, prévias e POPs.

Uma tabela paralela duplicaria esse caminho inteiro e abriria um segundo lugar para o isolamento por
conta falhar (Princípio I). O que muda no tipo `assunto`:
- nunca vai para `concluida` (o `tarefa_concluida` do bloco é ignorado);
- tem grupo;
- o contexto do prompt é montado pela regra da Decision 3.

**Alternatives considered**: tabela `central_ia_assuntos` com mensagens próprias foi rejeitada, porque
duplica fila, Telegram e cockpit. Usar só um `projeto` fictício também foi rejeitado: o projeto é uma
pasta real que o runner valida.

## Decision 2 — A memória é o dossiê, com três acréscimos

**Decision**: a memória do assunto é o `dossie` da tarefa: objetivo, `decisoes`, `pendencias`,
`resumos`. Ele ganha três chaves:
- `jeito_de_fazer`: itens com autor e data;
- `correcoes`: correções e remoções feitas pela pessoa, versionadas;
- `sementes`: de onde veio a memória de partida.

O bloco final do agente ganha o campo opcional `jeito_de_fazer` (lista).

**Rationale**: `Dossie::aplicarConclusao` já acumula decisões com autor e data e já substitui
pendências. Falta só o "como fazer" (FR-005) e a precedência da pessoa (FR-006).

**Alternatives considered**: memória em texto livre foi rejeitada, porque não dá para apagar um item
nem evitar duplicata.

## Decision 3 — Orçamento de contexto do histórico

**Decision**: cada pedido num assunto leva memória, mais condensado, mais parte recente literal, dentro
de um **orçamento de contexto**.
- **Tamanho**: padrão de 60 mil tokens, configurável por conta em `Preferencias` (20 mil a 150 mil).
  Tokens são estimados como caracteres ÷ 3,5, a mesma ordem de grandeza da medição feita nas sessões.
- **Montagem**: a memória vai sempre. A parte recente literal ocupa até 60% do orçamento, das mensagens
  mais novas para as mais antigas.
- **Sem condensado necessário**: se o histórico inteiro cabe, ele vai inteiro (FR-010).
- **Com condensado**: o que não coube e já foi condensado vai como o texto `condensado` (FR-011).

**Rationale**: o pedido "todo o histórico, desde que não gaste todo o uso" pede um teto fixo por
pedido. Sem teto, o custo de cada pedido cresce com a idade do assunto.

**Alternatives considered**:
- Mandar sempre tudo: rejeitado, porque o Gestor.SG sozinho tem ~790 mil tokens de texto.
- Só a memória: rejeitado, porque perde o tom e o detalhe dos últimos pedidos.

## Decision 4 — A condensação roda depois do pedido e não o atrasa

**Decision**: quando o histórico não condensado passa do orçamento, o pedido segue com memória e parte
recente, e a Central enfileira uma execução de finalidade `condensar` depois dele, na mesma tarefa.
- **Modelo**: nível `leve`, o modelo econômico da preferência.
- **Prompt**: o condensado anterior mais as mensagens que saíram da janela.
- **Retorno**: um novo condensado que mantém decisões e pendências.
- **Gravação**: em `dossie.condensado = {ate_mensagem_id, texto, em}`.
- **Na conversa**: aparece como linha de sistema ("histórico condensado: N mensagens, custo X"), não
  como resposta.

**Rationale**: condensar antes do pedido faria a pessoa esperar duas execuções. A decisão que importa
já está na memória (Decision 2), então o pedido não perde nada essencial.

**Alternatives considered**: condensar de forma síncrona foi rejeitado pela latência. Condensar num job
periódico foi rejeitado porque não há scheduler local (Princípio III).

## Decision 5 — Cota perto do fim reduz o contexto

**Decision**: antes de montar o contexto, a Central lê o consumo que a batida já traz:
- Claude: `limites_claude.janelas`, rótulo "Sessão…";
- Codex: `cota_codex.primaria`.

Com `usado_pct ≥ 80` para o agente escolhido, o contexto cai para memória mais as 10 últimas mensagens,
e a conversa avisa (FR-012). O Copilot não informa cota e segue a regra normal.

**Rationale**: `AgentesService::esgotados` já lê esses números para o corte em 100%. O limiar de 80%
deixa margem para o próprio pedido.

## Decision 6 — A semente é feita pelo runner, por partes, com modelo econômico

**Decision**: semear é uma execução de finalidade `semear` com uma lista de sessões por `{agente, id}`.
O servidor nunca envia caminho de arquivo. O runner localiza o arquivo pelo id entre as sessões que
ele mesmo lista; é o mesmo critério de inviolabilidade de `continuarSessao`.

O runner monta um **extrato** da sessão:
- **Entra**: cada pedido da pessoa (até 4 mil caracteres) e o texto final de cada resposta (até 4 mil
  caracteres).
- **Fica de fora**: saídas de ferramenta, `isSidechain` e mensagens `isMeta`.

O extrato é dividido em partes de ~100 mil caracteres. Cada parte vai a um modelo de nível `leve`, que
devolve itens de memória com o bloco da Central. Uma passada final junta e tira duplicatas.

O custo estimado (caracteres do extrato) é medido **antes**. Acima do teto de importação (padrão de
1,5 milhão de caracteres, ~430 mil tokens), a execução para em "aguardando você" com a estimativa, e só
segue com "sim".

**Medido em 13/10/2026**, texto de pedidos mais respostas, antes do corte de 4 mil:

| Sessão | Pedidos | Respostas | Texto | ~tokens |
|---|---|---|---|---|
| Proposta Comercial VixCard (`0c19a881`) | 156 | 462 | ~428 mil caracteres | ~122 mil |
| Gestor.SG (`bc6adf44`) | 521 | 3.259 | ~2,77 milhões de caracteres | ~790 mil |
| Gestor.SG (`aa8ff7fc`) | 4 | 76 | ~67 mil caracteres | ~19 mil |

Com o corte de 4 mil, o Gestor.SG cai abaixo disso. O número exato sai da estimativa da própria
execução, que é mostrada antes.

**Rationale**:
- O arquivo bruto tem até 119 MB, quase tudo saída de ferramenta.
- O modelo econômico é a regra de custo da Central.
- Dividir em partes cabe em qualquer CLI.
- Os segredos passam por `ProtecaoDeSegredos` no servidor antes de gravar (FR-015).
- O prompt de extração manda registrar só o que está no texto (FR-016).

**Alternatives considered**:
- Enviar o arquivo inteiro ao agente: rejeitado por custo e por tamanho.
- Ler a sessão no servidor: rejeitado, porque o arquivo só existe na máquina (Princípio II).

## Decision 7 — Nome das sessões vem do próprio arquivo

**Decision**: o runner (`uso-local.mjs`) passa a informar `titulo` em cada sessão, nesta ordem:
1. o último `customTitle`, que é o nome dado pela pessoa;
2. o último `aiTitle`;
3. o primeiro pedido, cortado em 80 caracteres.

Nas sessões do Claude, `customTitle` e `aiTitle` estão nas linhas do `.jsonl`. Isso foi conferido em
13/10/2026: "Gestor.SG", "Proposta Comercial VixCard" e o `aiTitle` "Operador comercial VixCard".
Codex e Copilot não têm o campo e usam o primeiro pedido.

**Grupos do VS Code**: os grupos do print não estão nos arquivos do WSL e não são lidos. O grupo é
sugerido pela regra que a Central já usa para projetos (nome Vix* vai para VixCard, o resto para Alfa
Tecnologia). A pessoa troca à vontade.

## Decision 8 — Cópia única do Mac, empurrada pelo Mac

**Decision**: o WSL não tem acesso SSH ao Mac (`~/.ssh/config` não tem host do Mac). O Mac alcança o
WSL (memória `acesso-mac-wsl-tunel-e-boot`). Por isso a pessoa roda **no Mac** um comando, deixado
pronto no quickstart, que copia as duas sessões para o WSL em
`~/.local/share/alfa/central-ia-importadas/`.

O runner lista os arquivos dessa pasta como sessões de origem "Mac (cópia)" e apaga cada um depois de
a semente concluir (FR-020).

**Alternatives considered**: um runner leitor permanente no Mac foi descartado pelo dono em 13/10/2026.

## Decision 9 — Interface: aba "Business" que reaproveita a conversa

**Decision**: nova aba de topo **Business** na Central, entre "Chats Ativos" e "Projetos".
- **À esquerda**: os chats de assunto agrupados e recolhíveis, como em Projetos, com pulso e novidade
  como em Chats Ativos. Mais dois botões: "+ Novo assunto" e "Trazer do VS Code".
- **À direita**: o mesmo painel de conversa da Central, com as abas Chat e Memória.

**Rationale**: reaproveita o composer, as bolhas, o "aguardando" e as prévias. Uma conversa e um jeito
de pedir só (SC-005).

## Decision 10 — Telegram aponta para um assunto

**Decision**: o comando `/assunto <nome>` aponta o vínculo para um chat de assunto (coluna nova no
vínculo). `/assunto` sozinho lista os chats, e `/geral` limpa o ponteiro. O que entra pelo Telegram
cai na mesma tarefa (FR-019).

## Decision 11 — Correção do critério SC-003

**Decision**: o SC-003 da spec ("no máximo 30% acima de um chat vazio") não é compatível com "todo o
histórico, desde que não gaste todo o uso". Um assunto com 60 mil tokens de histórico custa
naturalmente mais que um vazio. O critério passa a ser: **o contexto enviado por pedido nunca passa do
orçamento configurado, qualquer que seja o tamanho do histórico**.
