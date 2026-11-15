# Tarefas AlfaMatriz - API Executiva de leitura

Escopo: interface só de leitura para o ChatGPT consultar o que é da Matriz (spec `api-executiva-leitura`).

**Legenda de status:** `[ ]` Pendente · `[~]` Em andamento · `[x]` Concluido · `[!]` Bloqueado
**Criticidade:** `[C]` Critico · `[A]` Alto · `[M]` Medio

**Premissas adotadas (recomendação do plano, aceita com "Siga" em 15/11/2026; o Felipe pode reverter):**
- Autenticação: chave de API em token Sanctum, capacidade única `executivo:ler`, validade 30 dias.
- LGPD: remetente só como nome; título de compromisso truncado em 80 caracteres, sem participantes.
- Pedro: só contagens e idade da fila.
- Limite de "desatualizado" do e-mail: 60 min (a leitura roda a cada poucos minutos; ajustável em config).

**Ainda bloqueado por decisão do Felipe:** critérios de realizado e previsto (spec, decisão 6) e exposição à internet (decisão 3).

---

## FASE 1 - Base segura `[C]`

Ref: FR-001, FR-002, FR-003, FR-008, FR-009

- [x] 1.1 `alfa:executivo-token <email> [--revogar]`: só a conta do Felipe, só hash, validade configurável
- [x] 1.2 `routes/executivo.php` fora do grupo `web`: GET, `auth:sanctum`, `abilities:executivo:ler`, `throttle`
- [x] 1.3 Auditoria de chamadas (token, rota, hora, status; sem conteúdo)
- [x] 1.4 Envelope comum (`fonte, periodo, consultado_em, atualizado_em, situacao, motivo, dados`); falha de fonte não gera 500
- [x] 1.5 Testes: isolamento entre contas, `mcp` x `executivo:ler`, 405 em escrita, rejeição de `user_id`
- [x] 1.6 (15/11) Auditoria também das recusas (sem token, token inválido, capacidade errada, 405, 429): `ExecutivoAuditoria` é o primeiro middleware; o 405 passa por uma rota que só recusa
- [x] 1.7 (15/11) Teste varre toda rota protegida e prova que o token `executivo:ler` não autentica em nenhuma outra

## FASE 2 - Extrações sem mudar comportamento `[A]`

Ref: FR-006

- [x] 2.1 Testes de caracterização de `ConsultaDeEmails::importantes/aguardando` (texto atual)
- [x] 2.2 Extrair consulta estruturada de e-mail; os métodos de texto passam a usá-la
- [x] 2.3 `situacaoEmail(User)` por conta: `falha`, `desatualizado`, `desconectado`, `ok`

## FASE 3 - Blocos `[A]`

Ref: FR-004, FR-005, FR-006, FR-007

- [x] 3.1 `agenda` (com `situacaoGoogle`, sem participantes, título truncado)
- [x] 3.2 `emails` (só metadados, lista branca)
- [x] 3.3 `pendencias` (`OQueEsperaVoce`, `CentralDeGestao::numeros`)
- [x] 3.4 `alertas` (`Notificacao`, `VigiaErro`, `MonitorAlerta`; não marca como lida)
- [x] 3.5 `pedro` (contagens e idade)
- [x] 3.6 `fontes` (painel de saúde)
- [x] 3.7 Teste de lista branca: sem corpo, e-mail de remetente, telefone, documento

## FASE 4 - Financeiro `[C]`

Ref: FR-007 · Critérios fixados pelo Felipe em 15/11/2026 (pedido da Fase 4); implementada localmente, aguardando QA independente

- [x] 4.1 (15/11, critérios ditos no pedido da fase) Confirmar com o Felipe os critérios de realizado e previsto usados nas telas
- [x] 4.2 `GET /api/executivo/financeiro?de&ate&referencia` (`FinanceiroExecutivo`): realizado, previsto e lucro separados, em centavos, `null` quando ausente, critério escrito
- [x] 4.3 `FinanceiroExecutivoTest` (17 testes, base fictícia). Paridade com as telas é PROPOSITALMENTE parcial: saldo e MRR iguais; entradas/saídas e vencido divergem de `IndicadoresService::entradasDoMes` e dos cards (ajuste/transferência e cancelado) — ver docblock
- [ ] 4.4 Decisão do Felipe: `financeiro_desde` (2026-10-01) e o que fazer com a origem não gravada em `movimentacoes_financeiras` (`origem_*` fora do `$fillable`)

## FASE 5 - Fechamento

- [x] 5.1 (15/11) Suíte inteira: 2.348 testes, 0 falhas, 30 pulados (`run-tests.py`, 50 do Executivo)
- [ ] 5.2 Teste local; commit e publicação só com autorização do Felipe
- [ ] 5.3 Exposição à internet: decisão 3, fora desta spec

## Correções após QA independente — 15/11/2026

- [x] Reproduzir os achados com testes fictícios antes de corrigir (três falhas observadas).
- [x] Minimizar texto livre na serialização executiva e proteger campos equivalentes (ver `qa-correcoes.md`).
- [x] Validar intervalo efetivo da agenda, inclusive apenas `ate`.
- [x] Ampliar isolamento para parâmetros, Bearer explícito e capacidades nos dois sentidos.
- [x] Validação final: 56 testes Executivo (825 assertions) e suíte completa com 2.354 testes (15.741 assertions), sem falhas, 30 pulados; duas depreciações do PHPUnit. Pint aprovado nos quatro arquivos PHP alterados; `git diff --check` aprovado.
- [ ] Nova revisão independente pelo Codex; homologação não autorizada.

A redação original dos itens 1.7 e 3.7 descrevia cobertura maior que a prova;
o alcance corrigido e os limites estão em `qa-correcoes.md`. A política de
minimização substitui a premissa anterior de remetente/título como texto livre.
A tarefa 4.4 continua pendente e o financeiro aprovado não foi modificado.
