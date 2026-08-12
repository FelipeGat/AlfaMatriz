# Handoff: AlfaMatriz — repaginação visual do painel

## Overview

AlfaMatriz é o painel interno da Alfa Tecnologia (software house): controla revendas, clientes finais,
os sistemas licenciados (AlfaGym, AlfaControl, AlfaHome, AlfaMed, AlfaJornada, AlfaSchool, Gestor),
preço de atacado por tier, o motor de faturamento mensal das revendas e o financeiro da própria Alfa.

Este pacote entrega a **repaginação visual completa** do painel: 13 telas + login, em tema escuro e claro,
mantendo a estrutura de dados e as rotas que já existem no Laravel. Nada de novo módulo — o que muda é a
camada de apresentação e a densidade/hierarquia da informação.

O objetivo do redesign, nas palavras do cliente: *"o design está muito pobre, precisamos repaginar"* —
com visual moderno e elegante, coerente com a proposta do sistema (ferramenta interna de operação
financeira/comercial, usada diariamente pelo time da Alfa, desktop primeiro e mobile funcional).

## About the Design Files

Os arquivos `.dc.html` deste bundle são **referências de design feitas em HTML** — protótipos que mostram
aparência e comportamento pretendidos. **Não são código de produção para copiar e colar.**

A tarefa é **recriar estes designs no codebase existente do AlfaMatriz**, que é:

- **Laravel 12 + Blade + Tailwind CSS** (tema dark, marca Alfa)
- **Alpine.js** para interatividade leve (sem SPA)
- MySQL via Docker; Vite para build

Portanto: os estilos inline dos protótipos devem virar **classes Tailwind** com tokens declarados no
`tailwind.config.js`; a interatividade (recolher menu, alternar tema, seleção em massa, drag do kanban)
deve virar **Alpine.js**; as listas devem vir dos controllers/models que já existem. Reaproveite os
componentes Blade atuais (`resources/views/components/*.blade.php`) evoluindo-os, em vez de criar um
sistema paralelo.

Para abrir os protótipos: basta abrir o `.html` no navegador (o `support.js` ao lado é o runtime que os
renderiza). Navegue clicando na sidebar; o botão de tema fica no rodapé da sidebar.

## Fidelity

**Alta fidelidade (hifi).** Cores, tipografia, espaçamentos, raios, estados e microcópia estão finais.
Recrie pixel-perfect usando Tailwind. Onde este documento e o HTML divergirem, **o HTML é a fonte da verdade**.

Os dados exibidos são **plausíveis, não reais** — derivados dos seeders (`SistemasPrecosSeeder`,
`DadosIniciaisSeeder`). Substitua por dados do banco.

---

## Design Tokens

Estes são os valores finais. Recomendo declará-los no `tailwind.config.js` como está abaixo, mantendo os
nomes semânticos que o projeto já usa (`canvas`, `panel`, `ink`, `brand`, `status`) e acrescentando os
novos (`rule`, `chip`, `card-grad`, `board`).

### Cores — tema escuro (padrão)

| Token | Hex / valor | Uso |
|---|---|---|
| `frameBg` (canvas) | `#070d0f` | fundo da aplicação |
| `sideBg` (panel) | `#0a1215` | sidebar, cabeçalhos de painel/tabela |
| `headBg` | `#0a1215` | faixas de cabeçalho, rodapés de tabela |
| `panelBg` | `rgba(255,255,255,0.025)` | superfície de card |
| `subtleBg` | `rgba(255,255,255,0.018)` | superfície de painel de lista/tabela |
| `cardGrad` | `linear-gradient(180deg,rgba(255,255,255,0.05),rgba(255,255,255,0.015))` | superfície dos cards de KPI |
| `boardBg` | `rgba(0,0,0,0.28)` | fundo recuado do quadro do funil |
| `inputBg` | `rgba(255,255,255,0.03)` | inputs, selects, busca |
| `chipBg` | `rgba(255,255,255,0.05)` | chips, hover de linha, tiles neutros |
| `border` | `rgba(255,255,255,0.08)` | bordas de painel e divisórias estruturais |
| `rule` | `rgba(255,255,255,0.055)` | divisória entre linhas de tabela/lista |
| `ruleStrong` | `rgba(230,238,240,0.30)` | divisória entre grupos no rail recolhido |
| `btnBorder` | `rgba(255,255,255,0.12)` | borda de botão secundário |
| `barTrack` | `rgba(255,255,255,0.05)` | trilha de barra de progresso |
| `glow` | `rgba(2,156,175,0.10)` | halo radial do topo do Centro de Controle |
| `navActiveBg` | `rgba(2,156,175,0.16)` | fundo do item de menu ativo |
| `ink` | `#e6eef0` | texto primário |
| `inkDim` | `#9db0b6` | texto secundário |
| `inkMute` | `#8798a0` | rótulos, metadados |
| `inkFaint` | `#7b8c93` | texto terciário (mín. AA em 11px+) |
| `brand` | `#029caf` | marca — botões primários, barras, marca do item ativo |
| `brandText` | `#5be3ef` | marca em texto sobre fundo escuro |
| `brandBright` | `#26d4e6` | hover de botão primário |
| `onBrand` | `#04181b` | texto sobre fundo de marca |
| `accent` | `#5be3ef` | sparklines, acentos |
| `amber` | `#e8a045` | acento âmbar |
| `chartOut` | `#c98500` | barras de "saídas"/negativo em gráfico |
| `good` | `#0ca30c` | sucesso, entradas, em dia |
| `warn` | `#fab219` | atenção, a vencer, pendência |
| `crit` | `#d03b3b` | crítico, atraso, remover |
| `goodTint` / `goodBorder` | `rgba(12,163,12,0.09)` / `rgba(12,163,12,0.25)` | faixa de status positivo |
| `warnTint` / `warnBorder` | `rgba(250,178,25,0.09)` / `rgba(250,178,25,0.28)` | faixa de pendência |
| `critTint` | `rgba(208,59,59,0.12)` | hover de ação destrutiva |
| `tintAlpha` | `0.13` | alpha padrão dos fundos de badge |

### Cores — tema claro

| Token | Hex / valor |
|---|---|
| `frameBg` | `#f5f8f9` |
| `sideBg` | `#ffffff` |
| `headBg` | `#fafcfc` |
| `panelBg` / `subtleBg` / `inputBg` | `#ffffff` |
| `cardGrad` | `linear-gradient(180deg,#ffffff,#f2f7f8)` |
| `boardBg` | `#eaf0f1` |
| `chipBg` | `#eef3f4` |
| `border` | `rgba(11,26,30,0.10)` |
| `rule` | `rgba(11,26,30,0.07)` |
| `ruleStrong` | `rgba(11,26,30,0.22)` |
| `btnBorder` | `rgba(11,26,30,0.15)` |
| `barTrack` | `rgba(11,26,30,0.07)` |
| `glow` | `rgba(2,156,175,0.09)` |
| `navActiveBg` | `rgba(2,156,175,0.10)` |
| `ink` | `#0b1a1e` |
| `inkDim` | `#4a5c62` |
| `inkMute` | `#5d6e75` |
| `inkFaint` | `#66777e` |
| `onBrand` | `#ffffff` |
| `brandText` / `accent` | `#06788a` / `#029caf` |
| `amber` / `chartOut` | `#b06a12` / `#b57500` |
| `good` / `warn` / `crit` | `#0a7a0a` / `#b57500` / `#c02b2b` |
| `goodTint` / `goodBorder` | `rgba(10,122,10,0.07)` / `rgba(10,122,10,0.22)` |
| `warnTint` / `warnBorder` | `rgba(181,117,0,0.08)` / `rgba(181,117,0,0.26)` |
| `critTint` | `rgba(192,43,43,0.10)` |
| `tintAlpha` | `0.10` |

