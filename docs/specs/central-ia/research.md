# Research — Central de IA (correções de 12/10/2026)

## Decision 1 — Como a máquina confirma sem confiar no servidor

**Decision**: confirmação fora de banda gerada pelo runner.
- O runner tem um segredo local (32 bytes, em `~/.config/alfa/central-ia-runner.env`, 0600) que o servidor nunca vê.
- Para cada execução que pode modificar, calcula `HMAC-SHA256(segredo, id | sha256(conteúdo canônico))` e tira dele um código de 6 dígitos.
- Manda o pedido exato e o código ao Telegram do dono, pelo `sendMessage` do bot, com token e chat configurados na própria máquina.
- O dono digita o código na tela ou com `/confirmar` no Telegram, e o servidor só repassa o que foi digitado.
- O runner confere em tempo constante, com no máximo 3 tentativas e validade de 15 minutos, e executa exatamente o conteúdo que guardou na memória.

**Rationale**:
- A interface web é servida pelo próprio servidor. Assinar no navegador não protege, porque um servidor comprometido entrega um JavaScript que assina o que quiser.
- O único canal que o servidor não lê é o que sai da máquina do dono para o Telegram dele. O bot não consegue ler as mensagens que ele mesmo enviou, e o `getUpdates` só traz as que chegam.
- O código fica preso ao conteúdo: trocar o pedido invalida o código. A pessoa também lê o pedido exato antes de confirmar, então um pedido forjado fica evidente.

**Alternatives considered**:
- TOTP: o código não fica preso ao conteúdo.
- Assinatura WebCrypto no navegador: o servidor serve o código que assina. Além disso, `http://home:8096` não é contexto seguro e o `crypto.subtle` não existe lá.
- Um segundo bot só para confirmação: dá mais isolamento, mas obriga você a criar outro bot. Fica como opção na configuração local.

## Decision 2 — Analisar só leitura, imposto pela ferramenta

**Decision**: no modo analisar, o runner ignora o `acesso` vindo do servidor e aplica a configuração de leitura de cada CLI.
- **Claude**: `--permission-mode default` com `--allowedTools` só de leitura: Read, Grep, Glob, WebSearch, WebFetch e Bash de leitura (`git log/status/diff/show/branch`, `ls`, `cat`, `docker ps/logs`). `--disallowedTools` cobre Edit, Write, NotebookEdit e a leitura de `~/.ssh`, `~/.config/alfa`, `~/.aws`, `**/.env*`. Em `-p`, ferramenta não autorizada é negada.
- **Codex**: `sandbox_mode="read-only"`, que bloqueia escrita e rede no nível do sistema operacional.
- **Copilot**: `--deny-tool write`, `--deny-tool shell` e `--allow-all-tools`; a negação vence.

**Rationale**: decisão do dono, "análise é para averiguar, não modificar". Um texto no prompt não impede nada.

**Residual**: o sandbox de leitura do Codex lê qualquer arquivo do usuário, e o CLI não tem negação de leitura por caminho. Um pedido de análise forjado por quem controla o servidor ainda poderia ler um segredo pelo Codex. Mitigação: o runner recusa analisar no Codex quando o texto pede caminhos sensíveis, e registra cada pedido no log de auditoria local. Esse resíduo vai ao dono.

## Decision 3 — Teto local de acesso e pastas

**Decision**:
- `CENTRAL_IA_ACESSO_MAXIMO` (`total` ou `restrito`) na configuração local. O acesso efetivo é o menor entre o teto local e o que o servidor pede, e implementar com acesso total só depois da confirmação.
- Toda pasta de trabalho (projeto, worktree, cwd de sessão) passa por `realpath` e tem de ficar dentro de `CENTRAL_IA_PROJETOS`; o que estiver fora é recusado.

## Decision 4 — Quem administra contas com Central

**Decision**:
- `CENTRAL_IA_DONO` (e-mail) define o dono da Central.
- Conta com o perfil `central_ia` só é alterada (senha, e-mail, perfis, ativo, exclusão) pela própria pessoa ou pelo dono da Central. Ninguém altera a conta do dono, a não ser ele mesmo.
- A governança dos limites de outros usuários (o que o Codex fez) passa a ser só do dono da Central, e não de qualquer admin.

**Rationale**: o dono pediu ao Codex limites para o Alexandre (`governanca-usuarios.md`), e em 12/10 disse que admin não pode virar ele.

## Decision 5 — Níveis de custo

**Decision**: quatro níveis.

| Nível | Quando | Execução |
|---|---|---|
| `sem_ia` | Listagem de agenda sem pedido de análise | O runner devolve os dados oficiais formatados, sem acionar nenhuma IA |
| `leve` | Leitura, resumo, relatório, status | Modelo barato com esforço baixo |
| `medio` | Análise, revisão, planejamento, investigação | Modelo intermediário |
| `forte` | Implementação | Modelo principal |

- Os modelos de cada nível ficam em `config/central_ia.php`, sobrescritos por conta nos Ajustes.
- Padrões, a partir do catálogo local de 12/10:
  - Codex: leve `gpt-5.6-luna`/low, médio `gpt-6-sol`/medium, forte `gpt-6.1-sol`/high.
  - Claude: leve `sonnet`/low, médio `sonnet`/medium, forte `fable`/high.
  - Copilot: leve `auto`, médio `auto`, forte `auto`.
- **A confirmar na prática**: o custo premium de cada modelo do Copilot. O catálogo local não traz o multiplicador. Até medir, o Copilot fica com `auto`.

**Rationale**: o pedido do dono foi "agenda não precisa do fable". Os três CLIs aceitam `--model` e esforço por chamada: Claude `--model`/`--effort`, Codex `-m` e `-c model_reasoning_effort`, Copilot `--model`/`--reasoning-effort`.

## Decision 6 — Entrega ao Telegram sem transação aberta

**Decision**: reserva `pendente → enviando` numa transação curta; o HTTP fica fora dela; o resultado é gravado em outra transação. Se o processo cair durante o envio, a entrega fica em `enviando` e a recuperação a marca `incerta`, sem reenvio.

## Decision 7 — nginx local

**Decision**: `resolver 127.0.0.11 valid=10s` com o upstream do PHP em variável. Assim o nome `app` é resolvido de novo a cada pouco e não fica preso ao IP do Tailscale que respondeu no boot. O arquivo é local, fora do Git.
