# Tasks: Tarefas de desenvolvimento

> feature: tarefas-desenvolvimento

<!--
  A ordem aqui é a ordem de execução: esta feature é uma fatia vertical e as
  partes se empilham (o serviço de fluxo precisa do model; as rotas precisam
  do controller; o card e o formulário são incluídos pela tela do quadro).
  Por isso as tarefas compartilham `app/Http/Controllers/TarefaController.php`
  e `resources/views/tarefas/index.blade.php` de propósito — é o que impede o
  planejador de mandá-las para faixas paralelas que quebrariam entre si.
-->

## T-058 — Estrutura de dados: tarefa, evento de etapa e relatório de teste [concluida]
- Refs: US-036, AC-083, AC-084, AC-091
- Arquivos: database/migrations/2026_08_10_090000_criar_tarefas_desenvolvimento.php, app/Models/Tarefa.php, app/Models/TarefaEvento.php, app/Models/TarefaRelatorioTeste.php, database/factories/TarefaFactory.php, tests/Feature/TarefasDesenvolvimento/EstruturaTarefaTest.php
- Notas: três tabelas — `tarefas` (título, resumo, detalhes, sistema_id nulo,
  responsavel_id nulo, criado_por_id, prioridade, status, iniciada_em, soft
  delete), `tarefa_eventos` (de_status, para_status, motivo, entrou_em,
  saiu_em, duracao_segundos) e `tarefa_relatorios_teste` (aprovado, notas).
  Constantes `Tarefa::STATUS` e `Tarefa::PRIORIDADES` no padrão de
  `Lead::ESTAGIOS`. Status inicial decidido no model: sem responsável nasce
  `aberta`, com responsável nasce `backlog`. Base de tudo.
- Esforço: alto

## T-059 — Motor do fluxo: transições permitidas, exigências e tempo por etapa [concluida]
- Refs: US-037, US-038, AC-085, AC-086, AC-087, AC-088, AC-089, AC-090, AC-091
- Arquivos: app/Services/FluxoTarefaService.php, tests/Feature/TarefasDesenvolvimento/FluxoTarefaTest.php
- Notas: porta `assertTransitionAllowed` + `applyStatusChange` do
  `lib/task-store.ts` do alfadev. Mapa de transições permitidas; recusa com
  exceção de domínio quando falta responsável (aberta→backlog), motivo
  (→ajustes, →cancelada) ou relatório de teste aprovado (→concluída);
  cancelada não sai de lugar nenhum. Cada mudança fecha o evento aberto
  (saiu_em + duracao_segundos) e abre o próximo. Depende de T-058.
- Esforço: alto

## T-060 — Quadro no ar: permissão `tarefas`, rotas, controller e a tela com as colunas [concluida]
- Refs: US-036, US-039, AC-081, AC-082, AC-095
- Arquivos: database/seeders/PerfilPermissaoSeeder.php, routes/web.php, app/Http/Controllers/TarefaController.php, resources/views/tarefas/index.blade.php, tests/Feature/TarefasDesenvolvimento/QuadroTest.php, tests/Feature/TarefasDesenvolvimento/AcessoTarefasTest.php
- Notas: recurso `tarefas` novo no seeder de permissões (só perfis da matriz
  recebem); rotas `tarefas.index`, `tarefas.store`, `tarefas.update`,
  `tarefas.mover` e `tarefas.historico` sob `permissao:tarefas`, com 403 para
  quem tem `temEscopoDeRevenda()` mesmo tendo a permissão no perfil. A tela
  monta as sete colunas do ciclo na ordem, cada uma com a contagem;
  o layout espelha `resources/views/leads/index.blade.php` (altura da janela,
  colunas de largura fixa, sombras de borda) e o Alpine do quadro vai inline
  na própria view, como o `funil`. Permissão, rota, controller e tela andam
  juntos porque nenhum deles se sustenta (nem se testa) sem os outros.
  Depende de T-058 e T-059.
- Esforço: alto

## T-061 — Grupo "Desenvolvimento" no menu lateral [concluida]
- Refs: US-036, US-039, AC-081, AC-094
- Arquivos: resources/views/layouts/navigation.blade.php, tests/Feature/TarefasDesenvolvimento/MenuDesenvolvimentoTest.php
- Notas: grupo novo entre "Financeiro" e "Sistema", com o item Tarefas
  (`route: tarefas.index`, `pattern: tarefas.*`, ícone `view-grid`) marcado
  `'matriz' => true` — é a flag que já tira o item do menu de quem tem escopo
  de revenda. Depende de T-060 (a rota precisa existir).

