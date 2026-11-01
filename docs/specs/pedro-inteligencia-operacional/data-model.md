# Data Model: o Pedro acompanha a operação

**Plano**: `plan.md` · **Research**: `research.md` · **Data**: 31/10/2026

Tudo **aditivo**: nenhuma coluna existente muda de tipo, nome ou regra. As migrações do Matriz rodam em MariaDB e
SQLite e têm `down()` (Matriz, princípio V); a do Gestor não é destrutiva (Gestor, princípio II). Os nomes novos
estão marcados **[novo]**; o resto existe hoje (research.md, "Fatos").

## Gestor

### Entity: AtendimentoAndamento (`atendimento_andamentos`)

| Campo | Tipo | Regra |
|---|---|---|
| id, atendimento_id, user_id, descricao, timestamps | (existentes) | `user_id` NOT NULL em produção: o autor é sempre um usuário real (quem relatou). |
| origem **[novo]** | string(30), null | `pedro_whatsapp` quando veio do Pedro; null em tudo o que já existe e no que a tela e o app gravam. |
| matriz_chave **[novo]** | string(64), null, **unique** | A chave da prévia confirmada (Matriz). Gravar de novo com a mesma chave devolve o andamento existente. Mesmo padrão de `whatsapp_mensagens.matriz_chave`. |
| confirmado_por_user_id **[novo]** | FK users, null, set null | Quem validou a proposta (FR-011a) ou confirmou por outra pessoa; null quando o autor confirmou o próprio relato. |
| corrige_andamento_id **[novo]** | FK atendimento_andamentos, null, set null | O andamento que esta correção cita (FR-011b). O original nunca é editado. |

Fotos: `atendimento_andamento_fotos.arquivo` (existente), em `atendimento_andamentos/{atendimento_id}/{uuid}.{ext}`
no disco `public`, copiadas de `whatsapp_mensagens.midia_path`; até 10 por andamento.

### Atendimento (`atendimentos`) — só escopos novos, sem coluna

- `scopeParados()` e `scopeAtrasados()` **[novo]**: a regra que hoje está escrita no `CentralController`
  (research.md, Decision 2), movida para o model sem mudar o resultado.

### TreinamentoProcedimento (`treinamento_procedimentos`)

| Campo | Tipo | Regra |
|---|---|---|
| matriz_chave **[novo]** | string(64), null, **unique** | A chave da proposta aprovada (POP novo ou a última alteração vinda do Pedro). Mesma chave de novo = nada novo. |

Publicação de proposta aprovada: `status = publicado`; POP novo com `autor_id` = quem aprovou; alteração com
`editado_por_id`/`editado_em` = quem aprovou. `updated_at` é a versão conferida (FR-016b).

## Matriz

### Entity: SuporteGestorAndamento (`suporte_gestor_andamentos`) **[novo]**

Fila de gravação e, ao mesmo tempo, registro de origem (FR-020). Nunca é apagada.

| Campo | Tipo | Regra |
|---|---|---|
| id | bigint | |
| conversa_id | FK suporte_conversas, cascade | De onde veio. |
| mensagem_id | FK suporte_mensagens, null | A mensagem do relato. |
| chave | string(80), unique | Lote da prévia confirmada (`a<conversa>-<YmdHis>`), vai ao Gestor como `matriz_chave`. |
| tipo | string(12) | `andamento` \| `correcao`. |
| atendimento_id, atendimento_numero | bigint, string(30) | A OS no Gestor. |
| corrige_andamento_id | bigint, null | Só na correção. |
| texto | text | O texto exato da prévia (FR-007). |
| fotos | json | Lista de `wamid` das fotos (até 10, as mais recentes). |
| relatado_por | json | `{telefone_chave, nome, gestor_user_id, nivel}` no momento da prévia. |
| vinculo | string(15) | `responsavel` \| `adicional` \| `visita` \| `gestor` \| `sem_vinculo` (decide proposta ou gravação). |
| situacao | string(12), índice | `proposta → pendente → reservado → feito \| falhou`; `proposta → recusada`. |
| validado_por, validado_em | FK users (Matriz), null; datetime | Quem validou a proposta. |
| validado_por_gestor_user_id | bigint, null | O usuário do Gestor de quem validou (vai como `confirmado_por_user_id`). |
| motivo_recusa | string(300), null | |
| resultado | json, null | `{andamento_id, ja_estava}` do Gestor. |
| erro | string(300), null | |
| reservado_em, timestamps | | |

