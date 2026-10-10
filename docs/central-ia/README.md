# Central de IA no AlfaMatriz

Estado em 10/10/2026, branch local `Felipe`. Os demais documentos desta pasta
(`HANDOFF-CLAUDE.md`, `single-database.md`, `identity-integration.md`, …) são
o histórico da primeira versão, feita como aplicação separada. Onde divergirem
deste README, vale este.

## Decisões do dono (Felipe, 10/10/2026)

1. **Um sistema só.** A Central roda dentro do AlfaMatriz, no mesmo banco, em
   tabelas `central_ia_*`. Não há segunda aplicação, nem PostgreSQL, nem
   tickets entre aplicações — o vaivém Matriz → Central → Matriz foi o que
   gerou o 503 com o PHP sem worker livre.
2. **Cada pessoa com as suas IAs e a sua máquina.** O Rossini tem o login dele
   nas IAs e a WSL dele; o Felipe, os seus; e assim para todos. O AlfaMatriz
   guarda a conversa e coordena; quem executa é um *runner* na WSL de cada um,
   com a credencial de IA daquela pessoa. Nenhuma credencial de IA entra no
   banco, no navegador ou no Git.
3. **Começar limpo.** Nada é importado do banco da `central-agentes`; o
   Telegram é pareado de novo.
4. **Acesso por perfil.** O perfil "Central de IA" é dado conta a conta na
   tela de usuários. O Administrador NÃO o recebe de fábrica (o Rossini também
   é admin). A conversa de cada um é só dele, sem exceção para administrador.
5. **Homologação local de uma semana**; depois, deploy — começando limpo em
   produção. A especificação (`.spec`) é escrita ao fim dos testes, antes do deploy.
6. **Tudo na branch `Felipe`.**

## Etapas

| # | Etapa | Estado |
|---|---|---|
| 1 | Conversa na tela: histórico, envio idempotente, privacidade por dono | **Feita** |
| 2 | Telegram (`@GrupoSolucoesAgentBot`): pareamento, as duas mãos, um consumidor só | **Feita** — consumidor trocado em 10/10/2026 |
| 3 | Agentes (Claude, GPT/Codex, Copilot) pelo runner de cada pessoa, nos projetos dela | **Feita** — testada com os três em 10/10/2026 |
| 4 | Cockpit multiprojetos: tarefas com dossiê, troca de agente com handoff, IA automática, 2 em paralelo, comandos do Telegram | **Feita** em 10/10/2026 |
| 5 | Homologação de uma semana, ajustes e cstk (briefing, constitution, spec) | Agora |

## O que existe (etapa 1)

- `central_ia_conversas` e `central_ia_mensagens` (migração
  `2026_10_10_200000_criar_central_ia.php`), que também cria o recurso
  `central_ia` e o perfil "Central de IA" (ler, incluir, editar).
- `App\Services\CentralIa\ConversaService`: a regra de gravar e ler, para as
  três portas que vão escrever (tela, Telegram, runner).
- `CentralIaController` + rotas `central-ia.*` no grupo autenticado do
  `routes/web.php` — sem id de conversa ou de usuário na rota.
- Tela `resources/views/central-ia/index.blade.php` + `resources/js/central-ia.js`
  (poll de 5 s, reenvio seguro com o mesmo `client_id`, reconexão).
- Testes: `tests/Feature/CentralIa/ConversaTest.php`.

## O que existe (etapa 2)

- `central_ia_telegram_vinculos` (um chat por conta, uma conta por chat),
  `central_ia_telegram_codigos` (só o hash), `central_ia_telegram_cursor` e
  `central_ia_entregas` (migração `2026_10_10_210000_telegram_da_central_ia.php`).
- `TelegramApi` (único ponto que fala com o bot; o token nunca vai para log),
  `TelegramCanal` (parear, receber, entregar) e o comando `central-ia:telegram`.
- Na tela: "Vincular Telegram" gera um link `t.me/...?start=<código>` de uso
  único (10 min); cada mensagem mostra se foi entregue no Telegram.
- Mensagem da tela vai ao Telegram; mensagem do Telegram aparece na tela e
  não volta para ele. Entrega `incerta` (5xx, queda) não é reenviada sozinha.
- Testes: `tests/Feature/CentralIa/TelegramTest.php`.

### Troca do consumidor (10/10/2026)

O Telegram aceita UM leitor por bot. O consumidor antigo,
`central-agentes-telegram-1`, foi PARADO (não removido) e o novo,
`alfamatriz-dev-telegram`, subiu no lugar. O token saiu do ambiente do
container antigo direto para `docker/local/telegram.env` (0600, fora do Git);
só o container do bot o recebe e só ele alcança o `api.telegram.org`.

