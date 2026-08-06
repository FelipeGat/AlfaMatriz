# Handoff: Redesign do AlfaMatriz + nova identidade

> Pacote de entrega para implementação assistida por Claude Code no repositório **AlfaMatriz** (Laravel 12 + Blade + Tailwind + Alpine.js).

## Visão geral

Redesign visual completo do painel interno da Alfa Tecnologia (revendas, clientes, sistemas licenciados, faturamento e financeiro), mais a criação do ícone da marca.

Direção visual definida com o cliente: **funcional/minimalista inspirada em Vercel/Linear** — neutros puros, tipografia Geist, botões monocromáticos, header fino com breadcrumb, sidebar fixa, zero sombras, cor somente como semântica (indicadores e gráficos).

Objetivos, na ordem em que foram pedidos:
1. Visual moderno e minimalista (direção "blueprint" Vercel/Linear).
2. Sidebar fixa expandida de 240px (SEM colapsar — decisão final do cliente).
3. Tema **dark e light** com alternância.
4. Marca monocromática na UI; cor viva apenas em gráficos e indicadores.

## Sobre os arquivos deste pacote

Os arquivos HTML aqui são **referências de design** — protótipos que mostram aparência e comportamento pretendidos. **Não são código de produção para copiar.** A tarefa é **recriar esses designs dentro do ambiente já existente do AlfaMatriz**: Blade + Tailwind + Alpine.js, seguindo os padrões do repositório (componentes em `resources/views/components`, layouts em `resources/views/layouts`, tokens em `tailwind.config.js`). Nada de introduzir React, SPA ou build novo.

## Fidelidade

**Alta fidelidade (hi-fi).** Cores, tipografia, espaçamentos, raios e estados estão definitivos.

---

## Identidade da marca

### Ícone (escolhido: "convergência 13c")

Duas setas convergindo para um núcleo sólido — as revendas convergem para a matriz.

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none">
  <path d="M5 4l13 15L5 34" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" opacity=".38"/>
  <path d="M43 4L30 19l13 15" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" opacity=".38"/>
  <circle cx="24" cy="39" r="6.6" fill="currentColor"/>
</svg>
```

Regras:
- **Na UI o ícone é monocromático**: `color: var(--ink)` (branco no dark, quase-preto no light). O teal `#029caf` fica reservado para o favicon e materiais externos.
- Setas sempre a 38% de opacidade; núcleo a 100%. Traço 6 (versão "bold" aprovada).
- Ângulo entre as hastes: 98°; vão entre as pontas: 12 unidades do viewBox. Não alterar — geometria calibrada para não ler como rosto.
- ViewBox recortado para alinhamento: `viewBox="2 1 44 45.6"`.

Arquivo pronto: **`favicon.svg`** (traço 4.4, teal — versão externa). Copiar para `public/favicon.svg`:

```blade
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
```

### Wordmark

Arquivo **`alfamatriz-wordmark.png`** — fornecido pelo cliente (pedir versão SVG antes de publicar). Na UI é renderizado **monocromático** via filtro CSS: `filter: brightness(0)` no light, `brightness(0) invert(1)` no dark (token `--logo-filter`).

### Lockup na sidebar

Ícone 24×23px + wordmark 14px de altura, `gap: 10px`, faixa de 48px de altura, `padding: 0 16px`, **sem borda inferior**.

---

## Design tokens

CSS custom properties trocadas por `data-theme` no `<html>`; mapear no `tailwind.config.js` (cada cor Tailwind aponta para a var).

### Tema dark (padrão)

