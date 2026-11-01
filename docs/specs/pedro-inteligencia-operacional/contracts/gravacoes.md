# Contrato: gravação de andamento e de POP pela máquina

**Existente, que serve de molde** (conferido em 31/10/2026): a fila da agenda — `POST
api/central-ia/runner/suporte/gestor/agenda` (`suporte.runner.agenda`) reserva até 5 `pendente` e devolve
`{mudancas: [...]}`; `POST …/agenda/{mudanca}` (`suporte.runner.agenda-feita`) recebe `ok`, `erro`, `visita_id`,
`numero`, `ja_estava`. O `suporte.mjs` roda o script pela ponte (cópia do PHP com `'__DADOS__'` trocado pelo JSON em
base64, `--apply`), lê uma linha marcada e não tenta de novo. As duas checagens de todo método do runner continuam:
`maquina()` e `exigirConta()`.

**[PROPOSTA — a validar na implementação]**: as rotas, campos e linhas abaixo são novos.

## 1. Andamentos

### Reservar — `POST api/central-ia/runner/suporte/gestor/andamentos` (`suporte.runner.andamentos`)

Entrada `{maquina}`. Reserva até 5 em `pendente` (transação + `lockForUpdate`) e devolve:

```json
{ "andamentos": [ { "id": 1, "chave": "a812-20261103101500", "tipo": "andamento|correcao",
  "atendimento_id": 812, "texto": "…", "fotos": ["wamid.…"], "corrige_andamento_id": null,
  "autor_email": "tecnico@…", "confirmado_por_email": null, "vinculo": "responsavel|adicional|visita|gestor" } ] }
```

`autor_email` é o e-mail do usuário do Gestor de quem relatou (não mais a conta do dono); `confirmado_por_email`, o
de quem validou a proposta.

### Script — `deploy/central-ia/suporte_gestor_andamento.php` → linha `SUPORTE_ANDAMENTO:{json}`

Carrega o Gestor e chama `AndamentoDeAtendimentoService::registrar(...)`:

| Saída | Quando |
|---|---|
| `{ok: true, andamento_id, ja_estava: false}` | Gravou. |
| `{ok: true, andamento_id, ja_estava: true}` | A `matriz_chave` já existia: nada novo. |
| `{ok: false, motivo: "sem_vinculo"}` | O vínculo de quem relatou caiu (FR-011c): a linha volta a `proposta`. |
| `{ok: false, motivo: "os_fechada"}` | OS concluída ou cancelada. |
| `{ok: false, motivo: "autor_invalido"}` | Usuário inexistente, inativo ou excluído. |
| `{ok: false, motivo: "erro", erro}` | Qualquer outra falha (sem gravar: transação). |

Fotos: cada `wamid` é procurado em `whatsapp_mensagens.wa_message_id`; o arquivo de `midia_path` é copiado para
`atendimento_andamentos/{atendimento_id}/{uuid}.{ext}`. Foto ausente não derruba o andamento: volta em
`fotos_faltando` (int).

### Devolver — `POST …/suporte/gestor/andamentos/{andamento}` (`suporte.runner.andamento-feito`)

Entrada `{ok (bool, obrigatório), andamento_id?, ja_estava?, motivo?, erro? (300), fotos_faltando?}`. Só aceita linha
em `reservado` (senão 404). `sem_vinculo` → `proposta`; outro `ok: false` → `falhou`; `ok: true` → `feito` e a
mensagem para quem relatou ("Registrei no Gestor, na OS nº …").

## 2. POPs

### Reservar — `POST …/suporte/gestor/pops` (`suporte.runner.pops`)

```json
{ "pops": [ { "id": 3, "chave": "p77-20261104090000", "pop_id": 7, "pop_versao": "2026-10-20 14:05:11",
  "pasta_id": 2, "empresa_id": 3, "segmento": "Portões", "titulo": "…", "conteudo": "…", "passos": ["…"],
  "aprovado_por_email": "admin@…" } ] }
```

### Script — `deploy/central-ia/suporte_gestor_pop.php` → linha `SUPORTE_POP:{json}`

| Saída | Quando |
|---|---|
| `{ok: true, pop_id, ja_estava}` | Publicou (novo ou alteração), com `status = publicado`. |
| `{ok: false, motivo: "mudou", atualizado_em}` | `updated_at` atual ≠ `pop_versao` (FR-016b): a proposta vai a `revisao`. |
| `{ok: false, motivo: "sem_permissao"}` | Quem aprovou não é admin no Gestor (`podeEditar`). |
| `{ok: false, motivo: "erro", erro}` | Falha, sem gravar. |

Idempotência: o POP novo grava `treinamento_procedimentos.matriz_chave` (coluna nova, unique) com a `chave`; a mesma
chave devolve o POP existente com `ja_estava: true`. A alteração confere a versão antes e grava a chave depois, então
a repetição cai em `ja_estava` (chave igual) e não em `mudou`.

### Devolver — `POST …/suporte/gestor/pops/{proposta}` (`suporte.runner.pop-feito`)

Entrada `{ok, pop_id?, ja_estava?, motivo?, atualizado_em?, erro?}`. `mudou` → `revisao`; `ok: true` → `feito`.

## 3. Máquina (`suporte.mjs` / `runner.mjs`)

`gravarAndamentos` e `gravarPops` entram na volta de 20 s, depois de `gravarContatos`, com a mesma trava da ponte
(`pontePresa`) e sem nova tentativa. O teste da máquina (`test-suporte.mjs`) confere que todo campo da saída do
script chega ao Matriz (lição de 31/10/2026: campo que a máquina não repassa some sem erro).
