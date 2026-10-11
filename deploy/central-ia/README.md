# Runner da Central de IA

Programa que roda na máquina de cada pessoa e executa os agentes (Claude Code,
Codex/GPT, Copilot) **com o login de IA dela**, nos **projetos dela**. O
AlfaMatriz guarda a conversa e os pedidos; o runner busca, executa e devolve.
Nenhuma credencial de IA vai para o AlfaMatriz.

## Instalar (WSL / Linux)

Pré-requisitos: Node 18+, `git`, e os agentes logados como a pessoa usa no
dia a dia (`claude`, `codex`, `copilot`). O runner também acha o Claude e o
Codex dentro das extensões do VS Code (`~/.vscode-server/extensions`).

1. Ter o perfil **Central de IA** na tela de usuários do AlfaMatriz.
2. Emitir o token (aparece uma vez) — no servidor do AlfaMatriz:
   `php artisan alfa:central-ia-token <email> --nome=<maquina>`
3. Configuração, só para a própria pessoa ler:

   ```bash
   mkdir -p ~/.config/alfa && umask 077
   cat > ~/.config/alfa/central-ia-runner.env <<'EOF'
   ALFAMATRIZ_URL=http://localhost:8096
   ALFAMATRIZ_RUNNER_TOKEN=<token>
   CENTRAL_IA_PROJETOS=~/projetos
   EOF
   ```

4. Serviço:

   ```bash
   mkdir -p ~/.local/share/alfa ~/.config/systemd/user
   cp deploy/central-ia/runner.mjs ~/.local/share/alfa/central-ia-runner.mjs
   cp deploy/central-ia/uso-local.mjs ~/.local/share/alfa/uso-local.mjs
   cp deploy/central-ia/central-ia-runner.service ~/.config/systemd/user/
   systemctl --user daemon-reload
   systemctl --user enable --now central-ia-runner
   loginctl enable-linger "$USER"   # continua rodando sem sessão aberta
   journalctl --user -u central-ia-runner -f
   ```

O runner é copiado para fora do repositório de propósito: trocar de branch no
checkout não troca o programa que está rodando. Para atualizar, copie de novo
e `systemctl --user restart central-ia-runner`.

## O que ele faz em cada modo

| | Analisar | Implementar |
|---|---|---|
| Onde | pasta do projeto (ou a worktree da tarefa, se ela já tem uma) | worktree DA TAREFA em `~/projetos/.central-ia/worktrees/<projeto>-t<n>`, branch `central-ia/t<n>` |
| Claude | só leitura (Read, Grep, Glob, web) | edita e roda comandos, exceto git push/commit/reset/checkout/merge/rebase, rm -rf, sudo, docker, ssh |
| Codex | sandbox `read-only` | sandbox `workspace-write` (só a worktree) |
| Copilot | sem escrita e sem shell | tudo, exceto os mesmos comandos negados |

- Nenhum agente faz commit nem push. A resposta de implementar traz a branch e
  os arquivos alterados; revisar e integrar é com a pessoa.
- A tarefa tem uma worktree só: outro agente que assuma continua nela, com o
  dossiê da tarefa no prompt. A sessão interna do agente só é retomada pelo
  MESMO agente na mesma pasta.
- Na batida (30 s) o runner manda o estado de cada projeto: branch, pendências,
  último commit, sessões de agente abertas na pasta (`/proc`) e fase/ritmo do
  `painel-avanco/retrato.json`, se existir.
- Até 2 execuções ao mesmo tempo (`CENTRAL_IA_PARALELO`); 15 minutos de teto (`CENTRAL_IA_TIMEOUT_MINUTOS`);
  20 pedidos por dia por pessoa (`CENTRAL_IA_LIMITE_DIARIO`) — no `.env` do AlfaMatriz.
- Os hooks e instruções de cada agente (`~/.claude`, `CLAUDE.md` do projeto…)
  valem normalmente: o agente roda como a pessoa rodaria.

Limpar uma worktree já revisada: `git -C ~/projetos/<projeto> worktree remove <caminho>`.

## Anexos (tarefa 9)

Copiar também `anexos.mjs` para a pasta do runner. Construir a imagem local:
`docker build -t alfamatriz-central-media:local deploy/central-ia/media`.
Ela contém Poppler e FFmpeg para PDFs e vídeos; o runner usa containers
transitórios sem rede e com limites de recursos. Teste independente:
`node tools/central-ia/test-anexos.mjs`.

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
