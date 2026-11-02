# Contrato: fila de orçamentos do Pedro (Matriz ↔ máquina ↔ Gestor)

Proposta de 02/11/2026, implementada nesta feature. Mesmo formato da fila da agenda
(`pedro-dia-do-tecnico/contracts/runner-agenda.md`).

## 1. Matriz → máquina: `POST /api/central-ia/suporte/gestor/orcamentos`

Corpo: `{ "maquina": "<nome>" }`. Só a conta do Pedro. Reserva até 3 pendentes.

```json
{ "orcamentos": [ {
  "id": 12, "chave": "o41-20261102143005",
  "autor_email": "<e-mail do usuário do Gestor de quem pediu>",
  "pedido_por": "Felipe",
  "cliente_id": 167, "pre_cliente_id": null,
  "empresa_id": 3, "sem_nf": false, "atendimento": "812",
  "descricao": "Manutenção do motor do portão", "observacoes": "…",
  "validade": null, "forma_pagamento": null, "prazo_pagamento": null,
  "itens": [ { "nome": "Manutenção do motor", "tipo": "servico", "quantidade": 1, "valor_unitario": 450.0, "descricao": null } ],
  "total_previa": 517.5
} ] }
```

## 2. Máquina → Gestor (ponte, `--apply`): `suporte_gestor_orcamento.php`

Entrada: o item acima. Saída, numa linha:

```
SUPORTE_ORCAMENTO:{"ok":true,"id":991,"numero":"045/2026","empresa":"Invest","valor_total":517.5,"imposto_nf_percentual":15.0,"sem_nf":false,"ja_estava":false,"link":"https://…/orcamentos/991","pdf":"https://…/orcamentos/991/imprimir","ensaio":false}
SUPORTE_ORCAMENTO:{"ok":false,"erro":"o atendimento nº 812 é de outro cliente","ensaio":false}
```

- `ja_estava`: a chave já tinha um orçamento; nada novo foi criado.
- Sem o serviço no Gestor: `ok:false`, "o Gestor ainda não tem a criação de orçamento pelo Pedro —
  publicar o Gestor antes".

## 3. Máquina → Matriz: `POST /api/central-ia/suporte/gestor/orcamentos/{id}`

```json
{ "maquina": "<nome>", "ok": true, "numero": "045/2026", "orcamento_id": 991, "valor_total": 517.5,
  "imposto_nf_percentual": 15.0, "link": "…", "pdf": "…", "ja_estava": false }
{ "maquina": "<nome>", "ok": false, "erro": "…" }
```

Só para o que está `reservado`. O Matriz grava o resultado e manda a mensagem a quem pediu.

## 4. Retrato do Gestor: chave nova `empresas`

```json
"empresas": [ { "id": 3, "nome": "Invest", "imposto_nf": 15.0 } ]
```

`imposto_nf` é `Orcamento::percentualImpostoNfVigente(id)`. Matriz sem a chave ignora; máquina sem a
chave deixa a prévia sem o percentual.