## T-062 — Card da tarefa: sistema, prioridade, tempo na etapa e destaque de esquecida [concluida]
- Refs: US-036, US-038, AC-084, AC-092, AC-093
- Arquivos: resources/views/tarefas/_card.blade.php, resources/views/tarefas/index.blade.php, tests/Feature/TarefasDesenvolvimento/CardTarefaTest.php
- Notas: porta `timeInCurrentColumn` e `ticketAgingLevel` do `board-utils.ts`
  para métodos do model — tempo curto ("agora", "3h", "2d") e destaque de
  atenção a partir de 24h / crítico a partir de 48h, só em Aberta e Em testes.
  Prioridade em `x-badge` com os tons já existentes. Depende de T-060.

## T-063 — Criar e editar tarefa: sistema, responsável e prioridade [concluida]
- Refs: US-036, AC-083, AC-084
- Arquivos: resources/views/tarefas/_form.blade.php, resources/views/tarefas/index.blade.php, app/Http/Controllers/TarefaController.php, tests/Feature/TarefasDesenvolvimento/FormularioTarefaTest.php
- Notas: modal no padrão do `x-modal` já usado em Leads. Select de sistema
  oferece só sistemas ativos e aceita vazio; select de responsável lista
  usuários da matriz (sem escopo de revenda). Salvar sem responsável deixa a
  tarefa em Aberta. Depende de T-060.

## T-064 — Mover card: arrastar, menu "Mover" e confirmação com motivo ou notas de teste [concluida]
- Refs: US-037, AC-085, AC-086, AC-087, AC-088, AC-089, AC-090
- Arquivos: resources/views/tarefas/_mover.blade.php, resources/views/tarefas/index.blade.php, app/Http/Controllers/TarefaController.php, tests/Feature/TarefasDesenvolvimento/MoverTarefaTest.php
- Notas: arrastar como no Funil de Vendas, e o menu "Mover ▾" no card como
  caminho acessível — obrigatório nas transições que pedem texto (ajustes,
  cancelamento, conclusão com relatório de teste). O erro devolvido pelo motor
  do fluxo aparece como aviso na tela e o card não sai do lugar. Depende de
  T-059 e T-062.
- Esforço: alto

## T-065 — Quadro enxuto e histórico inteiro: recorte de 30 dias e listagem sem filtro [concluida]
- Refs: US-040, AC-096, AC-097
- Arquivos: resources/views/tarefas/historico.blade.php, app/Http/Controllers/TarefaController.php, tests/Feature/TarefasDesenvolvimento/HistoricoTarefasTest.php
- Notas: as colunas Concluída e Cancelada do quadro passam a trazer só os
  últimos 30 dias, avisando quantas ficaram fora; o link no cabeçalho leva ao
  histórico completo, em `x-tabela`, com sistema, responsável, etapa final e
  data, sem nenhum recorte de período — é o caminho de auditoria. Depende de
  T-060.

<!--
  Emenda de 2026-08-10, depois de olhar a tela rodando: três defeitos que
  passaram pelo gate porque a especificação não tinha critério de aceite para
  eles. O primeiro é o grave — a rota respondia e o teste provava a rota, mas
  a tela que leva até ela estava morta.
-->

## T-073 — Menu "Mover" volta a oferecer os destinos permitidos [concluida]
- Refs: US-037, AC-122
- Arquivos: resources/views/tarefas/_mover.blade.php, tests/Feature/TarefasDesenvolvimento/MoverTarefaTest.php
- Notas: a correção do `x-data` JÁ ESTÁ APLICADA na árvore de trabalho
  (`@json` dentro de atributo HTML fechava o atributo na primeira aspa dupla,
  o Alpine não avaliava e o `x-for` do select nunca renderizava opção nenhuma;
  trocado por `Illuminate\Support\Js::from`, como em `clientes/_form.blade.php`).
  Falta o TESTE DE REGRESSÃO: renderizar o quadro e assertar que o HTML do
  card em Em testes traz os três destinos permitidos no menu, que o card em
  Cancelada não traz menu, e — o que pega a causa raiz — que o atributo
  `x-data` do bloco de mover não sai truncado. Sem essa última asserção o
  mesmo bug volta na próxima edição da view.
- Esforço: alto