| Token | Valor | Uso |
|---|---|---|
| `--bg` | `#0a0a0a` | fundo da aplicação e da sidebar |
| `--panel` | `#111111` | cards, tabelas |
| `--raised` | `#1a1a1a` | cabeçalho de tabela, inputs, chips, card de destaque |
| `--border` | `#262626` | todas as bordas |
| `--ink` | `#ededed` | texto primário, botões primários, ícone da marca |
| `--dim` | `#a1a1a1` | texto secundário |
| `--mute` | `#7a7a7a` | rótulos, terciário |
| `--brand` | `#2ec9d9` | detalhes de marca (marcador do catálogo) |
| `--chart` | `#2ec9d9` | série principal dos gráficos |
| `--good` | `#4ac97e` | positivo |
| `--warn` | `#e0a13a` | atenção / saídas |
| `--bad` | `#e5484d` | vencido / negativo |
| `--track` | `#222222` | trilho de barra |
| `--track2` | `#333333` | série secundária |
| `--nav-active` | `#1c1c1c` | item de menu ativo |
| `--nav-hover` | `#161616` | hover de menu |
| `--logo-filter` | `brightness(0) invert(1)` | wordmark mono |

### Tema light

| Token | Valor |
|---|---|
| `--bg` | `#fafafa` |
| `--panel` | `#ffffff` |
| `--raised` | `#f4f4f4` |
| `--border` | `#ebebeb` |
| `--ink` | `#171717` |
| `--dim` | `#666666` |
| `--mute` | `#8f8f8f` |
| `--brand` | `#017d8c` |
| `--chart` | `#029caf` |
| `--good` | `#0f7c46` |
| `--warn` | `#b4671f` |
| `--bad` | `#c62f38` |
| `--track` | `#ebebeb` |
| `--track2` | `#d4d4d4` |
| `--sidebar` | `#fafafa` |
| `--nav-active` | `#ececec` |
| `--nav-hover` | `#f2f2f2` |
| `--logo-filter` | `brightness(0)` |

Regra de cor: **UI monocromática**. Cor viva só em: gráficos (`--chart`, paleta da rosca `#029caf #2ec9d9 #0f7c8a #7fdce6 #e8a045 #8fa4a8`, `--warn` no ranking por valor), indicadores dos cards de resumo (`--good`/`--bad`/`--warn` nos valores e barras) e badges de estado. Botões primários, seleção de menu, links "Ver todas", avatares, tags e sparkline são todos neutros (ink/raised/hairline).

### Tipografia

**Geist** (interface, 400/500/600) + **Geist Mono** (todo número, valor, data, percentual e rótulos uppercase). Via Bunny/Google Fonts. Inter, Space Grotesk e IBM Plex saem. `font-variant-numeric: tabular-nums` no body.

| Elemento | Especificação |
|---|---|
| Breadcrumb do header | grupo 13px/400 `--mute` + "/" + título 13px/500 `--ink` |
| Título de card (h3) | 15px/600 Geist (14px nos menores) |
| Rótulo de KPI / cabeçalho de tabela | 10px/500 Geist Mono, uppercase, tracking .06–.08em, `--mute`, truncando com reticências |
| Valor de KPI | `clamp(19px,2.1vw,26px)`/500 Geist Mono, tracking -.03em, `nowrap` |
| Valor de card de resumo | `clamp(17px,1.6vw,21px)`/500 Geist Mono, `nowrap` |
| Célula de tabela | texto 12.5–13px/400–450 Geist; valor 12.5px/500 Geist Mono `nowrap` |
| Badge de status | 10px/500 Geist Mono uppercase |
| Item de menu | 13px, 400 (500 quando ativo), altura 34px |
| Título de grupo do menu | 11px/500 Geist, sem caixa alta, `--mute` |

### Raios, espaçamento, sombras

- Raios: cards **8px** · botões/inputs/menu **6px** · badges **4px** · modal **12px** · barras 2–4px · topo das barras de gráfico `4px 4px 0 0`.
- Padding: cards `22px 24px` · resumo `16px 18px` · linhas de tabela `12–13px 20px` · main `24px 26px 40px`.
- **Zero sombras** (exceção: overlay de modal/toast). Separação por hairline `1px solid var(--border)` e diferença de superfície.

---

## Estrutura

