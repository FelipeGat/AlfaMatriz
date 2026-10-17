# Runner da Central de IA

Programa que roda na máquina de cada pessoa e executa os agentes (Claude Code,
Codex/GPT, Copilot) **com o login de IA dela**, nos **projetos dela**. O
AlfaMatriz guarda a conversa e os pedidos; o runner busca, executa e devolve.
Nenhuma credencial de IA vai para o AlfaMatriz.

## Instalar (Linux, Windows com WSL, Mac) — pela tela

Desde 14/10/2026 (T-374) ninguém instala à mão nem roda nada no servidor:

1. Ter o perfil **Central de IA** na tela de usuários do AlfaMatriz.
2. Na máquina: Tailscale ligado, Node 18+, `git`, e os agentes logados como a pessoa usa no dia a dia
   (`claude`, `codex`, `copilot`). O runner também acha o Claude e o Codex dentro das extensões do VS Code
   (`~/.vscode-server/extensions` na WSL, `~/.vscode/extensions` no Mac e no Linux de mesa).
3. Central de IA → Ajustes → Telegram: criar o SEU bot no @BotFather, colar o token e tocar em Começar nele
   (T-374: cada pessoa usa o próprio bot; ele conversa e leva o código de confirmação).
4. Ajustes → **Minhas máquinas → Gerar comando de instalação**, e colar no terminal (no Windows, no Ubuntu
   do WSL):

   ```bash
   curl -fsSL https://<alfamatriz>/central-ia/instalar.sh | bash -s -- <código>
   ```

O `instalar.sh` (servido pelo AlfaMatriz com o endereço dele dentro):

- confere sistema, Node, git e se a máquina alcança o AlfaMatriz (sem a tailnet, diz para ligar o Tailscale);
- baixa os módulos do runner para `~/.local/share/alfa/` (fora de qualquer repositório: trocar de branch num
  checkout não troca o programa que está rodando);
- troca o código (uso único, 15 min) pelo token, pergunta a pasta dos projetos e grava
  `~/.config/alfa/central-ia-runner.env` (0600), mantendo o que já houver nele. O segredo da confirmação
  nasce ali e nunca sai da máquina; o chat do Telegram e o token do SEU bot vêm do servidor nessa
  hora, uma vez só (trocou de bot nos Ajustes? gere um comando novo e rode de novo);
- liga o serviço: `systemd --user` no Linux e na WSL (com `linger`), `launchd` no Mac
  (`~/Library/LaunchAgents/br.com.alfa.central-ia-runner.plist`, log em `~/Library/Logs/central-ia-runner.log`).
  WSL sem systemd: o instalador diz como ligar;
- prepara a imagem Docker que converte PDF e vídeo, se houver Docker (`--sem-anexos` pula);
- manda uma mensagem de teste pelo seu bot.

**Atualizar:** o mesmo comando sem o código (`curl -fsSL https://<alfamatriz>/central-ia/instalar.sh | bash`).
Ele baixa o runner de novo e reinicia o serviço só quando estiver ocioso (`atualizar-quando-ocioso.sh`).

**Desconectar:** Ajustes → Minhas máquinas → Desconectar (o token deixa de valer na hora).

Opções da configuração, todas da máquina e nenhuma vinda do servidor:

