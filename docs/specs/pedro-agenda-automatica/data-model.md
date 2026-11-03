# Data Model: pedro-agenda-automatica

Tudo no **Gestor** (`~/projetos/gestor`, MySQL 8). O AlfaMatriz não ganha tabela: lê e muda pelas portas de
`contracts/integracao-agenda.md`. Migrações só aditivas (tabelas novas), com `down()`; nenhuma coluna existente muda.
Nomes de tabela e coluna em snake_case, no padrão do Gestor. Tipos e índices são **[PROPOSTA]**, a confirmar na
implementação.

## Entity: AgendaDestinatario (`agenda_destinatarios`)

Quem recebe. Não guarda número: ele vem de `funcionarios.celular` a cada envio (FR-001, FR-003).

| Field | Type | Constraints | Notes |
|---|---|---|---|
| id | bigint | PK | |
| funcionario_id | bigint | FK `funcionarios`, NOT NULL | funcionário já existente (FR-002); nunca criado nem alterado aqui |
| papel | enum `tecnico`, `gestor` | NOT NULL | o mesmo funcionário pode ter as duas linhas |
| ativo | boolean | default true | desativar não apaga o histórico |
| consentimento_em | datetime | nullable | sem ele, nada sai (FR-006) |
| consentimento_por | string(120) | nullable | e-mail de quem registrou, vindo do Matriz |
| parou_em | datetime | nullable | "parar" (FR-006); preenchido → nada sai |
| criado_por | string(120) | NOT NULL | e-mail de quem incluiu |
| timestamps | | | |

- Único: `(funcionario_id, papel)`.
- `LogsActivity` (spatie) com `logOnly` dos campos acima: quem mudou, quando e o quê (FR-005), mais o `activity()` com o
  autor do Matriz em cada porta de escrita.

**Pendência calculada (não gravada)**, na ordem: `inativo` (destinatário ou funcionário inativo/apagado) → `parou` →
`sem_consentimento` → `sem_numero` → `numero_compartilhado` (outro funcionário ativo com a mesma chave) →
`nunca_enviar` (chave em `AGENDA_NUNCA_ENVIAR`) → nenhuma.

## Entity: AgendaAviso (`agenda_avisos`)

Cada mensagem preparada, enviada ou ensaiada, e as linhas de programação vazia e de pendência. É também a "versão
comunicada" (FR-012).

| Field | Type | Constraints | Notes |
|---|---|---|---|
| id | bigint | PK | |
| destinatario_id | bigint | FK `agenda_destinatarios`, NOT NULL | o técnico sem destinatário não tem linha: a pendência dele é calculada no relatório (Decision 18) |
| tipo | enum `programacao`, `mudanca`, `relatorio`, `alerta` | NOT NULL | |
| dia_agenda | date | NOT NULL | o dia de que a mensagem fala |
| modo | enum `ensaio`, `real` | NOT NULL | ensaio não conta para a comparação do real |
| versao | char(64) | NOT NULL | sha256 da programação canônica; no alerta, da sequência |
| programacao | json | NOT NULL | compromissos comunicados: `[{atendimento, cliente, inicio, fim, visitas:[ids]}]`; é a base da próxima comparação |
| mudancas | json | nullable | no tipo `mudanca`, a lista classificada (Decision 3) |
| texto | text | nullable | o texto exato (livre) ou o corpo preenchido (modelo), por parte |
| partes | tinyint | default 1 | |
| partes_enviadas | tinyint | default 0 | quantas partes a Meta já aceitou: a nova tentativa manda só as que faltam (Fase 3, migração `2026_11_03_120000`) |
| previa | boolean | default false | linha da "Prévia agora": não conta como comunicada (Fase 4, mesma migração) |
| canal | enum `livre`, `modelo` | nullable | nulo quando nada foi enviado |
| modelo | string(60) | nullable | nome do modelo usado |
| numero | string(20) | nullable | wa_id para onde saiu; apagado na retenção (Decision 20) |
| situacao | enum `preparada`, `aceita`, `entregue`, `lida`, `falha`, `ensaio`, `sem_envio`, `pendente` | NOT NULL | ver transições |
| pendencia | string(30) | nullable | o motivo quando `pendente` (ver acima, mais `sem_modelo`) |
| tentativas | tinyint | default 0 | até 3 (FR-026) |
| proxima_tentativa_em | datetime | nullable | quando a linha volta à fila: a nova tentativa, o alerta adiado, ou o prazo de retomada da `preparada` (10 min), para o envio que caiu no meio |
| wamid | string(120) | nullable, único | da tentativa mais recente; liga ao webhook |
| erro_codigo | string(20) | nullable | código da Meta |
| erro | string(500) | nullable | título/mensagem da Meta ou da nossa recusa |
| enviado_em | datetime | nullable | aceite pela Meta |
| timestamps | | | |

- Único (FR-029-INFRA-IDEMP): `(destinatario_id, dia_agenda, tipo, versao, modo)`.
- Índices: `(dia_agenda, tipo)`, `(situacao, proxima_tentativa_em)`.
- **Versão comunicada** de (destinatário, dia, modo) = a linha mais recente de tipo `programacao` ou `mudanca` com
  situação `aceita`, `entregue`, `lida` ou `sem_envio` (no modo `ensaio`, também `ensaio`), que não seja prévia.
- **`versao` por tipo** (implementação, 03/11/2026): na programação, o sha256 da programação canônica; na mudança, o
  sha256 do id da linha comunicada mais a versão da programação nova — a agenda que vai de A a B, volta a A e vai
  de novo a B precisa de três avisos, e a chave só da programação nova barraria o terceiro; no relatório, o sha256
  do conteúdo contado; no alerta, o da mensagem que abriu a sequência.