> **`brand` (#029caf) é o mesmo nos dois temas.** O que muda é `brandText`, porque `#5be3ef` não tem
> contraste suficiente sobre branco.

**Contraste:** `inkFaint` foi calibrado para ≥4,5:1 nos dois temas (o valor claro anterior, `#95a3a8`,
media 2,6:1 e foi reprovado). Não clareie esses tokens sem recalcular.

### Tipografia

Três famílias, cada uma com um papel fixo:

| Família | Pesos | Papel |
|---|---|---|
| **Space Grotesk** | 500, 600, 700 | títulos de tela e de painel, **todos os números grandes de destaque** (KPIs, saldos, totais). É a voz que dialoga com o wordmark. |
| **Geist** | 400, 500, 600, 700 | corpo, rótulos de formulário, texto de tabela, botões |
| **Geist Mono** | 400, 500, 600 | rótulos em caixa alta, números tabulares em tabela, datas, deltas, eixos de gráfico, atalhos de teclado |

Fontes: Space Grotesk via `fonts.bunny.net` (já usado no projeto); Geist e Geist Mono via Google Fonts.
Fallback do mono: `'Geist Mono', 'JetBrains Mono', monospace`.

Escala em uso:

| Papel | Família | Tamanho / peso | Extras |
|---|---|---|---|
| Título de tela (topbar) | Space Grotesk | 17px / 600 | — |
| Saudação (Centro de Controle) | Space Grotesk | 31px / 600 | `letter-spacing:-0.01em` |
| Número de KPI | Space Grotesk | 24–27px / 600 | `letter-spacing:-0.02em`, `font-variant-numeric:tabular-nums` |
| Saldo total (Caixa) | Space Grotesk | 32px / 600 | idem |
| Título de painel | Space Grotesk | 14,5–15,5px / 600 | — |
| Rótulo de seção (faixa) | Geist Mono | 10,5px / 600 | `uppercase`, `letter-spacing:0.16em` |
| Cabeçalho de tabela | Geist Mono | 10,5px / 600 | `uppercase`, `letter-spacing:0.14em` |
| Rótulo de KPI | Geist | 11px / 400 | `uppercase`, `letter-spacing:0.10em` |
| Corpo de tabela | Geist | 13,5px / 400–500 | — |
| Subtítulo de célula | Geist | 11,5px / 400 | cor `inkMute` |
| Valor em tabela | Geist Mono | 13–13,5px / 500 | — |
| Badge / chip | Geist Mono | 10px / 600 | `uppercase`, `letter-spacing:0.08em` |
| Botão | Geist | 12,5–13px / 600 | — |
| Meta do topbar | Geist Mono | 11px / 400 | `uppercase`, `letter-spacing:0.14em` |

### Raio de canto — escala aninhada (decisão de design importante)

A primeira versão usava 14px empilhado (card 14 dentro de painel 14 dentro de moldura 16) e ficou com cara
de app de consumo, além de brigar com o wordmark, que já é o elemento mais arredondado da tela.
A escala final é **curta e aninhada** — filho sempre menor que o pai:

| Elemento | Raio |
|---|---|
| Painel, card, moldura | **8px** |
| Botão de ação, input, select, busca | **6px** |
| Controle pequeno (toggle da sidebar, botões do rodapé, cartão de lead) | **5px** |
| Tile de ícone, botão de ação em tabela, chip de conta | **4px** |
| Badge, chip, tag, tecla ⌘K | **3px** |
| Avatar, ponto de status, pílula de contagem | `9999px` (círculo) |

**O menu não tem raio** (raio 0): é faixa de borda a borda, marcada por régua e barra — o menu é
estrutura, o conteúdo é superfície.

### Espaçamento

Grade de 4px. Valores em uso: `2 4 5 6 8 10 11 12 14 16 20 24 28 32 40`.

- `main`: `padding: 24px 28px 40px`
- Painel: `padding: 16px`; faixa de cabeçalho: `height: 38px` com `padding: 0 16px`
- Célula de tabela: `padding: 13px 16px`; célula de total: `12px 16px`
- Gap entre cards: `12px`; entre painéis: `16px`; entre blocos de tela: `16–20px`
- Topbar: `height: 56px`, `padding: 0 28px`
- Sidebar: expandida `228px`, recolhida `60px`; header `56px`; item de menu `34px`

### Sombras

Praticamente ausentes por decisão — o contraste vem do hairline de 1px e do degrau de superfície.
A única sombra é a das molduras de apresentação no arquivo de opções (não usar no app).

---

## Screens / Views

Rotas correspondentes no `routes/web.php` existente entre parênteses.

### Shell da aplicação (`layouts/app.blade.php` + `layouts/navigation.blade.php`)

**Sidebar** (`<aside>`, `position:sticky; top:0; height:100vh`)

- Largura `228px` expandida / `60px` recolhida, transição `width 180ms ease`, `overflow:hidden`
- Fundo `sideBg`, borda direita 1px `border`
- **Header** (`height:56px`, borda inferior 1px): ícone `assets/icon-matriz.svg` 28×28 + wordmark
  `uploads/alfamatriz.png` (altura 15px). Recolhido: só o ícone, centralizado (`justify-content:center`, `padding:0`)
- **Nav** (`flex:1`, `overflow-y:auto`), 4 grupos: Painéis (Centro de Controle, Financeiro, Comercial),
  Comercial (Funil de Vendas, Revendas, Clientes, Produtos, Faturamento), Financeiro (Receitas, Despesas,
  Caixa), Sistema (Cadastros)
  - Rótulo de grupo: Geist Mono 9,5px/600, `uppercase`, `letter-spacing:0.18em`, cor `inkFaint`, `padding:6px 14px`
  - **Recolhido:** o rótulo vira `display:none` e no lugar entra uma **divisória de 1px** (`background:ruleStrong`,
    `margin:11px 9px`) entre grupos — **exceto antes do primeiro grupo**
  - Item: `height:34px`, `display:flex`, `gap:12px`, `padding:0 13px`, ícone 18px, texto 13,5px
  - **Ativo:** `background:navActiveBg`, `color:brandText`, `font-weight:600`,
    `border-left:3px solid #029caf`. Inativos têm `border-left:3px solid transparent` para o ícone não deslocar
  - **Recolhido:** `justify-content:center` e `padding:0 3px 0 0` (compensa a marca de 3px). O menu não
    reserva largura de barra de rolagem quando recolhido (`scrollbar-width:none`), senão os ícones saem 6px do centro
- **Rodapé** (borda superior 1px, `padding:12px 14px`): avatar circular 28px (`rgba(2,156,175,0.18)` /
  `brandText`) + nome, **botão de notificações** (28×28, raio 5, badge circular de contagem em `crit` no
  canto superior direito) e **botão de tema** (28×28, raio 5, ícone sol/lua conforme o tema ativo).
  Recolhido: `flex-direction:column`, os três empilhados e centralizados

**Topbar** (`<header>`, `height:56px`, sticky, `z-index:20`, fundo `headBg`, borda inferior 1px)

- **Botão de recolher painel** na extremidade esquerda: 30×30, raio 5, borda `btnBorder`, ícone de painel
  (retângulo com divisória vertical à esquerda — padrão VS Code/Linear). Fica no topbar justamente para não
  mudar de posição entre os dois estados
- Título da tela (Space Grotesk 17px/600) + linha de contexto (Geist Mono 11px, uppercase, `inkFaint`)
- **Prioridade de encolhimento (importante):** o `h1` é `flex:0 0 auto; max-width:100%` com
  `overflow:hidden; text-overflow:ellipsis`; a linha de contexto é `flex:0 1 auto; min-width:0`. Sem isso o
  contexto (mais longo) rouba espaço e corta o título ao meio
- À direita: busca (`height:34px`, raio 6, ícone de lupa, placeholder "Buscar cliente, revenda, cobrança…",
  tecla `⌘K` em Geist Mono 11px com borda) e o botão primário da tela quando houver. A busca é
  `flex:0 1 auto; min-width:168px` — ela cede espaço antes do título

**Barra de rolagem customizada** (único CSS que não pode ser inline):

```css
* { scrollbar-width: thin; scrollbar-color: rgba(135,152,160,0.38) transparent; }
::-webkit-scrollbar { width: 11px; height: 11px; }
::-webkit-scrollbar-track, ::-webkit-scrollbar-corner { background: transparent; }
::-webkit-scrollbar-thumb {
  background: rgba(135,152,160,0.34); border-radius: 3px;
  border: 3px solid transparent; background-clip: padding-box;
}
::-webkit-scrollbar-thumb:hover {
  background: rgba(2,156,175,0.75); border: 2px solid transparent; background-clip: padding-box;
}
::-webkit-scrollbar-thumb:active { background: #029caf; background-clip: padding-box; }
nav[data-rail="closed"] { scrollbar-width: none; }
nav[data-rail="closed"]::-webkit-scrollbar { width: 0; height: 0; }
```

---

### 1. Centro de Controle (`/centro-controle`)

**Propósito:** responder "o que precisa de mim hoje" em uma tela, sem scroll para o essencial.

- Halo radial no topo: `radial-gradient(ellipse 80% 100% at 18% 0%, glow, transparent 70%)`, 240px de altura,
  `pointer-events:none`
- **Saudação:** data em Geist Mono 11px uppercase `letter-spacing:0.18em` cor `brandText` ("QUINTA · 06 AGO 2026"),
  "Bom dia, Administrador" em Space Grotesk 31px/600, e uma linha de contexto
  ("4 itens pedem decisão sua hoje · fechamento de 08/2026 em 25 dias")
- **4 cards de KPI** — `grid-template-columns: repeat(auto-fit, minmax(210px,1fr))`, gap 12px.
  Cada card: raio 8, `background:cardGrad`, borda 1px, `padding:17px 16px 15px`, e **um fio de luz de 1px
  no topo**: `position:absolute; top:0; left:16px; right:16px; background:linear-gradient(90deg,transparent,<accent>,transparent)`.
  Conteúdo: rótulo (11px uppercase), valor (Space Grotesk 27px/600, tabular), e uma linha final com o delta
  à esquerda e uma **sparkline** de 6 pontos à direita (SVG 88×26, `stroke-width:1.6`, sem preenchimento).
  Cards: MRR `R$ 52.430` (+7,2% vs jul), Caixa `R$ 187.413` (30 dias de folga), Atrasado `R$ 11.580`
  (3 títulos, acento `crit`), Clientes ativos `1.478` (+34 no mês, acento `amber`)
- **Grid 1,55fr / 1fr**, gap 16px:
  - **Coluna esquerda — "Fila de ação"** (painel raio 8, `subtleBg`): faixa de cabeçalho com o título e
    "4 abertos". Cada linha (`padding:14px 16px`, borda inferior `rule`) tem **barra de severidade de 2px
    à esquerda** na cor do nível, tile de ícone 28×28 raio 4 tingido, título 14px/500, subtítulo 11,5px,
    valor em mono à direita e **botão de ação por linha** (28px, raio 4, borda `btnBorder`; hover → borda e
    texto na marca). Itens: 2 receitas atrasadas de revenda → *Cobrar* (crit); Licenças JetBrains vencidas →
    *Pagar* (warn); 2 leads parados há +30 dias → *Revisar* (warn); AlfaSchool sem tier de atacado →
    *Definir* (marca). Cada botão navega para a tela correspondente.
    Abaixo, no mesmo painel, a régua **"Origem do MRR · 08/2026"** (ver "Gráficos" adiante).
  - **Coluna direita:** painel "Próximos 7 dias" (timeline com ponto de 7px e linha vertical de 1px, dia em
    mono, descrição e valor colorido por sinal); card "Pipeline aberto" (`background:rgba(2,156,175,0.07)`,
    borda `rgba(2,156,175,0.24)`, valor Space Grotesk 26px, botão "Abrir funil"); painel
    "Entraram esta semana" com avatar circular de iniciais, nome e data

### 2. Painel Financeiro (`/dashboard`)

- 5 cards de KPI (`auto-fit minmax(200px,1fr)`): MRR, ARR projetado, Saldo em caixa, Entradas do mês,
  Saídas do mês. Mesmo tratamento de card + fio de luz, com tile de ícone 26×26 raio 4 no canto superior direito
- Nota: "ARR = MRR × 12 (projeção simples, não considera sazonalidade nem contratos anuais reais)"
- **Grid 2fr / 1fr:** gráfico de barras "Entradas x saídas — últimos 6 meses" (legenda na própria faixa de
  cabeçalho) e painel "Base instalada" (Revendas ativas 4, Clientes ativos 1.478, Clientes diretos 312)
- Dois painéis de lista: "Receitas pendentes" e "Despesas em aberto" (descrição + meta + valor em mono),
  cada um com link "Ver todas" na faixa de cabeçalho

### 3. Painel Comercial (`/comercial`)

- 4 cards de KPI: Sistemas ativos, Clientes ativos, Revendas ativas, MRR de atacado
- **2 cards de ranking** (`auto-fit minmax(420px,1fr)`) — o destaque desta tela, em três camadas:
  1. Faixa de cabeçalho (título + nota)
  2. **Bloco de topo** com `background:cardGrad`: total à esquerda (rótulo + Space Grotesk 26px), líder à
     direita (nome + share), e abaixo uma **faixa segmentada** de 8px de altura com `gap:2px` — um segmento
     por produto, largura proporcional ao share, cor = tint da cor do ranking com alpha decrescente
     `[0.9, 0.72, 0.58, 0.46, 0.36, 0.28]`, `title` com nome e valor
  3. **Linhas** (`height:40px`): posição em mono 2 dígitos (`01`, `02`…) cor `inkFaint`, nome
     (`flex:0 0 30%`, líder em 600), barra proporcional ao líder (altura 10px, **cap sólido de 2px na ponta
     direita** em cor mais saturada), valor em mono (80px) e share (38px)
  - Ranking por clientes ativos: AlfaJornada 960, AlfaHome 431, AlfaGym 41, AlfaControl 28, AlfaMed 11, Gestor 7 (marca)
  - Ranking por valor gerado: AlfaGym 14.940, AlfaControl 12.580, AlfaMed 9.800, Gestor 8.400,
    AlfaHome 4.310, AlfaJornada 2.400 (`chartOut`)
- 2 painéis com a mesma gramática: "Clientes por revenda" e "Portfólio por categoria" (nome, barra, valor, share)

### 4. Funil de Vendas (`/leads`)

**Propósito:** mover leads entre estágios; ver onde o pipeline está parado.

- Tela em `display:flex; flex-direction:column; height:calc(100vh - 120px); min-height:520px` — o quadro
  ocupa a altura disponível em vez de ser dimensionado pelo conteúdo
- 4 cards de KPI: Taxa de conversão 18,2%, Pipeline aberto R$ 5.108, Ticket médio fechado R$ 274, Abertos/Perdidos 9/1
- **Quadro** — `flex:1; min-height:0`, raio 8, borda 1px, `background:boardBg` (fundo **recuado**, para o
  quadro não se confundir com os cards de KPI acima):
  - Cabeçalho próprio: tile de ícone de colunas 28×28 na marca, "Quadro do funil" (Space Grotesk 15,5px/600)
    com subtítulo "6 estágios · 9 leads abertos" em mono, e à direita a dica "arraste o card para mover de estágio"
  - Colunas: `flex:1; min-height:0; overflow-x:auto; padding:14px; align-items:stretch` — **todas com a mesma
    altura**; cada coluna `width:276px`, raio 6, `background:sideBg`
  - Cabeçalho de coluna: `border-top:3px solid <cor do estágio>`, ponto de 7px na cor do estágio, nome em
    **Space Grotesk 14px/600 na tinta cheia**, chip de contagem tingido na cor do estágio alinhado à direita,
    e segunda linha "R$ 548 em jogo" em mono recuada
  - Lista de cards: `flex:1; min-height:0; overflow-y:auto` — cada coluna rola por dentro
  - Card de lead: raio 5, `background:cardGrad`, `cursor:grab`; nome 13,5px/500 + chip de dias no estágio
    (verde/âmbar/vermelho por temperatura), meta ("SaaS · Indicação"), e rodapé separado por régua com valor
    em `brandText` e revenda. Cards frios/esfriando têm a borda tingida
  - Estágios e cores: Lead e Contato feito (`accent`), Proposta e Negociação (`#029caf`), Fechado (`good`), Perdido (`crit`)

### 5. Revendas (`/revendas`)

- 4 cards de KPI: Revendas ativas 4 (de 5 cadastradas), Clientes via revenda 1.166 (79% da base),
  MRR de revenda R$ 19.430 (37% do MRR total), Ticket médio R$ 4.858 (por revenda ativa)
- Filtros: busca ("Buscar revenda ou CNPJ…"), status, ordenação
- **Tabela** (painel raio 8; `<table>` com `min-width:1000px` dentro de um wrapper `overflow-x:auto`):
  - **Revenda** — tile 32×32 raio 5 com iniciais (Space Grotesk 12,5px/600) + nome e CNPJ empilhados
  - **Contato** — nome + e-mail/telefone
  - **Base de clientes** — número em mono, share da base, e barra de 6px proporcional ao líder
  - **MRR / mês** — valor em mono + delta vs julho
  - **Sistemas revendidos** — chips por sistema, com `+N` quando passa de três
  - **Status** — badge com ponto; alerta em âmbar quando há pendência
  - **Ações** — ícones: ver, faturamento da revenda, editar, remover
  - Linha com pendência: `border-left:2px solid warn` + fundo `tint(warn,0.05)`. Hover: `background:chipBg`
  - **Linha de totais** (fundo `headBg`): 1.166 clientes · R$ 19.430,00 · 6 sistemas distintos.
    **Todas as células de total precisam de `white-space:nowrap`** — os rótulos em mono/uppercase quebram em
    duas linhas sem isso
  - Rodapé: "5 de 5 revendas · 37% do MRR total vem de revenda" + Exportar CSV
- Dados: Invest Soluções (624, R$ 8.940, +6,3%), Nexa Sistemas (341, R$ 5.480, +5,2%),
  Vetor Tecnologia (148, R$ 3.120, −1,8%, cobrança de 07 em atraso), Prisma Digital (53, R$ 1.890, +2,1%),
  Orbe Soft (inativa, sem CNPJ/contato/sistema)

### 6. Clientes (`/clientes`)

- 4 cards de KPI: Clientes cadastrados 87 (82 ativos · 5 inativos), Em contrato 61 (70% da base),
  Avulsos 26, Ticket médio R$ 541
- Filtros: busca ("Buscar nome, CNPJ ou cidade…"), revenda, sistema, status + legenda do marcador
  ("▮ cobrança em atraso") alinhada à direita
- **Tabela** (`min-width:1060px`): Cliente (tile de iniciais + nome + CNPJ) · Revenda / praça (revenda ou
  **"Venda direta" em `brandText`**, com cidade/UF) · Sistemas (chips) · Cobrança (valor em mono +
  "contrato · dia 15" ou "avulso") · **Pagamento** (badge: Em dia / Atrasado 6d / Sem cobrança) · Status ·
  Ações (ver, cobranças do cliente, editar → formulário, remover)
- Inativos com `opacity:0.62`; linhas em atraso com marca âmbar
- Totais: 6 contratos · 2 avulsos · R$ 4.987,00 · 2 em atraso. Rodapé: "8 de 87 clientes · página 1 de 11"

### 7. Formulário de cliente (`/clientes/{cliente}/edit`)

- `max-width:1000px`, seções em painéis com faixa de cabeçalho em mono uppercase
- Grid de 6 colunas; cada campo declara seu `span` (2, 3, 4…). Input `height:36px`, raio 6
- Seções: **Dados básicos** (revenda, tipo de pessoa, CPF/CNPJ com botão *Buscar* e ajuda
  "Preenche razão social e endereço pela Receita.", razão social, nome fantasia) · **Endereço** (CEP com
  botão *Buscar*, logradouro, número, bairro, cidade, UF) · **Contrato e cobrança** (tipo de cliente, valor
  mensal, dia do vencimento, sistemas licenciados, status)
- **E-mails e telefones**: painel com botão "+ Adicionar" no cabeçalho; cada linha tem tile de ícone
  (envelope/telefone), input, checkboxes *Principal* / *Financeiro* e botão remover em `crit`
- Rodapé: Cancelar (secundário) + Salvar cliente (primário)
- As buscas de CNPJ/CEP já existem no Alpine do `clientes/_form.blade.php` (BrasilAPI e ViaCEP) — preservar

### 8. Produtos (`/produtos`)

**Mudança estrutural:** 7 cartões numa grade de 3 deixavam um buraco na última fila e cada cartão repetia
uma grade 2×3 de métricas — muita moldura e nenhuma comparação possível. Virou **lista comparável**,
ordenada por MRR.

- Topo: card "MRR total · todos os produtos" (R$ 52.430,00) + alternador lista/cartões
  (raio 6, `padding:3px`; ativo com fundo `rgba(2,156,175,0.14)` e cor `brandText`) — **lista é o padrão**
- **Tabela** (`min-width:1080px`): Sistema (tile de ícone 34×34 + nome em Space Grotesk 14,5px/600 + chip de
  categoria + "v3.4.1 · Marina Alves") · **MRR · share** (valor, % do total e barra proporcional ao líder) ·
  ARR · **Base ativa** (número + a unidade de cobrança real: academias, condomínios, vidas agregadas,
  famílias, funcionários, usuários, escolas) · Ticket médio · **Churn** (taxa + cancelados acumulados;
  >10% em `crit` e a linha ganha marca) · Status (badge + alerta) · Ações (configurar tiers, editar gestão,
  ver clientes)
- AlfaSchool: inativo, `opacity:0.72`, marca âmbar e alerta "sem tier de atacado"
- Totais: R$ 52.430,00 · R$ 629.160 · 1.478 · 142 cancel. Rodapé: "7 sistemas · 6 ativos · 1 sem tier de atacado"

### 9. Faturamento das revendas (`/faturamento`)

**Propósito:** responder "posso gerar isso com segurança?" — o cálculo precisa ser auditável antes de gerar.

- **Barra de ciclo** (raio 8, `background:cardGrad`): seletor de competência (`input[type=month]`) + selo de
  estado ("prévia · nada gerado", badge âmbar com ponto) | separador vertical de 1px | resumo do ciclo em 4
  números (Total do ciclo R$ 10.075,00, Revendas 4, Linhas 13, **Pendências 1** em `warn`) | ações:
  *Exportar prévia* (secundário) e **"Gerar 4 cobranças"** (primário — a contagem no rótulo, não genérico).
  Rodapé da barra: "Calculado em tempo real com os clientes ativos de hoje. Gerar cria uma cobrança
  consolidada por revenda com vencimento em 10/09/2026."
- **Faixa de pendência** acima dos painéis (fundo `warnTint`, borda `warnBorder`, `border-left:2px solid warn`):
  tile de alerta, "1 linha fora do faturamento deste ciclo" + explicação ("AlfaSchool não tem tier de atacado
  configurado — 6 escolas ativas da Vetor Tecnologia não serão cobradas") + botão "Definir tier" → Produtos
- **Um painel por revenda:** cabeçalho com checkbox (geração seletiva), tile de iniciais, nome com
  "4 sistemas · 963 unidades ativas", e total à direita (Space Grotesk 19px + "por mês")
- Tabela do painel (`min-width:720px`): Sistema · **Tier aplicado** (badge: marca para tier fixo, neutro para
  metrado, âmbar para "sem tier") · **Unidades ativas** (número + unidade de cobrança) · **Cálculo**
  (`620 × R$ 2,50`, `fixo do tier`, `fixo · teto 1.000` — coluna que torna o número auditável) · Valor
- Linha sem tier: marca âmbar, valor `—` e fora do subtotal, declarado no rodapé do painel
- Rodapé do painel: "Ver os 963 clientes considerados" + status das linhas
- **Os subtotais são somados das linhas, não digitados** — mantenha isso na implementação
- Esta tela **não deve ter largura máxima fixa**: com `max-width` ela desliza inteira ao recolher o menu, em
  vez de refluir como as demais

### 10. Receitas / Contas a receber (`/cobrancas`)

- 4 cards de KPI: Em aberto R$ 22.040 (4 títulos), Vence em 7 dias R$ 14.420, Recebido no mês R$ 30.390
  (`good`), Atrasado R$ 7.620 (`crit`, "2 títulos · até 26 dias")
- **Faixa de aging** (raio 8, `cardGrad`): "Em aberto por faixa de vencimento" com o total, as 4 faixas
  (a vencer / 1 a 15 dias / 16 a 30 dias / +30 dias) com quadrado de cor + valor, e **barra segmentada**
  de 8px proporcional
- **Barra de seleção em massa** (fundo `rgba(2,156,175,0.08)`, borda na marca): "2 selecionadas · R$ 14.420,00"
  + "Dar baixa nas receitas" + "Limpar"
- Filtros: status, competência
- **Tabela:** checkbox · **Título** (tile + descrição + "cobrança consolidada" / "serviço avulso") ·
  **Origem** (nome + "revenda" / "cliente final") · **Vencimento** (data em mono + o prazo real por baixo:
  "em 4d", "atraso 26d", colorido) · Valor · Status (badge) · Ações (anexos, dar baixa, ver, editar, remover)
- Linhas: atraso → `border-left:2px solid crit` + `tint(crit,0.07)`; vence em ≤3 dias → âmbar

### 11. Despesas (`/contas-pagar`)

- 4 cards de KPI: A pagar R$ 26.736, Vence em 7 dias R$ 22.290, Pago no mês R$ 12.090, Atrasado R$ 3.960
- Mesma faixa de aging (concentração em 1–15 dias) e mesma barra de seleção em massa
- **Tabela:** checkbox · **Despesa** (tile + descrição + "recorrente · todo dia 05" / "pontual") ·
  **Fornecedor** (razão social + "centro: Alfa Tecnologia") · Vencimento (data + prazo) · Valor · Status ·
  Ações (anexos, dar baixa, **pausar recorrência**, editar, remover)
- Recorrentes e pontuais convivem na mesma lista, diferenciadas pelo subtítulo e pelo ícone do tile

### 12. Caixa / Contas financeiras (`/contas-financeiras`)

- **Hero** (raio 8, `cardGrad`, fio de luz): "Saldo total consolidado", R$ 187.412,68 em Space Grotesk 32px,
  e "4 contas ativas · cobre 30 dias de despesa fixa com folga"
- **Cards de conta** (`auto-fit minmax(250px,1fr)`): tile de ícone + nome + tipo em mono uppercase; saldo em
  Space Grotesk 21px; e uma linha final com variação no mês + share do caixa à esquerda e **sparkline de 6
  meses** à direita. Rodapé do card (fundo `headBg`): Extrato · Editar · contagem de movimentações
- **Painel "Movimentação de agosto":** Entradas / Saídas / Resultado com barras comparáveis e valores coloridos
- **Painel "Últimas movimentações":** data em mono, barra de 2px na cor do sinal, descrição, valor
- Contas: Bradesco PJ R$ 94.218,40 (+4,2%), Reserva CDB R$ 50.000 (+0,9%), Nubank PJ R$ 41.880,12 (−2,1%),
  Caixa interno R$ 1.314,16 (estável)

### 13. Extrato (`/contas-financeiras/{conta}/extrato`)

- Tabela: Data · Descrição · Tipo (badge Entrada/Saída) · Valor (com sinal, colorido) · Saldo resultante
- Rodapé: "8 de 214 movimentações"

### 14. Cadastros auxiliares (`/cadastros-auxiliares`)

- **Dois painéis** (`auto-fit minmax(320px,1fr)`): **Centros de custo** e **Fornecedores**. Cada item:
  tile de ícone 26×26, nome, **quantos lançamentos usam** (o dado que decide se é seguro remover) e botão
  remover. Lista com `max-height:232px; overflow-y:auto`; campo de adição fixo no rodapé do painel
- **Plano de contas** — painel com cabeçalho de seção (tile de ícone + "Plano de contas" +
  "4 categorias · 8 subcategorias · 16 contas") e, à direita, o caminho declarado "categoria › subcategoria › conta"
  - Cada **categoria** é um bloco raio 6 com `border-left:2px` na cor do tipo (`good` para receita,
    `crit` para despesa), nome em Space Grotesk 14px/600, badge de tipo, resumo "2 sub · 5 contas" e remover
  - Cada **subcategoria** é uma linha: à esquerda (`flex:0 0 168px`) o `↳` + nome + remover; à direita as
    **contas como chips removíveis** e um input tracejado "+ conta" no fim da fila — a hierarquia lê-se na
    horizontal, em vez de descer quatro níveis de indentação
  - Rodapé do bloco: input tracejado "+ subcategoria em <categoria>" + Adicionar
  - Rodapé do painel: "Nova categoria" + select Despesa/Receita + "Adicionar categoria"
- Plano usado no protótipo: **Receitas de software** (receita) → Licenciamento [Atacado de revenda, Venda
  direta], Serviços [Implantação, Treinamento, Customização] · **Operação e infraestrutura** → Infraestrutura
  [Servidores, Domínios & SSL, Backup], Software [Licenças de dev, SaaS interno] · **Pessoal** → Sócios
  [Pró-labore], Equipe [Folha, Benefícios, Vale-transporte] · **Administrativo** → Escritório [Aluguel,
  Energia, Internet], Serviços [Contabilidade, Jurídico]

### 15. Login (`/login`)

- Tela cheia centrada, fundo `frameBg`, com **grade de 56px** (`linear-gradient` 1px em `gridLine`:
  `rgba(230,238,240,0.025)` escuro / `rgba(11,26,30,0.03)` claro) e **halo radial** de marca no topo
  (`820×560`, `rgba(2,156,175,0.16)` → transparente)
- Card `max-width:396px`, raio 8, `padding:30px 28px`: ícone + wordmark, "Entrar" (Space Grotesk 21px/600),
  "Acesso restrito à equipe AlfaMatriz.", campos de e-mail e senha (`height:40px`, raio 6, olho de
  mostrar/ocultar), "Lembrar-me" + "Esqueci minha senha", botão primário `height:42px`
- Abaixo do card: selo de status "Sistemas operacionais" (fundo `goodTint`, borda `goodBorder`, ponto em
  `good`, texto em mono uppercase) — alimentado pelo `/healthz` que já existe — e
  "Painel interno · acesso somente por convite"

### 16. Tarefas — quadro (`/tarefas`)

**Referência: `AlfaMatriz Tarefas.dc.html`** (protótipo interativo completo, com botão **Simular** que roda
o ciclo de uma pergunta passo a passo). A tela do sistema (`AlfaMatriz Sistema.dc.html` → menu Trabalho →
Tarefas) traz a versão resumida; o comportamento está no arquivo dedicado.

Esta tela mudou o modelo mais do que qualquer outra. **Leia as notas de banco no fim da seção antes de
implementar** — são oito, e várias são caras de desfazer.

#### 16.1 As seis etapas em curso

O fluxo antigo (`em_testes` + `ajustes_necessarios`) foi substituído por um que espelha o pipeline real de
vocês — `deploy-staging-alfamatriz.sh` sobe da main com a suíte como portão; o vigia de tags aplica cada
`v*` em produção a cada 5 minutos:

| Etapa | Chave | Portão (dito no cabeçalho da coluna) | WIP |
|---|---|---|---|
| Aberta | `aberta` | fila de triagem | — |
| Backlog | `backlog` | priorizado, sem ninguém tocando | — |
| Em andamento | `em_desenvolvimento` | — | 3 |
| Em revisão | `em_revisao` | PR · admin analisa | 3 |
| Em staging | `em_staging` | na main · dev valida | 3 |
| Pronta p/ produção | `pronta_producao` | fila do admin · tag v* | — |

`concluida` e `cancelada` são terminais e vivem na aba Histórico.

**"Em testes" guardava dois portões.** Revisão de PR (admin lê o código) e validação em staging (dev testa
rodando) têm revisor, artefato e modo de falha diferentes. Juntos, o quadro não respondia "quem espera quem".

**"Concluída" mentia.** Concluir a partir de Em testes marcava a tarefa como pronta com o código só no
staging. Agora **concluída significa EM PRODUÇÃO**, e o painel de conclusão pede a versão (`v1.4.2`), que
aparece na coluna *Desfecho / versão* do histórico — é ela que responde "desde quando o cliente tem isso".

**"Pronta p/ produção" é coluna porque muda de mão.** É o único ponto em que a bola passa do dev para o
admin. O critério que separa coluna de marca, e que vale para todo o quadro: *uma coluna só se justifica se
muda quem está segurando a tarefa*.

Tarefa **operacional** não tem PR, não passa por staging e não é taggeada: o fluxo dela vai direto de
Em andamento para Concluída, e o painel de conclusão troca de copy ("Encerrar tarefa", sem pedir versão).

#### 16.2 O que virou marca em vez de coluna

Três informações que antes seriam colunas hoje viajam **dentro do card**, porque são ortogonais à etapa ou
não mudam quem segura a tarefa:

**Bloqueio** — tarja âmbar com o motivo (2 linhas, largura inteira), o tempo travado e o botão Destravar. A
tarefa **fica na etapa em que está** e **sai da conta de WIP** (vaga ocupada por tarefa parada não é
trabalho em curso). Isso apagou 4 arestas do fluxo antigo e o contorno manual que o `FLUXOS` fazia para
lembrar de onde o card veio.

**Retorno** — "Ajustes necessários" foi **eliminado**. Reprovar devolve direto para Em andamento com uma
tarja que nomeia o portão: *Voltou da revisão* / *Voltou do staging* / *Voltou da porta da produção*, mais o
motivo. Motivo da eliminação: a única saída de Ajustes era Em andamento (mesmo dono), ela achatava as três
reprovações numa só e escondia retrabalho do WIP. A marca some quando a tarefa anda para frente; o histórico
permanente fica nos eventos.

**Pergunta** — ver 16.3.

O texto do painel de motivo muda conforme o portão de origem. Vindo do staging ele avisa que **o código já
está na main** e pergunta se precisa `deploy/voltar.sh` ou dá para corrigir seguindo em frente — a
recuperação é materialmente diferente de um PR reprovado.

#### 16.3 Perguntas na revisão (não é bloqueio)

Dúvida durante a revisão não é impedimento nem correção. Bloqueio é externo e indefinido ("esperando
credencial do financeiro"); uma pergunta entre duas pessoas do time é outra coisa:

- **Mantém a etapa** (o PR continua aberto) e **continua no WIP** — responder é rápido; fingir que saiu de
  circulação seria mentira
- **Não conta como travada** — senão uma dúvida de 20 minutos dilui o sinal de um bloqueio de 6 dias
- **Aponta para o outro lado**: numa revisão só há dois lados, então não se escolhe destinatário

**O ponteiro rastreia DE QUEM É A VEZ, não perguntas.** Cinco dúvidas viram um comentário e uma rodada.
Perguntar de novo sem ter recebido resposta é a **mesma** rodada; só conta rodada nova quando a bola estava
com você. Daí sai um sinal que não existia: **3ª rodada** fica vermelha e o quadro sugere *devolver para
correção* — três idas e voltas quer dizer que o PR está grande demais ou a tarefa foi mal especificada.

**Como a pessoa fica sabendo** (três camadas):
1. Chip **"N p/ você"** no cabeçalho do quadro, primeiro da fila e em cor de marca — é a caixa de entrada;
   clicando, filtra. Também está nos filtros como "Só as que esperam por você"
2. Sino da sidebar (seção 17)
3. KPI "Esperando você" na tela de Tarefas do sistema

**Como responde:** botão **Responder** na própria tarja, que abre o campo no card — sem abrir modal. Pelo
detalhe também funciona, para respostas longas.

Na tarja, o **nome ocupa linha própria**: em 197px de row, ícone + badge + tempo consomem 100px fixos e
"AGUARDANDO RESPOSTA DE CAMILA" precisa de 187px — quem deve a resposta, que é a informação inteira da
tarja, era justamente o que sumia.

#### 16.4 Perfis — Admin e Membro

A regra é sobre **capacidade, não senioridade**, e a tela nunca nomeia cargo:

- Membro **não faz triagem**: prioridade e responsável **somem** do formulário (não aparecem desabilitados),
  com a ausência explicada uma vez
- Membro **só move o que já está com ele**. Como entrar em Aberta solta o responsável (AC-130), a fila de
  triagem fica fora do alcance dele sem precisar de regra própria
- Recusa sempre com o motivo dito: *"Esta tarefa está com Camila Reis. Só um admin move o trabalho de outra
  pessoa."*
- Excluir é só de admin
- **Não se restringe** abrir, comentar, bloquear, perguntar, checklist nem mover as próprias. Travar isso não
  impede ninguém de trabalhar em algo não pedido — impede de REGISTRAR, e o quadro passa a mentir

**Prioridade "A definir"** (`nao_definida`), em âmbar: sem ela, tarefa aberta por quem não faz triagem
cairia em "Média" por omissão e o padrão viraria uma mentira que ninguém revisa. O cabeçalho da coluna
Aberta mostra "N aguardando triagem".

#### 16.5 Card, ações e o resto

**Card:** título · prioridade · resumo (1 linha) · tarjas de pergunta / retorno / bloqueio quando houver ·
rodapé com avatar do responsável (círculo tracejado quando não há — AC-130), sistema, tempo na etapa, selo
de checklist `3/5`, selo de comentários e três botões de ícone: **bloquear/destravar** (sempre válido),
**concluir** (só onde o fluxo permite — botão fixo ficaria morto na maioria dos cards) e **Mover ▾**.

**Menu Mover ▾** lista os destinos válidos, marcando os que pedem motivo. É o único caminho para Cancelar.

**Painel de motivo** — soltar numa etapa que pede texto NÃO deve parecer falha: coluna de destino continua
realçada, painel tintado na cor do destino com barra de 2px e entrada animada, título nomeando a ação
("Movendo para **Ajustes**"), × para desistir, uma linha dizendo *por que* se pede o texto, foco automático
no textarea, botão nomeando o resultado ("Bloquear tarefa", não "Confirmar"), e botão apagado com
"obrigatório" em âmbar enquanto vazio. O botão de confirmação ocupa a **largura inteira** do painel — com
Cancelar ao lado ele era espremido a 110px e "Liberar para o admin subir" virava "Liberar para o a…";
desistir é o × do cabeçalho ou Esc.

**Checklist (não subtarefa).** Itens marcáveis com barra de progresso, edição no lugar (input sem moldura
até receber foco), reordenação por arraste com linha de inserção, e remoção. Selo `3/5` no card. Não tem
responsável nem etapa, não entra no WIP nem no histórico — subtarefa obrigaria a responder em que coluna ela
mora, se conta no WIP e se o pai anda sozinho. Trabalho que precisa de dono próprio vira tarefa irmã.

**Excluir ≠ Cancelar.** Cancelar encerra com motivo e fica auditável no histórico; excluir apaga o registro.
Por isso excluir pede confirmação em dois passos, vive no rodapé do detalhe (longe do fluxo), é só de admin
e declara a diferença ali mesmo.

**Envelhecimento em todas as colunas**, com limiar próprio (Aberta 24h, Em andamento 72h, Em revisão 24h,
Em staging 24h, Pronta 24h; Backlog não envelhece). O AC-093 limitava a Aberta e Em testes — mas a tarefa
que apodrece é a de Em andamento parada há 3 dias.

**Raias** por Responsável ou Sistema, com cabeçalho de coluna fixo. Em raias por responsável, quem tem mais
de 2 em andamento ganha um selo.

**Reordenar dentro da coluna:** arrastar sobre um card mostra linha de inserção na posição.

**Teclado:** `↑↓` seleção na coluna · `←→` entre colunas · `⇧+←/→` move a tarefa de etapa recusando
transição fora do fluxo · `B` bloqueia/destrava · `M` menu · `Enter` detalhe · `C` criação rápida ·
`N` formulário completo · `/` busca · `Esc` fecha · `?` atalhos.

**Limiar de arraste:** o card abre o detalhe no clique E arrasta. O clique só vale se o ponteiro andou menos
de 4px e não houve arraste nos últimos 300ms.

**Vista mobile.** No celular o quadro não é quadro: uma etapa por vez, trocada por uma tira de chips com
contagem; o cabeçalho mantém o WIP; arrastar sai de cena e mover é o menu. Alvos de 44px, criação rápida
fixa no rodapé. Detalhe, nova tarefa, painel de motivo e resposta inline são os mesmos das duas vistas.

#### 16.6 O que exige banco

1. **Migração de `bloqueada`** (status → marca). Colunas `bloqueado_em`, `bloqueio_motivo`, e uma migração
   que devolva cada tarefa travada à etapa de onde veio — legível no `de_status` do evento aberto. O
   histórico mantém o status antigo. **É o maior risco do redesign.**
2. **Remoção de `ajustes_necessarios`** (status → marca de retorno). Colunas `retorno_de` (o portão) e
   `retorno_motivo`; migrar as tarefas nesse status para `em_desenvolvimento` carimbando `retorno_de` com o
   `de_status` do último evento.
3. **Três status novos** — `em_revisao`, `em_staging`, `pronta_producao` — e migração de `em_testes`. Sem
   informação extra, o destino honesto de quem está em `em_testes` é `em_revisao`.
4. **Conversa:** `rodadas` (int) e `interlocutor` (user_id) na tarefa, mais `pergunta_de`, `pergunta_para`,
   `pergunta_em`. Guardar a contagem dentro do ponteiro NÃO funciona: responder apaga o ponteiro e toda
   rodada nova recomeçaria do 1 — o alerta de 3ª rodada nunca dispararia. Pelo mesmo motivo o interlocutor
   precisa ser persistido: sem ele, responder faz o sistema esquecer com quem estava falando.
   `comentarios.pergunta` (bool) marca qual comentário abriu a rodada.
5. **Prioridade `nao_definida`** em `Tarefa::PRIORIDADES`, e o `booted()` passa a usá-la quando quem cria
   não tem permissão de triagem.
6. **Coluna `ordem`** em `tarefas`, para reordenar dentro da coluna. Não existe hoje.
7. **`tarefa_itens`** (`tarefa_id`, `texto`, `feito`, `ordem`) para o checklist.
8. **Versão na conclusão:** `versao_producao` (string) preenchida pelo painel de conclusão — é o que liga a
   tarefa à tag que o vigia aplicou.

**Antes do teste em staging:** semear dados **envelhecidos** (`tarefa_eventos.entrou_em` retroativo) e
volume realista — com seed novo nada está velho, e o teste não exercita envelhecimento nem estouro de WIP,
que é o que o redesign mais mudou. E **concorrência**: duas pessoas movendo o mesmo card; hoje o segundo
ganha em silêncio. Mandar o `de_status` esperado e recusar com "alguém já moveu esta tarefa" é barato.

**Gancho de integração:** quando o portão de testes reprovar (`deploy-staging-alfamatriz.sh` sai sem
aplicar), a tarefa em Em staging deveria ser bloqueada automaticamente com motivo "portão reprovou" — hoje
ela pensa que está no staging e não está.

### 17. Painel de notificações (sino da sidebar)

O sino no rodapé da sidebar deixou de ser enfeite quando o quadro passou a produzir eventos que alguém
precisa saber sem olhar para ele. Abre um painel de 352px ancorado ao rodapé do menu (acompanha o rail
recolhido), com overlay que fecha ao clicar fora.

- Cabeçalho com "Notificações" e **Marcar lidas**
- Cada item: tile de ícone tingido pelo tipo, título em uma linha e meta (quem/quando)
- **Não lida** = barra de 2px à esquerda na cor do tipo + fundo levemente elevado
- Rodapé: "Ver tudo que exige ação" → Centro de Controle
- Fonte dos eventos: pergunta aguardando resposta, devolução para correção, tarefa travada há N dias,
  tarefas aguardando triagem, receitas atrasadas, conclusões e geração de faturamento — os mesmos que
  alimentam a fila de ação do Centro de Controle. O badge conta as não lidas.

**Entrega por e-mail/Slack sai do mesmo evento** e é backend: o desenho para em produzir e mostrar o evento.

**Armadilha de implementação:** o `<aside>` é `position:sticky`, e sticky **cria contexto de empilhamento**
mesmo com `z-index:auto`. Sem um `z-index` explícito no aside, o painel e o overlay ficam escopados dentro
dele e são pintados atrás do `<main>` — ilegíveis e não clicáveis. O aside precisa de `z-index` acima do
header.

---

## Gráficos

Todos em SVG inline, sem biblioteca. Reaproveite/evolua `components/bar-chart.blade.php`.

**1. Barras "Entradas x saídas" (Painel Financeiro)** — `viewBox="0 0 720 260"`.
Escala: `max = maiorValor × 1,15`; área de plotagem 704×204 com `paddingTop:24`; 6 grupos
(`groupWidth = 704/6`), barra de 28px, `gap:4`, sem raio. Entradas `#029caf`, saídas `chartOut`.
Rótulo do valor em mono 10px acima da barra ("38,2k") e mês em mono 11px na base. 4 linhas de grade
horizontais em `gridStroke`.

**2. "Origem do MRR" (Centro de Controle)** — régua horizontal, não gráfico de barras empilhadas.
Escala fixa de **0 a 35.000** com **linhas-guia em 10k / 20k / 30k** dentro da pista de cada linha e
**eixo rotulado** ao pé (0 · 10k · 20k · 30k). Cada linha: nome + "624 clientes · 17% do MRR" à esquerda
(`flex:0 0 38%`), pista com a barra de 12px (`tint(cor, 0.55)` + cap sólido de 2px na ponta) e, à direita,
valor e delta em mono (`width:74px`). **Ordenada por valor.**
Cuidado com o bug original: as barras precisam ser proporcionais ao valor (Venda direta, R$ 33.000, aparecia
menor que Invest, R$ 8.940) e as colunas precisam ser proporcionais, senão a pista colapsa para 0 em painel estreito.

**3. Faixas segmentadas (rankings do Comercial, aging do financeiro)** — `display:flex; gap:2px; height:8px`,
largura de cada segmento = share, `min-width:3px`, cor = tint com alpha decrescente, `title` por segmento.

**4. Sparklines (KPIs e cartões de conta)** — `viewBox="0 0 88 26"`, path de 6 pontos normalizado entre
min e max, `stroke-width:1.6`, `stroke-linecap/linejoin:round`, sem fill.

---

## Interactions & Behavior

Tudo com Alpine.js; nada exige SPA.

| Interação | Comportamento |
|---|---|
| **Recolher/expandir menu** | Botão no topbar. `width` 228px ↔ 60px com `transition: width 180ms ease`. Rótulos e wordmark somem (`display:none` + `opacity:0` com `transition: opacity 140ms`). Rodapé passa a `flex-direction:column`. Divisórias de grupo aparecem. **Persistir em `localStorage`.** |
| **Alternar tema** | Botão no rodapé da sidebar, ícone sol/lua. Troca o conjunto de tokens inteiro (13 telas de uma vez). **Persistir em `localStorage`**; implementar como `class="dark"` no `<html>` + variantes `dark:` do Tailwind, ou via CSS custom properties. |
| **Navegação** | Links normais do Laravel; item ativo por `request()->routeIs()`, como já é feito hoje. Clientes → formulário e Caixa → Extrato mantêm o item pai ativo. |
| **Botões da fila de ação** | Navegam para a tela do problema (Receitas, Despesas, Funil, Produtos). |
| **Seleção em massa** | Checkbox por linha alimenta um array Alpine; a barra aparece quando `length > 0`, mostrando contagem e soma. Reaproveitar as rotas `baixarEmMassa` que já existem. |
| **Kanban** | Arrastar o card entre colunas (`cursor:grab`). Manter o fallback "Mover ▾" para acessibilidade. Cada coluna rola por dentro; o quadro rola na horizontal. |
| **Faturamento** | Trocar competência recarrega a prévia. Checkbox por revenda controla o que será gerado, e a contagem entra no rótulo do botão. |
| **Hover de linha** | `background: chipBg`. |
| **Hover de botão secundário** | Borda e texto vão para `#029caf`. |
| **Hover de botão primário** | `background: #26d4e6`. |
| **Hover de ação em ícone** | `background: chipBg`; ações destrutivas usam `critTint`. |
| **Tabelas estreitas** | `<table>` com `min-width` dentro de wrapper `overflow-x:auto`; o raio arredondado fica no painel externo. **Sem isso a coluna de ações fica inalcançável.** |
| **Grids de card** | `repeat(auto-fit, minmax(200–250px, 1fr))` — nunca `repeat(N, 1fr)`, senão os números quebram no meio em janela estreita. |
| **Busca ⌘K** | Não implementada no protótipo; o campo é a âncora visual. Se implementar, paleta de comandos sobre clientes/revendas/cobranças. |
| **Notificações** | Badge com contagem; abrir dropdown com a mesma fila de ação do Centro de Controle. Não desenhado. |

## Responsive behavior

Desktop primeiro, mobile funcional (é a orientação do cliente).

- ≥1280px: layout como desenhado
- 1024–1280px: grids de KPI refluem por `auto-fit`; tabelas rolam na horizontal
- <1024px: sidebar deve virar drawer sobreposto (o overlay com `x-show="sidebarOpen"` já existe no
  `layouts/app.blade.php`); topbar mantém só o botão de menu, título e busca em ícone
- <768px: KPIs em 1–2 colunas; tabelas viram lista de cards (cada linha um card com os pares rótulo/valor);
  o kanban rola na horizontal com colunas de largura cheia

## State Management

| Estado | Onde vive | Observação |
|---|---|---|
| `railOpen` | Alpine + `localStorage` | largura da sidebar |
| `theme` | Alpine + `localStorage` | `'dark'` \| `'light'` |
| Tela atual | rota do Laravel | — |
| `selecionados[]` | Alpine, por tabela | seleção em massa |
| `competencia` | query string | prévia de faturamento |
| `modo` (produtos) | Alpine + `localStorage` | `'lista'` \| `'card'` — já existe hoje |
| Filtros | query string | busca, status, revenda, sistema, tipo |

## Assets

| Arquivo | Origem | Uso |
|---|---|---|
| `uploads/alfamatriz.png` | **fornecido pelo cliente** — wordmark oficial AlfaMatriz (1899×312, transparente) | header da sidebar (altura 15px), login (17px) |
| `assets/icon-matriz.svg` | **criado neste projeto** a pedido do cliente | ícone do app: um nó central ligado a quatro nós periféricos — a matriz que enxerga e cobra as revendas. Geométrico, na paleta da marca. Substitui o `public/logo-tile.svg` |
| `assets/icon-matriz-solid.svg` | **criado neste projeto** | variante sólida do ícone: disco cheio em `#029caf` com o desenho **vazado** (transparente, via `<mask>`), sem borda. Para favicon, app icon, avatar e qualquer uso sobre fundo claro ou fotografia. Duas restrições geométricas que precisam ser mantidas se o ícone for redesenhado: o desenho é recuado do aro (nós externos a 9,4 do centro, disco a 16) para a silhueta circular não ser mordida; e o nó central é um **anel** vazado (furo r=4,1 com núcleo opaco r=1,9) com os raios começando em 5,8 — se tudo for vazado de uma vez, o miolo funde com os raios num único furo e o ícone perde a leitura de hub |
| `assets/logo-tile.svg` | `public/logo-tile.svg` do repositório | referência do ícone anterior |
| Ícones de UI | traçados de `resources/views/components/nav-icon.blade.php` (família Heroicons outline, `stroke-width` 1.6–1.8) | menu, ações, tiles. **Continue usando o componente `<x-nav-icon>`** || Ícone de recolher painel | novo, padrão "panel-left" | retângulo com divisória vertical à esquerda |
| Fontes | Space Grotesk (fonts.bunny.net), Geist + Geist Mono (Google Fonts) | ver Tipografia |

Nenhum asset de terceiros licenciado foi usado. Não há imagens de conteúdo — a interface é toda tipografia,
ícone e dado.

## Files

| Arquivo | O que é |
|---|---|
| `AlfaMatriz Sistema.dc.html` | **O design final.** As 14 telas + login, tema claro/escuro, sidebar expansível, painel de notificações, navegável pela sidebar. É a referência principal. |
| `AlfaMatriz Tarefas.dc.html` | **O quadro de Tarefas, interativo de ponta a ponta** — arraste com reordenação, menu Mover, bloqueio como marca, checklist, raias, perfis Admin/Membro, teclado, histórico e vista mobile. Referência da seção 16. |
| `AlfaMatriz Redesign.dc.html` | Canvas de exploração: as três direções iniciais (1a Cockpit, 1b Leitura calma, 1c Terminal), a revisão de raio (2a Preciso, 2b Réguas) e **3a**, a direção aprovada. Útil para entender *por que* o design é assim. |
| `AlfaMatriz Atual.dc.html` | Recriação fiel da interface **antes** do redesign, para comparação lado a lado. |
| `support.js` | Runtime que renderiza os `.dc.html`. Não é código de produção. |
| `assets/`, `uploads/` | Ícone, wordmark e logo anterior. |

Arquivos do repositório que este redesign substitui ou altera:
`resources/views/layouts/app.blade.php`, `layouts/navigation.blade.php`, `layouts/guest.blade.php`,
`components/stat-card.blade.php`, `components/bar-chart.blade.php`, `components/nav-icon.blade.php`,
`centro-controle/index`, `dashboard`, `dashboard-comercial`, `leads/index`, `revendas/index`,
`clientes/index`, `clientes/_form`, `produtos/index`, `faturamento/index`, `cobrancas/index`,
`contas-pagar/index`, `contas-financeiras/index`, `contas-financeiras/extrato`,
`cadastros-auxiliares/index`, `auth/login`, `resources/css/app.css`, `tailwind.config.js`.

---

## Armadilhas já encontradas (não repita)

Estas foram corrigidas no protótipo depois de revisão; a implementação vai tropeçar nas mesmas se copiar
ingenuamente.

1. **Título do topbar cortado ao meio.** `h1` e a linha de contexto com o mesmo `flex-shrink` → o texto
   secundário (mais longo) rouba o espaço. Solução: `h1` com `flex:0 0 auto; max-width:100%`, contexto com
   `flex:0 1 auto; min-width:0`, e a busca com `flex:0 1 auto; min-width:168px`.
2. **Coluna de ações inalcançável.** Painel com `border-radius` + `overflow:hidden` corta a tabela sem
   oferecer rolagem. Solução: wrapper interno `overflow-x:auto` + `min-width` na `<table>`.
3. **Números quebrando no meio** ("R$" / "52.430"). `repeat(N,1fr)` sem piso. Solução: `auto-fit minmax()`
   e `white-space:nowrap` nos valores.
4. **Linhas de total com duas alturas.** Rótulos em mono/uppercase com `letter-spacing` largo quebram.
   Solução: `white-space:nowrap` em **todas** as células da linha de total.
5. **Contraste do tema claro.** `#95a3a8` para texto informativo media 2,6:1. Usar `#66777e` / `#5d6e75`.
6. **Ícones do rail descentralizados.** A barra de rolagem do menu reserva 11px. Solução:
   `scrollbar-width:none` no menu quando recolhido + `justify-content:center` + `padding-right:3px` para
   compensar a marca de 3px do item ativo.
7. **Divisória invisível.** `border-top` em elemento de altura zero não pinta. Use um `div` de 1px com
   `background`.
8. **Botão com ícone fora de centro.** O `display` do botão vinha de um valor `block` compartilhado com os
   rótulos — sem `flex`, `justify-content` não vale.
9. **Conteúdo deslizando ao recolher o menu.** Bloco com `max-width` fixa viaja inteiro em vez de refluir.
   Não fixe largura máxima nas telas de tabela.
11. **A vista sem raias e a com raias querem larguras opostas.** No quadro de Tarefas, sem raias o wrapper
    precisa de `min-width:0` + cadeia `flex:1/min-height:0` (colunas dividem o espaço, cada uma rola por
    dentro); com raias precisa de `min-width:max-content` e altura automática (colunas fixas em 272px,
    o quadro inteiro rola). Misturar os dois quebra um dos modos.
12. **`flex:1 1 272px` sem `min-width` resolve pelo min-content** e a coluna encolhe até o card ficar
    ilegível. O piso é obrigatório: com ele a coluna cresce quando há espaço e transborda quando não há.
13. **`align-items:center` num container com `overflow`** joga o excedente do filho para fora da caixa
    rolável — o topo fica inalcançável. Use `margin:auto` no filho + `max-height:100%`.
14. **Estado vazio precisa saber que há recorte.** Um único `recorteAtivo` alimenta o texto da coluna, o
    botão Limpar e o subtítulo. Separados, a coluna Aberta dizia "fila de triagem vazia" com 3 tarefas
    dentro, enquanto o botão Limpar aparecia na mesma tela.
15. **A vista escolhe a FORMA; a aba escolhe O QUE aparece.** Misturar as duas condições fez a aba
    Histórico não abrir na vista celular.
16. **Rodapé do card é flex `nowrap`:** o único elemento com `flex:1` absorve todo o encolhimento. Com 6
    selos, o nome do sistema virava "Alfa…". Dê piso ao que não pode sumir e reduza o custo fixo (o botão
    Mover virou ícone por isso).
17. **`nowrap` sem `overflow:hidden` PINTA POR CIMA do irmão** em vez de cortar. Foi a causa de quatro
    defeitos diferentes nesta fase (subtítulo do quadro, barra superior, tarja de pergunta, nota lateral).
    Regra: todo texto `nowrap` num item flexível precisa de `overflow:hidden`.
18. **`flex-shrink:1` nos dois lados REPARTE o déficit** proporcionalmente à base — não protege ninguém. Para
    o texto de identidade sobreviver, ele precisa de `flex:0 0 auto` e o texto auxiliar de `flex:0 1 auto`.
19. **Texto de ajuda ou aparece inteiro ou não aparece.** "ARRASTE ENTRE COLU…" é pior que a ausência. Quando
    não couber, esconda; a elipse serve para conteúdo, não para dica.
20. **`position:sticky` cria contexto de empilhamento** mesmo com `z-index:auto`. Overlay e painel dentro de
    um aside sticky são pintados atrás do `<main>`. Dê `z-index` explícito ao aside (ou monte o overlay fora
    dele).
21. **Tinta de superfície não serve de fundo de overlay.** `panelBg` é `rgba(255,255,255,0.025)` — feita para
    sobrepor o canvas. Painel flutuante precisa de cor opaca (`sideBg`).
22. **Temporal dead zone em `renderVals()`:** `const` lido antes da declaração derruba a tela inteira, e o
    sintoma (template pintado, zero valores resolvidos) não parece erro de JS. Declare tudo que os
    construtores de card/coluna leem no TOPO da função.
23. **Estado de conversa dentro do objeto que é apagado** se perde. Contagem de rodadas e interlocutor vivem
    na TAREFA, não no ponteiro de pergunta — responder limpa o ponteiro.