## T-074 — Prioridade Crítica, o quarto nível do ciclo [concluida]
- Refs: US-036, AC-123
- Arquivos: database/migrations/2026_08_10_140000_adicionar_prioridade_critica.php, app/Models/Tarefa.php, resources/views/tarefas/_card.blade.php, tests/Feature/TarefasDesenvolvimento/PrioridadeCriticaTest.php
- Notas: o alfadev tem quatro níveis e só três foram portados. Migration para
  ampliar o enum `prioridade` de `['baixa','media','alta']` para incluir
  `critica` (e a volta atrás no `down`), mais a constante `Tarefa::PRIORIDADES`.
  No card, o mapa de tons está em `_card.blade.php:31` e hoje pinta ALTA de
  vermelho; com quatro níveis o alinhamento com o alfadev é baixa=neutro,
  media=neutro, alta=atencao (âmbar) e critica=critico (vermelho) — os tons
  do `x-badge` são bom|atencao|critico|marca|neutro. O `_form.blade.php` lê a
  constante e não precisa de alteração.
- Esforço: alto

## T-075 — Devolver do Backlog para Aberta, soltando o responsável [concluida]
- Refs: US-037, AC-124
- Arquivos: app/Services/FluxoTarefaService.php, tests/Feature/TarefasDesenvolvimento/FluxoTarefaTest.php
- Notas: o alfadev permite `BACKLOG → ABERTA` e, ao fazer isso, limpa o
  responsável (`fromStatus === 'BACKLOG' && toStatus === 'ABERTA'` zera o
  assignee) — é como se desdireciona um ticket. Aqui a transição não existe.
  Acrescentar ao mapa de transições permitidas e soltar o `responsavel_id` na
  volta, sem mexer nas outras regras.

## T-076 — Caminho para o histórico e escala de prioridade legível [concluida]
- Refs: US-036, US-040, AC-125, AC-126
- Arquivos: app/Http/Controllers/TarefaController.php, resources/views/tarefas/index.blade.php, resources/views/tarefas/_card.blade.php, tests/Feature/TarefasDesenvolvimento/HistoricoTarefasTest.php, tests/Feature/TarefasDesenvolvimento/CardTarefaTest.php
- Notas: três defeitos vistos na tela rodando. (1) O quadro não tem link
  nenhum para o histórico — a rota existe e o teste do AC-097 a exercita, mas
  o caminho humano nunca foi construído. (2) O controller enfia o aviso do
  recorte dentro do próprio `label` da coluna, que trunca em 276px e come
  justamente o número; separar em `ocultas` e renderizar em linha própria no
  cabeçalho, no padrão do Funil de Vendas, virando o link do item (1).
  (3) `_card.blade.php:31` mapeia `baixa` e `media` para o mesmo tom `neutro`,
  então dois dos quatro níveis são indistinguíveis; a escala do `x-badge`
  (`neutro < marca < atencao < critico`) cobre os quatro.

## T-077 — Cor da etapa na coluna, no padrão do Funil de Vendas [concluida]
- Refs: US-036, AC-127
- Arquivos: app/Http/Controllers/TarefaController.php, resources/views/tarefas/index.blade.php, tests/Feature/TarefasDesenvolvimento/QuadroTest.php
- Notas: o quadro nasceu monocromático — `style="width: 276px"` sem faixa e o
  contador em `bg-chip text-ink-dim` — enquanto o Funil (`leads/index.blade.php:74`)
  pinta a coluna com `border-top: 3px solid rgb(var(--cor))`, um ponto de 7px e o
  contador tingido com `var(--tint-alpha)`. Trazer o mesmo padrão: `cor` por
  etapa vinda do controller (aberta/backlog=accent, em_desenvolvimento/em_testes=brand,
  ajustes_necessarios=warn, concluida=good, cancelada=neutro/line — terminal sem
  valor não merece destaque). A borda do CARD não muda: ela continua sendo o
  canal do aviso de esquecida (AC-093), e duplicar o status ali roubaria o
  único sinal que não está dito em outro lugar.

## T-078 — Ordem dentro da coluna: prioridade primeiro, depois o que está mais parado [concluida]
- Refs: US-036, AC-128
- Arquivos: app/Http/Controllers/TarefaController.php, tests/Feature/TarefasDesenvolvimento/QuadroTest.php
- Notas: hoje o controller ordena só por `created_at desc`, então uma crítica
  antiga afunda embaixo de tarefas baixas recentes — o que anula na prática a
  prioridade. Ordenar por gravidade (critica > alta > media > baixa) e, no
  empate, pela entrada na etapa atual (mais antiga primeiro), usando o mesmo
  critério que o card exibe no chip de tempo — se a ordem discordasse do que
  o chip mostra, a lista pareceria embaralhada. Isso exige carregar `eventos`
  junto (o card já os acessa hoje, um por card: some também um N+1).

