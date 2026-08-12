# Tarefas — especificação dimensional

Este arquivo existe porque a tela de Tarefas é a mais densa do redesign e reproduzi-la "de olho" não
funciona. Aqui estão os valores exatos.

## Antes de tudo: o protótipo É a especificação dimensional

A regra "não porte o código dos protótipos" vale para a **arquitetura** (não traga React, não traga
`useState`, não traga a estrutura de componentes). Ela **não** significa ignorar o arquivo.

`AlfaMatriz Tarefas.dc.html` tem todos os estilos inline, em `px` e `hex` literais. **Abra o arquivo num
editor e leia.** Procure pelo texto de um elemento e o `style=` ao lado dele é a especificação daquele
elemento. Nada precisa ser adivinhado nem medido em screenshot.

Duas formas de trabalhar, ambas válidas:

- **Grep no fonte:** `grep -n 'AGUARDANDO\|Mover\|prioBg' "AlfaMatriz Tarefas.dc.html"`
- **Inspecionar no navegador:** abra o arquivo, F12, e leia o computed style. Os valores são os mesmos.

Onde os temas divergem, os dois objetos de tema estão no fonte: `const DARK = {...}` e `const LIGHT = {...}`.
Toda cor da tela sai de uma chave desses objetos — nenhuma cor é escrita solta no meio do template.

## Tema — as chaves e o que elas valem

| Chave | Escuro | Claro | Uso |
|---|---|---|---|
| `canvas` | `#070d0f` | `#f5f8f9` | fundo da página |
| `panel` | `#0a1215` | `#ffffff` | cabeçalhos, painéis flutuantes (opaco) |
| `panelRaised` | `#101a1e` | `#ffffff` | fundo do card |
| `board` | `rgba(0,0,0,0.28)` | `#eaf0f1` | fundo do quadro |
| `input` | `rgba(255,255,255,0.03)` | `#ffffff` | campos |
| `chip` | `rgba(255,255,255,0.05)` | `#eef3f4` | selos neutros |
| `line` | `rgba(255,255,255,0.12)` | `rgba(11,26,30,0.14)` | bordas |
| `rule` | `rgba(255,255,255,0.10)` | `rgba(11,26,30,0.12)` | divisores internos |
| `btnLine` | `rgba(255,255,255,0.12)` | `rgba(11,26,30,0.15)` | borda de botão |
| `ink` | `#e6eef0` | `#0b1a1e` | texto principal |
| `inkDim` | `#9db0b6` | `#4a5c62` | texto secundário |
| `inkMute` | `#8798a0` | `#5d6e75` | texto terciário |
| `inkFaint` | `#7b8c93` | `#63747b` | rótulos mono |
| `brandSolid` | `#029caf` | `#029caf` | marca (igual nos dois) |
| `brandText` | `#5be3ef` | `#06788a` | texto na cor da marca |
| `onBrand` | `#04181b` | `#ffffff` | texto sobre a marca |
| `good` | `#0ca30c` | `#0a7a0a` | sucesso |
| `warn` | `#fab219` | `#b57500` | atenção |
| `crit` | `#d03b3b` | `#c02b2b` | crítico |
| `accent` | `#5be3ef` | `#029caf` | entrada do fluxo |
| `amber` | `#e8a045` | `#b06a12` | prioridade alta |

**Tintas:** toda tarja e selo colorido usa a cor com alfa `tintAlpha` — **0.13 no escuro, 0.10 no claro**.
No fonte é a função `tint(rgb, a)`. Ex.: fundo do selo de prioridade crítica no escuro =
`rgba(208,59,59,0.13)`.

Sombra do card: `0 1px 2px rgba(0,0,0,0.35)` (escuro) / `0 1px 2px rgba(11,26,30,0.07)` (claro).
Durante o arraste: `0 6px 16px -6px rgba(0,0,0,0.6)` / `0 6px 16px -6px rgba(11,26,30,0.18)`.

## Tipografia

Três famílias, com papéis fixos:

- **Geist** — corpo, rótulos de campo, texto de card
- **Geist Mono** — números, datas, rótulos em caixa alta, contadores, tudo tabular
- **Space Grotesk** — títulos e números grandes (dialoga com o wordmark)

Tamanhos usados na tela, sem exceção: `9px`, `9.5px`, `10px`, `10.5px`, `11px`, `11.5px`, `12px`, `12.5px`,
`13px`, `13.5px`, `14px`, `14.5px`, `15px`, `15.5px`, `16px`, `17px`.

Rótulo em caixa alta mono: `letter-spacing:0.08em` a `0.16em` conforme o tamanho — quanto menor a fonte,
maior o tracking. Os valores exatos estão no fonte.

## Coluna

```
largura        272px  (flex: 1 1 272px, min-width: 272px)   ← o min-width é obrigatório
gap entre      10px
recolhida      42px   (flex: 0 0 42px)
borda          1px solid line
borda topo     3px solid <cor da etapa>
raio           6px  (0 0 6px 6px quando sem raias, porque o cabeçalho fica colado em cima)
```

**Cabeçalho da coluna** — `padding: 9px 8px 9px 12px`, fundo `panel`:

```
[ponto 7px redondo cor-da-etapa]  gap 8px
[h3  Space Grotesk 13.5px/600 ink, nowrap + overflow:hidden]  flex:1
[contador  altura 19px, padding 0 6px, raio 9999px, mono 10px/600]
[botão recolher  19×19px, ícone 12px]
```

