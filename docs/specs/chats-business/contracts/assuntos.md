# Contracts: Chats Business

**Feature**: `chats-business` | **Data**: 2026-10-13

Tudo fica atrás de `auth` + `permissao:central_ia` (tela) ou de `auth:sanctum` com a capacidade
`central-ia-runner` (runner), como as rotas que já existem em `routes/web.php` e em
`routes/central-ia.php`.
- Recurso de outra conta responde **404** (Princípio I).
- Nenhuma rota recebe id de usuário.

## A. Rotas existentes que mudam (contrato real, extraído do código em 13/10/2026)

### `POST api/central-ia/runner/batida` (`central-ia.runner.batida`)

Hoje: `sessoes` é um array de até 60 itens `{agente, id, projeto, cwd, ultima_atividade, mensagens}`
(`CentralIaRunnerController::sessoes`), e a sessão cujo `projeto` não está em `projetos` é
**descartada**.

**Muda** `[PROPOSTA — a validar na implementação]`:
- Cada sessão aceita `titulo` (string, até 120 caracteres) e `origem` (`wsl` | `mac_copia`).
- A sessão da **raiz** dos projetos passa a ser aceita com `projeto = ''`. É o caso das quatro conversas
  da primeira leva, gravadas em `~/.claude/projects/-home-duque-de-caxias-projetos/`.
- O limite sobe de 60 para 120 sessões.
- A sessão com projeto fora de `projetos` continua descartada.

### `POST central-ia/mensagens` (`central-ia.enviar`)

Sem campo novo. O pedido num assunto usa `tarefa_id` do assunto, como numa tarefa. O servidor decide o
contexto pelo `tipo` da tarefa.

### `GET central-ia/painel` (`central-ia.painel`)

**Acrescenta** `[PROPOSTA]`:
- `assuntos`: `[{id, titulo, grupo_id, projeto, status, ultima_atividade_em, novidade, aguardando}]`,
  só do usuário, sem os arquivados;
- `sessoes[].titulo`, `sessoes[].origem` e `sessoes[].semeada_em` (id do assunto, quando já semeou um).

### Payload de `proxima` (`central-ia.runner.proxima`)

**Acrescenta** `[PROPOSTA]`:
- `finalidade`: `pedido` | `condensar` | `semear`;
- para `semear`: `sessoes: [{agente, id}]`, `teto_caracteres` e `lido_ate` (por sessão, para atualizar
  só o trecho novo).

O `contexto` de um assunto é montado por `ContextoDoAssunto` (ver plan).

### `POST api/central-ia/runner/execucoes/{id}/conclusao`

**Acrescenta**, só para `finalidade = semear` `[PROPOSTA]`:
- `semente: [{agente, id, lido_ate, caracteres}]`;
- `estimativa_caracteres`, quando parou antes de ler por ter passado do teto.

O bloco de memória volta pelo mesmo canal do `<<<CENTRAL-IA … CENTRAL-IA>>>` que já existe (`Dossie`).

## B. Rotas novas `[PROPOSTA — a validar na implementação]`

| Método e caminho | Nome | Corpo | Resposta |
|---|---|---|---|
| `POST central-ia/assuntos` | `central-ia.assuntos.criar` | `{titulo, grupo_id?, projeto?}` | 201 `{id}` |
| `PUT central-ia/assuntos/{assunto}` | `central-ia.assuntos.salvar` | `{titulo?, grupo_id?, projeto?, status?: aberta\|arquivada}` | 200 |
| `GET central-ia/assuntos/{assunto}/memoria` | `central-ia.assuntos.memoria` | — | 200 `{objetivo, decisoes, jeito_de_fazer, pendencias, condensado, sementes, correcoes}` |
| `PUT central-ia/assuntos/{assunto}/memoria` | `central-ia.assuntos.memoria.salvar` | `{acao: editar\|apagar, chave, indice, texto?}` | 200 (memória nova) |
| `POST central-ia/assuntos/semear` | `central-ia.assuntos.semear` | `{sessoes: [{agente, id}] (1–5), titulo, grupo_id?, projeto?}` | 201 `{id, execucao_id}` |
| `POST central-ia/assuntos/{assunto}/atualizar-semente` | `central-ia.assuntos.atualizar` | — | 202 `{execucao_id}` (422 se a origem é `mac_copia`) |

**Validação**:
- `grupo_id` precisa ser grupo da conta, senão 422.
- `projeto` precisa estar em `projetos` de um runner da conta, ou ser vazio.
- As sessões precisam estar entre as que as máquinas da conta informaram (`AgentesService::sessoes`),
  senão 422 "Sessão não encontrada entre as que a sua máquina informou.".

## C. Telegram

- `/assunto`: lista os chats de assunto, numerados.
- `/assunto <nome ou número>`: aponta o vínculo. As mensagens seguintes caem nele.
- `/geral`: o comando que já existe também limpa `assunto_atual_id`.
