# Contrato: portas da agenda automática — AlfaMatriz → Gestor

**Implementado no Gestor na Fase 1 (02/11/2026), sem commit nem publicação até o QA e a autorização**:
`IntegracaoMatrizAgendaController`, regra em `app/Services/WhatsApp/AgendaAutomatica/Administracao.php`. O teste de
contrato `tests/Integracao/AgendaAutomaticaPortasTest.php` (Gestor) chama as sete portas e confere chaves, tipos e
valores permitidos contra este arquivo. O que a implementação decidiu está em "Decidido na Fase 1", no fim.

O que **já existe** e foi lido do código do Gestor em 02/11/2026 (reaproveitado sem mudança):

- **Autenticação**: `Authorization: Bearer <segredo da integração>`, o mesmo segredo dos dois lados
  (`MATRIZ_PEDRO_TOKEN` no Gestor, `SUPORTE_GESTOR_TOKEN` no Matriz), conferido pelo middleware
  `AutenticaIntegracaoMatriz` (`app/Http/Middleware/AutenticaIntegracaoMatriz.php:18-27`) com `hash_equals`; falha →
  401 `{"motivo":"credencial"}`. Fonte: `docs/specs/whatsapp-pedro/contracts/integracao-matriz.md` (Gestor) e
  `routes/api.php:36-59`.
- **Limite**: `throttle:120,1`, como as portas de `integracao/matriz/whatsapp/*`.
- **Cliente no Matriz**: `services.suporte_gestor.url` e `.token` (`config/services.php:70-73`), o mesmo usado por
  `CanalWhatsApp::pedirAoGestor` e `numerosInternosAoGestor`.

Todas as portas novas ficam sob `POST|GET /api/integracao/matriz/agenda/...`, com os dois middlewares acima. Corpo e
resposta em JSON, chaves em snake_case. Toda porta de escrita recebe `autor` (e-mail de quem mudou no Matriz, até 120)
e grava `activity()` com ele, o antes e o depois. Telefone **nunca** sai inteiro: só `final` (4 últimos dígitos).

## 1. Estado — `GET agenda/estado`

O que a aba mostra no topo.

Resposta 200:

```json
{"configuracao": {"modo": "desligada|ensaio|ligada", "manha": "06:30", "programacao": "16:30", "relatorio": "17:00", "silencio_inicio": "20:00"}, "envio_real": bool, "modelos": [{"nome": str, "conferido": bool, "aprovado": bool, "categoria": str|null, "variaveis_ok": bool}], "verificacoes": [{"tipo": str, "dia": "AAAA-MM-DD", "modo": str, "concluida_em": iso|null, "resumo": obj|null, "erro": str|null}], "destinatarios": [Destinatario]}
```

`envio_real` = modo `ligada` **e** `APP_ENV=production` (Decision 13). `modelos`: os quatro de
`contracts/mensagens.md`, conferidos na lista de aprovados que a tela do WhatsApp do Gestor guarda em cache
(`whatsapp:templates:<id>`, 10 min). A porta **não chama a Meta**: sem a lista no cache, `conferido` = `false` e
`aprovado` = `false` (ver "Decidido na Fase 1").
`verificacoes`: as de hoje e de ontem.

`Destinatario` = `{"id": int, "funcionario_id": int, "nome": str, "papel": "tecnico|gestor", "ativo": bool, "final": str|null, "pendencia": str|null, "consentimento_em": iso|null, "consentimento_por": str|null, "parou_em": iso|null}`.

## 2. Funcionários para escolher — `GET agenda/funcionarios`

Resposta 200:

```json
{"funcionarios": [{"id": int, "nome": str, "is_tecnico": bool, "final": str|null, "problema": null|"sem_numero"|"numero_compartilhado"}]}
```

Só funcionários ativos e não apagados. Nenhum número digitado na tela (US4, cenário 1).

## 3. Incluir destinatário — `POST agenda/destinatarios`

| Pedido | `{"funcionario_id": int, "papel": "tecnico\|gestor", "consentimento": bool, "autor": str}` |
|---|---|
| 200 | `{"destinatario": Destinatario}` — já existente com o mesmo funcionário e papel volta o mesmo (idempotente) |
| 404 | `{"motivo": "funcionario_inexistente"}` — inexistente, inativo ou apagado |
| 422 | Validação |

## 4. Mudar destinatário — `POST agenda/destinatarios/{id}`

| Pedido | `{"ativo"?: bool, "consentimento"?: true, "autor": str}` — só o que muda; `consentimento: true` registra agora, com o autor, e limpa `parou_em` |
|---|---|
| 200 | `{"destinatario": Destinatario}` |
| 404 | `{"motivo": "destinatario_inexistente"}` |

Não há exclusão: desativar guarda o histórico (FR-024).

## 5. Configuração — `POST agenda/configuracao`

