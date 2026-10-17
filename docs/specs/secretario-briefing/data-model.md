# Data Model: Secretário, Etapa 3

Migração aditiva `2026_10_17_160000_secretario_briefing.php`.

## Entity: SecretarioBriefing (`secretario_briefings`)

| Campo | Tipo | Regra |
|---|---|---|
| user_id | fk users, cascade | dona |
| data | date | dia do briefing (America/Sao_Paulo) |
| origem | string(10) | `automatico` ou `pedido` |
| texto | text | o briefing (nunca em log) |
| fontes | json | `{usadas: [], ausentes: [], google: {estado, sincronizado_em}}` |
| telegram | string(12) | `pendente`, `enviando`, `entregue`, `falhou`, `sem` |
| tentativas | tinyint | |
| proxima_tentativa_em | datetime, null | |
| gerado_em | datetime | |
| timestamps | | |

Restrição: `unique(user_id, data, origem)`. O pedido sob demanda atualiza a linha `pedido` do dia, e o automático
nunca repete.

## Alteração: `secretario_preferencias`

| Campo | Tipo | Padrão |
|---|---|---|
| briefing | bool | true |
| briefing_hora | string(5) | `07:00` |
| briefing_dias | json | `[1,2,3,4,5]` (ISO: segunda = 1) |
