# Contrato — confirmação de execução pelo runner [PROPOSTA — a validar na implementação]

Rotas existentes do runner: `routes/central-ia.php`, prefixo `api/central-ia/runner`,
`auth:sanctum` com capacidade `central-ia-runner`. As rotas abaixo são novas.

## Runner → AlfaMatriz

### POST `execucoes/{id}/confirmacao`

Informa o estado da confirmação. A execução tem de ser do dono do token e estar `executando`
nesta máquina (`runner_id`).

Request:

| Campo | Tipo | Valores |
|---|---|---|
| `maquina` | string | nome da máquina |
| `estado` | string | `aguardando` · `confirmada` · `codigo_errado` · `recusada` · `expirada` · `sem_canal` |
| `tentativas` | int | 0..3 |
| `expira_em` | ISO-8601 | quando `aguardando` |
| `resumo` | string ≤ 300 | o que a máquina vai fazer, para a tela (sem o código) |

Response 200: `{ "codigo": "123456" | null }`. É o último código digitado ainda não
consumido; o servidor o apaga ao entregar.

O runner chama a cada 3 s enquanto `aguardando`. Cada chamada com `codigo_errado`
ou terminal (`confirmada`, `recusada`, `expirada`) gera evento.

## Tela → AlfaMatriz

### POST `central-ia/execucoes/{id}/confirmar` (grupo `web`, CSRF, `permissao:central_ia,incluir`, throttle 10/min)

Request: `{ "codigo": "^\d{6}$" }`. Grava o código na execução, se ela for da conta e estiver
`aguardando`. Response: `{ "ok": true }`. Quem confere é o runner.

## Telegram → AlfaMatriz

`/confirmar 123456` ou só `123456`, quando há exatamente uma execução da conta aguardando:
mesmo efeito da rota da tela. Com várias, `/confirmar <id> 123456`.

## Runner → Telegram (direto, sem passar pelo AlfaMatriz)

`sendMessage` com `chat_id` = `CENTRAL_IA_TELEGRAM_CHAT_ID` local e
`link_preview_options.is_disabled=true`. O texto traz o agente, o modo, o projeto, a pasta
real, a instrução (até 1500 caracteres), o código e a validade.
