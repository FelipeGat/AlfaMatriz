# Contrato: runner ↔ AlfaMatriz ↔ Gestor para a agenda dos técnicos

> **[PROPOSTA — a validar na implementação]**, exceto onde marcado como existente.
> As rotas existentes são `POST /api/central-ia/runner/suporte/gestor` (retrato),
> `.../suporte/gestor/aberturas` e `.../suporte/gestor/aberturas/{id}` (conferidas no
> `SuportePedroRunnerController` e no log do nginx de produção, 23/10/2026).

## 1. Retrato — lista nova `visitas` (rota existente, corpo ampliado)

`POST /api/central-ia/runner/suporte/gestor` passa a aceitar:

```json
{ "visitas": [ { "id": 1185, "atendimento_id": 925, "tecnico_id": 9,
  "inicio": "2026-10-23 08:00", "fim": "2026-10-23 10:00", "situacao": "agendado",
  "observacoes": "", "adicionais": [] } ] }
```

Validação item a item (como as outras listas desde v2026.10.23.1); `max:5000`.

## 2. Fila de mudanças de agenda (rotas novas)

`POST /api/central-ia/runner/suporte/gestor/agenda` — reserva até 5 mudanças `pendente` e devolve:

```json
{ "mudancas": [ { "id": 1, "chave": "p12-3", "tipo": "reagendar_visita", "visita_id": 1185,
  "atendimento_id": null, "tecnico_id": 9, "inicio": "2026-10-23 10:00", "fim": "2026-10-23 11:00",
  "esperado": { "inicio": "2026-10-23 08:00", "fim": "2026-10-23 10:00", "situacao": "agendado" },
  "motivo": null, "observacao": null, "pedido_por": "Wallas", "autor_email": "..." } ] }
```

`POST /api/central-ia/runner/suporte/gestor/agenda/{id}` — resultado:

```json
{ "maquina": "HOME", "ok": true, "visita_id": 1190, "numero": "756" }
{ "maquina": "HOME", "ok": false, "erro": "a visita mudou desde a prévia" }
```

Mesma autenticação das aberturas (token de capacidade `central-ia-runner`, conta do Pedro).

## 3. Script no Gestor (novo): `deploy/central-ia/suporte_gestor_agenda.php`

Roda pela ponte `run_prod.py`, como `suporte_gestor_gravar.php`: lê a mudança do ambiente, aplica
numa transação usando o serviço de visitas do Gestor (research, Decision 3) e imprime uma linha
`SUPORTE_AGENDA:{json}` com o resultado. Sem `--apply`, faz e desfaz (ensaio).