Voltar ao antigo: `docker stop alfamatriz-dev-telegram && docker start central-agentes-telegram-1`.
Nunca os dois ligados — o novo avisa no log ("Outro consumidor… 409") e recua.

Ainda ligados na `central-agentes`: web 8100, fila, agentes. A fila antiga
ainda pode mandar ao bot o que alguém escrever na tela 8100; ninguém deve usá-la.

## O que existe (etapa 3)

- `central_ia_runners` (o que cada máquina informou: agentes e projetos) e
  `central_ia_execucoes` (pedido, agente, projeto, modo, estado, sessão do
  agente, branch) — migração `2026_10_11_090000_agentes_da_central_ia.php`,
  que também cria a permissão `central_ia_agentes` (acionar agentes, separada
  de conversar) e a dá ao perfil "Central de IA".
- `AgentesService`: atalho `/claude projeto pedido` (`!` = implementar; `/gpt`
  = Codex), permissão, projeto informado pela máquina, limite diário, um por
  vez, conclusão idempotente, expiração de quem não responde, continuidade da
  sessão. Resposta de agente nunca vira pedido.
- Porta do runner: `routes/central-ia.php` (`api/central-ia/runner/*`, token
  Sanctum de capacidade `central-ia-runner` — `alfa:central-ia-token`).
- O runner: `deploy/central-ia/runner.mjs` + README de instalação. Analisar é
  só leitura na pasta do projeto; implementar é numa worktree e branch
  `central-ia/<n>`, sem commit nem push.
- Na tela: seletor de agente, projeto e modo; estado de cada pedido; card de
  Agentes com a máquina online/offline.
- Testes: `tests/Feature/CentralIa/AgentesTest.php`.

Na máquina do Felipe (10/10/2026): serviço `systemctl --user` `central-ia-runner`,
com linger ligado (roda com o VS Code fechado), configuração em
`~/.config/alfa/central-ia-runner.env` (0600). Teste real: um pedido de
análise a cada agente no `alfahome` — os três responderam em ~15 s.

### Operação

- O bot e o runner são processos longos: código novo só vale depois de
  `docker restart alfamatriz-dev-telegram` e, para o runner, copiar o
  `runner.mjs` de novo e `systemctl --user restart central-ia-runner`.
- A trava do bot tem dono fixo (o hostname do container,
  `alfamatriz-dev-telegram`): reiniciar ou recriar o container retoma na hora.
  No SIGTERM o Symfony sai de dentro do handler e a trava não é solta — por
  isso o dono fixo, e por isso `stop_signal: SIGTERM` (a imagem do php-fpm
  para com SIGQUIT, que o artisan não trata).

## O que existe (etapa 4 — cockpit)

Pedido do dono em 10/10/2026: trabalhar em até ~5 projetos ao mesmo tempo, ver
cada um, e a IA escolhida automaticamente sem perder o contexto quando ele
troca de agente no meio. Decisões dele: "aguardando você" só para deploy ou
dúvida do agente; 2 execuções em paralelo; cstk depois da validação.

- **Tarefa** (`central_ia_tarefas`): a unidade de trabalho de um projeto. Junta
  pedidos, respostas e execuções; tem worktree e branch PRÓPRIAS (não mais
  uma por execução) e o **dossiê** — objetivo, pedidos, decisões, agentes,
  resumos, git medido, testes, próximos passos, pendências, riscos, dúvida,
  lacunas. Migração `2026_10_11_100000_cockpit_da_central_ia.php`.
- **Dossiê** (`App\Services\CentralIa\Dossie`): o agente termina a resposta
  com um bloco `<<<CENTRAL-IA {json} CENTRAL-IA>>>`; o bloco sai do texto e
  vai para o dossiê. O git vem do RUNNER (fato), não do agente. Sem bloco, o
  dossiê registra a lacuna. `duvida` põe a tarefa em "aguardando você".
- **Troca de agente / handoff**: uma execução por tarefa por vez (a anterior
  termina antes); o novo agente trabalha na mesma worktree, recebe o dossiê
  com instrução de conferir o estado real antes de alterar, e não herda a
  sessão interna do anterior. Testado de verdade: Claude implementou, Codex
  assumiu, conferiu o git contra o dossiê e confirmou.
