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
  `central_ia_telegram_codigos` (só o hash), `central_ia_telegram_cursor` (sem uso desde a T-374: o cursor
  é por bot, em `central_ia_telegram_bots`) e
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
- A trava do bot é por conexão MariaDB (`GET_LOCK`, `TelegramConsumerLock`,
  desde 11/10/2026): cai sozinha com o processo, sem TTL nem dono fixo. O
  `stop_signal: SIGTERM` continua no compose.

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
- **Acesso total** — desde 14/10/2026 (T-368) o padrão do servidor é `restrito`. No `total`, o
  código continua só no início da conversa e em deploy, mas push, ssh, `gh` de publicação e afins
  ficam bloqueados fora de pedido de deploy (`PUBLICACAO_NO_SHELL`), e o Codex só recebe `total` em
  pedido de deploy. Era (dono: "permita tudo"): `CENTRAL_IA_ACESSO=total` —
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

Removidos em 13/10/2026, na revisão para produção: `tools/central-ia/reference/backend`
(a aplicação separada antiga, mantida só como consulta) e
`tools/central-ia/run-real-backup-validation.py` (feito para o PostgreSQL dela).

### Ajustes locais após a retomada pelo Codex

- Salvar ponto também na Conversa: após conclusão bem-sucedida, o ID da
  resposta marca o início visível da tarefa. Ver histórico salvo reabre as
  mensagens anteriores sem apagar registros; a escolha de exibição é temporária.
- A aba VS Code saiu do cockpit; as sessões já continuadas permanecem intactas.
- Abas principais à esquerda, indicadores agrupados à direita.
- Conta financeira Sicoob fictícia cadastrada somente na cópia local, sem seed
  nem migração de dados para produção.

### Tarefa 9 — anexos e atividade (Codex, 11/10/2026)

- Web: Anexar, colar captura ou arrastar; JPG/PNG/WebP/PDF/MP4/WebM/MOV, até cinco arquivos de 20 MB cada, upload sequencial e reenvio com UUID.
- Arquivos no disco `local`, privados por dono, inclusive no download pelo runner. Vinculação transacional à mensagem e hash SHA-256.
- Telegram recebe foto/documento/vídeo com legenda opcional, confirma o download e renova `typing` com leitura curta (3 s). Um consumidor continua obrigatório.
- Tela distingue fila, execução e máquina offline, com tempo decorrido. Isso dá feedback durante a espera; não garante resposta completa mais rápida do modelo.
- Runner precisa de `anexos.mjs` ao lado do `central-ia-runner.mjs` e da imagem `alfamatriz-central-media:local` (Dockerfile em `deploy/central-ia/media`).
- PDF: texto até 50 mil caracteres por arquivo e imagens das primeiras oito páginas. Vídeo: até doze quadros distribuídos pela duração; sem transcrição do áudio. Codex recebe as imagens via `--image`, inclusive ao retomar sessão.
- Conversores rodam sem rede, sem privilégios e com memória/CPU limitadas. Mídias temporárias são removidas ao fim da execução.
- Teste: `node tools/central-ia/test-anexos.mjs`; testes PHP em `tests/Feature/CentralIa/AnexosTest.php`. Especificação em `docs/specs/central-ia-interacao-anexos/`.

### Tarefa 9 — avisos de status a cada 90 segundos

