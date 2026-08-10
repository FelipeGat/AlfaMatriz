# Tasks: Redesign visual do painel

> feature: redesign-visual

<!--
  Ordem de dependência (o plano de execução respeita isto):
  BASE      T-031 → T-032 → T-033   (uma após a outra, é a fundação)
  TELAS     T-034 .. T-045          (paralelizáveis entre si, depois da base)
  Status: pendente | em-andamento | concluida
-->

## Contrato compartilhado — leia antes de qualquer tarefa de tela

**A referência de design é `Assets/design_handoff_alfamatriz_redesign/`.** Leia
o `README.md` de lá (seção da sua tela + "Armadilhas já encontradas") e, se
precisar do detalhe fino, o `AlfaMatriz Sistema.dc.html` — onde os dois
divergirem, **o HTML manda**. Os protótipos são referência visual, **não código
para copiar**: estilo inline vira classe Tailwind, interação vira Alpine, e a
lista vem do controller que já existe.

**A fundação (T-031, T-032, T-033) já está pronta e commitada. Use-a; não
reinvente:**

- **Cor, tipografia e raio saem de token.** Nenhum hexadecimal em `.blade.php`
  — há teste varrendo isso (`TokensTest`). Classes: `bg-canvas`, `bg-panel`,
  `bg-head`, `bg-subtle`, `bg-surface`, `bg-chip`, `bg-board`, `bg-card-grad`,
  `border-line`, `border-rule`, `border-btn-line`, `text-ink`, `text-ink-dim`,
  `text-ink-mute`, `text-ink-faint`, `text-brand-text`, `bg-brand`, `text-good`,
  `text-warn`, `text-crit`, `bg-good-tint`, `bg-warn-tint`, `bg-crit-tint`,
  `bg-bar-track`. Precisa de alpha própria? `rgb(var(--warn) / 0.4)` em `style`.
- **Tipografia:** `font-display` (Space Grotesk) em título de painel e número
  grande; `font-sans` (Geist) no corpo; `font-mono` (Geist Mono) em caixa alta,
  número de tabela, data, delta e eixo. Número de destaque leva a classe
  `tabular`.
- **Raio:** `rounded-panel` (8px) painel/card, `rounded-control` (6) botão e
  input, `rounded-ctl` (5) controle pequeno, `rounded-tile` (4) tile de ícone e
  ação de tabela, `rounded-badge` (3) badge e chip.
- **Componentes prontos** (`resources/views/components/`): `<x-tabela>`,
  `<x-linha-total>`, `<x-painel>`, `<x-kpi-card>`, `<x-sparkline>`,
  `<x-badge>`, `<x-faixa-segmentada>`, `<x-acao-tabela>`, `<x-bar-chart>`,
  `<x-nav-icon>`. **Toda `<table>` passa por `<x-tabela>`** (o teste reprova
  `<table>` solta numa tela) e **toda linha de totais por `<x-linha-total>`**.
- **Moldura:** `<x-app-layout>` com os slots novos `titulo`, `contexto` e
  `acoes` — o slot `header` antigo ainda funciona, mas telas migradas usam os
  novos.
- **Grid de cards** sempre `repeat(auto-fit, minmax(200–250px, 1fr))`, nunca
  `grid-cols-N` fixo — senão os números quebram no meio em janela estreita.
- **Regra de negócio não muda.** Nenhuma rota, tabela ou migration nova. O
  controller pode ganhar agregação/série para alimentar a tela, sempre a partir
  do que já está no banco.
- **Teste:** cada critério de aceite da sua tarefa vira um método com
  `@spec:AC-xxx` no docblock, em `tests/Feature/Redesign/`. Rode
  `php tools/onp-spec-tap.php` até passar. Teste pulado não é prova.

