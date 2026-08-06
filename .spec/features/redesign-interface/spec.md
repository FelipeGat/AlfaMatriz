# Spec: Redesign da interface e identidade da marca

> feature: redesign-interface
> status: rascunho

## Contexto

O AlfaMatriz tem visual datado, hierarquia confusa, tabelas pesadas e nenhuma
identidade de marca própria. O pacote de handoff
(`Assets/Redesign sistema moderno minimalista.zip`) traz um redesign completo
em alta fidelidade: ícone e wordmark, tokens dos temas escuro e claro,
tipografia, e o desenho das sete telas.

O handoff é prescritivo — cores, espaçamentos, raios e estados estão
definitivos. O trabalho é **recriar aquilo dentro do que já existe**: Blade +
Tailwind + Alpine, sem SPA, sem framework novo, sem build diferente.

Uma restrição que pesa mais que a estética: **o sistema está em produção com
os dados reais da empresa**. Um redesign que quebre uma tela de faturamento
custa mais do que um visual datado. Por isso a rede de segurança desta entrega
é garantir que nenhuma tela pare de responder.

## Histórias

### US-020 — O painel tem a cara da Alfa, em claro ou escuro

Como quem usa o painel todo dia, quero uma interface moderna com tema claro e
escuro, para trabalhar confortavelmente e reconhecer o sistema como da Alfa.

#### AC-039 — Os dois temas existem e a preferência é lembrada

- **Dado** o painel aberto
- **Quando** a pessoa alterna entre tema claro e escuro
- **Então** a interface inteira troca de cor, e ao recarregar ou navegar para
  outra tela o tema escolhido continua valendo

#### AC-040 — A identidade nova substitui a antiga por completo

- **Dado** a aplicação compilada
- **Quando** as fontes e o ícone são carregados
- **Então** valem as duas famílias do handoff (Geist na interface e Geist Mono
  em todo número), nenhuma das fontes anteriores (Inter, Space Grotesk, IBM
  Plex) é mais referenciada, e o ícone da marca aparece como favicon

#### AC-045 — A interface é monocromática; cor viva só onde significa algo

- **Dado** qualquer tela do painel
- **Quando** ela é exibida
- **Então** botões, seleção de menu, links e avatares são neutros, e cor viva
  aparece apenas onde carrega significado: séries de gráfico, indicadores de
  positivo/negativo/atenção e estados de situação

### US-021 — A navegação fica sempre à vista

Como quem usa o painel o dia todo, quero o menu sempre visível e a busca junto
dele, para alcançar qualquer tela sem etapa intermediária.

> **Mudou em 2026-08-05.** A versão anterior desta história pedia um menu
> colapsável (trilho de ícones ↔ menu expandido), e ele chegou a ser
> implementado. O cliente decidiu pelo menu **fixo**, na direção
> Vercel/Linear: menos controle para o usuário administrar, navegação sempre
> no mesmo lugar. O recolher foi removido.

#### AC-041 — O menu é fixo e traz a busca

- **Dado** o painel aberto em tela larga
- **Quando** a pessoa navega entre telas
- **Então** o menu permanece com a mesma largura, sem controle de recolher, e
  traz o campo de busca logo abaixo da marca, com o atalho de teclado indicado

### US-022 — Nenhuma tela quebra no caminho

Como responsável pela Alfa, quero que o redesign não derrube nenhuma tela, para
que o faturamento e o financeiro continuem operáveis durante e depois da troca.

#### AC-042 — Todas as telas do painel continuam abrindo

- **Dado** uma pessoa autenticada no painel
- **Quando** ela abre qualquer tela do sistema (painéis, cadastros,
  faturamento, financeiro e cadastros auxiliares)
- **Então** todas respondem normalmente, sem erro de renderização — nenhuma
  view fica quebrada por referenciar componente, variável ou classe que o
  redesign removeu

#### AC-043 — Valor em dinheiro nunca quebra em duas linhas

- **Dado** uma tabela ou card com valores monetários
- **Quando** a tela é exibida em largura reduzida
- **Então** o valor permanece em uma linha só — foi o defeito mais recorrente
  do protótipo, e é o que torna uma coluna de dinheiro ilegível

### US-023 — Os números batem entre as telas

Como responsável pela Alfa, quero que o mesmo indicador mostre o mesmo número
em qualquer tela, para não ter que descobrir qual das duas está certa.

#### AC-044 — O mesmo indicador dá o mesmo valor em telas diferentes

- **Dado** um indicador que aparece em mais de um lugar (por exemplo, o total
  de clientes ativos, que está no painel financeiro, no comercial e na tela de
  clientes)
- **Quando** as telas são carregadas com os mesmos dados
- **Então** o valor exibido é idêntico nas três, porque sai da mesma origem —
  no protótipo esses números divergiam e pareciam defeito

## Fora de escopo

- Mudança de comportamento de negócio: o redesign é visual e de navegação.
- Telas de autenticação (login, recuperação de senha): ficam para depois.
- Wordmark em SVG: o handoff entrega PNG e recomenda pedir o vetor ao cliente
  antes de publicar — ver Q-009.
- Novos relatórios, filtros ou colunas que não existam hoje.
- Acessibilidade além do que o handoff especifica (contraste dos tokens).

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-021 | O handoff é a fonte da verdade visual; onde ele e o código atual divergirem, vale o handoff | aberta | Confirmar com o dono nos pontos em que o código atual tiver comportamento que o protótipo não previu |
| ASM-022 | As telas de lista mantêm o filtro server-side por query string; só sidebar, tema, modal e toast ficam no Alpine | confirmada | É o que o handoff determina, e preserva o comportamento atual |
| ASM-023 | O redesign não altera nenhuma consulta ao banco, exceto para unificar indicadores divergentes (AC-044) | aberta | Confirmar ao chegar nas telas de painel |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-009 | O wordmark em PNG entra agora, ou esperamos o SVG do cliente? O PNG não escala nem troca de cor por tema | respondida | Usar o PNG por ora (2026-08-05). Fica a dívida de trocar pelo SVG quando o cliente enviar |
| Q-010 | O redesign deve ir para produção de uma vez, ou tela a tela, conforme cada uma fica pronta? | respondida | De uma vez (2026-08-05): valida no staging e vai a produção por tag, sem fase de visual misturado |
