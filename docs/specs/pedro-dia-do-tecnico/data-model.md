# Data Model: o Pedro lança e corrige o dia do técnico

## Entity: Visita no retrato (AlfaMatriz, cópia de leitura)

Nova lista `visitas` no retrato do Gestor (`suporte_gestor_retrato`, coluna JSON nova, como
`equipe` e `showroom`). Substituída inteira a cada leitura.

| Campo | Origem no Gestor (`atendimento_visitas`, conferido em produção 23/10/2026) | Notas |
|---|---|---|
| id | `id` | |
| atendimento_id | `atendimento_id` | liga ao atendimento do retrato |
| tecnico_id | `funcionario_id` | principal; liga à `equipe` do retrato |
| inicio | `data_inicio` | `AAAA-MM-DD HH:MM`, fuso do Gestor |
| fim | `data_fim` | idem |
| situacao | `status` | `agendado`, `em_atendimento`, `finalizado`, `cancelado` (constantes `STATUS_*` de `AtendimentoVisita`, conferidas em 23/10/2026) |
| observacoes | `observacoes` | até 300 caracteres |
| adicionais | `atendimento_visita_tecnicos.funcionario_id` | só ids, para avisar na prévia |

Janela: `data_inicio` entre hoje −7 e hoje +14 dias; sem `deleted_at`.

A **equipe** do retrato passa a levar todos os funcionários ativos que aparecem como técnico de
visita na janela, não só os que têm telefone (hoje a lista é "funcionário ativo com telefone").

## Entity: Mudança de agenda (AlfaMatriz, tabela nova `suporte_gestor_agenda`)

Uma linha por parada confirmada. Nasce só depois do "sim".

| Campo | Tipo | Notas |
|---|---|---|
| id | pk | |
| conversa_id | fk `suporte_conversas` | de onde veio o pedido |
| chave | string única | prévia + parada (idempotência) |
| tipo | enum | `criar_visita`, `reagendar_visita`, `cancelar_visita` |
| visita_id | int nulo | visita do Gestor (reagendar/cancelar) |
| atendimento_id | int nulo | atendimento do Gestor (criar) |
| abertura_id | fk nulo `suporte_gestor_aberturas` | quando a visita é de um atendimento novo da mesma prévia |
| tecnico_id | int | funcionário do Gestor |
| inicio, fim | datetime | horário pedido |
| esperado | json nulo | como a visita estava na prévia (horário, situação) — conferido no Gestor antes de mudar |
| motivo | string nulo | obrigatório para cancelar |
| observacao | string nulo | resultado técnico do relato ("placa condenada") |
| pedido_por | string | nome da lista de acesso interno |
| situacao | enum | `pendente` → `reservado` → `feito` / `falhou` |
| resultado | json nulo | o que o Gestor devolveu (id da visita, número do atendimento) |
| erro | string nulo | motivo da falha |
| timestamps | | |

### State transitions

```
pendente --(runner pega)--> reservado --(Gestor aplicou)--> feito
                                    \--(Gestor recusou)--> falhou
pendente --(ninguém pegou no prazo)--> falhou ("nenhuma máquina gravou")
```

Mudança com `abertura_id` só é oferecida ao runner depois que a abertura está `feito` (a visita
precisa do atendimento criado).

## Entity: Prévia do dia (AlfaMatriz, guardada na conversa)

O que a IA propôs e o código conferiu, mostrado a quem pediu. Fica no estado da conversa até o
"sim" ou até a próxima prévia substituí-la; o "sim" converte a prévia em mudanças
(`suporte_gestor_agenda`) e aberturas (`suporte_gestor_aberturas`). Prévia com bloqueio
(lacuna, conflito não resolvido) não pode ser confirmada.
