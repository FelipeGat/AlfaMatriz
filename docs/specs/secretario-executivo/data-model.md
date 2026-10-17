# Data Model: Secretário Executivo, Etapa 1

Uma migração aditiva (`2026_10_17_100000_criar_secretario.php`), com `down()`, que roda em MariaDB e em SQLite.
Todas as datas usam o fuso da aplicação (America/Sao_Paulo).

## Entity: SecretarioItem (`secretario_itens`)

| Campo | Tipo | Regra |
|---|---|---|
| id | bigint | |
| user_id | fk users, cascade | dono; toda consulta é escopada por ele |
| tipo | string(16) | `tarefa`, `lembrete`, `compromisso`, `acompanhamento` |
| titulo | string(200) | obrigatório |
| descricao | text, null | |
| quando | datetime, null | nulo = sem data |
| so_data | bool, padrão false | true = prazo de dia inteiro (aviso às 08:00) |
| fuso | string(40), padrão `America/Sao_Paulo` | |
| antecedencia_min | smallint, null | minutos antes de `quando`; nulo = 0 (lembrete) ou a preferência (compromisso) |
| prioridade | string(8), padrão `normal` | `baixa`, `normal`, `alta` |
| status | string(12), padrão `aberto` | `aberto`, `concluido`, `cancelado` |
| recorrencia | json, null | `{freq: diaria\|semanal\|mensal, dias?: [1..7], dia_mes?: 1..31}` |
| origem | string(16) | `matriz`, `telegram` (depois: `reuniao`, `email`, `gestor`) |
| origem_ref | string(64), null | id na origem (ex.: id da proposta) |
| concluido_em, cancelado_em | datetime, null | |
| timestamps | | |

Índices: `(user_id, status, quando)`.

**Transições**:
- `aberto → concluido`: Concluir. Um item recorrente não conclui: ele avança para a próxima ocorrência e
  continua `aberto`.
- `aberto → cancelado`: Cancelar. Num item recorrente, "todas as próximas"; "só esta" avança.
- Adiar e reagendar mudam `quando` e mantêm `aberto`.
- Item encerrado não muda mais (FR-017).

## Entity: SecretarioAviso (`secretario_avisos`)

| Campo | Tipo | Regra |
|---|---|---|
| id | bigint | |
| item_id | fk secretario_itens, cascade | |
| user_id | fk users | redundante, para escopo e índice |
| para | datetime | quando entregar |
| canal | string(10) | `sino`, `telegram` |
| status | string(10), padrão `pendente` | `pendente`, `enviando`, `entregue`, `falhou`, `incerta`, `cancelado` |
| tentativas | tinyint, padrão 0 | |
| proxima_tentativa_em | datetime, null | |
| atrasado | bool, padrão false | entregue mais de 2 min depois de `para` |
| telegram_message_id | bigint, null | para editar a mensagem depois de um toque |
| erro | string(40), null | código, nunca texto do item |
| entregue_em | datetime, null | |
| timestamps | | |

Restrições: `unique(item_id, para, canal)`. Índice `(status, para)`.

**Transições**:
- `pendente → enviando → entregue | pendente (tentar de novo) | falhou | incerta`
- `pendente → cancelado`: o item foi reprogramado, concluído ou cancelado antes da hora.

## Entity: SecretarioProposta (`secretario_propostas`)

| Campo | Tipo | Regra |
|---|---|---|
| id | bigint | |
| user_id | fk users, cascade | |
| origem | string(16) | `matriz`, `telegram`, `ia` |
| texto | text | a frase original |
| dados | json | `{tipo, titulo, quando, so_data, recorrencia, antecedencia_min}` já validados |
| falta | string(16), null | `quando` quando o Matriz perguntou e espera a resposta |
| status | string(12), padrão `pendente` | `pendente`, `confirmada`, `descartada`, `expirada` |
| expira_em | datetime | criação + 30 min |
| item_id | fk secretario_itens, null | preenchido ao confirmar |
| execucao_id | bigint, null | proposta pedida à IA (fallback) |
| telegram_message_id | bigint, null | |
| timestamps | | |

Índice: `(user_id, status, expira_em)`. Só a proposta pendente mais recente da conta recebe "sim" ou correção.

## Entity: SecretarioPreferencia (`secretario_preferencias`)

| Campo | Tipo | Padrão |
|---|---|---|
| user_id | fk users, unique | |
| auto_confirmar | bool | false |
| antecedencia_padrao | smallint | 30 |
| telegram | bool | true |
| timestamps | | |

Sem linha, valem os padrões (nada é criado só por abrir a tela).

## Entity: SecretarioToque (`secretario_toques`)

| Campo | Tipo | Regra |
|---|---|---|
| id | bigint | |
| callback_id | string(64), unique | id do `callback_query` do Telegram |
| user_id | fk users | |
| created_at | datetime | limpo pelo `central-ia:limpar` depois de 7 dias |

Deduplica toques repetidos (FR-026).

## Permissão (na mesma migração)

- `permissoes`: recurso `secretario`, descrição "Secretário (lembretes e tarefas pessoais)".
- `perfis`: "Secretário", slug `secretario`, com `ler`, `incluir`, `editar` e `excluir` no recurso.
- Ninguém recebe o perfil pela migração. O Felipe liga na própria conta, pela tela de usuários.
- `down()` remove as tabelas, a linha de `perfil_permissao`, o perfil e o recurso.