## T-031 — Fundação visual: tokens dos dois temas e tipografia [concluida]
- Refs: US-016, AC-032, AC-033, AC-034
- Arquivos: tailwind.config.js, resources/css/app.css, tests/Feature/Redesign/TokensTest.php
- Esforço: alto
- Notas: Declara os tokens do handoff como CSS custom properties em
  `:root` (tema escuro) e `.theme-light` (tema claro), e expõe cada um ao
  Tailwind via `rgb(var(--…))`. Tokens novos além dos atuais: `rule`,
  `ruleStrong`, `chip`, `card-grad`, `board`, `inkFaint`, `btnBorder`,
  `barTrack`, `glow`, `navActiveBg`, tints de status. Escala de raio aninhada
  (8/6/5/4/3), grade de 4px, barra de rolagem customizada. Carrega Geist e
  Geist Mono além da Space Grotesk. **Primeira tarefa — tudo depende dela.**

## T-032 — Shell: sidebar com rail, topbar, alternador de tema e marca [concluida]
- Refs: US-017, AC-035, AC-036, AC-037, AC-038, AC-061
- Arquivos: resources/views/layouts/app.blade.php, resources/views/layouts/navigation.blade.php, resources/views/components/nav-icon.blade.php, resources/views/components/application-logo.blade.php, resources/js/app.js, public/icon-matriz.svg, public/alfamatriz.png, tests/Feature/Redesign/ShellTest.php
- Esforço: alto
- Notas: Depende de T-031. Sidebar 228px ↔ 60px (`transition: width 180ms`),
  raio 0, item ativo com `border-left:3px` na marca; recolhida mostra
  divisória entre grupos e esconde a barra de rolagem. Topbar de 56px com
  botão de recolher à esquerda, título/contexto com as prioridades de
  encolhimento do handoff (armadilha 1) e busca. Rodapé com avatar,
  notificações e botão sol/lua. `railOpen` e `theme` em `localStorage`, com
  script inline no `<head>` para não piscar o tema errado. Padrão: escuro
  (Q-008). Marca: `icon-matriz.svg` + wordmark `alfamatriz.png` do handoff,
  substituindo `logo-tile.svg` (Q-009). Abaixo de 1024px a sidebar vira
  gaveta sobreposta.

## T-033 — Componentes compartilhados do sistema visual [concluida]
- Refs: US-016, AC-046, AC-047
- Arquivos: resources/views/components/kpi-card.blade.php, resources/views/components/sparkline.blade.php, resources/views/components/painel.blade.php, resources/views/components/badge.blade.php, resources/views/components/faixa-segmentada.blade.php, resources/views/components/tabela.blade.php, resources/views/components/linha-total.blade.php, resources/views/components/acao-tabela.blade.php, resources/views/components/stat-card.blade.php, resources/views/components/bar-chart.blade.php, tests/Feature/Redesign/ComponentesTest.php
- Esforço: alto
- Notas: Depende de T-031. `<x-tabela>` resolve as armadilhas 2 e 4 de uma
  vez: painel com raio por fora, wrapper `overflow-x-auto` por dentro e
  `min-width` na `<table>`; `<x-linha-total>` aplica `whitespace-nowrap` em
  todas as células. `<x-kpi-card>` traz o fio de luz no topo, valor tabular e
  slot de sparkline. Grid de cards sempre `auto-fit minmax()` (armadilha 3).
  O teste desta tarefa varre **todas** as telas de tabela — só fica verde
  quando as telas de T-037 a T-043 tiverem migrado.

## T-034 — Centro de Controle [concluida]
- Refs: US-018, AC-039, AC-040, AC-041, AC-070
- Arquivos: resources/views/centro-controle/index.blade.php, app/Http/Controllers/CentroControleController.php, config/app.php, tests/Feature/Redesign/CentroControleTest.php, tests/Feature/Redesign/FusoHorarioTest.php
- Esforço: alto
- Notas: Depende de T-033. Halo radial, saudação, 4 cards de KPI com
  sparkline, fila de ação com barra de severidade e botão que navega para a
  tela do problema, régua "Origem do MRR" com escala fixa 0–35k e barras
  proporcionais ao valor, coluna direita (próximos 7 dias, pipeline aberto,
  entraram esta semana). O controller passa a calcular a fila de ação e as
  séries a partir do banco.

