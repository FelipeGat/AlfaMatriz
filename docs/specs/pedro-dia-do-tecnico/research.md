# Research: o Pedro lança e corrige o dia do técnico

Fontes lidas em 23/10/2026: `deploy/central-ia/suporte_gestor_ler.php` e `suporte_gestor_gravar.php`
(AlfaMatriz), `app/Services/Suporte/{Contexto,AcessoInterno,Atendimento,DiretorioGestor,Pedro}.php`,
`app/Http/Controllers/SuportePedroRunnerController.php`; no Gestor, `app/Models/AtendimentoVisita.php`,
`app/Services/AgendaTecnicaService.php`, `app/Console/Commands/FinalizarVisitasDoDia.php` e o banco de
produção (só leitura) para a tabela `atendimento_visitas`.

## Decision 1: como o Pedro lê a agenda

**Decision**: o retrato do Gestor que o runner já manda a cada 10 min passa a levar também as
visitas dos técnicos numa janela de **7 dias para trás e 14 para frente** (não canceladas e
canceladas recentes, para o Pedro explicar o que sumiu). O Pedro consulta essa cópia no Matriz,
como já faz com clientes e atendimentos.

**Rationale**: é o mesmo caminho que já funciona (só leitura no Gestor, banco em sessão READ ONLY,
transação desfeita), sem nova porta no Gestor. O volume é pequeno: o Gestor tem visitas na casa
das dezenas por dia (1185 visitas no total até 23/10/2026). Desde a v2026.10.23.1 a validação do
retrato é item a item, então mais uma lista não traz de volta o estouro de 22/10.

**Alternatives considered**:
- Consulta na hora, por pedido, via máquina: resposta mais fresca, mas cada pergunta esperaria a
  ponte (10–60 s) e a máquina; desliga junto com o PC.
- Endpoint de leitura no Gestor chamado pelo Matriz: exigiria porta nova no Gestor exposta ao
  Matriz; contraria o desenho atual (o Gestor não é chamado pelo Matriz para dados de agenda).

**Consequência aceita**: a agenda que o Pedro mostra pode estar até 10 min atrasada; ele sempre
diz a hora da leitura, como já faz com os atendimentos ("lidos às 11:16"). Antes de gravar, o
Gestor reconfere o estado (Decision 4), então o atraso não vira gravação errada.

## Decision 2: como o Pedro grava

**Decision**: uma fila nova de **mudanças de agenda** no Matriz, preenchida só depois do "sim",
consumida pelo runner pela mesma ponte que grava as aberturas. No Gestor, um script novo
(`deploy/central-ia/suporte_gestor_agenda.php`) aplica cada mudança e devolve o resultado.
Atendimento novo e pré-cliente novo continuam pela fila de aberturas que já existe; a visita
desse atendimento novo entra como hoje, junto da abertura.

**Rationale**: reaproveita a mesma máquina, o mesmo cuidado de "sim" e o mesmo retorno de números.
Mantém a regra do dono de 21/10: chamado de cliente nasce no Gestor, nunca no quadro.

**Alternatives considered**: estender a abertura para carregar várias visitas e correções —
misturaria dois ciclos de vida (abrir atendimento vs. mexer em visita existente) numa tabela só.

## Decision 3: regras de visita do Gestor

**Decision**: o script de agenda usa as regras que o Gestor já tem — `AgendaTecnicaService::
adicionarVisitas` (cria visita com checagem de sobreposição), `existeConflitoVisita`,
`ressincronizarCacheAtendimento`, e no modelo `podeSerReagendada`, `podeSerCancelada`,
`cancelamentoExigeMotivo`, `reativarSeFinalizada`. Reagendar e cancelar uma visita hoje vivem no
controller da API do app (`Api/V1/Admin/AtendimentoVisitaController`); **extrair essa lógica para
um serviço do Gestor** (`AtendimentoVisitaService`, reagendar e cancelar) usado pelo controller e
pelo script.