```bash
ALFAMATRIZ_URL=…                       # gravado pelo instalador
ALFAMATRIZ_RUNNER_TOKEN=…              # gravado pelo instalador
CENTRAL_IA_PROJETOS=~/projetos         # a pasta dos projetos (o instalador pergunta)
CENTRAL_IA_MAQUINA=notebook-ana        # nome na tela (padrão: o hostname)
# Decidido AQUI, não no servidor (ver "Inviolável" abaixo):
CENTRAL_IA_ACESSO_MAXIMO=restrito      # ou total
CENTRAL_IA_CONFIRMAR=inicio            # mínimo: nunca, deploy, inicio, implementar, sempre
CENTRAL_IA_ANALISE=leitura             # ou total: a IA decide o que consultar; o rastro fica na auditoria
CENTRAL_IA_CODEX_TOTAL=sim             # opcional: o Codex também com acesso total fora de deploy (rede, pontes
                                       # de produção). Sem isso ele fica no workspace-write, sem rede (T-368).
                                       # O deploy continua pedindo o código no Telegram.
CENTRAL_IA_IGNORAR=videos,orcamentos   # subpastas que não são projeto (pasta vazia já é ignorada)
CENTRAL_IA_SEGREDO_CONFIRMACAO=…       # 64 hex, gerado pelo instalador
CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=… # o SEU bot (T-374); o nome é do tempo do bot só de código
CENTRAL_IA_TELEGRAM_CHAT_ID=…
# Opcionais (T-374: nada disso tem mais padrão de uma máquina específica):
CENTRAL_IA_MEMORIA=…                   # o MEMORY.md que vai aos agentes quando a pessoa liga "memória" nos
                                       # Ajustes; sem valor, o do Claude para a pasta de projetos; `nenhuma` desliga
CENTRAL_IA_AGENDA="programa|script"    # agenda pessoal: chamado com `list --inicio AAAA-MM-DD --dias N`,
                                       # uma linha por evento. Sem ele, a agenda é a do AlfaMatriz
CENTRAL_IA_RETRATO=…/retrato.json      # fase e atividade de um painel de andamento, se a pessoa usa um
```

Instalação à mão (sem a tela) continua possível: `php artisan alfa:central-ia-token <email> --nome=<maquina>`
no servidor, o arquivo acima escrito à mão e os módulos copiados para `~/.local/share/alfa/` (o `runner.mjs`
com o nome `central-ia-runner.mjs`); `central-ia-runner.service` é o modelo da unidade do systemd.

## Inviolável pelo servidor (12/10/2026)

Dono: "o runner faz tudo o que o servidor manda — isso não pode ocorrer". Agora:

- **Analisar é só leitura, imposto pela ferramenta** (`politica.mjs`), seja qual for
  o acesso que o servidor pedir. Nem na leitura o Claude lê `~/.ssh`, `~/.config/alfa`,
  `~/.aws`, credenciais das IAs e `.env`. O Codex só leitura não bloqueia leitura por
  caminho; pedido de análise que cita credenciais é recusado nele.
- **Implementar exige o seu código**:
  - A máquina calcula o código com o `CENTRAL_IA_SEGREDO_CONFIRMACAO`, preso ao pedido
    exato (agente, projeto, pasta, acesso, instrução), e manda os dois direto ao seu
    Telegram (`confirmacao.mjs`).
  - Você digita o código na Central ou com `/confirmar <código>` no Telegram.
  - Três erros ou 15 minutos sem código: o pedido é recusado e nada muda.
  - O servidor nunca vê o segredo nem o código esperado: não consegue forjar a
    confirmação nem trocar o pedido.
- **Teto local**: o acesso efetivo é o menor entre o pedido e `CENTRAL_IA_ACESSO_MAXIMO`.
  Toda pasta vinda do servidor (worktree, sessão) passa por `realpath` e tem de ficar
  dentro de `CENTRAL_IA_PROJETOS`.
- **Auditoria local**: `~/.local/share/alfa/central-ia-auditoria.log` (0600) registra
  cada leitura, execução que a regra deixou rodar sem código (`sem_confirmacao`, com a
  regra e o acesso — T-374: antes saía como "leitura"), pedido de confirmação, código
  errado, aceite, recusa e expiração. O código em si nunca é gravado.
- Sem segredo ou sem Telegram configurado, implementar é recusado.
- **Análise com acesso total (opcional, `CENTRAL_IA_ANALISE=total`)**: decisão do dono em 12/10/2026,
  "quem tem que analisar o que consulta e altera é a própria IA". O preço é que uma análise forjada no
  servidor também roda sem código. **Desde 14/10/2026 (T-368)** ela só vale se o servidor também pedir
  acesso total, e nem com acesso total publica (push, ssh, `gh`) fora de pedido de deploy. Com ou sem ela:
  - antes de toda análise, o runner roda `git fetch --all`, então o agente vê o remoto atualizado mesmo sem rede;
  - toda execução manda a **auditoria** (`auditoria.mjs`): o que o agente consultou e executou (eventos da
    CLI; o Copilot não informa) e o que mudou no git (arquivos e commits, medidos antes e depois);
  - a auditoria aparece no Contexto da tarefa ("Quem viu o quê e quem alterou o quê");
  - análise que alterou arquivo gera aviso no log.