- Mensagens da Central informam fila, etapa técnica observada, última saída e confirmação por execução, sem percentuais. São compartilhadas na tela e no Telegram pelo mesmo mecanismo de entregas; lock por execução impede duplicidade entre bot, runner e poll web.
- O bot publica mesmo enquanto o runner está offline. Sem confirmação de progresso por 90 s, o texto informa que não é possível confirmar atividade; máquina online sozinha não prova progresso.
- `/status`, “como estamos?” e “travou?” consultam diretamente o banco, sem executar uma IA. Pedidos de status de negócio continuam no fluxo de IA; anexos não são ignorados como consulta operacional.
- `inplementar` é reconhecido como implementar. “Pode seguir ... implantação” autoriza escrita; “análise do plano de implantação” permanece análise. Escolha explícita de só análise é preservada. A execução recebe o timeout correspondente ao modo.
- Runner: copiar também `progresso.mjs` ao lado de `central-ia-runner.mjs`. Mudança requer reinício em ociosidade. Não reiniciar enquanto houver agentes filhos.
- Validação isolada: `python3 tools/central-ia/run-tests.py --central-root ~/projetos/central-agentes --filter CentralIa` e `node tools/central-ia/test-progresso.mjs`. Teste visual real opcional: `CENTRAL_IA_TEST_CODEX=/caminho/do/codex node tools/central-ia/test-anexos.mjs` (mídias sintéticas; usa o login local da pessoa).

### Histórico compartilhado do Telegram (11/10/2026)

- A tela identifica a conta logada e oferece “Abrir conversa do Telegram · <projeto>”, usando a última mensagem do Telegram da própria conta. Não copia nem move mensagens; Geral e projetos diferentes continuam separados.
- O atalho abre a aba Conversa, libera filtro de outra tarefa e reabre o histórico salvo da tarefa da conversa. Primeira visita sem projeto escolhido acompanha esse contexto; URL/projeto já escolhido e rascunhos são preservados.
- “Carregar mensagens anteriores” funciona também com tarefa selecionada. O poll continua acrescentando mensagens automaticamente.
- O navegador e o Telegram precisam pertencer à mesma conta no mesmo ambiente. Nesta homologação: conta Felipe no ambiente local `home:8096` / `localhost:8096`.
- Testes: `node tools/central-ia/test-historico.mjs` e `HistoricoCompartilhadoTest.php`. No navegador local foram exibidos pedidos e respostas reais da tarefa 9, e um novo aviso apareceu sem recarregar nem enviar mensagens artificiais.


### Continuidade de provedores e trava Telegram — 11/10/2026

Decisão atual do dono: aproveitar as assinaturas com troca automática, mantendo
o acesso amplo autorizado. O teto interno de 20 pedidos passa a ser opcional
(`CENTRAL_IA_LIMITE_DIARIO_ATIVO`, padrão desligado); não é a franquia de tokens.
A falha reconhecida de um provedor gera uma tentativa por alternativa, na mesma
tarefa e conta, preservando instrução, anexos e dossiê. Não há compra de créditos
ou novo adaptador de API. GPT (Codex) e Codex são um executor nesta aplicação.

O consumidor usa exclusividade por conexão MariaDB, sem TTL nem proprietário
compartilhado por hostname. Atualizar o código não reinicia o consumidor nem a
cópia instalada do runner; ativação controlada continua pendente.

[Implementação, testes, revisão Claude e demandas pendentes](tarefa-10-continuidade-provedores.md).
Timeout completo, checkpoint em falhas, sessões/forks e desligamento do legado
foram explicitamente deixados para próximas entregas.


### Atendimento por um canal por vez

Ver [fluxo, implementação e ativação controlada](canal-ativo.md). Ativado no AlfaMatriz local em 11/10/2026, com backup cifrado, restauração ensaiada e consumidor Telegram reiniciado.

### Chats separados por assunto

Ver [Chats Ativos: uso, isolamento, testes e rollback](chats-ativos.md).

### Regras individuais e arquivamento

Ver [auditoria, Ajustes por conta e próximos controles](ajustes-individuais.md).
As preferências de roteamento, habilitação e limites são individuais. Acesso por
pasta/ferramenta e orçamento exato de tokens ainda não são controles comprovados.


## Revisão e endurecimento (12/10/2026)

Revisão dos commits de 09 a 11/10 (Claude e Codex) passada pelo cstk: `docs/briefing.md`,
`docs/constitution.md` e `docs/specs/central-ia/` (spec, plano, pesquisa, contrato, tarefas e
`revisao-2026-10-12.md`). Decisões do dono nesse dia:

