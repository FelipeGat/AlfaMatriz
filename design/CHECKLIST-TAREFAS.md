# Checklist de aceite — tela de Tarefas

Marque cada item com `[x]` e diga onde no código ele foi implementado. Item não marcado = tela incompleta.
**Não reporte a tela como pronta com qualquer item em aberto.**

Referência de comportamento: abra `design/AlfaMatriz Tarefas.dc.html` e clique em **Simular**.
Referência de valores: `design/TAREFAS-SPEC.md`.

## A. Colunas

- [ ] 6 colunas, nesta ordem: Aberta · Backlog · Em andamento · Em revisão · Em staging · Pronta p/ produção
- [ ] Cada coluna tem borda superior de 3px na cor da etapa
- [ ] Em revisão / Em staging / Pronta mostram o portão no cabeçalho ("PR · admin analisa", "na main · dev valida", "fila do admin · tag v*")
- [ ] Em andamento, Em revisão e Em staging mostram contador `N/3` (limite de WIP)
- [ ] Contador vira âmbar + "acima do limite de 3" quando estoura
- [ ] **Tarefa bloqueada NÃO conta no WIP**
- [ ] Coluna Aberta mostra "N aguardando triagem" quando há prioridade `nao_definida`
- [ ] Colunas recolhem e expandem (42px quando recolhida, rótulo vertical)
- [ ] Aberta e Backlog têm campo de criação rápida no rodapé, criando com Enter
- [ ] Coluna vazia mostra texto próprio; sob filtro mostra "Nada no recorte"

## B. Card — estados visuais

Cada um destes tem que ser visível na tela. Se o seed não produz o estado, crie o dado.

- [ ] Prioridade: 5 selos distintos, incluindo **A definir** em âmbar
- [ ] Selo "Oper." em tarefa operacional
- [ ] Avatar do responsável com iniciais
- [ ] **Sem responsável: círculo tracejado**, sem iniciais
- [ ] Selo de tempo na etapa
- [ ] **Envelhecido:** selo de tempo em âmbar acima do limiar, vermelho acima do dobro
- [ ] Selo de checklist `3/5`, verde quando tudo feito
- [ ] Selo de comentários com contagem
- [ ] Borda do card seguindo a precedência do TAREFAS-SPEC.md
- [ ] Card em arraste: opacidade 0.5 + sombra maior

## C. Tarjas dentro do card

- [ ] **Bloqueio:** tarja âmbar, motivo em 2 linhas, tempo travado, botão Destravar funcionando
- [ ] **Retorno:** tarja âmbar nomeando o portão — "Voltou da revisão" / "Voltou do staging" / "Voltou da porta da produção" — com o motivo
- [ ] Marca de retorno **desaparece** quando a tarefa anda para frente
- [ ] **Pergunta:** tarja na cor da marca (NÃO âmbar), com "Aguardando resposta", tempo, **nome em linha própria** e selo de rodada
- [ ] Selo de rodada fica **vermelho na 3ª** + "considere devolver para correção"

## D. Perguntas — comportamento

- [ ] Botão **Perguntar** no detalhe: publica o comentário e passa a vez
- [ ] Ponteiro aponta sempre para o outro lado; se a tarefa é sua e ninguém perguntou, o botão não aparece
- [ ] Botão **Responder** na própria tarja do card, abrindo campo inline (sem modal)
- [ ] Responder limpa o ponteiro e devolve a vez
- [ ] **Rodada só incrementa quando a bola estava com quem pergunta.** Perguntar 2× seguidas sem resposta = mesma rodada
- [ ] `rodadas` e `interlocutor` persistidos na TAREFA (responder apaga o ponteiro, não a memória)
- [ ] Chip **"N p/ você"** no cabeçalho do quadro, primeiro da fila, filtrando ao clicar
- [ ] Filtro "Só as que esperam por você"
- [ ] Notificação no sino: "X perguntou em «tarefa» · Nª rodada · aguardando sua resposta há Nh"

