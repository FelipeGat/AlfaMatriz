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
| 2 | Telegram (`@GrupoSolucoesAgentBot`): pareamento, as duas mãos, um consumidor só | Próxima — exige desligar o consumidor da `central-agentes` |
| 3 | Runner por usuário: projetos, Claude Code / Codex com a credencial da pessoa | Depois — primeira execução real só com aprovação |
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

Em 10/10/2026, depois da etapa 1: **1.386 testes, 8.409 asserções, verde**
(duas depreciações do PHPUnit, preexistentes). O runner esconde o `.env` do
checkout, que agora existe porque ele serve a porta 8096.

## A remover antes do deploy

- `tools/central-ia/reference/backend` — código da aplicação separada, mantido
  só como consulta para o Telegram e o runner.
- `tools/central-ia/run-real-backup-validation.py` — feito para o PostgreSQL
  da aplicação separada.
