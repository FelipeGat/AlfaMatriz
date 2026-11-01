# Contrato: retrato do Gestor ampliado

**Existente** (conferido em 31/10/2026): `deploy/central-ia/suporte_gestor_ler.php` imprime uma linha
`SUPORTE_GESTOR:{json}`; a máquina manda o JSON a `POST api/central-ia/runner/suporte/gestor`
(`suporte.runner.gestor`, `SuportePedroRunnerController::gestor`, `auth:sanctum` + `abilities:central-ia-runner`),
com `maquina`, `lido_em` e as listas `clientes`, `atendimentos`, `equipe`, `assuntos`, `showroom`, `visitas`,
`orcamentos`. Resposta `{ok: true, clientes: N}`. Campo sem regra de validação é descartado sem erro.

**[PROPOSTA — a validar na implementação]**: tudo abaixo é novo. Todos os campos novos são `sometimes` no Matriz:
o Matriz novo aceita a leitura antiga, e o antigo descarta os campos novos sem recusar a leitura.

## Atendimento (cada item de `atendimentos`) — campos novos

| Campo | Tipo | Origem no Gestor |
|---|---|---|
| `responsavel_id` | int \| null | `atendimentos.funcionario_id` (hoje vai misturado em `funcionarios`, que continua igual) |
| `em_execucao` | bool | `atendimentos.em_execucao` |
| `ultima_atividade` | `Y-m-d H:i:s` | a mesma expressão do escopo extraído da Central (research.md, Decision 2) |
| `etapa_desde` | `Y-m-d H:i:s` \| null | `created_at` da última linha de `atendimento_status_historicos` |
| `parado_central`, `atrasado_central` | bool | `Atendimento::parados()` / `atrasados()` (escopos novos no Gestor) |
| `tecnicos_de_visita` | int[] (até 20) | `funcionario_id` e `atendimento_visita_tecnicos.funcionario_id` de visitas com `status <> 'cancelado'` e sem `deleted_at`, de qualquer data |
| `andamentos` | até 5, só para OS fora de concluido/cancelado | `[{id, data, autor, origem, texto (300), fotos (int)}]`, mais recentes primeiro |
| `etapas` | até 5, idem | `[{data, status, autor}]` de `atendimento_status_historicos` |

Validação nova no Matriz (por item, como as atuais): `andamentos` array max 5, `texto` max 300, `autor` max 120,
`origem` max 30; `etapas` array max 5, `status` max 40; datas `date_format:Y-m-d H:i:s`.

## Lista nova: `usuarios` (até 500)

Usuários do Gestor com algum perfil de `User::PERFIS_INTERNOS`, inclusive inativos e excluídos (para o Matriz tirar
o acesso no retrato seguinte, FR-012b).

```json
{ "id": 12, "email": "…", "nome": "…", "ativo": true, "excluido": false,
  "funcionario_id": 31, "funcionario_ativo": true, "perfis": ["tecnico"], "parceiro": false,
  "pastas_visiveis": ["suporte"] }
```

`pastas_visiveis` vem de `TreinamentoPasta::slugsVisiveis` para o usuário. Nada de senha, token ou hash.

## Lista nova: `pops` (até 500)

`treinamento_procedimentos` com `status = 'publicado'` e sem `deleted_at`, em pasta ativa.

```json
{ "id": 7, "pasta": "suporte", "pasta_id": 2, "empresa_id": 3, "segmento": "Portões", "titulo": "…",
  "passos": ["…"], "conteudo": "… (até 1.500)", "atualizado_em": "2026-10-20 14:05:11" }
```

Passos até 40, cada um cortado em 500 no retrato (o texto inteiro fica no Gestor; a proposta de alteração parte do
texto lido de novo pelo script, não do retrato).

## Armazenamento no Matriz

`DiretorioGestor::gravar(...)` ganha `usuarios` e `pops` (parâmetros opcionais no fim, como os anteriores) e grava
nas colunas novas de `suporte_gestor_retrato`. O `retrato()` devolve as duas listas.
