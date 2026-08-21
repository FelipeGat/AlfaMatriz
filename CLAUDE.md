# Instruções de implementação — AlfaMatriz redesign

> **Copie este arquivo para a RAIZ do repositório `AlfaMatriz` antes de começar.** Só o `CLAUDE.md` da raiz
> do diretório de trabalho entra no contexto no início da sessão; dentro da pasta do handoff ele é um arquivo
> comum, que só é lido se alguém apontar. O repositório não tem `CLAUDE.md` hoje.
>
> Estado do repositório conferido em 12/08/2026. Se fizer meses que este pacote está parado, **confira o que
> já existe antes de criar** — parte do plano abaixo já foi implementada entre o desenho e a entrega.

Este pacote é um **handoff de design** para ser implementado no repositório `AlfaMatriz` (Laravel + Blade +
Alpine + Tailwind). Leia este arquivo inteiro antes de escrever qualquer código.

## Ordem de leitura

1. **`README.md`** deste pacote — é a especificação. Tem tokens, tipografia, as 14 telas e as armadilhas.
2. **`TAREFAS-SPEC.md`** — valores exatos da tela de Tarefas (a mais densa). Leia antes de implementá-la.
3. **`AlfaMatriz Sistema.dc.html`** — o design final navegável das 14 telas + login. Abra no navegador.
4. **`AlfaMatriz Tarefas.dc.html`** — o quadro de tarefas interativo de ponta a ponta. Abra e **clique em
   Simular**: ele roda o ciclo de pergunta/resposta passo a passo. É a referência de comportamento.
5. **`AlfaMatriz Atual.dc.html`** — como o sistema é HOJE. Serve só para comparar; não implemente a partir dele.

Os `.dc.html` são protótipos React de página única. **Não porte o código deles.** Eles descrevem
comportamento, espaçamento e cor; a implementação é Blade + Alpine, seguindo os padrões que já existem no
repositório.

## Regras invioláveis

1. **Não invente valores.** Toda cor, espaçamento, raio e tamanho de fonte está no README ou no
   `tailwind.config.js` do repositório. Se precisar de um valor que não está em nenhum dos dois, pare e
   pergunte — não escolha por conta própria.
2. **Não porte a ARQUITETURA dos protótipos — mas LEIA os estilos deles.** Não traga React nem a estrutura de
   componentes; no repositório é Blade + Alpine, com os componentes que já existem (`x-nav-icon`,
   `x-stat-card`, `x-bar-chart`). Mas os `.dc.html` têm todo estilo inline em px e hex literais: **eles são a
   especificação dimensional.** Abra num editor, ache o texto do elemento, leia o `style=` ao lado. Nada
   precisa ser medido em screenshot nem adivinhado.
3. **Ícones:** continue usando `resources/views/components/nav-icon.blade.php`. Se faltar um ícone, adicione
   ao componente (família Heroicons outline, `stroke-width` 1.6–1.8). Não desenhe SVG solto na view.
4. **Tire `em_testes` e `ajustes_necessarios` do FLUXO, não do vocabulário.** Os dois saem das transições
   possíveis, mas tarefas encerradas continuam com esses status no histórico. O repositório já tem o padrão
   para isso: **`Tarefa::ETAPAS_APOSENTADAS`** (`app/Models/Tarefa.php`), criado quando `bloqueada` saiu,
   justamente para o histórico não passar a exibir a chave crua no lugar do nome da etapa. **Acrescente os
   dois lá** — não apague os rótulos. Hoje eles aparecem em 14 arquivos, 10 deles de teste.
5. **Comentários no código:** o repositório documenta o *porquê* das decisões, não o *o quê*. Siga esse
   padrão — as justificativas estão no README, use-as.
6. **Não rode `db:seed` em produção.** O deploy roda só `migrate --force`; dado que precisa valer em
   produção vai em migração, como o próprio repositório já faz.

## Plano de implementação

Faça em fases, nesta ordem, com a suíte passando ao fim de cada uma (`php artisan test`). **Não comece a
fase seguinte com a anterior vermelha** — é o mesmo portão que o `deploy/deploy-staging-alfamatriz.sh` aplica.

### Fase 1 — Banco (a mais arriscada)

**Metade já está no repositório.** Confira antes de criar qualquer coisa:

| # | Item | Estado |
|---|---|---|
| 1 | `bloqueada` vira marca + backfill | **Feito** — `2026_08_11_140000_bloqueio_vira_marca_na_tarefa.php` |
| 2 | `retorno_de`, `retorno_motivo` | **Falta** |
| 3 | `em_revisao`, `em_staging`, `pronta_producao` | **Falta** |
| 4 | Conversa (`rodadas`, interlocutor, pergunta) | **Falta** |
| 5 | `nao_definida` em `PRIORIDADES` | **Feito** — `2026_08_11_150000_adicionar_prioridade_a_definir.php` |
| 6 | `ordem` em `tarefas` | **Feito** — `2026_08_12_090000_ordem_manual_da_tarefa_na_coluna.php` |
| 7 | `versao_producao` em `tarefas` | **Falta** |
| — | `tarefa_itens` (checklist) | **Feito** — `2026_08_11_160000_criar_itens_de_tarefa.php` |

Escrever um segundo backfill em cima de dado já migrado é o pior erro possível aqui. **Rode
`grep -r` pelos identificadores antes de criar a migração.**

Sobram quatro — 2, 3, 4 e 7. Só a **2** tem backfill:

- **2.** `retorno_de`, `retorno_motivo`. Tarefas em `ajustes_necessarios` vão para `em_desenvolvimento` com
  `retorno_de` = `de_status` do último evento. Espelhe a migração do bloqueio (item 1): ela já resolveu
  exatamente esse problema, inclusive reabrindo o evento anterior.
- **3.** Status novos e migração de quem está em `em_testes` → `em_revisao` (destino honesto sem informação
  extra).
- **4.** `rodadas`, `interlocutor_id`, `pergunta_de_id`, `pergunta_para_id`, `pergunta_em` em `tarefas`;
  `pergunta` (bool) em `tarefa_comentarios`.
- **7.** `versao_producao` (string) em `tarefas`.

**Checkpoint:** migrar e reverter numa cópia do banco, conferindo que nenhuma tarefa perdeu a etapa.

### Fase 2 — Domínio

- `app/Services/FluxoTarefaService.php` — **já existe; é edição, não criação.** Substitua os dois mapas de
  `FLUXOS` pelos do README §16.1. As arestas de `bloqueada` já saíram; saem agora as de
  `ajustes_necessarios`, e entram `em_revisao` / `em_staging` / `pronta_producao`.
- `app/Models/Tarefa.php` — marcas de bloqueio/retorno/pergunta, `rodadas`, escopos.
- `app/Http/Controllers/TarefaController.php` — `corDaEtapa` para os status novos; endpoints de perguntar,
  responder, destravar, reordenar.
- Autorização: perfis Admin/Membro (README §16.4). O repositório já tem `PerfilPermissaoSeeder` e
  capacidades — siga esse padrão, não crie um sistema paralelo.

**Checkpoint:** testes de fluxo cobrindo cada transição válida e cada recusa, mais a regra de que rodada só
incrementa quando a bola estava com quem pergunta.

### Fase 3 — Telas

Comece por `resources/views/tarefas/` (a tela que mais mudou) — e para essa leia **`TAREFAS-SPEC.md`**, que
tem os valores exatos de coluna, card, tarjas e rodapé, mais a lista de estados a conferir um por um. Depois
as 13 restantes na ordem do README.
O layout (`layouts/app.blade.php`, `layouts/navigation.blade.php`) muda em conjunto: sidebar expansível,
tema claro/escuro, sino.

**Checkpoint por tela:** compare lado a lado com o `.dc.html` correspondente, no tema claro e no escuro.

## O que costuma dar errado

O README tem 23 armadilhas já encontradas na fase de design — **leia a seção "Armadilhas já encontradas"
antes de escrever CSS**. As quatro que mais se repetiram:

- `nowrap` sem `overflow:hidden` pinta por cima do vizinho em vez de cortar
- `flex-shrink:1` nos dois lados reparte o problema; quem precisa sobreviver leva `flex:0 0 auto`
- `position:sticky` cria contexto de empilhamento e enterra overlays
- texto de ajuda ou aparece inteiro ou não aparece — nunca pela metade

## Antes de mandar para staging

- **Semeie dados envelhecidos** (`tarefa_eventos.entrou_em` retroativo) e volume realista. Com seed novo
  nada está velho, e o teste não exercita envelhecimento nem estouro de WIP — que é o que o redesign mais
  mudou.