### Sidebar (`layouts/navigation.blade.php`)

- **Fixa, 240px, sem colapso.** Fundo = `--bg` (dark) / `#fafafa` (light), borda direita hairline.
- Topo: lockup mono (48px de faixa, sem borda inferior).
- Abaixo do lockup: **campo de busca estilo "Find"** — 32px de altura, borda hairline, raio 6px, lupa 13px, placeholder "Buscar", kbd `/` desenhado à direita (11px Geist Mono com borda).
- Navegação: grupos Painéis / Comercial / Financeiro / Sistema (mesmos itens de hoje); título de grupo 11px sem caps; item 34px com ícone 18px stroke 1.7; ativo = fundo `--nav-active` + texto ink (sem cor de marca, sem barrinha); hover = `--nav-hover`.
- Scrollbar da navegação **escondida** (rolagem preservada).
- Rodapé: avatar quadrado 28px raio 4px em `--raised`, nome 13px/500, papel 12px `--mute`.

### Header (`layouts/app.blade.php`)

Fino, **48px**, borda inferior hairline, `padding: 0 20px`:
- Esquerda: breadcrumb `Grupo / Título` (13px, separador barra diagonal em `--border`).
- Direita: botão de tema 30×30px sem borda (hover `--raised`).
- Sem busca no header (ela vive na sidebar).

### Botões

- **Primário: monocromático invertido** — `background: var(--ink); color: var(--bg)`, raio 6px, 12.5–13px/500. (Como o "Add New" da Vercel.)
- Desabilitado: `--raised` + `--mute`, cursor default.
- Secundário: transparente com borda hairline, texto `--dim`.
- Segmented control: container `--raised` com borda; item ativo = `--panel` + borda hairline + texto ink, 11.5px Geist Mono.

---

## Telas

(Anatomia detalhada de cada tela — Painel Financeiro, Painel Comercial com rosca do ranking, listas com cards de resumo, Sistemas com catálogo+detalhe, Faturamento — está no protótipo `AlfaMatriz.dc.html`, que é a fonte da verdade. Pontos não óbvios abaixo.)

### Painel Financeiro
- 4 KPIs em `repeat(auto-fit,minmax(230px,1fr))`; valores `nowrap` + clamp (nunca quebram linha). Entradas com valor em `--good` e barra `--good`; Saídas em `--warn`.
- Gráfico Entradas × Saídas: barras lado a lado (`flex-shrink:0`), série principal `--chart`, secundária `--track2`, topo arredondado 4px, mês corrente com rótulo em ink.
- Card "Fechamento de agosto": **neutro** (`--raised` + hairline), não mais teal.
- Links "Ver todas": `--dim` com hover ink (não teal).

### Painel Comercial
- Ranking: rosca SVG (r=47, stroke 11, gap 2.5) com a paleta viva de 6 tons + lista com marcador de cor, posição em mono, participação %.
- Alternador "Por clientes / Por valor"; por valor usa `--warn`.
- KPIs derivados da mesma base de dados das outras telas (números batem entre si).

### Listas (Revendas, Clientes, Receitas, Despesas)
- Cards de resumo no topo (`auto-fit/minmax(200px,1fr)`); em Clientes eles **reagem aos filtros**. Valores com semântica de cor viva: vencidas `--bad`, baixadas/entradas `--good`, totais de saída `--warn`, neutros ink.
- Tabelas: colunas de valor/status com piso fixo (`minmax(100–112px,…)` / 74–104px), valores `nowrap`, cabeçalhos truncando; linhas com hover `--raised`.
- Badges: Ativo/Baixada verde-soft, Vencida vermelho-soft, Aberta/Inativo `--raised`+`--dim`, Gerada `--raised`+ink.

