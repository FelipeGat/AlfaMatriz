# Tasks: pedro-orcamento

Fases em ordem; cada uma termina com os testes dela passando.

## Fase 1 — Gestor: a regra da tela num serviço (crítica)

- [x] 1.1 Testes de caracterização de `POST /orcamentos` (tela): com NF (percentual carimbado,
  total = subtotal + NF), sem NF, descontos por categoria, taxas, atendimento de outro cliente solto,
  `cobranca_pendente` limpa, descrição e itens em maiúsculas. Passando ANTES da extração.
- [x] 1.2 Extrair o corpo transacional do `store` para `App\Services\Comercial\CriacaoDeOrcamento`;
  auxiliares de equipe técnica no serviço, com o controller delegando. 1.1 passando de novo.
- [x] 1.3 Migração `orcamentos.matriz_chave` (nula, única).
- [x] 1.4 `App\Services\Comercial\OrcamentoPeloPedro` + testes (com/sem NF, atendimento do mesmo
  cliente, de outro cliente, inexistente, pré-cliente, usuário sem permissão / inativo / sem
  funcionário, chave repetida, item inválido, falha desfaz tudo).
- [x] 1.5 `pint`; nota no `docs/specs/whatsapp-pedro` do Gestor (FR e contrato).
- [x] 1.6 QA independente (Copilot) com `.qa-diff` do escopo. **Depende de 1.1–1.5.**

## Fase 2 — Máquina

- [x] 2.1 `suporte_gestor_orcamento.php` (ponte, ensaio sem `APPLY`).
- [x] 2.2 `suporte.mjs`: `gravarOrcamentoNoGestor`, `gravarOrcamentos`; `runner.mjs` chama; o instalador
  (`Maquinas::ARQUIVOS`) leva o script novo e o `suporte_gestor_agenda.php`, que tinha ficado de fora.
- [x] 2.3 `suporte_gestor_ler.php`: `empresas` com o percentual de NF.
- [x] 2.4 Testes em `tools/central-ia/test-suporte.mjs`.

## Fase 3 — Matriz

- [x] 3.1 Migração: `previa_orcamento`, `suporte_gestor_orcamentos`, `suporte_gestor_retrato.empresas`.
- [x] 3.2 `AcessoInterno`: 4ª coluna (e-mail do Gestor), `criaOrcamento`, `oQueFaz`; tela da lista
  explica a coluna.
- [x] 3.3 `OrcamentoDoGestor`: conferir, prévia montada pelo sistema, confirmar, concluir.
- [x] 3.4 `Contexto`: trecho CRIAR ORÇAMENTO, `ler` com as ações e o bloco, EMPRESAS com NF,
  orçamentos em "O QUE FOI ENVIADO".
- [x] 3.5 `Pedro`: ações, prazos (`vencidos`, `semRetornoDoGestor`), neutralização para cliente.
- [x] 3.6 Runner controller + rotas; retrato aceita `empresas`.
- [x] 3.7 `PedroOrcamentoTest`: os cenários da spec (autorizado, não autorizado, incompleto, com e
  sem NF, vários itens, atendimento do cliente / de outro / sem número, correção, "sim" repetido,
  prévia vencida, falha do Gestor, prazo sem máquina, áudio transcrito).
- [x] 3.8 Suíte isolada inteira (`run-tests.py`).

## Fase 5 — Ajustes do dono (02/11/2026, sessão 2; FR-022 a FR-027)

- [x] 5.1 Valor com NF calculado pela regra do Gestor, conta na prévia (FR-022).
- [x] 5.2 Acompanhar depois do "sim"; "tentar de novo" com a mesma chave; trava de prévia nova do mesmo
  cliente com orçamento incerto; runner grava logo depois da resposta (FR-023).
- [x] 5.3 Atendimento só do mesmo assunto; "sem vínculo" não volta a perguntar (FR-011, FR-024).
- [x] 5.4 Quantidade 1 sugerida no único serviço; faltas agrupadas; aviso não repetido (FR-025, FR-027).
- [x] 5.5 `corrigir_orcamento` com só o que mudou; itens numerados (FR-026).
- [x] 5.6 Testes de feature dos pontos 5.1–5.5 e os antigos ajustados de propósito.
- [x] 5.7 Bateria com a IA de verdade: os 7 cenários de 02/11 + os novos, antes e depois.
- [x] 5.8 Ponta a ponta em ambiente de testes: fila do Matriz → script da ponte no Gestor
  (`gestor_alfa_test`, `APPLY=1`) → número, PDF, repetição, recusa, autorização → resultado no
  Matriz; áudio sintetizado e transcrito pelo Whisper da máquina.
- [x] 5.9 Suíte isolada inteira e testes da máquina.

Ferramentas da bateria e da ponta a ponta: `tools/central-ia/bateria-pedro/OrcamentoCenariosTest.php` e
`tools/central-ia/bateria-pedro/ponta-a-ponta/` (o `gestor_e2e.php` recusa qualquer banco que não seja o
`gestor_alfa_test` e apaga no fim tudo o que criou).

## Fase 4 — Publicação (só com autorização do dono)

- [x] 4.1 Gestor (`gestor-publicar-main.sh`), depois do QA aprovado — `08f86efc` e `e482c368` em produção em
  02/11/2026, com a migração `2026_11_02_160000` aplicada.
- [x] 4.2 Matriz (`matriz-publicar.sh`), instalador da máquina, conferência arquivo por arquivo — tag
  `v2026.11.02.1`, autorizada pelo dono em 02/11/2026.
- [ ] 4.3 Dono acrescenta o e-mail do Gestor nas linhas dele e do Wallas em Suporte › Pedro.
- [ ] 4.4 Validação real com um cliente de teste, e o orçamento de teste excluído depois.
