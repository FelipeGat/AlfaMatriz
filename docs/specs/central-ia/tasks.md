# Tasks: Central de IA — correções de 12/10 e FR-022 a FR-029

Ordem = dependência. Cada fase termina com a suíte isolada verde e um commit local na `Felipe`.

## Fase 0 — Assumir o trabalho do Codex
- [ ] T001 Revisar o não commitado (RespostaVisual, ProtecaoDeSegredos, front). Trocar a fixture com dados reais do Gestor por uma sintética. Mascarar segredos DEPOIS de `Dossie::extrair`. Telegram leva análise + métricas. Ampliar os padrões de segredo. Tabelas pelo `<x-tabela>`. Prova: `RespostaVisualTest`, `ComponentesTest` verdes.
- [ ] T002 Commit "assume respostas visuais do Codex". Prova: suíte inteira verde.

## Fase A — Conta (FR-026)
- [ ] T010 `central_ia.dono` + `User::temCentral()` / `ehDonoDaCentral()`.
- [ ] T011 `UsuarioController`: proteger update, senha, ativo, exclusão e perfis de conta com Central. Prova: `ProtecaoDaContaTest` (Rossini → recusado; o próprio → ok; o dono → Alexandre ok; ninguém → o dono).
- [ ] T012 `Governanca`: só o dono administra; nunca a si mesmo. Relatório de outro usuário só para o dono. Prova: `GovernancaTest` atualizado.
- [ ] T013 Banco local: Alexandre sem `admin` (DRY e depois aplicar).

## Fase B — Runner inviolável (FR-022, FR-024, FR-025)
- [ ] T020 Migração `confirmacao` em `central_ia_execucoes` (MariaDB e SQLite, com `down`).
- [ ] T021 Rota do runner `execucoes/{id}/confirmacao` + `AgentesService::confirmacao()`. Prova: `ConfirmacaoTest` (dono, máquina, estados, código entregue uma vez, eventos).
- [ ] T022 Rota web `execucoes/{id}/confirmar` + `/confirmar` no Telegram. Prova: `ConfirmacaoTest`.
- [ ] T023 `expirarPresas` considera a espera; a confirmação expirada falha com motivo.
- [ ] T024 `deploy/central-ia/confirmacao.mjs`: código HMAC preso ao conteúdo, envio ao Telegram, conferência em tempo constante, 3 tentativas, 15 min, log local. Prova: `test-confirmacao.mjs`.
- [ ] T025 `runner.mjs`: teto local, `realpath` em toda pasta, analisar só leitura por CLI, implementar só confirmado, sem canal = recusa. Prova: `test-runner-politica.mjs` (argumentos gerados por modo e acesso; pasta fora recusada).
- [ ] T026 Telegram: recusar encaminhada; desligar a prévia de link.

## Fase C — Bugs
- [ ] T030 Sessão retomada após falha ou corte (runner manda `cwd`; servidor cai no da tarefa). Prova: `AgentesTest` novo caso.
- [ ] T031 `exigirPeriodo` sem falso positivo; agenda sem data = hoje. Prova: `RegrasAnaliseTest`.
- [ ] T032 `/status` entregue com projeto ou tarefa no canal. Prova: `CanalAtivoTest`.
- [ ] T033 Pendente expira; progresso só para execução em andamento. Prova: `ProgressoTest`.
- [ ] T034 Conclusão resiliente (runner até 30 min); `expirarPresas` com trava.
- [ ] T035 Entrega do Telegram fora da transação; `incerta` funcionando. Prova: `TelegramTest`.
- [ ] T036 Consumo do Claude sem contagem em dobro entre arquivos. Prova: `test-uso-local.mjs`.
- [ ] T037 Roteador: verbos de alteração implementam. Prova: `RespostasConsistentesTest`.
- [ ] T038 `/salvar` na tarefa do canal. T039 Bot: update venenoso avança após 3 falhas.

## Fase D — Custo (FR-027)
- [ ] T040 `central_ia.niveis` + override por conta; o roteador devolve o nível. Prova: `NiveisTest`.
- [ ] T041 `sem_ia` para listagem de agenda; o runner devolve os dados sem acionar IA. Prova: `NiveisTest` + `test-runner-politica.mjs`.
- [ ] T042 O runner aplica modelo e esforço por CLI. O compositor mostra e permite trocar o nível.

## Fase E — UI e UX (FR-028, FR-029)
- [ ] T050 Screenshots atuais (Playwright em Docker), desktop e celular, com a lista de atritos.
- [ ] T051 Faixa "Esperando você" + localização (projeto › tarefa › canal) + entrada do código.
- [ ] T052 Balão de resposta com ações (opções da dúvida, Aprovar, Ajustar, Delegar).
- [ ] T053 Revisão de cada aba; screenshots depois.

## Fase F — Ativação local
- [ ] T060 nginx com resolver (arquivo local).
- [ ] T061 Configurar o env do runner (segredo, Telegram, teto); reinstalar e reiniciar ocioso.
- [ ] T062 Ponta a ponta real: os 5 cenários do plano §F.
- [ ] T063 Atualizar `docs/central-ia/README.md` e a revisão com o resultado.
