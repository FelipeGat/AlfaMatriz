# Contrato: runner ↔ AlfaMatriz (transcrição) — [PROPOSTA — rotas novas desta etapa]

O prefixo `api/central-ia/runner/` e o token Sanctum de capacidade `central-ia-runner` são os mesmos de
`routes/central-ia.php`.

| Método | Rota | Corpo | Resposta |
|---|---|---|---|
| POST | `transcricoes/proxima` | `{maquina}` | 200 `{id, sha256, extensao, modo}` ou 204 |
| GET | `transcricoes/{id}/audio` | — | o arquivo (só para a máquina que reservou, com `status = transcrevendo`) |
| POST | `transcricoes/{id}` | `{maquina, status: "ok"\|"falhou", erro?, duracao?, trechos?: [{inicio, fim, texto}]}` | 200 `{ok: true}` |

Limites: até 5.000 trechos, cada texto com até 2.000 caracteres, e `0 ≤ inicio ≤ fim`.

## Imagem `alfamatriz-central-whisper:local`

Entrada: `/media/original.<ext>`. Saída, em stdout: JSON `{"duracao": s, "trechos": [{"inicio": s, "fim": s,
"texto": "…"}]}` ou `{"erro": "…"}`.

Roda com `--network=none --read-only --cap-drop=ALL --memory=3g --cpus=2`.