- ~~**Ninguém vira o Felipe**: conta com o perfil Central de IA só muda pela própria pessoa, ou
  pelo dono da Central.~~ **Revogado em 14/10/2026 (T-368)** pelo dono do produto: "admin precisa
  ter acesso total a tudo". Conta com a Central segue a regra de contas de sempre; o que protege a
  máquina da pessoa é a confirmação fora de banda do runner. Governança de limites continua só do
  dono da Central.
- **Runner inviolável** (`deploy/central-ia/README.md`, "Inviolável pelo servidor"):
  - analisar é só leitura imposta por cada CLI;
  - implementar exige o código que a própria máquina manda ao Telegram do dono;
  - o teto de acesso e as pastas são decididos na máquina;
  - há auditoria local em `~/.local/share/alfa/central-ia-auditoria.log`.
  
  Na tela, a faixa "Esperando você" recebe o código; no Telegram, `/confirmar <código>`.
- **Níveis de custo**: sem IA (a agenda sai direto), econômico, médio e máximo. Os modelos de
  cada nível ficam em `config/central_ia.php` e podem ser ajustados por conta. O custo premium
  de cada modelo do Copilot ainda não foi medido; até lá, o Copilot fica em `auto`.
- **Tela**:
  - uma faixa só no topo;
  - Chats Ativos abre na Geral com atalhos;
  - as respostas trazem aprovar, ajustar e passar para outra IA;
  - Consumo e Ajustes foram reordenados;
  - a pergunta "Rápida ou Elaborada" saiu.

Ambiente local: `CENTRAL_IA_DONO` no `.env`, o env do runner com segredo e Telegram próprios
(0600), e o nginx com `resolver` (o 502 do boot de 12/10). O banco está com a migração
`2026_10_12_090000` aplicada (backup em `backups/`).

## Chats Business — um chat contínuo por assunto (13/10/2026)

Spec, plano e tarefas: `docs/specs/chats-business/`. O dono passou a trabalhar pela Central em vez do VS Code.

- **O que é.** Aba **Business**: um chat por assunto ("Proposta Comercial VixCard", "Gestor.SG"…), nos grupos da conta.
  - É uma `central_ia_tarefas` com `tipo = 'assunto'`: reaproveita fila, Telegram, avisos, confirmação, auditoria e POPs.
  - Nunca se conclui sozinho e não aparece em Chats Ativos nem na aba Tarefas.
- **Contexto.** Qualquer agente recebe a memória do assunto e o histórico do chat (`ContextoDoAssunto`), até `assunto_contexto_tokens` por pedido (padrão 60 mil).
  - O resto fica de fora, e a bolha do pedido diz quanto foi.
  - Sempre sessão nova do agente: retomar a sessão dele dobraria o histórico.
- **Sem projeto.** O assunto sem projeto aciona IA em toda mensagem e implementa na raiz `~/projetos` (`na_pasta`), com as mesmas confirmações.
- **Memória.** Mapa do trabalho (`mapa`: o que é, caminho, etapa), `jeito_de_fazer`, decisões, pendências e próximos passos.
  - O mesmo caminho atualiza o item.
  - A aba **Memória** edita e apaga, e o que a pessoa apaga não volta pela mão de um agente.
- **Semente.** "Trazer do VS Code" lê a ÚLTIMA SEMANA das conversas marcadas (`extrato.mjs`, até ~420 mil caracteres) numa execução de nível econômico.
  - O servidor só manda o id; o runner acha o arquivo entre as conversas que ele mesmo listou.
  - Primeira leva (13/10): Proposta Comercial VixCard, Gestor.SG, Proposta comercial Grupo Soluções, Relatório avanço de obra.
- **Telegram.** `/assunto` lista, `/assunto 2` ou `/assunto comercial` aponta e `/geral` sai. As mensagens caem no mesmo chat da tela.
- **Pedido pelo stdin.** O Claude e o Codex recebem o pedido pela entrada padrão. Como argumento, passava do limite de 128 KB do Linux (`spawn E2BIG`).
- **Adiado.** Condensar o histórico antigo e reduzir pela cota (fase 3). "Atualizar do VS Code" saiu do escopo.

