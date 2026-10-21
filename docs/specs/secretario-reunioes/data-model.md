# Data Model: Secretário, Etapa 4

Migração aditiva `2026_10_17_180000_secretario_reunioes.php`.

## Entity: SecretarioReuniao (`secretario_reunioes`)

| Campo | Tipo | Regra |
|---|---|---|
| user_id | fk users, cascade | dona |
| titulo | string(200) | nome do arquivo ou "Áudio do Telegram dd/mm hh:mm"; a análise pode sugerir |
| origem | string(10) | `telegram`, `upload`, `gravacao` |
| modo | string(8) | `reuniao`, `ditado` |
| status | string(14) | `aguardando`, `transcrevendo`, `analisando`, `pronta`, `revisada`, `falhou` |
| erro | string(80), null | código ou motivo curto |
| arquivo | string(255), null | caminho no disco privado; nulo depois de apagado |
| mime | string(60) | |
| tamanho | int | bytes |
| sha256 | string(64) | |
| duracao_s | int, null | |
| maquina | string(64), null | quem reservou |
| reservada_em | datetime, null | |
| tentativas | tinyint | |
| tipo_reuniao | string(16), null | |
| contexto | json, null | `{empresa, cliente, projeto, objetivo, problema, necessidade}` |
| resumo | text, null | |
| participantes | json, null | nomes citados (inferência) |
| proximos_passos | json, null | |
| roteiro | json, null | |
| parcial | bool | transcrição cortada para a análise |
| execucao_id | bigint, null | pedido de análise |
| telegram_update_id | bigint, null, unique | deduplica o reenvio do Telegram |
| transcrita_em, analisada_em | datetime, null | |
| timestamps | | |

Índice: `(user_id, status)`.

## Entity: SecretarioReuniaoTrecho (`secretario_reuniao_trechos`)

`reuniao_id` (fk, cascade), `ordem` (int, a partir de 1), `inicio` e `fim` (decimal 9,2, em segundos), `texto`
(text). Unique `(reuniao_id, ordem)`.

## Entity: SecretarioReuniaoProposta (`secretario_reuniao_propostas`)

`reuniao_id` (fk, cascade), `user_id`, `categoria` (`decisao`, `tarefa`, `pendencia`), `texto` (string 500),
`responsavel` (string 120, null), `prazo` (string 16, null: `AAAA-MM-DD` ou `AAAA-MM-DD HH:MM`), `prioridade`
(`baixa`, `normal`, `alta`), `classificacao` (`decisao`, `compromisso`, `sugestao`, `hipotese`, `pendencia`,
`informacao`), `evidencias` (json: lista de `ordem`), `status` (`pendente`, `aprovada`, `descartada`), `item_id`
(fk `secretario_itens`, null).