- Teste: `node tools/central-ia/test-confirmacao.mjs`.

## O que ele faz em cada modo

| | Analisar | Implementar |
|---|---|---|
| Onde | pasta do projeto (ou a worktree da tarefa, se ela já tem uma) | worktree DA TAREFA em `<pasta de projetos>/.central-ia/worktrees/<projeto>-t<n>`, branch `central-ia/t<n>` |
| Claude | só leitura (Read, Grep, Glob, web e comandos de leitura: git log/status/diff, ls, cat, docker ps/logs) | `restrito`: edita e roda comandos, exceto git push/commit/reset/checkout/merge/rebase, rm -rf, sudo, docker, ssh. `total` (teto local + confirmação): sem restrição |
| Codex | sandbox `read-only` | sandbox `workspace-write` (só a worktree) |
| Copilot | sem escrita e sem shell | tudo, exceto os mesmos comandos negados |

- Nenhum agente faz commit nem push. A resposta de implementar traz a branch e
  os arquivos alterados; revisar e integrar é com a pessoa.
- A tarefa tem uma worktree só: outro agente que assuma continua nela, com o
  dossiê da tarefa no prompt. A sessão interna do agente só é retomada pelo
  MESMO agente na mesma pasta.
- Na batida (30 s) o runner manda o estado de cada projeto: branch, pendências,
  último commit, sessões de agente abertas na pasta (`/proc` no Linux e na WSL;
  `ps` e `lsof` no Mac) e fase/ritmo do `CENTRAL_IA_RETRATO`, se configurado.
- Sessões do VS Code: só as abertas dentro da pasta de projetos têm título e
  começo do pedido enviados (para a pessoa reconhecer o chat). As de outras pastas
  ficam na máquina; delas só sai a contagem de tokens.
- Até 2 execuções ao mesmo tempo (`CENTRAL_IA_PARALELO`); 15 minutos de teto (`CENTRAL_IA_TIMEOUT_MINUTOS`,
  60 para implementar) — no `.env` do AlfaMatriz.
- **Não há teto diário por padrão.** Quem limita é a assinatura de IA de cada pessoa. Os limites de pedidos
  por dia (por IA e no total) são dados pela tela: a pessoa nos Ajustes dela e o administrador para cada
  conta; vale o menor. Conta nova que não é admin nasce bloqueada até um admin liberar. O teto
  `CENTRAL_IA_LIMITE_DIARIO` (20) do `.env` só vale com `CENTRAL_IA_LIMITE_DIARIO_ATIVO=true`, desligado
  por padrão desde 11/10/2026.
- Ritmo da consulta por pedido novo (T-374): a cada 3 s até 5 min depois do último trabalho da máquina,
  a cada 10 s quando ela está parada (~8,6 mil consultas por dia em vez de ~29 mil). Máquina desconectada
  na tela (401/403) ou servidor fora de alcance: avisa uma vez no log e espaça até 10 min / 2 min, voltando
  ao normal sozinha quando o acesso volta (`politica.mjs`: `acompanharAcesso`, `ritmoDaConsulta`).
- Os hooks e instruções de cada agente (`~/.claude`, `CLAUDE.md` do projeto…)
  valem normalmente: o agente roda como a pessoa rodaria.

Limpar uma worktree já revisada: `git -C <pasta de projetos>/<projeto> worktree remove <caminho>`.

## O que é apagado sozinho (T-378)

Prazos aceitos pelo dono em 14/10/2026. Antes nada da Central era apagado.

**No servidor** — `php artisan central-ia:limpar`, todo dia às 03:30 (`routes/console.php`); prazos em
`config/central_ia.php` → `retencao`, mostrados nos Ajustes da Central:

| O quê | Prazo |
|---|---|
| Anexos e prévias (arquivo e registro) | 90 dias (`CENTRAL_IA_RETENCAO_ANEXOS_DIAS`) |
| Mensagens, pedidos aos agentes, eventos e entregas ao Telegram | 1 ano (`CENTRAL_IA_RETENCAO_HISTORICO_DIAS`) |