**Rationale**: regra 5 do CLAUDE.md do Gestor ("regra de negócio não vai em Controller quando já
existe Service") e o mesmo motivo do AlfaMatriz para `TarefaService::criar`: o que a tela recusa, a
outra porta recusa com a mesma frase. Copiar a regra para o script faria as duas divergirem.

**Alternatives considered**: o script repetir a lógica do controller — mais rápido, mas é a mesma
divergência que a `AtendimentoVisita::podeSerReagendada` foi criada para eliminar.

## Decision 4: idempotência e estado vencido

**Decision**: cada mudança da fila tem uma chave única (prévia + parada). O script:
1. criação: antes de inserir, procura visita do mesmo técnico, no mesmo atendimento, com o mesmo
   início e fim; se existe, devolve a existente em vez de criar outra;
2. correção/cancelamento: confere que a visita ainda está como a prévia mostrou (horário e
   situação); se mudou, recusa com o motivo "a visita mudou desde a prévia" e o Pedro remonta a
   prévia.

**Rationale**: SC-002 e FR-011 (sem duplicar), e a prévia pode ter sido montada com um retrato de
até 10 min (Decision 1). A fila do Matriz já impede o segundo processamento da mesma mudança
(reservado → feito), a checagem no Gestor cobre a máquina que cai no meio.

## Decision 5: quem pode o quê

**Decision**: a consulta (story 1) vale para toda a lista de acesso interno; lançar, corrigir e
cancelar (stories 2–4) só para os papéis `diretor` e `gestor` da lista (FR-017). A decisão é do
código, não da IA: a mudança pedida por outro papel nunca chega à fila.

**Rationale**: decisão do dono em 23/10/2026; mesmo princípio de 21/10 ("o número decide, não o
nome que a pessoa diz").

## Decision 6: situação das visitas lançadas

**Decision**: visita lançada a partir do relato entra **agendada**, com o horário relatado
(FR-015). Observação: o comando do Gestor `visitas:finalizar-dia` (23:55) finaliza toda visita
agendada cuja janela já passou — então a visita de ontem lançada hoje é finalizada na próxima
rodada dele, sem ação do técnico. O Pedro diz isso na resposta final para o dia que já passou.

## Decision 7: como a IA devolve a prévia e o que gravar

**Decision**: no pedido interno, um bloco novo `## AGENDA DOS TÉCNICOS` com as visitas da janela
relevante (dia citado e vizinhos) e as regras do fluxo; a IA devolve, no JSON que já devolve, uma
ação `agenda` com a lista de paradas propostas, cada uma com o tipo (`criar_visita`,
`reagendar_visita`, `cancelar_visita`, `abrir_atendimento`), os ids existentes que ela citou e os
horários. O Matriz **confere cada parada** contra o retrato antes de mostrar a prévia: id que não
existe no retrato, técnico fora da equipe, horário sem fim ou conflito não declarado bloqueiam a
gravação, e o Pedro pergunta — a IA propõe, o código decide (como no registro de atendimento,
onde a agenda sem técnico ou sem fim não registra).

**Rationale**: o desenho do registro de atendimento (22/10) já provou o padrão "IA propõe → código
confere → prévia → sim → fila".

## Decision 8: reconhecer o técnico

**Decision**: o nome do técnico é casado contra a equipe do retrato com a mesma comparação por
pronúncia e grafia aproximada da busca de cliente (`DiretorioGestor`): "Victor" acha "Vitor".
Mais de um candidato → pergunta.

## Decision 9: o que fica fora

- Jornada (almoço, hora extra): é do ponto do RH; o Pedro não lança e diz isso.
- Finalizar visita (`finalizado`, com `iniciada_em`/`finalizada_em` reais): fora, por FR-015.
- Técnicos adicionais numa visita: fora da primeira entrega; a prévia avisa quando a visita tem
  adicionais e não mexe neles.