## T-079 — Card conta o resumo e assume a falta de responsável [concluida]
- Refs: US-036, AC-129, AC-130
- Arquivos: resources/views/tarefas/_card.blade.php, tests/Feature/TarefasDesenvolvimento/CardTarefaTest.php
- Notas: dois buracos vistos com a tela rodando. (1) `resumo` é gravado pelo
  formulário e não aparece em lugar nenhum — dado que se preenche e nunca se
  lê é pior que campo ausente; entra como uma linha sob o título, truncada,
  e some quando vazio. (2) A ausência de responsável hoje é lida por
  comparação com os cards vizinhos; a linha de metadados passa a ter sempre
  os dois segmentos, dizendo "sem responsável" quando for o caso — é
  justamente a fila que pede triagem (a regra `aberta → backlog` trava nela).
  Sem cor de alarme: a borda do card já é o canal do aviso de esquecida e
  encher a coluna Aberta de âmbar apagaria esse sinal.

## T-080 — O quadro é o trabalho em curso; o encerrado vive no histórico [concluida]
- Refs: US-036, US-040, AC-082, AC-096, AC-125, AC-131
- Arquivos: app/Http/Controllers/TarefaController.php, resources/views/tarefas/index.blade.php, resources/views/tarefas/historico.blade.php, tests/Feature/TarefasDesenvolvimento/QuadroTest.php, tests/Feature/TarefasDesenvolvimento/HistoricoTarefasTest.php
- Notas: sete colunas não cabiam em 1568px e as duas terminais eram justamente
  as de menor valor no dia a dia. Em vez de recolher (protótipo descartado: a
  faixa recolhida virava um retângulo alto e vazio), o quadro passa a mostrar
  só as cinco etapas do trabalho em curso. Com isso o recorte de 30 dias fica
  sem função e sai — encerrou, saiu do quadro.
  CUIDADO: reabrir uma tarefa concluída só existia pelo menu "Mover" do card
  no quadro. Sem a coluna Concluída, esse caminho desaparece e o AC-090 fica
  sem porta — por isso o histórico ganha a ação de reabrir (AC-131), que
  chama a mesma rota `tarefas.mover` e o mesmo motor de fluxo. Cancelada não
  ganha ação nenhuma: ela não tem saída no mapa de transições.

## T-081 — Quadro sem sobra à direita e histórico que conta o custo da tarefa [concluida]
- Refs: US-036, US-040, AC-132, AC-133
- Arquivos: app/Models/Tarefa.php, app/Http/Controllers/TarefaController.php, resources/views/tarefas/index.blade.php, resources/views/tarefas/historico.blade.php, resources/views/tarefas/_card.blade.php, tests/Feature/TarefasDesenvolvimento/QuadroTest.php, tests/Feature/TarefasDesenvolvimento/HistoricoTarefasTest.php
- Notas: (AC-132) as colunas são `width: 276px` fixo; com cinco delas sobra uma
  faixa vazia à direita do quadro. Trocar por `flex: 1 1 276px` com
  `min-width: 276px` — crescem quando há espaço, mantêm a largura de leitura
  quando não há, e o `overflow-x` do quadro continua valendo.
  (AC-133) o histórico hoje é título, sistema, responsável, etapa final e data
  — não diz nada do que a tarefa custou, sendo que o custo é justamente o que
  os eventos por etapa medem. Acrescentar prioridade, resumo e a duração do
  ciclo (da criação até a entrada na etapa terminal). A formatação curta de
  duração já existe embutida no `_card.blade.php`; vira `Tarefa::duracaoCurta()`
  para os dois usarem a mesma régua.