- **IA automática** (`Roteador`): regras fixas, sem chamada a modelo (perguntar
  ao GPT custaria uma execução da cota e 10–15 s por pedido). Implementar →
  Claude; revisar/planejar/investigar → Codex; apoio rápido → Copilot.
  Preferências em `config/central_ia.php` (`roteamento`). O motivo aparece em
  cada pedido; a escolha manual vence. Continuidade só no mesmo tipo de pedido.
- **Paralelismo**: `paralelo` = 2 por pessoa (`CENTRAL_IA_PARALELO`), nunca duas
  na mesma tarefa; o runner também roda até 2.
- **Cockpit** (`CockpitService`, rota `central-ia.painel`): projetos fixados +
  com tarefa em curso + com atividade em 24 h; estado, agente, tarefas,
  última atividade, atenção; git de cada projeto (branch, pendências, último
  commit, sessão de agente aberta na pasta, fase e ritmo do painel-avanco),
  medido pelo runner a cada minuto. Conversa por projeto (`projeto` nulo =
  Geral). Painel de atividade de todos os projetos.
- **Telegram**: `/projetos`, `/status`, `/usar <projeto>`, `/nova`, `/geral`,
  `/auto|/claude|/gpt|/copilot` (sem argumento escolhe o agente),
  `/trocar <agente> [pedido]`. Sem projeto em uso, mensagem comum só anota.
- **Runner**: worktree por tarefa, checkpoint do git ao fim de toda execução
  (também na falha), sessão retomada só na mesma pasta, parada limpa (encerra
  os agentes e reporta "interrompida" com o git medido).
- Testes: `tests/Feature/CentralIa/{CockpitTest,TelegramComandosTest}.php`.

### Consumo, recentes e continuar sessão do VS Code (10/10/2026)

- O runner lê os registros locais das três IAs (`deploy/central-ia/uso-local.mjs`):
  Claude `~/.claude/projects`, Codex `~/.codex/sessions` (com a cota da
  janela de 5 h e da semana), Copilot `~/.copilot/session-state` (com as
  requisições premium). Só contagens e metadados — nunca o texto. Leitura
  incremental: a primeira leva ~16 s (a sessão do gestor tem 115 MB), as
  seguintes milissegundos. Vai na batida, a cada 2 min.
- Cockpit: card "Consumo de IA" (hoje / 7 dias por IA, cota do Codex,
  projetos que mais gastaram) e "Recentes no git" (todos os projetos da
  máquina pelo último commit, com Fixar).
- **Continuar sessão do VS Code**: o runner lista as sessões que a PESSOA
  abriu (`claude-vscode`, `codex_vscode`, Copilot), com o projeto (pela pasta
  ou, na raiz `~/projetos`, pelo projeto mais citado nos caminhos). "Continuar
  daqui" (ou `/continuar gestor` no Telegram) abre uma tarefa que trabalha NA
  PASTA da sessão — não em worktree, porque o trabalho sem commit está lá — e
  cuja primeira execução retoma a sessão: no Claude uma CÓPIA
  (`--fork-session`), a do VS Code fica intacta; Codex e Copilot retomam a
  própria. Recusado se a sessão foi usada há menos de 5 min. Daí em diante as
  duas seguem separadas.
- Migração `2026_10_11_110000_consumo_e_sessoes_da_central_ia.php`; testes em
  `tests/Feature/CentralIa/SessoesEConsumoTest.php`.

### Respostas consistentes e fontes oficiais (10/10/2026)

Pedido do dono: resumo, análise e relatório vão ao Codex, e "me faça um
resumo da agenda de hoje" não pode trazer uma resposta por IA.

- Roteador: termos que pedem RESPOSTA (resumo, relatório, análise, status,
  agenda, "quais…", "o que…") vencem verbos genéricos ("faça", "crie",
  "escreva"); só implemente/corrija/conserte/refatore/migre forçam
  implementação. "Me faça um resumo" ia ao Claude pelo "faça".
- `FontesDeDados`: pedido sobre agenda/compromisso/reunião ganha a fonte
  oficial e o período (hoje, amanhã, semana, próxima semana, mês). O runner lê
  o Google Agenda pela ponte `C:\tmp\gcal\gcal.py list --inicio … --dias …`
  (opção `--inicio` acrescentada em 10/10; sem ela o `list` começava "agora")
  e entrega no prompt com a regra de usar só aquilo. Era a causa das respostas
  diferentes: cada IA procurava a agenda num lugar, e o Codex isolado nem
  alcança a ponte. Testado: Codex e Claude responderam a mesma coisa.