Linha de aviso abaixo (portão da etapa, ou "acima do limite de 3", ou "N aguardando triagem"):
`margin-top:5px`, mono `9.5px`, `letter-spacing:0.1em`, caixa alta, `nowrap` + `overflow:hidden`.
Cor: `warn` quando é alerta, `inkFaint` quando é só o portão.

**Corpo:** `padding:10px`, `gap:10px` entre cards, `overflow-y:auto`, `flex:1; min-height:0`.

**Rodapé de criação rápida** (só em Aberta e Backlog): `padding:8px 10px`, `border-top:1px solid rule`,
input `height:30px`, `border:1px dashed btnLine`, raio 5px, `12px`.

## Card

```
fundo    panelRaised        raio 5px        padding 10px
borda    1px solid — a cor muda por estado, nesta precedência:
         1. painel de motivo aberto → cor do destino
         2. bloqueada OU voltou     → warnLine  rgba(250,178,25,0.4)
         3. tem pergunta            → rgba(2,156,175,0.4)
         4. selecionada (teclado)   → brandSolid
         5. envelhecida             → tint(nível, 0.4)
         6. normal                  → line
```

**Linha 1 — título e prioridade** (`display:flex; align-items:flex-start; gap:8px`):

```
[p  13.5px/500, line-height 1.35, ink]  flex:1; min-width:0
[selo "Oper."  só se operacional]
[selo prioridade]
```

Selo (os dois iguais): `padding:2px 6px`, raio `3px`, mono `9.5px/600`, `letter-spacing:0.08em`, caixa alta,
`flex-shrink:0`. Fundo = `tint(cor, tintAlpha)`, texto = a cor. Prioridade baixa usa `chip` + `inkMute`.

**Linha 2 — resumo:** `margin-top:5px`, `12px`, `line-height:1.4`, `inkMute`, uma linha só
(`nowrap; overflow:hidden; text-overflow:ellipsis`).

**Tarjas** (pergunta, retorno, bloqueio — nesta ordem, quando presentes):

```
margin-top 8px    padding 7px 9px    raio 4px
fundo  tint(cor, ~0.085)      borda-esquerda 2px solid cor
linha 1: [ícone 12px] gap 6px [rótulo mono 9.5px/600 caixa alta cor] [meta mono 9.5px inkMute]
linha 2 (só pergunta): margin-top 4px, [nome 12px/600 ink] + [selo rodada mono 9px]
corpo:   margin-top 4px, 11.5px, line-height 1.4, ink, clamp 2 linhas (-webkit-line-clamp)
```

O **nome de quem deve resposta ocupa linha própria** — em 197px de row não cabe ao lado do selo e do tempo.

**Rodapé** — `margin-top:9px; padding-top:9px; border-top:1px solid rule; display:flex; align-items:center;
gap:7px`:

```
[avatar 21×21px redondo, 9px/600]        flex-shrink:0
[sistema 11.5px inkMute]                 flex:1; min-width:56px  ← o min-width evita "Alfa…"
[tempo na etapa  selo mono 10px/600]     flex-shrink:0
[selo checklist  3/5  mono 10px]         flex-shrink:0, só se houver
[selo comentários  mono 10px]            flex-shrink:0, só se houver
[grupo de 3 botões]                      margin-left:auto; gap:4px
```

Avatar sem responsável: fundo transparente, `border:1px dashed inkFaint`, sem iniciais.
Avatar com responsável: `tint(cor-derivada-do-nome, 0.18)`, iniciais em `ink`.

Botões do grupo: **20×20px**, raio `3px`, ícone `11px`, `border:1px solid`. São três — bloquear/destravar
(sempre), concluir (só onde o fluxo permite), Mover ▾ (chevron que gira 180° quando aberto).

**Envelhecimento** — o selo de tempo troca de cor por limiar da etapa (Aberta 24h, Em andamento 72h,
Em revisão 24h, Em staging 24h, Pronta 24h; Backlog nunca): abaixo do limiar `chip`/`inkMute`, acima
`tint(warn)`/`warn`, acima do dobro `tint(crit)`/`crit`.

## Sobre Tailwind

Boa parte desses valores não tem passo na escala do Tailwind (`13.5px`, `9.5px`, `21px`, `272px`). Duas
saídas, nesta ordem de preferência:

1. **Acrescente ao `tailwind.config.js`** o que se repete — os tamanhos de fonte da lista acima, `272px` de
   coluna, os raios `3px`/`4px`/`5px`. Vira vocabulário do projeto.
2. **Valor arbitrário** para o que aparece uma vez: `text-[13.5px]`, `w-[272px]`, `rounded-[5px]`.

Não arredonde para o passo mais próximo da escala. `13.5px` virando `text-sm` (14px) parece inofensivo, mas
a tela tem seis tamanhos entre 9 e 14px e a hierarquia depende dessa diferença.

## Como conferir

Abra o `.dc.html` e a sua tela lado a lado, **no mesmo tema e na mesma largura de janela**. Compare nesta
ordem: largura de coluna → altura do cabeçalho → altura do card → posição vertical de cada linha do card.
Se a altura do card bater, o resto bateu.

Estados que precisam ser conferidos um por um, porque não aparecem no primeiro paint:

- card com pergunta (dois no seed: "Relatório de inadimplência" e "Plano de contas em árvore")
- card com retorno ("Exportação de faturamento em PDF")
- card bloqueado ("Integração com a API do Asaas")
- card envelhecido (selo de tempo em âmbar ou vermelho)
- card sem responsável (avatar tracejado)
- painel de motivo aberto (arraste um card para Pronta p/ produção)
- coluna recolhida
- raias por responsável e por sistema
- vista celular
- tema claro em todos os anteriores