**Transições:** prévia confirmada com vínculo → `pendente`; sem vínculo → `proposta`; `proposta` validada →
`pendente`; recusada → `recusada`; a máquina reserva `pendente` → `reservado`; o script devolve → `feito` ou
`falhou`. `reservado` sem retorno em 10 min ganha o aviso de "sem confirmação do Gestor", como as aberturas
(`Pedro::semRetornoDoGestor`).

### Entity: SuportePopProposta (`suporte_pop_propostas`) **[novo]**

| Campo | Tipo | Regra |
|---|---|---|
| id, conversa_id, mensagem_id | | Origem. |
| chave | string(80), unique | Idem andamento. |
| pop_id | bigint, null | Null = POP novo. |
| pop_versao | string(25), null | O `updated_at` do POP afetado na hora da proposta. |
| pasta_id, empresa_id, segmento | bigint, bigint null, string(80) null | Do POP afetado, ou escolhidos por quem aprova. |
| titulo, conteudo, passos | string(255), mediumText null, json | O texto proposto (passos até 40, cada um até 2.000, como a tela do Gestor). |
| atendimento_numero | string(30), null | A OS de origem. |
| relatado_por | json | Como no andamento. |
| situacao | string(12), índice | `proposta → pendente → reservado → feito \| falhou`; `proposta → recusada`; `pendente/reservado → revisao` quando o POP mudou. |
| aprovado_por, aprovado_em, aprovado_por_gestor_user_id | | Quem aprovou (admin no Gestor). |
| motivo_recusa, resultado (`{pop_id}`), erro, reservado_em, timestamps | | |

### SuporteConversa (`suporte_conversas`) — colunas novas

| Campo | Tipo | Regra |
|---|---|---|
| previa_andamento **[novo]** | json, null | A prévia conferida (OS, texto, fotos, vínculo, lote, validade 3 h), no molde de `previa_agenda`. |
| previa_pop **[novo]** | json, null | Idem para POP. |

### Retrato do Gestor (`suporte_gestor_retrato`) — colunas novas

| Campo | Tipo | Regra |
|---|---|---|
| usuarios **[novo]** | longText, null | Usuários internos do Gestor (research.md, Decision 3). |
| pops **[novo]** | longText, null | POPs publicados (Decision 8). |

Os campos novos de cada atendimento (Decision 1) ficam dentro do JSON de `atendimentos`, que já existe.

### Ajustes (`suporte_ajustes`) — sem coluna

- `acessos_internos`: linha passa a aceitar `telefone | nome | perfil | e-mail no Gestor`; perfil `tecnico` novo.
- `vinculo_obrigatorio` **[novo]**: `1` liga o FR-012 (só o dono, na tela; recusado com linha sem vínculo válido).

## Relações

- Linha da lista → usuário do Gestor (pelo e-mail, no retrato `usuarios`) → funcionário (`funcionario_id`).
- Usuário → OS: responsável (`responsavel_id`), adicional (`funcionarios` menos o responsável), visita
  (`tecnicos_de_visita`, de qualquer data). O Gestor confere de novo na gravação (FR-011c); se o vínculo caiu, a linha
  volta a `proposta`.
- Fila de andamento → andamento do Gestor (`matriz_chave` = `chave`), 1 para 1.
- Proposta de POP → POP do Gestor (`pop_id`, ou o criado em `resultado.pop_id`).
