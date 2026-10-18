# Feature Specification: Secretário, projetos da VixCard pelo VixOrganize

**Feature**: `secretario-vixorganize`
**Created**: 2026-10-17
**Status**: Draft
**Pedido**: Felipe, 17/10/2026: "a VixCard tem esse sistema chamado vixorganized… preciso integrar ele aqui no matriz
para eu não ter 2 sistemas de gestão… trazendo e enviando demandas para ele".

## Contexto e decisões (17/10/2026)

- O VixOrganize (`VixHouse/vixorganized`, do Victor) é a fila priorizada de projetos da VixCard: rank, limite de
  projetos em andamento, kanban de fases, checklist por fase e registro por passo. Laravel 13 + Postgres.
- **Ele ainda não está no ar** (o Victor confirmou: só o código, sem banco de produção).
- **Mão dupla**: o Victor usa só o VixOrganize e o Felipe só o Matriz. Ninguém da VixCard entra no Matriz.
- A API do VixOrganize só lia. A escrita foi criada numa branch dele (`api-escrita-matriz`), para o Victor revisar:
  chave própria `VIX_API_WRITE_KEY`, só no header; autor por `X-Vix-Autor` e origem por `X-Vix-Origem`.

## Escopo

1. **Conexão por conta**, em Secretário → Configurações: endereço, chave de escrita (cifrada) e o e-mail do
   Felipe no VixOrganize (autor). Testar ao salvar. Sem conexão, nada aparece: nem menu nem linhas.
2. **Leitura** a cada 10 min (`secretario:vixorganize`): a fila e o detalhe dos projetos não concluídos, guardados
   num retrato da conta. Também relê logo depois de cada envio.
3. **Projetos VixCard** (tela nova, no grupo Secretário): a fila na ordem do rank, com status, fase atual, progresso
   e prazo. O projeto aberto mostra as fases e os passos.
4. **Enviar ao VixOrganize**: novo projeto, nova demanda (passo) numa fase, marcar ou desmarcar passo, registro num
   passo e mudar status.
5. **Central de gestão**: os projetos não concluídos da VixCard entram na tabela (aba "Projetos", chip
   "VixOrganize"), com o link para a tela.

## Fora do escopo

- Reordenar a fila, editar e excluir fases ou passos: o Victor faz no VixOrganize.
- Pedido pelo chat ou Telegram ("manda pro organize…"): fica para depois.
- Avisos proativos (projeto vencido) nas regras de acompanhamento: fica para depois.

## Requirements

- **FR-001**: A conexão MUST ser da própria conta. Outra conta MUST NOT ver retrato, tela ou linhas, e MUST
  receber 404 nas ações.
- **FR-002**: A chave MUST ficar cifrada, MUST NOT voltar para a tela e MUST NOT ir para log ou mensagem de erro.
- **FR-003**: O endereço MUST ser https e resolver para IP público. Redirect MUST NOT ser seguido, para a chave não
  sair do host. Em ambiente local, `VIXORGANIZE_PERMITE_LOCAL=sim` libera http e IP privado para o teste
  ponta a ponta.
- **FR-004**: Falha do VixOrganize MUST NOT quebrar Acompanhamentos nem a tela: mostra o último retrato com a hora
  e o erro.
- **FR-005**: Cada envio MUST ser feito na hora (sem fila) e MUST dizer se deu certo. POST não é repetido sozinho.
- **FR-006**: Marcar passo MUST mandar o estado desejado (`done`), não "alternar", para clique duplo não desfazer.
- **FR-007**: Retrato com mais de 30 min MUST aparecer como desatualizado.

## User Scenarios & Testing

1. **Given** a conexão salva, **When** passam 10 min, **Then** Projetos VixCard mostra a fila e Acompanhamentos
   mostra os projetos abertos (FR-007).
2. **Given** um projeto aberto, **When** o Felipe lança "Cotar leitores UHF" na fase Planejamento, **Then** o passo
   aparece no VixOrganize com "(via AlfaMatriz)" no histórico e o autor Felipe (FR-005).
3. **Given** o passo, **When** o Felipe marca, **Then** a fase fecha sozinha se era o último (FR-006).
4. **Given** a conta do Rossini, **Then** não há menu, linhas nem acesso às ações (FR-001).
5. **Given** um endereço http ou de IP privado em produção, **Then** a conexão é recusada (FR-003).
6. **Given** o VixOrganize fora do ar, **Then** a tela mostra o último retrato e o erro (FR-004).

## Delta Requirements

**Skip**: integração nova do Secretário, sem `docs/specs/current/` neste repositório. — Claude (pedido do Felipe),
2026-10-17