## T-035 — Painéis Financeiro e Comercial [concluida]
- Refs: US-019, AC-042, AC-043, AC-062
- Arquivos: resources/views/dashboard.blade.php, resources/views/dashboard-comercial.blade.php, app/Http/Controllers/PainelController.php, app/Services/IndicadoresService.php, tests/Feature/Redesign/PaineisTest.php, tests/Feature/Redesign/IndicadoresCoerentesTest.php
- Esforço: alto
- Notas: Depende de T-033. Financeiro: 5 KPIs, gráfico de entradas x saídas
  de 6 meses (SVG inline, escala `max × 1,15`), listas de pendentes.
  Comercial: 4 KPIs e os dois rankings em três camadas (bloco de topo com
  total e líder, faixa segmentada proporcional, linhas com barra e share).

## T-036 — Funil de vendas com arrastar e soltar [concluida]
- Refs: US-020, AC-044, AC-045
- Arquivos: resources/views/leads/index.blade.php, app/Http/Controllers/LeadController.php, tests/Feature/Redesign/FunilTest.php
- Esforço: alto
- Notas: Depende de T-033. Tela `height: calc(100vh - 120px)`, quadro
  `flex:1; min-height:0` com fundo recuado, colunas `align-items:stretch`
  rolando por dentro (armadilha 10). Arrastar com eventos HTML5 no Alpine
  chamando a rota `leads.mover` que já existe; menu "Mover" preservado como
  caminho acessível.

## T-037 — Revendas [concluida]
- Refs: US-021, AC-048
- Arquivos: resources/views/revendas/index.blade.php, resources/views/revendas/_form.blade.php, resources/views/revendas/create.blade.php, resources/views/revendas/edit.blade.php, app/Http/Controllers/RevendaController.php, tests/Feature/Redesign/RevendasTest.php
- Esforço: medio
- Notas: Depende de T-033. 4 KPIs, filtros, tabela via `<x-tabela>` com tile
  de iniciais, base de clientes com barra, chips de sistemas com `+N`, linha
  de pendência marcada em âmbar, linha de totais e rodapé de contagem.

## T-038 — Clientes: lista e formulário [concluida]
- Refs: US-021, AC-048
- Arquivos: resources/views/clientes/index.blade.php, resources/views/clientes/_form.blade.php, resources/views/clientes/_modal-novo.blade.php, resources/views/clientes/edit.blade.php, app/Http/Controllers/ClienteController.php, tests/Feature/Redesign/ClientesTest.php
- Esforço: alto
- Notas: Depende de T-033. Lista com badge de pagamento (Em dia / Atrasado /
  Sem cobrança), venda direta em cor de marca, inativos esmaecidos.
  Formulário em grid de 6 colunas com seções em painéis; **preservar o Alpine
  de busca de CNPJ (BrasilAPI) e CEP (ViaCEP) que já existe** e o bloco de
  e-mails e telefones.

## T-039 — Produtos: lista comparável e gestão do sistema [concluida]
- Refs: US-021, AC-048, AC-049
- Arquivos: resources/views/produtos/index.blade.php, resources/views/sistemas/index.blade.php, resources/views/sistemas/edit.blade.php, app/Http/Controllers/ProdutoController.php, tests/Feature/Redesign/ProdutosTest.php
- Esforço: alto
- Notas: Depende de T-033. Lista é o modo padrão, ordenada por receita
  recorrente, com share, ARR, base ativa na unidade de cobrança de cada
  sistema, ticket médio e churn (>10% em vermelho). Alternador lista/cartões
  com o modo guardado no navegador (já existe hoje). Sistema sem tier de
  atacado aparece esmaecido e com alerta.

## T-040 — Faturamento das revendas [concluida]
- Refs: US-022, AC-050, AC-051, AC-052
- Arquivos: resources/views/faturamento/index.blade.php, app/Http/Controllers/FaturamentoController.php, app/Services/FaturamentoService.php, tests/Feature/Redesign/FaturamentoTest.php
- Esforço: alto
- Notas: Depende de T-033. Barra de ciclo com competência, selo de estado,
  resumo em 4 números e botão com a contagem no rótulo. Faixa de pendência
  explicando as linhas fora do ciclo. Um painel por revenda com checkbox de
  geração seletiva e coluna **Cálculo** tornando cada valor auditável.
  Subtotais **somados das linhas**, nunca digitados. Esta tela não pode ter
  largura máxima fixa (armadilha 9).

