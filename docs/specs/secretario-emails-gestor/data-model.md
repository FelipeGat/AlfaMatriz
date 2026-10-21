# Data Model: Secretário, Etapa 5

Migração aditiva `2026_10_17_200000_secretario_emails_gestor.php`.

## SecretarioEmailConta (`secretario_email_contas`)

`user_id` (fk), `rotulo` (string 60), `host` (120), `porta` (int), `seguranca` (`ssl` ou `tls`), `usuario` (190),
`senha` (text, `encrypted`, hidden), `pasta_entrada` (120, padrão `INBOX`), `pasta_enviados` (120, null),
`posicoes` (json: `{pasta: {uidvalidity, ultimo_uid}}`), `situacao` (`ok` ou `erro`), `erro` (string 80, null),
`lida_em` (datetime, null), timestamps.

## SecretarioEmail (`secretario_emails`)

`user_id`, `conta_id` (fk, cascade), `pasta` (120), `uidvalidity` (bigint), `uid` (bigint), `message_id` (255,
null), `responde_a` (255, null), `de_nome` (190, null), `de_email` (190), `assunto` (255), `recebido_em`
(datetime), `tipo` (16), `urgencia` (8), `pede_retorno` (bool), `enviado` (bool: veio da pasta de enviados),
`respondido` (bool), timestamps.

Restrição: `unique(conta_id, pasta, uidvalidity, uid)`. Índices: `(user_id, recebido_em)` e `(conta_id, message_id)`.

## SecretarioGestorRetrato (`secretario_gestor_retratos`)

`user_id` (unique), `maquina`, `lido_em` (datetime), `dados` (json: `{blocos: {chave: {quantidade, valor?, itens:
[{texto, data?, valor?}]}}}`), timestamps.
