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
| 4 | Homologação de uma semana, ajustes e `.spec` | Depois |

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

Em 10/10/2026, depois da etapa 3: **1.414 testes, 8.521 asserções, verde**
(duas depreciações do PHPUnit, preexistentes). O runner esconde o `.env` do
checkout, que agora existe porque ele serve a porta 8096.

## A remover antes do deploy

- `tools/central-ia/reference/backend` — código da aplicação separada, mantido
  só como consulta para o Telegram e o runner.
- `tools/central-ia/run-real-backup-validation.py` — feito para o PostgreSQL
  da aplicação separada.