## T-041 — Receitas / contas a receber [concluida]
- Refs: US-023, AC-053, AC-054, AC-055
- Arquivos: resources/views/cobrancas/index.blade.php, resources/views/cobrancas/_form.blade.php, resources/views/cobrancas/create.blade.php, resources/views/cobrancas/edit.blade.php, resources/views/cobrancas/show.blade.php, app/Http/Controllers/CobrancaController.php, tests/Feature/Redesign/ReceitasTest.php
- Esforço: alto
- Notas: Depende de T-033. 4 KPIs, faixa de aging em 4 faixas com barra
  segmentada, barra de seleção em massa (contagem + soma) reaproveitando a
  rota `cobrancas.baixarEmMassa`, tabela com prazo real por baixo da data e
  marcação de atraso/a vencer.

## T-042 — Despesas / contas a pagar [concluida]
- Refs: US-023, AC-053, AC-054, AC-055
- Arquivos: resources/views/contas-pagar/index.blade.php, resources/views/contas-pagar/_form.blade.php, resources/views/contas-pagar/create.blade.php, resources/views/contas-pagar/edit.blade.php, resources/views/contas-fixas-pagar/index.blade.php, app/Http/Controllers/ContaPagarController.php, tests/Feature/Redesign/DespesasTest.php
- Esforço: alto
- Notas: Depende de T-033. Mesma gramática das Receitas, com recorrentes e
  pontuais convivendo na mesma lista (diferenciados pelo subtítulo e pelo
  ícone) e a ação de pausar recorrência preservada. Reaproveita
  `contas-pagar.baixarEmMassa`.

## T-043 — Caixa e extrato [concluida]
- Refs: US-024, AC-056, AC-057
- Arquivos: resources/views/contas-financeiras/index.blade.php, resources/views/contas-financeiras/_form.blade.php, resources/views/contas-financeiras/create.blade.php, resources/views/contas-financeiras/edit.blade.php, resources/views/contas-financeiras/extrato.blade.php, app/Http/Controllers/ContaFinanceiraController.php, tests/Feature/Redesign/CaixaTest.php
- Esforço: medio
- Notas: Depende de T-033. Hero com saldo consolidado, cards de conta com
  sparkline de 6 meses e rodapé de ações, painel de movimentação do mês e
  últimas movimentações. Extrato com saldo resultante acumulado por linha.

## T-044 — Cadastros auxiliares e plano de contas [concluida]
- Refs: US-025, AC-058, AC-059
- Arquivos: resources/views/cadastros-auxiliares/index.blade.php, app/Http/Controllers/CadastroAuxiliarController.php, tests/Feature/Redesign/CadastrosTest.php
- Esforço: medio
- Notas: Depende de T-033. Centros de custo e fornecedores com a contagem de
  lançamentos que usam cada item. Plano de contas na horizontal: categoria
  como bloco marcado pelo tipo, subcategoria como linha e contas como chips
  removíveis, com campos de adição em linha tracejada.

## T-045 — Login e telas de autenticação [concluida]
- Refs: US-026, AC-060
- Arquivos: resources/views/auth/login.blade.php, resources/views/auth/forgot-password.blade.php, resources/views/auth/reset-password.blade.php, resources/views/auth/confirm-password.blade.php, resources/views/auth/verify-email.blade.php, resources/views/layouts/guest.blade.php, tests/Feature/Redesign/LoginTest.php
- Esforço: medio
- Notas: Depende de T-031. Fundo com grade de 56px e halo radial de marca,
  card de 396px com marca, campos de 40px com mostrar/ocultar senha,
  lembrar-me e recuperação, e selo de sistemas operacionais alimentado pela
  rota `/healthz` que já existe. As demais telas de autenticação herdam a
  mesma moldura pelo `layouts/guest`.