- **`programacao` por tipo**: os compromissos comunicados (programação e mudança, a base da comparação); no
  relatório, o que foi contado (linhas, visitas, técnicos, conflitos, pendências); no alerta, quem escreveu, o texto e
  se pediu para parar. É daí que a mensagem é remontada a cada tentativa.
- `Prunable`: com mais de 365 dias, `numero` e `texto` viram nulos (Decision 20); no alerta, `programacao` também
  (é onde está o que o técnico escreveu). A linha fica: `prune()` sobrescrito, e o `model:prune` agendado só com
  `--model=AgendaAviso`, às 03:20.

### State Transitions

```text
preparada ──aceite da Meta──▶ aceita ──delivered──▶ entregue ──read──▶ lida
    │                           │  └────────read───────────────────────▲
    │                           └──failed──▶ falha
    └──recusa na chamada──▶ falha ──(tentativas < 3, na hora)──▶ preparada
ensaio      (modo ensaio: nasce e fica assim)
sem_envio   (programação vazia às 16:30: baseline sem mensagem)
pendente    (não enviada por motivo de pendência; não muda)
```

A situação só avança (`lida` não volta a `entregue`). `falha` com 3 tentativas é final.

A `preparada` do envio real tem sempre prazo: nasce com o de retomada, e quem vai à Meta conta a tentativa e o
renova antes da chamada. Se o processo cai no meio, ela fica `preparada` com o prazo vencido e a fila a retoma como
mais uma tentativa; a 3ª interrompida vira `falha` (`Não reenviado: a última tentativa foi interrompida…`). A parte
que estava saindo pode ter chegado — o mesmo risco da Meta que não responde (`conexao`), que também tenta de novo.

## Entity: AgendaVerificacao (`agenda_verificacoes`)

Uma linha por verificação agendada e dia: a prova de que rodou e a recuperação da perdida (FR-028-INFRA-SCHED).

| Field | Type | Constraints | Notes |
|---|---|---|---|
| id | bigint | PK | |
| tipo | enum `manha`, `programacao`, `relatorio` | NOT NULL | |
| dia | date | NOT NULL | o dia em que roda (a programação e o relatório falam do dia seguinte) |
| modo | enum `desligada`, `ensaio`, `real` | NOT NULL | `desligada` só registra que o ciclo rodou (a prova do agendador, Decision 4); ligar depois da hora roda a verificação do dia no modo novo |
| iniciada_em | datetime | NOT NULL | |
| concluida_em | datetime | nullable | nula = roda de novo no próximo ciclo |
| resumo | json | nullable | contagens: enviadas, sem envio, pendências, falhas |
| erro | string(500) | nullable | falha de leitura da agenda (vai ao relatório) |
| timestamps | | | |

- Único: `(tipo, dia, modo)`.

## Entity: AgendaObservacao (`agenda_observacoes`)

A estabilidade antes do aviso de mudança (Decision 2). Uma linha por destinatário e dia, sobrescrita.

| Field | Type | Constraints | Notes |
|---|---|---|---|
| id | bigint | PK | |
| destinatario_id | bigint | FK, NOT NULL | |
| dia_agenda | date | NOT NULL | |
| modo | enum `ensaio`, `real` | NOT NULL | |
| versao_vista | char(64) | NOT NULL | a programação atual vista no ciclo |
| vista_desde | datetime | NOT NULL | a mudança só sai com `vista_desde` ≤ agora − 2 min |
| timestamps | | | |

- Único: `(destinatario_id, dia_agenda, modo)`.

## Configuração (`app_settings`, chave `agenda_automatica`)

JSON, lido por `AppSetting::get` e gravado por `AppSetting::set` (que limpa o cache).

| Campo | Valor padrão | Origem |
|---|---|---|
| modo | `desligada` | FR-025 |
| manha | `06:30` | spec, Clarifications |
| programacao | `16:30` | spec |
| relatorio | `17:00` | spec |
| silencio_inicio | `20:00` | spec (padrão) |

Mudança: `activity()` com o autor do Matriz, o antes e o depois (FR-005).

**Configuração de ambiente** (`config/whatsapp.php` do Gestor, onde já mora o modelo da cobrança; chave nova
`agenda_automatica`), sem segredo:
`portal_url` (`AGENDA_PORTAL_URL`, padrão `https://gestor.alfa.solucoesgrupo.com/portal-funcionario`),
`nunca_enviar` (`AGENDA_NUNCA_ENVIAR`, chaves de 10 dígitos), `estavel_minutos` (2), `margem_janela_minutos` (5),
`tentativas_minutos` (`5,15`).

## Relationships

- `AgendaDestinatario` N:1 `Funcionario` via `funcionario_id`.
- `AgendaAviso` N:1 `AgendaDestinatario`.
- `AgendaObservacao` N:1 `AgendaDestinatario`.
- Leitura (sem FK nova): `AtendimentoVisita` (`funcionario_id`, pivô `atendimento_visita_tecnicos`) → `Atendimento`
  (`numero_atendimento`, `nome_tomador`).
- `WhatsappConversa`/`WhatsappMensagem`: só a resposta fixa ao técnico (Decision 14) e a janela (Decision 6).

## Value object: Compromisso (em memória)

`{atendimento: string (numero_atendimento), cliente: string (nome_tomador), inicio: "HH:MM", fim: "HH:MM",
visitas: [int]}`. A forma canônica para a versão: lista ordenada por (inicio, atendimento), só esses campos, sem os ids
das visitas (o `definirVisitas` troca os ids sem mudar o compromisso).
