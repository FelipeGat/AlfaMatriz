# Tasks: Redesign da interface e identidade da marca

> feature: redesign-interface

Ordem conforme o handoff. As três primeiras são a fundação: nada das telas faz
sentido antes delas.

## T-038 — Rede de segurança antes de mexer [concluida]
- Refs: US-022, AC-042
- Arquivos: tests/Feature/Redesign/TelasAbremTest.php
- Notas: teste que percorre TODAS as telas do painel autenticado e exige 200.
  Escrito ANTES do redesign de propósito: é ele que avisa se alguma view
  quebrar no caminho. Sem essa rede, o estrago aparece em produção.

## T-039 — Tokens, temas e tipografia [concluida]
- Refs: US-020, AC-039, AC-040
- Arquivos: tailwind.config.js, resources/css/app.css, resources/views/layouts/app.blade.php, public/favicon.svg, tests/Feature/Redesign/TemasETipografiaTest.php
- Notas: custom properties dos dois temas em `data-theme` no `<html>`, cores do
  Tailwind apontando para as variáveis (para o toggle não duplicar classe),
  fontes do Bunny (Space Grotesk, IBM Plex Sans, IBM Plex Mono) e saída do
  Inter. Favicon do pacote copiado para `public/`.

## T-040 — Alternância de tema com persistência [concluida]
- Refs: US-020, AC-039
- Arquivos: resources/views/layouts/app.blade.php, resources/js/app.js, tests/Feature/Redesign/AlternanciaTemaTest.php
- Notas: botão sol/lua no header, `data-theme` no `<html>`, preferência em
  `localStorage`, aplicada antes da primeira pintura para não piscar branco no
  tema escuro.

## T-041 — Sidebar colapsável com lockup da marca [concluida]
- Refs: US-021, AC-041
- Arquivos: resources/views/layouts/navigation.blade.php, resources/views/components/nav-icon.blade.php, public/brand/alfamatriz-wordmark.png, tests/Feature/Redesign/SidebarColapsavelTest.php
- Notas: 236px ↔ 68px, estado em `localStorage`, lockup ícone+wordmark no topo,
  marcador de 3px no item ativo, tooltip nativo quando colapsada. Mantém o
  comportamento de drawer abaixo de `lg`, que já existe.

## T-042 — Componentes base do novo visual [concluida]
- Refs: US-022, AC-043
- Arquivos: resources/views/components/summary-card.blade.php, resources/views/components/stat-card.blade.php, resources/views/components/status-pill.blade.php, resources/views/components/data-table.blade.php, tests/Feature/Redesign/ComponentesBaseTest.php
- Notas: é aqui que a armadilha do valor monetário é resolvida de uma vez —
  `nowrap` e largura mínima ficam no componente, não espalhados por tela. O
  teste prova que um valor longo não ganha permissão de quebrar.

## T-043 — Painéis Financeiro e Comercial [concluida]
- Refs: US-022, US-023, AC-042, AC-044
- Arquivos: resources/views/dashboard.blade.php, resources/views/dashboard-comercial.blade.php, app/Http/Controllers/PainelController.php, tests/Feature/Redesign/IndicadoresCoerentesTest.php
- Notas: grade de KPIs, gráfico de entradas × saídas, ranking com rosca. Os
  indicadores que aparecem em mais de uma tela passam a sair de uma origem só
  (AC-044) — é correção de defeito, não só visual.

## T-044 — Telas de lista [concluida]
- Refs: US-022, AC-042, AC-043
- Arquivos: resources/views/revendas/index.blade.php, resources/views/clientes/index.blade.php, resources/views/cobrancas/index.blade.php, resources/views/contas-pagar/index.blade.php
- Notas: faixa de cards de resumo, barra de filtros e tabela em card. Filtros
  continuam por query string. Colunas de valor com piso de largura.

## T-045 — Sistemas e Faturamento [pendente]

- Refs: US-022, AC-042
- Arquivos: resources/views/sistemas/index.blade.php, resources/views/faturamento/index.blade.php
- Notas: catálogo + detalhe em flex-wrap (com o cuidado do badge que colide com
  o botão), sparkline, faixas de preço, top 5 de revendas. Faturamento com
  prévia por revenda.

## T-046 — Modais, toasts e estados vazios [pendente]

- Refs: US-022, AC-042
- Arquivos: resources/views/components/modal.blade.php, resources/views/components/toast.blade.php
- Notas: overlay e caixa com as animações do handoff (nada acima de 220ms),
  toast centralizado embaixo, estados vazios das tabelas.