## E. Movimentação

- [ ] Arraste entre colunas respeitando o fluxo; colunas inválidas apagam durante o gesto
- [ ] Tentativa inválida recusada **com o motivo dito**
- [ ] Reordenar dentro da coluna, com linha de inserção na posição
- [ ] Menu **Mover ▾** com os destinos válidos, marcando os que pedem motivo
- [ ] Botão de bloquear/destravar no rodapé do card (sempre)
- [ ] Botão de concluir **só onde o fluxo permite**
- [ ] Clique abre o detalhe, mas só se o ponteiro andou menos de 4px e não houve arraste nos últimos 300ms

## F. Painel de motivo

- [ ] Aparece **dentro do card**, não em modal
- [ ] Coluna de destino continua realçada enquanto o motivo não é preenchido
- [ ] Título nomeia a ação ("Movendo para Ajustes"), com × para desistir
- [ ] Uma linha explica **por que** se pede o texto
- [ ] Foco automático no textarea
- [ ] Botão nomeia o resultado ("Bloquear tarefa", "Subiu para produção") — nunca "Confirmar"
- [ ] Botão em largura inteira do painel
- [ ] Botão apagado + "obrigatório" em âmbar enquanto vazio
- [ ] **Texto muda conforme o portão de origem**; vindo do staging avisa que o código já está na main e cita `deploy/voltar.sh`
- [ ] Conclusão pede a versão (`v1.4.2`)
- [ ] **Conclusão de tarefa operacional NÃO pede versão** — diz "Encerrar tarefa"
- [ ] "Validado no staging" na transição Em staging → Pronta p/ produção

## G. Detalhe

- [ ] Título, resumo, tipo, prioridade, sistema, responsável
- [ ] **Checklist:** marcar, editar no lugar, reordenar por arraste, remover, adicionar com Enter, barra de progresso
- [ ] Conversa com comentários existentes e campo novo
- [ ] Comentário publicado junto com o Salvar
- [ ] Banner de pergunta quando houver, com aviso na 3ª rodada
- [ ] Excluir em dois passos, só admin, declarando a diferença em relação a Cancelar

## H. Perfis

- [ ] Membro: prioridade e responsável **somem** do formulário (não desabilitados), com a ausência explicada
- [ ] Membro só move o que já está com ele; recusa nomeia quem está segurando
- [ ] Tarefa criada por membro nasce em Aberta com prioridade `nao_definida`
- [ ] Excluir só admin
- [ ] Membro continua podendo abrir, comentar, bloquear, perguntar e mexer no checklist

## I. Filtros, abas e teclado

- [ ] Busca varrendo título, resumo, sistema, responsável e corpo dos comentários
- [ ] Filtros de tipo, prioridade e situação (travadas / em curso / prontas / esperam por você)
- [ ] "Limpar recorte" aparecendo só quando há recorte
- [ ] Subtítulo dizendo "N de M no quadro" sob recorte
- [ ] Chips no cabeçalho: p/ você · travadas · p/ subir · hoje — todos clicáveis
- [ ] Aba Histórico com desfecho, **versão**, ciclo e Reabrir
- [ ] Raias por Responsável e por Sistema
- [ ] Teclado: `↑↓` `←→` `⇧+←/→` `B` `M` `Enter` `C` `N` `/` `Esc` `?`

## J. Temas e mobile

- [ ] Tema claro e escuro em **todos** os itens acima
- [ ] Vista mobile: uma etapa por vez, tira de chips com contagem, alvos de 44px, mover pelo menu
- [ ] Nenhum texto cortado ou sobreposto em janela de 1000px de largura

## Como reportar

Para cada seção, diga quantos itens ficaram em aberto e por quê. Se algo do checklist contradiz o
TAREFAS-SPEC.md ou o README, **pare e avise** — não escolha por conta própria.
