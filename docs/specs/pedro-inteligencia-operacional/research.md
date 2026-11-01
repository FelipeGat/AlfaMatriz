# Research: o Pedro acompanha a operação

**Spec**: `spec.md` (Clarified, 31/10/2026) · **Data**: 31/10/2026

Fontes lidas no código em 31/10/2026, nas duas bases: Matriz = este repositório; Gestor = `~/projetos/gestor`
(e `database/schema/mysql-schema.sql`, o schema de produção que a CI do Gestor usa). Nenhum dado de produção foi
lido para este plano. Sem `NEEDS CLARIFICATION` restante: os seis eixos estruturais (linguagem, stack, arquitetura,
persistência, ambiente, entrega) são os que já existem, por premissa do Felipe ("aproveitar o que já existe").

## Fatos que o desenho respeita

- **Andamento no Gestor:** `atendimento_andamentos` = `id, atendimento_id, user_id, descricao, timestamps`. Em
  produção, `user_id` é **NOT NULL** com FK RESTRICT (o schema diverge da migração, que o deixa nullable). Fotos em
  `atendimento_andamento_fotos (atendimento_andamento_id, arquivo)`. Não há rota que edite ou apague andamento.
- **Quem registra andamento hoje no Gestor:** na web, qualquer usuário logado que não seja parceiro
  (`AtendimentoAndamentoController@store`, sem `permissao:` nem policy); na API do app,
  `POST api/v1/admin/atendimentos/{id}/andamentos`, só `admin.administrativo` (= admin, administrativo ou
  coordenador, `EnsureAdminOrAdministrativo`), com até 10 fotos de até 8 MB em
  `atendimento_andamentos/{atendimento_id}/{uuid}.{ext}` no disco `public`.
- **Perfis do Gestor:** `perfis.slug` via `perfil_user`; `User::PERFIS_INTERNOS` = admin, administrativo,
  coordenador, financeiro, comercial, tecnico, funcionario; parceiro anula os internos. `users.ativo`,
  `users.deleted_at`, `users.funcionario_id`; `funcionarios.ativo`, `is_tecnico`, `deleted_at`.
- **Vínculos com a OS:** `atendimentos.funcionario_id` (responsável), `atendimento_tecnicos` (adicional),
  `atendimento_visitas.funcionario_id` e `atendimento_visita_tecnicos` (visita). A visita tem `status`
  (agendado, em_atendimento, finalizado, cancelado), `data_inicio`, `data_fim`, `deleted_at`.
- **Etapas:** `atendimento_status_historicos (status, observacao, user_id, timestamps)`, sem coluna "de".
- **Central do Gestor** (`CentralController.php:119-148`): última atividade = `GREATEST(created_at, último
  andamento, última mudança de etapa)`; **parado** = sem `deleted_at`, fora de concluido/finalizacao, última atividade
  há mais de 48 h (cancelado conta); **atrasado** = status aberto/orcamento/em_atendimento/garantia,
  `em_execucao = false`, visita `agendado` com `data_fim < NOW()` e nenhuma `agendado` com `data_fim >= NOW()` ou
  que comece hoje. A lista de atendimentos e o hub do app usam uma versão mais simples de "atrasado".
- **POP:** `treinamento_procedimentos` com `pasta_id` (NOT NULL), `empresa_id`, `segmento`, `titulo`, `conteudo`,
  `passos` (json), **`status`** (publicado, em_revisao, rascunho; padrão publicado, e nenhum controller o grava),
  `autor_id` (NOT NULL), `editado_por_id`, `editado_em`, `updated_at`, `deleted_at`. `podeEditar` = autor ou
  admin. A API do app lista os POPs da pasta sem olhar `status`. Visibilidade por pasta e perfil:
  `TreinamentoPasta::slugsVisiveis`.
- **Mídia do WhatsApp:** o Gestor já guarda o arquivo de cada mensagem do cliente (`whatsapp_mensagens.midia_path`,
  disco `public`, achado por `wa_message_id`); o Matriz o busca por `GET /api/integracao/matriz/whatsapp/midia/{wamid}`.