- **Concorrência já está resolvida** (`TarefaController` recebe `de_status` e recusa com "Alguém já moveu
  esta tarefa" — ver `tests/Feature/TarefasDesenvolvimento/OrdemEConcorrenciaTest.php`). Ao adicionar as
  transições novas, mantenha esse contrato: é fácil esquecer o `de_status` num endpoint novo.

## Quando parar e perguntar

- Um valor visual que não está no README nem no `tailwind.config.js`
- Uma transição de fluxo que o README não descreve
- Qualquer coisa que exija apagar dado de produção
- Divergência entre o README e os protótipos — **o README vence**, mas avise

---

**Redesign visual em andamento.** Antes de mexer em views ou no fluxo de tarefas, leia nesta ordem:

- `design/README.md` — a especificação: tokens, tipografia, as 14 telas e as 23 armadilhas
- `design/TAREFAS-SPEC.md` — os valores exatos da tela de Tarefas (coluna, card, tarjas, rodapé)
- `design/AlfaMatriz Tarefas.dc.html` — **abra num editor e leia os `style=` inline.** Eles são a
  especificação dimensional: ache o texto do elemento e o estilo ao lado é a medida dele. Nada aqui
  precisa ser adivinhado nem medido em screenshot.

---

## Publicar o changelog

O procedimento vale para todos os sistemas Alfa. O formato é HTML do Telegram com `<b>`/`<i>`, primeira linha
`<b>📋 AlfaMatriz — Changelog DD/MM/AAAA</b>`, emojis por seção, assinatura no fim, e divisão em
partes autocontidas acima de ~4096 caracteres.

**O token mora no chaveiro do macOS** (`alfa-telegram-bot` / `changelog`) desde 21/08/2026, e o
`chat_id` está no próprio script. Não duplique o token aqui. Para saber de onde ele está saindo — e
se ainda funciona — rode `deploy/publicar-changelog.sh --fonte`; para movê-lo de outra fonte para o
chaveiro, `--guardar`.

Ele já morou só no `CLAUDE.md` do AlfaControl, que é ignorado pelo git: um `git rm` em 17/07/2026 o
levou junto e a publicação parou de funcionar sem avisar ninguém — só se descobriu em 21/08, no dia
em que alguém tentou publicar. O script ainda lê aquele arquivo como último recurso, e avisa quando
o usa.

Procurar neste repo, nos servidores ou nos containers **não acha nada**: o `.spec` lista o envio como
trabalho futuro, e os únicos Telegram que aparecem na infra são canais de alerta de backup e de LGPD,
de outros sistemas.

- **Só Telegram.** O `CLAUDE.md` do AlfaControl manda mandar para o Discord em seguida — aqui não.
  Decisão do dono do produto em 12/08/2026: não enviar e não oferecer.
- **Só depois de o texto ser aprovado**, como o próprio AlfaControl já manda.
- **Use `deploy/publicar-changelog.sh`. Não remonte o `curl`.** Escreva a mensagem em
  `deploy/changelog/AAAA-MM-DD-assunto.txt` — HTML do Telegram, partes separadas por uma linha com
  apenas `---` — e rode `deploy/publicar-changelog.sh --conferir <arquivo>` para ver sem enviar, ou
  sem o `--conferir` para enviar. O script já confere o limite de 4096 ANTES de mandar qualquer
  parte, confere `"ok":true` no corpo da resposta, e busca o token em quatro lugares na ordem
  (`$ALFA_TELEGRAM_TOKEN`, chaveiro, `~/.config/alfa/telegram.env`, AlfaControl), dizendo em voz
  alta qual deles usou. Remontar o `curl` significa reescolher o chat, reescrever a checagem de erro e
  redescobrir o limite — três coisas que só se erram uma vez em produção.
- **O envio sai direto daqui.** Esta seção já mandou pedir `! bash <script>` ao dono do produto,
  dizendo que o classificador recusava. Em 13/08/2026 o envio saiu sem recusa nenhuma, e a orientação
  antiga custava uma volta inteira: eu entregava o comando e ele rodava o que eu podia rodar. Se a
  recusa aparecer um dia, o `! bash deploy/publicar-changelog.sh <arquivo>` continua valendo como
  saída — mas tente primeiro.