### Sistemas
- Flex com quebra: catálogo `flex:1 1 260px` (linhas com barra de proporção em `--chart`, selecionado `--raised` + filete 2px `--brand`) + detalhe `flex:5 1 420px`.
- Detalhe: avatar quadrado neutro com hairline; sparkline em **ink** sobre `--track`; escada de preço por faixa com "Faixa atual" em ink; "Quem revende" = top 5 + rodapé "N outras · X clientes" + Ver todas (escala para 50 revendas).
- Cabeçalho do detalhe: `flex-wrap:wrap` + título `flex:1 1 220px` (evita colisão badge×botão).

### Interações
| Interação | Comportamento |
|---|---|
| Tema | `data-theme` no `<html>` + localStorage; ícone sol/lua |
| Busca | filtra Clientes por nome/revenda/sistema; resumo recalcula |
| Novo cliente | modal 460px (nome, revenda, sistema, mensalidade, Ativo/Inativo); salvar inerte sem nome; ao salvar limpa filtros, insere no topo, toast |
| Gerar faturamento | modal com contagem/total reais das pendentes; confirmação gera cobranças que aparecem em Receitas; botão vira desabilitado |
| Dar baixa | linha de Receitas/Despesas; status → Baixada; resumos recalculam |
| Toast | inferior central, `--panel` + hairline, ponto `--good`, 2.6s |
| Animações | fadeIn .15s, popIn .18s, toastIn .22s, hovers .13s — nada acima de 220ms |

No Laravel: filtros por query string; só o puramente visual (tema, modais, toast) no Alpine.

## Assets

| Arquivo | Destino |
|---|---|
| `favicon.svg` | `public/favicon.svg` |
| `alfamatriz-wordmark.png` | `public/brand/` (pedir SVG ao cliente) |

Ícones: Heroicons outline, stroke 1.7, 18px na navegação (paths na constante `ICONES` do protótipo).

## Arquivos do repositório a alterar

- `tailwind.config.js` — paleta (vars) e fontes Geist
- `resources/css/app.css` — custom properties dos dois temas, tabular-nums, scrollbar
- `resources/views/layouts/app.blade.php` — header breadcrumb, favicon, fontes, data-theme
- `resources/views/layouts/navigation.blade.php` — sidebar fixa com busca e lockup mono
- `resources/views/components/` — `stat-card`, `summary-card` (novo), `bar-chart`, `nav-icon`, `modal`, `primary-button`, `text-input`, badge de status
- `dashboard.blade.php`, `dashboard-comercial.blade.php`, `{revendas,clientes,cobrancas,contas-pagar,sistemas,faturamento}/index.blade.php`

## Ordem sugerida

1. Tokens + Geist + toggle de tema.
2. Sidebar (busca, lockup mono) + header breadcrumb + favicon.
3. Componentes base (botão mono, summary-card, badge, tabela).
4. Painéis Financeiro e Comercial.
5. Listas, Sistemas, Faturamento.
6. Modais, toasts, estados vazios.

## Armadilhas já mapeadas

- Valor monetário quebrando linha → `nowrap` + `minmax` com piso na coluna.
- Cabeçalho de tabela invadindo vizinha → truncar com reticências.
- Números divergentes entre telas → derivar tudo da mesma consulta (inclusive textos de modal/toast).
- Gap fantasma da sidebar → sem estado de colapso isso não se aplica mais, mas a scrollbar da navegação deve ficar escondida (ela desalinhava os ícones).
- Barras de gráfico: irmãs lado a lado com `flex-shrink:0`, alturas percentuais fiéis.
- Hovers: declarar com os tokens do tema (regras literais), não interpolar objetos dinâmicos.

## Arquivos deste pacote

| Arquivo | O que é |
|---|---|
| `AlfaMatriz.dc.html` | Protótipo navegável completo — **fonte da verdade**. Abrir no navegador. |
| `AlfaMatriz Redesign.dc.html` | Canvas de exploração (rodadas de ícone e direções iniciais). Histórico. |
| `favicon.svg` | Ícone final. |
| `alfamatriz-wordmark.png` | Wordmark oficial. |