- **Idempotência que já existe no Gestor:** `whatsapp_mensagens.matriz_chave` (string 64, unique).
- **Gravação pelo Pedro (Matriz):** filas `suporte_gestor_aberturas` (sem chave única), `suporte_gestor_agenda`
  (`chave` unique, `lote`) e `suporte_gestor_contatos`, todas `pendente → reservado → feito|falhou`, reservadas pela
  máquina em `SuportePedroRunnerController` (rotas `suporte.runner.*`) e gravadas por scripts PHP que a ponte roda no
  container do Gestor, carregando o `bootstrap/app.php` dele (`deploy/central-ia/suporte_gestor_*.php`), sem nova
  tentativa automática. O autor hoje é a conta do dono (`autor_email`).
- **Retrato:** `suporte_gestor_ler.php` (só leitura, READ ONLY + rollback), recebido por `suporte.runner.gestor`
  (validação item a item; o que não tem regra é descartado sem erro) e guardado numa linha só em
  `suporte_gestor_retrato` (`DiretorioGestor::gravar`). Visitas de -7 a +14 dias; atendimentos abertos ou dos últimos
  120 dias, com 300 caracteres e `funcionarios` (responsável e adicionais juntos).
- **Aprovação no Matriz:** a Base do suporte (`SuporteNota`, `BaseDoSuporte::podeValidar` = editores ou admin,
  `SuporteBaseController`, permissão `suporte,editar`) é o padrão de proposta → validação que já existe.

## Decision 1: histórico e pendências entram no retrato que já existe

**Decision**: o `suporte_gestor_ler.php` passa a mandar, por atendimento, `responsavel_id`, `em_execucao`,
`ultima_atividade`, `etapa_desde`, as marcas `parado_central` e `atrasado_central`, os técnicos de visita não
cancelada de qualquer data (`tecnicos_de_visita`, porque a janela de visitas do retrato é de -7 a +14 dias), e, **só para os abertos**, os 5
andamentos mais recentes (id, data, autor, origem, 300 caracteres, nº de fotos) e as 5 mudanças de etapa mais
recentes. O Matriz guarda tudo no mesmo `suporte_gestor_retrato` (colunas novas para usuários e POPs).
**Rationale**: premissa "sem integração nova"; o retrato já é lido a cada 10 min e já é só leitura. Limitar a 5 e a
abertos segura o tamanho.
**Alternatives**: consulta sob demanda ao Gestor por OS (nova porta, e a resposta esperaria a ponte); histórico
completo (tamanho sem teto).

## Decision 2: "parado" e "atrasado" vêm da regra da Central, numa só fonte

**Decision**: extrair no Gestor os escopos `Atendimento::scopeParados()` e `scopeAtrasados()` (com a expressão
da última atividade) do `CentralController`, que passa a usá-los sem mudar comportamento; o leitor pergunta a esses
escopos quais ids estão parados ou atrasados. O Matriz tira os cancelados (spec, Edge Cases) e ordena por
`ultima_atividade`.
**Rationale**: SC-002 pede que o número bata com a Central; copiar o SQL para o leitor criaria duas regras. Calcular
no Matriz a partir das visitas do retrato erraria o atrasado com visita vencida há mais de 7 dias (fora da janela).
**Alternatives**: copiar o SQL no leitor; calcular no Matriz.

## Decision 3: o vínculo da lista da casa é o e-mail do usuário do Gestor, com uma chave de virada

**Decision**: a linha da lista ganha um quarto campo opcional, `telefone | nome | perfil | e-mail no Gestor`. O
retrato traz os usuários internos do Gestor (`id, email, nome, ativo, excluido, funcionario_id, funcionario_ativo,
perfis[], pastas_visiveis[]`). A regra nova (FR-012) liga por um ajuste `vinculo_obrigatorio` em `suporte_ajustes`,
que só o dono marca na tela e que a tela recusa marcar enquanto houver linha sem vínculo válido.
**Rationale**: o e-mail já é a chave usada pelos scripts de gravação (`autor_email`), é legível para quem mantém a
lista e não depende de id. A chave de virada permite publicar, ligar as três linhas de hoje e só então exigir, sem
derrubar o acesso de ninguém no meio (spec, Edge Cases).
**Alternatives**: id do usuário (ilegível na tela); duas publicações (parser antes, regra depois).

