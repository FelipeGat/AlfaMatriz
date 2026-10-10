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
| Onde | pasta do projeto | worktree própria em `~/projetos/.central-ia/worktrees`, branch `central-ia/<n>` |
| Claude | só leitura (Read, Grep, Glob, web) | edita e roda comandos, exceto git push/commit/reset/checkout/merge/rebase, rm -rf, sudo, docker, ssh |
| Codex | sandbox `read-only` | sandbox `workspace-write` (só a worktree) |
| Copilot | sem escrita e sem shell | tudo, exceto os mesmos comandos negados |

- Nenhum agente faz commit nem push. A resposta de implementar traz a branch e
  os arquivos alterados; revisar e integrar é com a pessoa.
- Pedido seguinte ao mesmo agente, projeto e modo continua a sessão anterior
  (e, em implementar, a mesma branch, enquanto a worktree existir).
- Uma execução por vez; 15 minutos de teto (`CENTRAL_IA_TIMEOUT_MINUTOS`);
  20 pedidos por dia por pessoa (`CENTRAL_IA_LIMITE_DIARIO`) — no `.env` do AlfaMatriz.
- Os hooks e instruções de cada agente (`~/.claude`, `CLAUDE.md` do projeto…)
  valem normalmente: o agente roda como a pessoa rodaria.

Limpar uma worktree já revisada: `git -C ~/projetos/<projeto> worktree remove <caminho>`.
