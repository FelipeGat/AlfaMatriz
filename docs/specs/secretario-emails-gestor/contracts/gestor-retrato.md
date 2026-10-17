# Contrato: retrato do Gestor (runner → AlfaMatriz) — [PROPOSTA]

`POST api/central-ia/runner/gestor`, com o token `central-ia-runner` e a permissão `secretario`.

```json
{"maquina": "HOME", "lido_em": "2026-10-17T06:52:00-03:00", "blocos": {
  "orcamentos_aprovacao": {"quantidade": 4, "valor": 38200.0, "itens": [{"texto": "Orç. 191 rev 2 — Cliente X", "data": "2026-10-10", "valor": 12000.0}]},
  "orcamentos_elaboracao": {"quantidade": 2, "itens": []},
  "cobrancas_vencidas": {"quantidade": 2, "valor": 5300.0, "itens": []},
  "cobrancas_hoje": {"quantidade": 1, "itens": []},
  "pagar_vencendo": {"quantidade": 0, "itens": []},
  "reunioes_proximo_passo": {"quantidade": 3, "itens": [{"texto": "Cliente Y: enviar amostra", "data": "2026-10-18"}]},
  "funil_parado": {"quantidade": 5, "itens": []}
}}
```

Limites:
- só as chaves acima;
- `quantidade` é um inteiro entre 0 e 100.000;
- `valor` é um número ≥ 0;
- até 10 itens por bloco;
- `texto` com até 200 caracteres;
- `data` no formato `AAAA-MM-DD`.

Retrato fora disso é recusado (422), e o anterior continua valendo.

Saída do script (`secretario_gestor.php`, rodado pela ponte): uma linha `SECRETARIO_GESTOR:` seguida do JSON de
`blocos`.