- Pedido pendente ou executando nunca sai, nem a mensagem dele. "Mais de N dias" é estrito: com N dias exatos fica.
- `--dry-run` mostra quanto apagaria sem apagar nada; a volta de verdade deixa a contagem no log do Laravel.
- Prazo abaixo de 7 dias é recusado (um `0` no `.env` apagaria tudo).

**Na máquina** — o runner, sozinho (`limpeza.mjs`; teste: `node tools/central-ia/test-limpeza.mjs`):

- **Log do runner** (só no Mac, `~/Library/Logs/central-ia-runner.log`; no Linux e na WSL é o journal,
  que tem limite próprio): passou de 10 MB, vira `.1` e o anterior vira `.2` — a cada 10 min. É copiado e
  esvaziado no lugar, porque o launchd o mantém aberto (em modo de acréscimo, conferido com `lsof`).
  Outro caminho: `CENTRAL_IA_LOG`.
- **Auditoria** (`~/.local/share/alfa/central-ia-auditoria.log`): mesmo limite e as mesmas duas cópias, 0600.
- **Worktree da tarefa**: uma vez por hora, só se a tarefa estiver **concluída ou arquivada** no AlfaMatriz
  (a batida traz a lista), não estiver rodando na máquina, não tiver **nenhuma alteração** (inclusive
  arquivo novo) e **nenhum commit fora da main** (`main`, `origin/main`, `master` ou `origin/master`).
  Os agentes não commitam: a worktree pode ser o único lugar do trabalho. Na dúvida — git que falha,
  main que não se acha — ela fica, e o log diz por quê. Remove com `git worktree remove` sem `--force`;
  a branch `central-ia/t<n>` fica, e reabrir a tarefa recria a pasta em cima dela.

## Anexos (tarefa 9)

O instalador baixa o `anexos.mjs` e constrói a imagem local quando há Docker (à mão:
`docker build -t alfamatriz-central-media:local deploy/central-ia/media`). No Mac, os anexos ficam em
`~/.local/share/alfa/tmp`, dentro do HOME, que é o que o Docker de lá (Colima) monta.
Ela contém Poppler e FFmpeg para PDFs e vídeos; o runner usa containers
transitórios sem rede e com limites de recursos. Teste independente:
`node tools/central-ia/test-anexos.mjs`.

Desde 17/10/2026 entram também texto (TXT, MD, CSV, TSV, JSON, LOG), que o runner lê direto, e
documentos (DOC/DOCX, XLS/XLSX, PPT/PPTX, ODT/ODS/ODP, RTF), que viram PDF numa segunda imagem,
`alfamatriz-central-office:local` (LibreOffice; à mão:
`docker build -t alfamatriz-central-office:local deploy/central-ia/office`). É separada para não refazer a
de PDF e vídeo de quem já a tem; o instalador constrói a que faltar. Sem ela, o pedido segue e o agente
recebe o caminho do original. Formatos com macro (docm, xlsm, pptm) o servidor recusa. Teste:
`node tools/central-ia/test-anexos-documentos.mjs`.

O download é autenticado pela conta do runner e confere SHA-256. PDFs têm
texto limitado e até oito páginas renderizadas; vídeos, até doze quadros,
sem transcrição de áudio. O contexto agregado tem limite de 60 mil caracteres.
Atualizar o runner somente quando não houver agentes em execução; reiniciar
durante uma tarefa interrompe o agente que está atendendo a pessoa.

Progresso por execução (tarefa 9): instalar também `progresso.mjs` ao lado do runner.
O runner envia enums e timestamps a cada 30 s; a Central publica avisos por execução a cada 90 s.
Não transmite texto bruto do agente nem comandos. Sem confirmação recente, informa ausência de confirmação,
mesmo que outra execução ou a máquina esteja online. O reinício deve aguardar os agentes filhos terminarem.


## Continuidade de provedores e consumidor exclusivo (11/10/2026)

Ver [tarefa 10](../../docs/central-ia/tarefa-10-continuidade-provedores.md) antes
de ativar: consumidor com named lock MariaDB por conexão, teto diário interno
opcional e recuperação limitada por provedor. Copiar o runner e reiniciar os
processos exige autorização controlada; não iniciar segundo consumidor e não
executar teste real de IA automaticamente. Não há migração adicional nesta entrega.