| Pedido | `{"modo"?: "desligada\|ensaio\|ligada", "manha"?: "HH:MM", "programacao"?: "HH:MM", "relatorio"?: "HH:MM", "silencio_inicio"?: "HH:MM", "autor": str}` |
|---|---|
| 200 | `{"configuracao": {...}, "envio_real": bool}` |
| 422 | `{"motivo": "horarios_invalidos"}` — exige `manha` < `programacao` ≤ `relatorio` < `silencio_inicio` |

## 6. Envios — `GET agenda/avisos?dia=&tipo=&situacao=&modo=&pagina=`

Resposta 200:

```json
{"avisos": [{"id": int, "nome": str, "papel": str, "tipo": str, "dia_agenda": "AAAA-MM-DD", "modo": "ensaio|real", "situacao": str, "pendencia": str|null, "canal": str|null, "modelo": str|null, "final": str|null, "tentativas": int, "erro_codigo": str|null, "erro": str|null, "texto": str|null, "criado_em": iso, "enviado_em": iso|null}], "pagina": int, "tem_mais": bool}
```

50 por página, mais recentes primeiro. `texto` sai inteiro: é o que o técnico recebeu (ou receberia, no ensaio).

## 7. Prévia agora — `POST agenda/previa`

| Pedido | `{"tipo": "programacao\|relatorio", "dia"?: "AAAA-MM-DD" (padrão amanhã), "autor": str}` |
|---|---|
| 200 | `{"avisos": [ {mesma forma do item 6} ]}` — gravados com modo `ensaio`; nada chama a Meta |
| 422 | `{"motivo": "dia_invalido"}` — só hoje ou amanhã |

Roda em qualquer modo e nunca envia (Decision 13).

## Respostas do Matriz aos erros

A aba traduz `motivo` como o `CanalWhatsApp::motivoDoGestor` já faz; Gestor fora do ar ou 404 na porta (Gestor ainda
sem a feature) → "O Gestor não respondeu; a configuração continua a que estava lá."

## Sem porta Gestor → Matriz

Nada novo nesse sentido: alertas vão por WhatsApp (Decision 15) e a supervisão é lida sob demanda.

## Decidido na Fase 1 (02/11/2026)

- **Formato malformado** (campo que não é do tipo pedido, `papel` fora da lista, `autor` ausente): 422 no formato
  padrão de validação do Laravel (`message` + `errors`), como as outras portas do Matriz. Os motivos próprios ficam
  para as regras: `horarios_invalidos` também cobre hora fora do formato `HH:MM`, e `dia_invalido` também cobre dia
  fora do formato `AAAA-MM-DD`.
- **Prévia do relatório**: até a Fase 5 (o relatório), `{"tipo":"relatorio"}` recebe 422
  `{"motivo":"tipo_indisponivel"}`. A aba deve traduzir como "a prévia do relatório ainda não existe no Gestor".
- **`ligada` em produção antes da Fase 3** (o envio de verdade): a porta aceita, `envio_real` sai `true`, e o ciclo
  não manda nada — a verificação do dia fica sem `concluida_em`, com `erro` = "O envio real ainda não existe nesta
  versão do Gestor: nada foi enviado…". Roda de verdade quando o envio chegar, se ainda for o mesmo dia.
- **Verificações nos modos ensaio e real, até as Fases 4 e 5**: só a da programação (16:30). Com o modo
  `desligada`, as três (manhã, programação, relatório) registram que o ciclo rodou.
- **Incluir de novo** o mesmo funcionário no mesmo papel devolve a linha como está, mesmo com outro
  `consentimento`: registrar consentimento é a porta 4.
- **Destinatários no `estado`**: todos, ativos e desativados, ordenados por papel e nome.
- **Avisos de ensaio e pendentes** guardam o número para onde sairiam (a porta devolve só o final) e o texto que
  sairia: é o que a supervisão confere contra a agenda antes de ligar (SC-005).
- **Modelos sem chamar a Meta** (QA da Fase 1, 02/11/2026): o `estado` só lê a lista de aprovados que a tela do
  WhatsApp do Gestor já pôs no cache. Cada modelo ganhou `conferido` (a lista estava no cache). Sem ela, os quatro
  saem `conferido: false`, `aprovado: false`, `categoria: null`, `variaveis_ok: false` — o lado seguro: modelo que
  ninguém conferiu não é dado como aprovado. A aba deve mostrar "não conferido", e não "não aprovado". Voltar a
  consultar a Meta daqui fica para a Fase 3 (tarefa 3.2.2), quando a chamada passa a ser permitida.
- **Trilha da prévia** (QA da Fase 1, 02/11/2026): a porta 7 grava avisos, então o `activity()` dela ("agenda
  automática: prévia") leva `autor`, `tipo`, `dia`, `antes` e `depois`. Antes e depois são os avisos de ensaio
  daquele tipo e dia, cada um com `id`, `destinatario_id`, `versao`, `situacao` e `pendencia`; a diferença é o que a
  prévia criou. Número e texto não entram no log: já estão na linha do aviso.