## Decision 4: o "menor dos dois" é uma escada de níveis

**Decision**: níveis do Pedro, do maior para o menor: `diretor > gestor > suporte > tecnico`. Teto dado pelos
perfis do Gestor do usuário vinculado (o maior deles): admin → diretor; administrativo ou coordenador → gestor;
financeiro, comercial ou funcionario → suporte; tecnico, com funcionário ativo → tecnico. Parceiro, usuário
inativo ou excluído → sem acesso. Vale `min(perfil da lista, teto do Gestor)`.
**Rationale**: o `canPermissao` do Gestor não guarda atendimentos nem andamentos (nenhuma rota usa `permissao:` ali),
então perfis são a única fonte coerente. Gestor = os três perfis que a API do app já deixa registrar andamento em
qualquer OS — o mesmo critério serve ao FR-011 ("gestor autorizado").
**Alternatives**: mapear por `canPermissao(recurso, ação)` (não cobre atendimentos); manter só a lista (contraria o
FR-012).

## Decision 5: andamento por uma fila nova, no mesmo caminho da máquina

**Decision**: tabela `suporte_gestor_andamentos` no Matriz, no padrão da `suporte_gestor_agenda` (`chave` unique,
`pendente → reservado → feito|falhou`), mais `proposta`/`recusada` para o FR-011a; rotas
`suporte.runner.andamentos` e `suporte.runner.andamento-feito`; `gravarAndamentos` no `suporte.mjs`, na mesma volta
de 20 s; script `deploy/central-ia/suporte_gestor_andamento.php` na ponte. No Gestor, o script chama um serviço novo
`AndamentoDeAtendimentoService::registrar()`, extraído do `Api\V1\Admin\AtendimentoController@andamentoStore` (que
passa a usá-lo), e que: confere a OS aberta; confere de novo o vínculo de quem relatou (FR-011c); devolve o andamento
já existente se a `matriz_chave` já foi gravada; copia as fotos da mensagem do WhatsApp (`midia_path`) para
`atendimento_andamentos/{id}/…`; grava `user_id` = usuário de quem relatou, `origem`, `matriz_chave`,
`confirmado_por_user_id` e, na correção, `corrige_andamento_id`.
**Rationale**: FR-009 exige o caminho que já existe; a chave única em duas pontas (Matriz e Gestor) cobre o "sim"
repetido, o reinício da máquina e a ponte que cai depois de gravar. O serviço é a regra única entre app e Pedro
(Gestor, princípio VI). As fotos não trafegam: já estão no disco do Gestor.
**Alternatives**: porta HTTP nova em `integracao/matriz` (a spec manda o caminho da máquina; e a porta não tem
usuário autenticado); reaproveitar a fila de aberturas (sem chave única).

## Decision 6: propostas e trilha ficam nas próprias filas do Matriz

**Decision**: proposta de andamento = linha da `suporte_gestor_andamentos` em `proposta`; validar a passa a
`pendente` com `validado_por`; recusar, a `recusada` com motivo. Proposta de POP = tabela nova `suporte_pop_propostas`
(`proposta → pendente → reservado → feito|falhou`, mais `recusada` e `revisao`). As duas linhas nunca são apagadas:
são o registro de origem do FR-020 (quem relatou, quem confirmou ou validou, conversa, mensagem, texto, fotos,
chave, resultado). A validação mora em Suporte › Pedro, numa aba "Propostas", no padrão da Base do suporte.
**Rationale**: "um sistema só" (Matriz, princípio III) e a decisão do Felipe de supervisão no AlfaMatriz; o app do
técnico mostraria um rascunho gravado no Gestor como oficial.
**Alternatives**: tabela de auditoria separada (duplicaria a fila); proposta como nota da Base (outra natureza:
a nota é conhecimento, não gravação no Gestor).

## Decision 7: quem valida e quem aprova, no AlfaMatriz

