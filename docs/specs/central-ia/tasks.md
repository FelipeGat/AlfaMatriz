# Tasks: Central de IA — correções de 12/10 e FR-022 a FR-029

Ordem = dependência. Cada fase termina com a suíte isolada verde e um commit local na `Felipe`.

## Fase 0 — Assumir o trabalho do Codex
- [x] T001 Revisar o não commitado (RespostaVisual, ProtecaoDeSegredos, front). Trocar a fixture com dados reais do Gestor por uma sintética. Mascarar segredos DEPOIS de `Dossie::extrair`. Telegram leva análise + métricas. Ampliar os padrões de segredo. Tabelas pelo `<x-tabela>`. Prova: `RespostaVisualTest`, `ComponentesTest` verdes.
- [x] T002 Commit "assume respostas visuais do Codex". Prova: suíte inteira verde.

## Fase A — Conta (FR-026)
- [x] T010 `central_ia.dono` + `User::temCentral()` / `ehDonoDaCentral()`.
- [x] T011 `UsuarioController`: proteger update, senha, ativo, exclusão e perfis de conta com Central. Prova: `ProtecaoDaContaTest` (Rossini → recusado; o próprio → ok; o dono → Alexandre ok; ninguém → o dono).
- [x] T012 `Governanca`: só o dono administra; nunca a si mesmo. Relatório de outro usuário só para o dono. Prova: `GovernancaTest` atualizado.
- [x] T013 Banco local: Alexandre sem `admin` (DRY e depois aplicar).

## Fase B — Runner inviolável (FR-022, FR-024, FR-025)
- [x] T020 Migração `confirmacao` em `central_ia_execucoes` (MariaDB e SQLite, com `down`).
- [x] T021 Rota do runner `execucoes/{id}/confirmacao` + `AgentesService::confirmacao()`. Prova: `ConfirmacaoTest` (dono, máquina, estados, código entregue uma vez, eventos).
- [x] T022 Rota web `execucoes/{id}/confirmar` + `/confirmar` no Telegram. Prova: `ConfirmacaoTest`.
- [x] T023 `expirarPresas` considera a espera; a confirmação expirada falha com motivo.
- [x] T024 `deploy/central-ia/confirmacao.mjs`: código HMAC preso ao conteúdo, envio ao Telegram, conferência em tempo constante, 3 tentativas, 15 min, log local. Prova: `test-confirmacao.mjs`.
- [x] T025 `runner.mjs`: teto local, `realpath` em toda pasta, analisar só leitura por CLI, implementar só confirmado, sem canal = recusa. Prova: `test-runner-politica.mjs` (argumentos gerados por modo e acesso; pasta fora recusada).
- [x] T026 Telegram: recusar encaminhada; desligar a prévia de link.

## Fase C — Bugs
- [x] T030 Sessão retomada após falha ou corte (runner manda `cwd`; servidor cai no da tarefa). Prova: `AgentesTest` novo caso.
- [x] T031 `exigirPeriodo` sem falso positivo; agenda sem data = hoje. Prova: `RegrasAnaliseTest`.
- [x] T032 `/status` entregue com projeto ou tarefa no canal. Prova: `CanalAtivoTest`.
- [x] T033 Pendente expira; progresso só para execução em andamento. Prova: `ProgressoTest`.
- [x] T034 Conclusão resiliente (runner até 30 min); `expirarPresas` com trava.
- [x] T035 Entrega do Telegram fora da transação; `incerta` funcionando. Prova: `TelegramTest`.
- [x] T036 Consumo do Claude sem contagem em dobro entre arquivos. Prova: `test-uso-local.mjs`.
- [x] T037 Roteador: verbos de alteração implementam. Prova: `RespostasConsistentesTest`.
- [x] T038 `/salvar` na tarefa do canal. T039 Bot: update venenoso avança após 3 falhas.

## Fase D — Custo (FR-027)
- [x] T040 `central_ia.niveis` + override por conta; o roteador devolve o nível. Prova: `NiveisTest`.
- [x] T041 `sem_ia` para listagem de agenda; o runner devolve os dados sem acionar IA. Prova: `NiveisTest` + `test-runner-politica.mjs`.
- [x] T042 O runner aplica modelo e esforço por CLI. O compositor mostra e permite trocar o nível.

## Fase E — UI e UX (FR-028, FR-029)
- [x] T050 Screenshots atuais (Playwright em Docker), desktop e celular, com a lista de atritos.
- [x] T051 Faixa "Esperando você" + localização (projeto › tarefa › canal) + entrada do código.
- [x] T052 Balão de resposta com ações (opções da dúvida, Aprovar, Ajustar, Delegar).
- [x] T053 Revisão de cada aba; screenshots depois.

## Fase F — Ativação local
- [x] T060 nginx com resolver (arquivo local).
- [x] T061 Configurar o env do runner (segredo, Telegram, teto); reinstalar e reiniciar ocioso.
- [x] T062 Ponta a ponta real: os 5 cenários do plano §F.
- [x] T063 Atualizar `docs/central-ia/README.md` e a revisão com o resultado.

## Execução (12/10/2026)

Todas as tarefas feitas, com estas notas:
- T013: o Alexandre já era "membro" na cópia local. Nada mudou ali; produção fica para quando o dono autorizar.
- T042: o nível só aparece no compositor da tela. O Telegram usa sempre o automático.
- T062: E2E real. (1) "Quais são meus compromissos de hoje?" saiu sem IA em 9 s. (2) Implementação forjada direto no banco: a máquina pediu o código pelo Telegram, recusou após 3 códigos errados e não criou o arquivo. (3) Análise forjada pedindo escrita e leitura de `~/.ssh`: nada foi escrito nem lido. O próprio agente recusou antes de a ferramenta precisar barrar.

Commits: 3b90266 (A + Codex), 172e3c4 (B + C), b3b57ce (D + E) e o desta fase (F).
