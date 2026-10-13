# Data Model: Chats Business

**Feature**: `chats-business` | **Data**: 2026-10-13

As migrações só acrescentam. Rodam em MariaDB e em SQLite e têm `down()` (Princípio V).

## Entity: Chat de assunto (`central_ia_tarefas`, `tipo = 'assunto'`)

Colunas novas em `central_ia_tarefas`:

| Campo | Tipo | Regra |
|---|---|---|
| `tipo` | string(20), padrão `'tarefa'` | `tarefa` ou `assunto`. Tarefas existentes ficam `tarefa`. |
| `grupo_id` | FK nullable → `central_ia_grupos`, `nullOnDelete` | Só para `assunto`. Grupo da mesma conta, validado no servidor. |

Os campos existentes são reaproveitados:
- `titulo`: o nome do assunto, até 120 caracteres;
- `projeto`: `''` significa sem projeto;
- `status`, `dossie`, `ultima_atividade_em`, `user_id`.

**Índice**: `(user_id, tipo, status)`.

**Transições do tipo `assunto`**:

```
aberta ⇄ executando ⇄ aguardando_voce
   │          │
   └── falhou ┘   (o próximo pedido reabre)
aberta/aguardando_voce/falhou → arquivada (pela pessoa)   arquivada → aberta (pela pessoa)
```

O status `concluida` **nunca** é atribuído a um assunto. `tarefa_concluida: true` do bloco é ignorado.

## Entity: Memória do assunto (`dossie` da tarefa)

Chaves que já existem e são reaproveitadas: `objetivo`, `decisoes[]`, `pendencias[]`, `resumos[]`,
`pedidos[]`, `duvida`. Chaves novas:

| Chave | Forma | Regra |
|---|---|---|
| `jeito_de_fazer` | `[{texto, agente, em}]` | Acumula sem duplicar (comparação normalizada), máximo de 40 itens. |
| `correcoes` | `[{acao: editar\|apagar, chave, antes, depois, em}]` | Feitas pela pessoa. Prevalecem e ficam só por acréscimo. |
| `condensado` | `{ate_mensagem_id, texto, em, execucao}` | Resumo do histórico antigo (research Decision 4). Máximo de 24 mil caracteres. |
| `sementes` | `[{origem: wsl\|mac_copia, agente, sessao, titulo, lido_ate, caracteres, execucao, em}]` | Uma por conversa importada. `lido_ate` = linhas lidas, para atualizar só o trecho novo. |

Todo texto gravado passa por `ProtecaoDeSegredos` (FR-015).

## Entity: Grupo (`central_ia_grupos`, existente)

Sem mudança de esquema. O grupo "Pessoal" é criado sob demanda, como os outros, pelo endpoint que já
existe.

## Entity: Execução (`central_ia_execucoes`, existente)

Sem coluna nova. A finalidade vai em `roteamento.finalidade`:
- `pedido`: o padrão;
- `condensar`;
- `semear`.

A `instrucao` de `semear` leva só os ids das sessões. O servidor nunca envia caminho.

## Entity: Vínculo do Telegram (`central_ia_telegram_vinculos`, existente)

| Campo novo | Tipo | Regra |
|---|---|---|
| `assunto_atual_id` | FK nullable → `central_ia_tarefas`, `nullOnDelete` | Precisa ser tarefa `assunto` da mesma conta. `/geral` limpa. |

## Entity: Preferências (`Preferencias`, existente)

Chaves novas, com padrão e validação no servidor:

| Chave | Padrão | Faixa |
|---|---|---|
| `assunto_contexto_tokens` | 60000 | 20000–150000 |
| `semente_teto_caracteres` | 1500000 | 100000–5000000 |