**Decision**: valida proposta de andamento quem tem `suporte,editar` no Matriz **e** cujo e-mail é de um usuário do
Gestor com nível gestor ou acima (Decision 4); aprova POP quem tem `suporte,editar` e é admin no Gestor (FR-016a,
`podeEditar`). A gravação no Gestor sai com o usuário do Gestor de quem validou ou aprovou.
**Rationale**: a decisão do Felipe é por "permissões no Gestor"; o e-mail é a mesma ponte da Decision 3.
**Alternatives**: editores do Pedro (`services.suporte_pedro.editores`): não diz nada do Gestor.

## Decision 8: POP publicado entra no retrato; proposta aprovada sai por fila própria

**Decision**: o retrato traz os POPs com `status = publicado` e sem `deleted_at` (id, pasta, empresa, segmento,
título, passos e conteúdo cortados, `updated_at`). O técnico só recebe os das `pastas_visiveis` do usuário dele. A
proposta guarda o id e o `updated_at` do POP afetado; o script `suporte_gestor_pop.php` recusa com `mudou` se o
`updated_at` atual for outro (FR-016b), e a proposta volta a `revisao`. Aprovada, grava pelo mesmo caminho da tela
(pasta, título, conteúdo/passos), com `autor_id` (POP novo) ou `editado_por_id`/`editado_em` (alteração) = quem
aprovou, e `status = publicado`. A chave da proposta vai em `treinamento_procedimentos.matriz_chave` (coluna
nova, unique), para a repetição não publicar duas vezes.
**Rationale**: `updated_at` sobe em todo `update` (o `registrarAcesso` não o toca de propósito); é o que já existe.
**Alternatives**: coluna de versão no Gestor (nova, sem necessidade); `treinamento_procedimento_contribuicoes`
(serve de conferência extra, não de chave).

## Decision 9: a conversa usa o mesmo molde da agenda do técnico

**Decision**: ações novas no bloco do pedido da casa — `previa_andamento`, `confirmar_andamento`, `previa_pop`,
`confirmar_pop`. A prévia é conferida e guardada na conversa (como `suporte_conversas.previa_agenda`, validade 3 h);
o "sim" cria a linha da fila com `chave = lote da prévia` (`firstOrCreate`), então o "sim" repetido não duplica.
Antes da prévia, o Matriz mostra o andamento parecido da mesma OS nas últimas 24 h (retrato + fila) (FR-010). O
bloco "O QUE VOCÊ FAZ POR ELE" (`AcessoInterno::oQueFaz`, pedro-comunicacao FR-019) passa histórico, andamento e POP
do "não faz" para o "faz" no mesmo commit.
**Rationale**: a IA propõe e o código decide, como no registro de atendimento e na agenda; o padrão já passou pela
validação de 23/10.
**Alternatives**: gravar direto na resposta da IA (sem conferência de código).

## Decision 10: repetição pelo assunto e cliente

**Decision**: no relato ou na pergunta sobre um cliente, o Matriz conta no retrato as OS do mesmo `assunto_id` e
`cliente_id` abertas nos últimos 30 dias; 3 ou mais vão num bloco REPETIÇÃO com os números. "Equipamento" fica de
fora: não é campo do atendimento que o retrato lê.
**Rationale**: os 120 dias do retrato já cobrem a janela; nenhum agendamento novo.
**Alternatives**: contar por texto parecido (frágil, sem fonte).

## Decision 11: ordem de publicação

**Decision**: (1) Gestor — migrações aditivas em `atendimento_andamentos` e `treinamento_procedimentos`, os escopos da Central, o serviço de
andamento, a origem na tela e na API do app, com QA independente (Gestor, princípio III); (2) Matriz — tabelas, rotas,
regras, tela, aceitando o retrato antigo e o novo; (3) instalador da máquina — leitor e scripts novos; (4) o dono liga
as linhas e marca `vinculo_obrigatorio`.
**Rationale**: os scripts da máquina dependem das colunas e do serviço do Gestor; o Matriz descarta campo novo de
retrato sem recusar, e o novo aceita o antigo (lição do `pedro-comunicacao`, FR-010).
