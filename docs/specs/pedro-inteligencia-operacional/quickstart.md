# Quickstart: o Pedro acompanha a operação

**Spec**: `spec.md` · **Plano**: `plan.md` · **Data**: 31/10/2026

Cenários para provar cada fluxo crítico, primeiro com dados fictícios nos testes (Matriz: `tests/Feature/Suporte/`;
Gestor: suíte `Integracao`), depois na semana de validação, de números de teste. Nenhum cenário de produção grava
sem o "sim" de quem está na conversa, e nenhum usa conversa de cliente real.

## Preparação (uma vez, antes da validação em produção)

1. Em Suporte › Pedro, ligar cada linha da lista da casa a um usuário do Gestor (FR-012) → a tela não mostra
   nenhuma linha "sem vínculo".
2. Cadastrar na lista um número de teste com perfil `tecnico`, ligado ao usuário de um técnico ativo.
3. Conferir que o retrato seguinte chegou (Suporte › Pedro mostra a hora) e traz usuários, perfis e históricos.

## Cenário 1 — Por que a OS não andou (US1, FR-001, FR-002, FR-004)

1. De um número diretor ou gestor: "por que a OS <n> não foi concluída?"
2. → **Expected**: etapa e desde quando; andamentos com data, autor e resumo; mudanças de etapa; visitas feitas e
   futuras; dias desde a última atividade; o que falta confirmar e quem pode confirmar. Nenhum motivo que não esteja
   no histórico.
3. Conferir as datas contra a tela do Gestor → **Expected**: a última atividade bate (SC-001).
4. **Erro**: do mesmo número, com o retrato parado há mais de 3 h → **Expected**: a hora da leitura e o aviso.

## Cenário 2 — Parados e atrasados (US2, FR-003)

1. De um gestor: "o que está parado?" e depois "o que está parado com o <técnico>?"
2. → **Expected**: o total, as 5 mais antigas (número, cliente, etapa, responsável, dias sem atividade) e a
   oferta do resto; o filtro traz só as OS em que a pessoa é responsável ou técnica.
3. Comparar, no mesmo minuto, com "Chamados parados +2 dias" e "atrasados" da Central do Gestor → **Expected**: batem,
   descontados os cancelados (SC-002).
4. **Erro**: do perfil suporte → **Expected**: "isso não está no que posso consultar para você".

## Cenário 3 — Registrar um andamento relatado (US3, FR-005 a FR-011c)

1. Do técnico responsável pela OS <n>: "troquei a placa da central do portão da OS <n>, ficou funcionando" + 1 foto.
2. → **Expected**: prévia com a OS, o cliente, o texto exato, "1 foto" e quem relatou; nada gravado ainda.
3. Responder "sim" → **Expected**: "enviando ao Gestor"; quando a máquina grava, o número do andamento.
4. No Gestor → **Expected**: um andamento só, com o texto, a foto, o autor = o usuário do técnico, a origem "Pedro,
   pelo WhatsApp"; a etapa da OS não mudou (SC-009).
5. Responder "sim" de novo → **Expected**: nada novo no Gestor (SC-004).
6. **Erro**: com a máquina desligada, repetir 1–3 → **Expected**: "a gravação ficou pendente", sem dizer que gravou.
7. **Erro**: "terminei o serviço" → **Expected**: a prévia avisa que a conclusão é pelo app, pelo técnico.

## Cenário 4 — Técnico sem vínculo com a OS (FR-011a)

1. Do número técnico, relatar serviço numa OS em que ele não é responsável, adicional nem técnico de visita.
2. "sim" → **Expected**: nada no Gestor; o Pedro diz que um gestor vai validar.
3. Em Suporte › Pedro, um gestor autorizado valida → **Expected**: o andamento aparece no Gestor com o autor = quem
   relatou e a validação registrada; recusar outra proposta → nada gravado, motivo guardado.

## Cenário 5 — Correção de andamento (FR-011b)

1. Do gestor: "corrige o andamento de ontem da OS <n>: a placa era da central, não do motor".
2. → **Expected**: prévia de um andamento NOVO que cita o original (data e autor); com o "sim", o original fica
   intacto no Gestor.

## Cenário 6 — Vínculo e perfil (US4, FR-012 a FR-013)

1. Do técnico: "o que tenho em aberto?" → **Expected**: só as OS dele, com a próxima visita.
2. Pedir a OS de outro técnico e o financeiro → **Expected**: "isso não está no que posso consultar para você".
3. Desativar no Gestor o usuário de teste e esperar um retrato → **Expected**: o número passa a ouvir que o acesso
   ainda não está liberado, sem ninguém mexer na lista (FR-012b).
4. **Erro**: número da lista sem vínculo → **Expected**: nada interno, nenhuma ação (SC-008).

## Cenário 7 — POP (US5, FR-014 a FR-016b)

1. Perguntar um procedimento que tem POP publicado → **Expected**: os passos e o nome do POP.
2. Relatar um passo que o POP não tem, aceitar a proposta → **Expected**: pendente em Suporte › Pedro, com texto, POP
   afetado, quem relatou e a OS; o POP publicado não mudou (SC-006).
3. Aprovar → **Expected**: no Gestor (tela e app), o POP com o texto aprovado e quem aprovou.
4. **Erro**: editar o POP no Gestor entre a proposta e a aprovação → **Expected**: a aprovação mostra que ele mudou e
   não sobrescreve.

## Cenário 8 — Repetição (US6, FR-017)

1. Com três OS do mesmo assunto no mesmo cliente em 30 dias, relatar a quarta → **Expected**: o Pedro aponta a
   repetição com os números das três e oferece a proposta de POP. Com duas, não aponta.

## Roundtrip de ponta a ponta (borda Matriz ↔ máquina ↔ Gestor)

1. Num teste de integração do Gestor, chamar o serviço de gravação com a carga que a máquina manda (a mesma que o
   teste do Matriz monta), e comparar a resposta com o contrato (`contracts/`).
2. No `test-suporte.mjs`, conferir que o `suporte.mjs` repassa ao Matriz todos os campos da resposta do Gestor
   (lição do `gestor.mjs` em 31/10/2026: campo que a máquina não conhece é descartado sem erro).