## T-083 — Comentários na tarefa [concluida]
- Refs: US-049, AC-134, AC-135, AC-136, AC-095
- Arquivos: database/migrations/2026_08_11_100000_criar_comentarios_de_tarefa.php, app/Models/TarefaComentario.php, app/Models/Tarefa.php, app/Http/Controllers/TarefaController.php, routes/web.php, resources/views/tarefas/_comentarios.blade.php, resources/views/tarefas/index.blade.php, resources/views/tarefas/_card.blade.php, resources/views/tarefas/historico.blade.php, tests/Feature/TarefasDesenvolvimento/ComentariosTarefaTest.php
- Notas: `detalhes` já existe na tabela e nunca teve campo na tela — mas um
  textarea único não serviria aqui: o que falta não é UM texto longo, é o
  registro do que foi sendo dito, datado e assinado. Daí uma tabela própria,
  `tarefa_comentarios` (tarefa, autor anulável, corpo, timestamps), e não uma
  coluna a mais em `tarefas`. Autor anulável de propósito: quem escreveu pode
  sair da empresa, e o porquê de uma decisão é o que a tarefa tem de mais caro.
  O corpo é gravado cru e exibido cru. (Esta tarefa entregou marcadores de
  lista — botões, Enter que continua a lista e conversão na leitura; T-084 os
  retirou por inteiro.)
  Os comentários entram DEPOIS do `_form` dentro do modal, nunca dentro dele:
  são dois envios independentes e formulário aninhado é HTML inválido — o
  comentário viraria campo do cadastro e se perderia no salvar.
  O histórico recebe a mesma partial em modo `somenteLeitura`: auditar um
  cancelamento sem poder ler o que foi dito é ler o resultado sem o motivo.
- Esforço: médio

## T-084 — A busca alcança a conversa, e o comentário para de formatar [concluida]
- Refs: US-049, AC-134, AC-135, ASM-047
- Arquivos: app/Http/Controllers/TarefaController.php, app/Models/TarefaComentario.php, resources/views/tarefas/_comentarios.blade.php, tests/Feature/TarefasDesenvolvimento/FiltrosTarefasTest.php, tests/Feature/TarefasDesenvolvimento/ComentariosTarefaTest.php
- Notas: duas correções de rumo depois de a conversa entrar no ar.
  (1) A busca varria título, resumo e detalhes — os três campos preenchidos no
  nascimento da tarefa. Só que, desde T-083, o assunto CONTINUA no comentário:
  o segundo chamado do mesmo cliente é escrito lá, e quem digitasse esse número
  recebia tela vazia. Entra um `orWhereHas('comentarios')` DENTRO do `where`
  aninhado da busca — fora dele, o `orWhere` escaparia do recorte de status e o
  quadro voltaria a mostrar tarefa encerrada.
  (2) Os marcadores de lista saem por inteiro (decisão do dono do produto,
  2026-08-11): botões, continuação no Enter e a conversão na leitura. Com eles
  vai embora `marcadoresEmHtml()` e, com ela, o `{!! !!}` da tela — o corpo
  passa a sair pelo escape normal do Blade, com `whitespace-pre-line` guardando
  as quebras de linha. Não sobra marcação para auditar nem sanitizador para
  manter; o traço digitado continua traço.
- Esforço: baixo

## T-085 — Um botão só no modal: o comentário vai junto no Salvar [concluida]
- Refs: US-049, AC-134, AC-136
- Arquivos: app/Http/Controllers/TarefaController.php, routes/web.php, resources/views/tarefas/_form.blade.php, resources/views/tarefas/_comentarios.blade.php, resources/views/tarefas/_comentarios-remocao.blade.php, resources/views/tarefas/index.blade.php, resources/views/tarefas/historico.blade.php, tests/Feature/TarefasDesenvolvimento/ComentariosTarefaTest.php
- Notas: o modal tinha dois botões de envio — "Salvar" no meio da tela e
  "Comentar" embaixo — porque a conversa era um formulário separado, montado
  DEPOIS do formulário da tarefa. Para quem edita, é uma passada só na tarefa:
  o comentário vira mais um campo do cadastro (`comentario`, anulável) e o
  Salvar publica os dois. Campo em branco não publica nada — é o caso comum de
  quem abriu o modal só para trocar o responsável. Some a rota
  `tarefas.comentarios.store` junto com o método `comentar()`: sem botão, era
  código morto.
  CUIDADO: com a conversa DENTRO do formulário, os formulários de apagar não
  cabem mais ali — formulário aninhado é HTML inválido e o navegador descarta o
  interno. Os botões do lixo passam a apontar, pelo atributo `form`, para os
  formulários que `_comentarios-remocao` monta depois do fechamento do
  formulário da tarefa, ainda dentro do modal. O teste assere o PAR (o `form=`
  do botão e o `id=` do formulário): sem ele, o lixo não apaga nada e a falha é
  silenciosa na tela.
  Apagar segue com envio próprio de propósito: é destrutivo, e ir de carona no
  salvar faria o clique no lixo publicar o comentário que estivesse escrito no
  campo.
- Esforço: baixo
