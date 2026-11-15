# Plan: API Executiva da Matriz (só leitura)

## Constitution Check

| Princípio | Resultado |
|---|---|
| I Privacidade por conta | Passa: escopo no dono do token, sem `user_id` na rota, recurso alheio dá 404. Admin não vê a conta do Felipe. |
| III Um consumidor só | Passa: camada fina sobre serviços existentes; regra nunca no controller. |
| IV Segredos | Passa: token só em hash (padrão `alfa:mcp-token`), nada em log. |
| VII Ações externas | Passa: sem rede, deploy ou commit nesta etapa. |
| VIII Veracidade | Passa com ressalva: valores só de serviço existente, `null` quando ausente; nenhum número deste plano é real. |

## Arquitetura

- `routes/executivo.php`, fora do grupo `web` (sem sessão/CSRF), só GET, middleware `auth:sanctum`, `abilities:executivo:ler`, `throttle`, auditoria. Prefixo `/api/executivo`.
- Emissão: `php artisan alfa:executivo-token <email> [--revogar]`, só para a conta do Felipe (recusa outras), validade configurável. Mostra o token uma vez.
- `App\Services\Executivo\*`: um serviço por bloco, devolvendo array tipado com o envelope (`fonte, periodo, consultado_em, atualizado_em, situacao, motivo, dados`). Controllers só chamam e serializam (API Resources, lista branca de campos).
- Acesso ao banco só leitura; nenhum serviço chamado pode gravar.

## Endpoints e serviços reaproveitados

| Rota | Reusa | Observação |
|---|---|---|
| `GET /agenda?de&ate` | `AgendaConsolidada::periodo`, `situacaoGoogle` | Sem participantes; título truncado. |
| `GET /emails` | `SecretarioEmail` via `ConsultaDeEmails::aguardandoLista` e o filtro de importantes | `ConsultaDeEmails::importantes/aguardando` devolvem TEXTO: extrair a consulta para método estruturado antes. Só nome do remetente, assunto truncado, urgência, idade. |
| `GET /pendencias` | `OQueEsperaVoce::pendencias`, `CentralDeGestao::numeros` | Perguntas, validações, retornos, prazos. |
| `GET /financeiro?de&ate` | `IndicadoresService` (séries de entradas/saídas, MRR), `ProjecaoFinanceira`, modelos `Cobranca`/`ContaPagar` | Realizado, previsto e lucro separados; só Alfa. Antes: definir os critérios (decisão 6). |
| `GET /alertas` | `Notificacao` (não lidas, por nível), `VigiaErro`, `MonitorAlerta` | Contagem + título; não marca como lida. |
| `GET /pedro` | modelos `Suporte*` | Fila, avaliações e pendências só como contagens/idade. |
| `GET /fontes` | `situacaoGoogle`, `SecretarioEmailConta` | Painel de saúde das integrações. |

## Falhas de Google e e-mail

- Google: reusar `situacaoGoogle`. Hoje, em produção, a leitura falha por `invalid_grant` desde 20/10 (informado em sessão anterior, não reverificado agora): a API devolveria `reconectar`.
- E-mail: novo `situacaoEmail(User)` análogo, por conta: `erro` → `falha` com o código; `lida_em` mais velho que um limite → `desatualizado`; sem conta → `desconectado`. O limite é decisão técnica a fixar no tasks.
- Em ambos, o bloco sai com `situacao` e `motivo` e a IA deve citar a limitação.

## Testes

- Isolamento: token da conta B recebe 404/403 e nunca vê dado do Felipe; rota rejeita `user_id`.
- Capacidade: token `mcp` não acessa `/api/executivo`; token `executivo:ler` não acessa `/mcp` nem escreve.
- Só leitura: POST/PUT/DELETE retornam 405 em todas as rotas.
- Privacidade: resposta não contém corpo, e-mail do remetente, telefone, documento (teste de lista branca).
- Falhas: Google `reconectar`/`desatualizado` e e-mail com erro geram `situacao` correta sem 500.
- Valor ausente é `null`; rodada com a suíte inteira (`php artisan test`).

## Riscos

- Dado de terceiros em título de evento e assunto (LGPD).
- Exposição à internet muda a superfície de ataque: só com decisão 3.
- `ConsultaDeEmails` e o financeiro hoje alimentam telas/IA em texto; a extração para método estruturado não pode mudar o comportamento atual (cobrir com testes antes).
- Critérios financeiros divergentes entre telas e API gerariam número "certo e diferente".
