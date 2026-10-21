# Research: Secretário, Etapa 7 (Acompanhamento)

## Decision 1: Regras no código, parâmetros na preferência

**Decision**: as sete regras são classes pequenas, numa lista fixa (`Regras\TarefaVencida`, …). Cada uma devolve
"achados" (chave, texto, link, data de referência). Liga/desliga, parâmetro e prioridade ficam em
`secretario_preferencias.acompanhamento` (JSON), com padrões no model.

**Rationale**:
- A missão pede regras configuráveis e proíbe a IA decidindo livremente (8.1).
- Regra em linguagem livre exigiria um interpretador ou uma IA no servidor (constitution II).
- Sete regras cobrem os exemplos da missão.

**Alternatives considered**: tabela de regras editável com condições genéricas. Seria um motor de expressão para
uma pessoa só, mais difícil de testar e de explicar na tela.

## Decision 2: Acompanhamento como linha com chave única

**Decision**: tabela `secretario_acompanhamentos` com único `(user_id, chave)`. Cada rodada faz um upsert do que
achou e resolve o ativo que não achou. "Resolvido" (dispensado) fica gravado e impede o aviso enquanto a mesma
chave continuar valendo. Situação que some e volta abre uma linha nova: a antiga é renomeada com o sufixo do id,
para liberar a chave.

**Rationale**: a chave única é a supressão de duplicata que dois processos simultâneos não furam, o mesmo desenho
dos avisos da Etapa 1.

## Decision 3: Resumo agrupado, com registro

**Decision**: `secretario_acompanhamento_envios` guarda cada resumo: user, canal, status, tentativas, ids dos
acompanhamentos e a mensagem do Telegram. O sino é uma `Notificacao` por resumo; o Telegram segue o padrão de
`Briefings::entregarPendentes`:
- `enviando` é gravado antes da chamada;
- há retentativa até +30 min para rede fora ou 429;
- envio preso vira `falhou`.

**Política** (padrões, todos configuráveis):

| Regra de política | Padrão |
|---|---|
| Alta | sai na hora |
| Normal | sai se o último resumo tiver 3 h ou mais |
| Baixa | nunca sai em resumo |
| Silêncio | 20:00–07:00 |
| Fim de semana | silencioso: ligado |
| Teto | 4 resumos por dia |
| Repetição | a cada 24 h, no máximo 3 vezes por situação |

## Decision 4: Regras e padrões

| Regra | Situação | Parâmetro (padrão) | Prioridade |
|---|---|---|---|
| `tarefa_vencida` | item tarefa/acompanhamento aberto, `quando` passou há N horas (só data: no dia seguinte) | 2 h (1–72) | normal |
| `reuniao_proxima` | evento do Google começando em até N min, sem item do Secretário com o mesmo título e horário | 30 min (5–180) | alta, **desligada** |
| `proposta_parada` | e-mail de proposta/orçamento aguardando resposta há N dias; Gestor com orçamento aguardando aprovação há mais de N+4 dias (um acompanhamento só) | 3 dias (1–30) | normal |
| `cliente_sem_resposta` | e-mail aguardando resposta há N horas (fora os de proposta, já cobertos) | 24 h (2–168) | normal |
| `revisao_de_reuniao` | reunião analisada e não revisada, com tarefas pendentes, há N horas | 24 h (1–168) | normal |
| `conflito` | conflito na agenda consolidada nos próximos N dias | 2 dias (1–7) | alta |
| `entrega_proxima` | item aberto com prazo (só data) entre hoje e N dias, vindo de reunião ou com "entrega/entregar/enviar" no título | 1 dia (1–7) | normal |

**Reunião próxima nasce desligada**: o Google já avisa no celular, e o compromisso criado no Secretário já tem
lembrete (Etapa 1). Ligar duplicaria o aviso para quem usa os dois. A pessoa liga na tela.

## Decision 5: Telegram com um botão

"Adiar estes até amanhã" (`ac:<envio_id>`) usa o `TelegramDoSecretario::toque`, que já confere o dono do chat e a
unicidade do toque (`secretario_toques`). O adiamento vale para os acompanhamentos daquele envio.

## Decision 6: Briefing sem repetição

A seção "🔔 Acompanhar" mostra só `cliente_sem_resposta`, `proposta_parada` e `revisao_de_reuniao`. Os e-mails
listados nela saem da seção "E-mails" do mesmo briefing. Tarefa vencida e conflito já estão em "Atrasados" e na
agenda.