- Conversa Geral aceita pedido (só analisar, pasta neutra
  `~/.local/share/alfa/central-ia-geral`): em automático, só quando a
  mensagem pede resposta; o resto continua anotação, sem gastar cota.
- Telegram: intenção dita com palavras ("continuar gestor de onde paramos",
  "usar o alfahome") vale como comando; mensagem só anotada recebe aviso;
  todo pedido recebe recibo na hora (agente, modo, motivo, fonte).
- Testes: `tests/Feature/CentralIa/RespostasConsistentesTest.php`.

### Abas, grupos, consumo, acesso total e log (10/10/2026)

- Tela sem rolagem de página (altura da janela), em abas: **Projetos**
  (busca + grupos à esquerda; sub-abas Conversa, Tarefas, Contexto, VS Code),
  **Atividade** (log de eventos + execuções), **Consumo**, **Ajustes**.
- **Grupos** de projetos por conta (`central_ia_grupos`; criados VixCard,
  Grupo Soluções e Pessoal). Pôr num grupo também fixa.
- **Consumo**: limites por IA — Central (pedidos do dia), Claude (`/usage`
  local, zero tokens, a cada 10 min: sessão 5 h e semanas), Codex (cota 5 h e
  semana, dos registros), Copilot (premium no mês; franquia em
  `CENTRAL_IA_COPILOT_PREMIUM_MES`) — e tokens por dia por IA (gráfico de
  barras em HTML: o Alpine não roda `<template x-for>` dentro de `<svg>`).
- A IA automática desvia do Claude só com a SESSÃO de 5 h em 100% (com a
  semana em 100% ele ainda rodou); do Codex, com qualquer janela em 100%.
- **Acesso total** (dono: "permita tudo"): `CENTRAL_IA_ACESSO=total` —
  Claude `bypassPermissions`, Codex `danger-full-access`, Copilot `--allow-all`;
  implementar DIRETO na pasta (`CENTRAL_IA_IMPLEMENTAR_NA_PASTA`), uma
  implementação por projeto de cada vez; memória do dono no prompt de todos;
  Geral roda na raiz `~/projetos`. Commit/push/deploy só quando o pedido
  disser (instrução no prompt).
- **Log de eventos** (`central_ia_eventos`, só acrescenta): pedido, início,
  conclusão, falha (com o erro), sem resposta, troca de agente, recusa,
  conversa do VS Code continuada — com quem, agente, projeto e origem.
- **Outra IA assume a conversa do VS Code**: se a IA da conversa estiver no
  limite, o Codex assume e recebe o histórico de texto dela (fim do arquivo,
  sem conteúdo de ferramentas).
- Análise nunca encerra a tarefa; só implementação com `tarefa_concluida`.
- Boot do Windows: `subir-stacks.sh` sobe o stack `alfamatriz-dev`, e o
  `vixcard-boot.ps1` mantém uma sessão `wsl --exec sleep infinity` para o
  Ubuntu (runner, painéis) não desligar sem ninguém conectado.

## Ambiente local

- `http://localhost:8096` (Windows) / `http://home:8096` (Mac). O stack Docker
  `alfamatriz-dev` passou a montar ESTE checkout (`alfamatriz-felipe-central-ia`),
  e não mais o de `main`. Sobe com
  `docker compose -p alfamatriz-dev -f docker-compose.local.yml up -d` daqui.
  O compose, `docker/local/`, `backups/` e o `.env` ficam fora do Git.
- O banco é uma cópia da produção com integrações reais: não rodar o scheduler
  nem `queue:work` genérico. Os hosts dos sistemas e o `api.telegram.org`
  estão bloqueados no container `app`.
- **Atualizar a cópia de produção apaga a Central** (tabelas `central_ia_*` e a
  conta local do Felipe, id 5, que não existe em produção). Antes de atualizar,
  salvar essas tabelas; depois, rodar `migrate` e devolver o perfil.

## Testes

Isolados, sem rede, código somente leitura, SQLite em memória:

```bash
python3 tools/central-ia/run-tests.py --central-root /home/duque_de_caxias/projetos/central-agentes
```

Em 10/10/2026, depois das abas e do acesso total: **1.455 testes, verde**
(duas depreciações do PHPUnit, preexistentes). O runner esconde o `.env` do
checkout, que agora existe porque ele serve a porta 8096.

## A remover antes do deploy

- `tools/central-ia/reference/backend` — código da aplicação separada, mantido
  só como consulta para o Telegram e o runner.
- `tools/central-ia/run-real-backup-validation.py` — feito para o PostgreSQL
  da aplicação separada.