### Quem implementa passa pelo cstk (13/10/2026) — virou opção por pessoa na T-374

O runner acrescenta a regra do cstk (`politica.mjs`, `regraCstk`) a todo pedido de implementar em projeto,
para as três IAs, e não só para o Claude, que já a recebia pelo `~/.claude/CLAUDE.md` global.
- **Frente nova:** só com spec. O Claude roda o pipeline; o Codex e o Copilot devolvem o pedido ao Claude
  pelo `delegar`.
- **Bug pontual e ajustes:** seguem direto.
- **Chat de assunto sem projeto** (propostas, planilhas): fica de fora, porque não é código de repositório.

## Central para o time (T-374, 14/10/2026)

Dono do produto: "nada pode estar preso à máquina do Felipe; a Central de IA é para um time".

- **Quem administra** os limites e libera a execução das outras contas é quem tem o perfil Administrador e o
  perfil "Central de IA" (`Governanca::administra`). O `CENTRAL_IA_DONO` saiu. Conta nova nasce bloqueada até
  um administrador liberar; administrador nunca é bloqueado por política.
- **Conectar minha máquina** (Ajustes → Minhas máquinas): código de instalação de uso único (15 min) →
  `curl …/central-ia/instalar.sh | bash -s -- <código>` → o instalador troca o código pelo token
  (`POST /api/central-ia/instalacao`), grava a configuração e liga o serviço (systemd no Linux/WSL, launchd no
  Mac). A tela lista as máquinas (`central_ia_runners.token_id` liga a batida ao token), avisa a que sumiu
  (tailnet) e desconecta. Ver `deploy/central-ia/README.md`.
- **Mac:** sessões abertas por `ps` + `lsof` (sem `/proc`), extensões do VS Code em `~/.vscode`, binário do
  Codex por arquitetura, anexos dentro do HOME (o Colima só monta o HOME).
- **Saiu do runner o que era de uma máquina:** memória com caminho fixo (agora opção nos Ajustes; o arquivo é
  o da pasta de projetos ou `CENTRAL_IA_MEMORIA`), agenda pelo script do Windows (agora a Agenda do AlfaMatriz,
  ou `CENTRAL_IA_AGENDA` na máquina), painel 8089 (agora `CENTRAL_IA_RETRATO`, opcional), cstk imposto (agora
  opção nos Ajustes, desligada).
- **Bot do Telegram de cada pessoa** (decisão do dono em 14/10/2026): nenhum bot no `.env`. A pessoa cria o
  dela no @BotFather e cola o token em Ajustes → Telegram (`PUT central-ia/telegram/bot`); o servidor confere
  com `getMe` e `getWebhookInfo`, guarda cifrado em `central_ia_telegram_bots` (um bot por conta, uma conta por
  bot) e o `central-ia:telegram` ouve todos os bots marcados com o `APP_URL` deste servidor, cada um com o
  próprio cursor. Um update só vale no bot de quem o recebeu (o código de pareamento e o chat têm de ser do dono
  do bot). O mesmo bot leva o código de confirmação: a instalação manda o token dele à máquina da pessoa — o
  bot de confirmação separado existia porque o bot era compartilhado, e deixou de ser. Token revogado (401),
  webhook ou outro leitor (409) tiram só aquele bot da leitura, e a tela avisa a pessoa.
- **O que sai da máquina:** título e começo do pedido só das conversas abertas dentro da pasta de projetos.
- **Auditoria local:** a implementação que roda sem código fica como `sem_confirmacao`, e não "leitura".
- **Prévia:** sem `X-Frame-Options` próprio (o nginx já põe o dele; saíam dois que se contradiziam). Quem
  proíbe a moldura é o `frame-ancestors 'none'` da CSP.
