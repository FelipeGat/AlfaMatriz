# Data Model: Secretário, Etapa 2

Uma migração aditiva: `2026_10_17_140000_secretario_google.php`.

## Entity: SecretarioGoogleConta (`secretario_google_contas`)

| Campo | Tipo | Regra |
|---|---|---|
| user_id | fk users, unique, cascade | dona |
| conta | string(255), null | id da agenda primária (o e-mail) |
| access_token | text, cast `encrypted`, hidden | |
| refresh_token | text, cast `encrypted`, hidden | |
| expira_em | datetime | |
| escopos | string(500) | o que o Google concedeu |
| situacao | string(16) | `conectada`, `reconectar` |
| sincronizado_em | datetime, null | última sincronização com sucesso |
| erro | string(40), null | código da última falha |
| aviso_reconectar_em | datetime, null | o sino avisou (uma vez) |
| timestamps | | |

## Entity: SecretarioGoogleAgenda (`secretario_google_agendas`)

| Campo | Tipo | Regra |
|---|---|---|
| user_id | fk users, cascade | |
| google_id | string(255) | unique com user_id |
| nome | string(255) | |
| primaria | bool | |
| incluida | bool, padrão true | |
| padrao | bool, padrão false | uma por conta |
| palavras | string(255), null | separadas por vírgula ("vixcard, vix-id, vixtag") |
| timestamps | | |

## Entity: SecretarioGoogleEvento (`secretario_google_eventos`)

| Campo | Tipo | Regra |
|---|---|---|
| user_id | fk users, cascade | |
| agenda_id | fk secretario_google_agendas, cascade | |
| google_id | string(255) | unique com agenda_id |
| titulo | string(255) | |
| inicio, fim | datetime | no fuso da aplicação |
| dia_inteiro | bool | |
| link | string(500), null | `htmlLink` |
| timestamps | | |

Índice: `(user_id, inicio)`.

## Alteração: `secretario_propostas`

Sem coluna nova. `dados.tipo = evento_google` leva `agenda_id`, `inicio`, `fim` e `titulo`.

## Alteração: `secretario_itens`

Sem coluna nova. O compromisso criado no Google guarda `origem = 'google'` e `origem_ref = 'google:<agenda_id>:<event_id>'`.
