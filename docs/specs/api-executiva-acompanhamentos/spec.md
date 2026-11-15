# Feature Specification: Cobertura de Acompanhamentos (Matriz) e Central (Gestor) na API Executiva

**Feature**: `api-executiva-acompanhamentos`
**Created**: 2026-11-15 · **Revisão 3**: 2026-11-15 (achados da revisão independente) · **Status**: Especificação corrigida; implementação autorizada no pedido e933; revisão independente desta versão pendente
**Pedido**: Felipe, 15/11/2026. Estende `api-executiva-leitura`; herda todas as regras dela (só leitura, token próprio, escopo na conta, `null` ≠ zero, auditoria, sem rede nova).

## Pergunta que a integração precisa responder
"O que depende de mim hoje, o que destrava a equipe e o que pode melhorar o caixa?" — sem inventar classificação nem valor.

## Comparação: o que já existe × lacuna

| Necessidade | Matriz (Acompanhamentos) | Gestor (Central) | Lacuna |
|---|---|---|---|
| Decisões atribuídas ao Felipe | `OQueEsperaVoce::pendencias` (bloco `pendencias`: código, tipo, data) | — | Campos uniformes (responsável, ator esperado, prioridade, prazo, link, situação) |
| Perguntas aguardando resposta | idem (`perguntas`) | — | idem |
| Pontos de gestão manuais | `SecretarioItem` (`tipo`, `prioridade`, `status`, `quando`, `empresa`, `origem`) aparece em Acompanhamentos e **não estava no desenho anterior** | — | Seção `pontos` na Matriz (FR-111) |
| Próximos passos de clientes | só chegam à Matriz pelo RETRATO do Gestor (`CentralDeGestao::linhas`, aba `clientes`) | `reunioes_proximo_passo` / funil na Central | **Fonte única = Gestor**; consulta, endpoint e tarefa próprios (FR-112). A Matriz não reexpõe o retrato |
| Propostas pendentes | alerta `proposta_parada` (regra da Matriz) | `orcamentos/pendencias` e `/sem-retorno` | `OrcamentoResource` NÃO traz identificador estável (só `numero`), prazo, prioridade, responsável nem link, e **nenhum endpoint informa quem deve aprovar**. Menor ampliação em FR-113 |
| Cobranças | `Cobranca`/`ContaPagar` da Alfa (bloco `financeiro`, agregado) | `financeiro/vencimentos` e `/inadimplencia` (agregados) | Lista item a item (sem descrição livre) nos dois |
| Chamados sem técnico / parados | — (Pedro só contagens) | `CentralController::proximasAcoes` | Endpoint novo `central/chamados` + serviço compartilhado (FR-114) |
| Atividade recente | `CentralDeGestao::atividade` | `CentralController::feed()` | Matriz: tipo e hora; Gestor: endpoint novo (FR-114) |
| Notificações | bloco `alertas` (não lidas, tipo em lista fechada) | — | Separar histórico × pendência aberta, relacionando sem esconder (FR-103/104) |

## Modelo de item comum (FR-101)
Todo item, em qualquer bloco novo, tem exatamente estes campos; ausente = `null`:

| Campo | Regra |
|---|---|
| `id` | estável e prefixado: `matriz:tarefa:123`, `gestor:atendimento:456` |
| `origem` | `matriz` \| `gestor` |
| `tipo` | lista fechada (abaixo) |
| `classe` | só e-mail: `cliente_confirmado` \| `indefinida`; demais itens `null` (FR-105) |
| `ator_esperado` | quem deve agir: `felipe` \| `rossini` \| `alexandre` \| `equipe` \| `cliente` \| `terceiro` \| `indefinido` |
| `responsavel` | quem está com o registro (dono da tarefa, técnico). **Distinto** de `ator_esperado` (ex.: tarefa do Rossini com pergunta ao Felipe → responsável Rossini, ator esperado Felipe) |
| `cliente_empresa` | FR-110 |
| `situacao` | lista fechada por tipo (abaixo); valor fora dela vira `desconhecida` |
| `prioridade` | `alta` \| `normal` \| `baixa` \| `null`. Mapeamento da original em FR-115; nunca presumida |
| `prazo` | data ou `null` |
| `atualizado_em` | ISO 8601 ou `null` |
| `aberta` / `historica` | bool, FR-103 |
| `possivel_duplicata_de` | id ou `null`, FR-104 |
| `notificacao_relacionada` | id da notificação associada ou `null`, FR-103 |
| `link` | FR-110 |
| `motivos` | lista (vazia quando não aplicável): `visita_vencida`, `triagem`, `sem_empresa`, `sem_tecnico`, `parado`; todos os motivos simultâneos permanecem |
| `valor_centavos` | inteiro ou `null`; nunca float; FR-116 |
| `criterio_valor` | enum `obrigacao_aberta_nominal`, `proposta_total_estimado`, ou `null`; FR-116 |
| `sentido_valor` | enum `a_receber`, `a_pagar`, `potencial_receita`, ou `null`; não inferir pelo id |

**Listas fechadas**
- `tipo`: `decisao`, `pergunta`, `validacao`, `retorno`, `prazo`, `ponto_gestao`, `alerta`, `proposta`, `cobranca`, `chamado`, `proximo_passo`, `atividade`, `notificacao`, `email`.
- `situacao`: `aberta`, `em_andamento`, `aguardando_resposta`, `aguardando_aprovacao`, `vencida`, `parada`, `sem_tecnico`, `em_triagem`, `visita_vencida`, `concluida`, `cancelada`, `desconhecida`. Cada fonte documenta no contrato qual valor original vira qual (tabela fechada; sem regex).
- **Ordenação estável**: `prazo` ascendente (nulos por último), depois `prioridade` (alta→baixa, nula por último), depois `atualizado_em` descendente, depois `id` ascendente como desempate. A ordenação é aplicada ANTES de limitar/paginar (FR-102).

## Requisitos
- **FR-102 Consulta completa, depois limite**: a consulta calcula o conjunto COMPLETO (aplicando escopo e permissões), conta `total`, ordena e só então fatia. Toda lista traz `total`, `retornados`, `limite`, `pagina`, `truncado`. Padrão 50, máximo 100 (igual ao Gestor). Inclui explicitamente `CentralDeGestao::atividade`, que limita cada uma das fontes antes do `take` final: extrair consultas completas, preservando os limites apenas no consumidor da tela. Resultados já limitados ou deduplicados de tela (`proximasAcoes`: 6 por motivo, `feed`: 8/6 por fonte e 12 no total) NÃO servem de base: a API não herda os limites da tela.
- **FR-103 Aberta × histórica, sem perder registros**: notificação lida/tratada é `historica=true, aberta=false`. "Pendência aberta" é a CONDIÇÃO atual (tarefa esperando, alerta ativo, título em aberto), recalculada a cada chamada. Quando uma notificação e a pendência que a originou coexistem, **ambos os registros continuam sendo devolvidos** (não se esconde nem funde nada, FR-104); a pendência leva `notificacao_relacionada=<id>` e a notificação leva `possivel_duplicata_de=<id da pendência>`. O adaptador, ao compor a resposta narrativa, conta a situação uma vez (usa a pendência) mas o `total` da API não é deduplicado.
- **FR-104 Duplicatas só sinalizadas**: `possivel_duplicata_de` por chave determinística (mesma tarefa/atendimento/proposta/cobrança; mesmo cliente+tipo+dia para alertas). Nenhum registro é removido, escondido ou fundido; `total` conta tudo.
- **FR-105 E-mail: evidência insuficiente = incerto**: seção `emails` de `GET /api/executivo/acompanhamentos`, `tipo=email`, `classe=indefinida` nesta versão. `SecretarioEmail::$fillable` não tem vínculo com atendimento/tarefa nem cabeçalhos List-Unsubscribe/Precedence. `responde_a`, `respondido` e remetente cadastrado não provam demanda de cliente. Portanto `cliente_confirmado` fica reservado e NÃO é emitido; nenhuma mensagem vira "cliente aguardando". Seleção usa `SecretarioEmail::de($user)->aguardando()`; situação `aguardando_resposta` indica apenas o sinal armazenado, não obrigação comprovada do Felipe. `ator_esperado=indefinido`; a narrativa exibe seção de itens a verificar, sem afirmar que depende dele. Assunto, remetente, endereço, Message-ID e corpo omitidos; `cliente_empresa=null`, `valor_centavos=null`, link apenas à tela interna permitida de e-mails, sem Message-ID. Taxa de erro não medida. Não ampliar o armazenamento nem buscar mensagens para esta classificação.
- **FR-106 Privacidade preservada**: texto livre continua omitido (assunto, título, descrição, remetente, `detalhe`, `desc`, `observacao`). Listas fechadas continuam. Nada do que foi aprovado no QA é relaxado.
- **FR-107 Só leitura / escopo / permissão por fonte**: GET; conta do token. No Gestor, TODA consulta aplica `EmpresasPermitidas` do usuário do token **e** a permissão do módulo de cada fonte (suporte para chamados, comercial para orçamentos/funil, financeiro para cobranças e para o feed financeiro), como `feed(verSuporte, verComercial, verFinanceiro)` já faz na tela. Fonte sem permissão é omitida e dita na `cobertura`, nunca devolvida vazia como se não houvesse dado.
- **FR-108 Meta de cobertura**: cada resposta diz o que NÃO cobre (ex.: "sem feriados", "financeiro Gestor só desde o corte", "fonte sem permissão").
- **FR-109 Origem separada**: a Matriz não lê o retrato nem o banco do Gestor. O adaptador consulta as duas APIs e só ele junta; o item carrega `origem`.

## Ampliação de conteúdo — EXPLÍCITA e sujeita a revisão independente (FR-110)
O pedido exige campos que o QA aprovou omitir. Proposta mínima, **a revisar**:

| Campo | Hoje | Proposta | Risco |
|---|---|---|---|
| `responsavel`, `ator_esperado` | ausente | `responsavel`: **primeiro nome** de usuário interno, resolvido por lista fechada de contas ativas (nunca texto gravado livre); fora da lista → `null`. `ator_esperado`: enum fechado | baixo; nome interno também é tratado de forma conservadora |
| `cliente_empresa` Gestor | só nome em propostas/agenda (já aprovado) | **`null` na ampliação**: o filtro atual `ConteudoPessoal` reconhece e-mail, sequências com oito ou mais dígitos e URLs; não garante detectar endereços físicos, nomes pessoais ou informações médicas. Não extrair nomes novos de texto cadastrado ou livre. Identificar a contraparte por código estruturado no `id`/link do registro; nome continua disponível na tela autenticada. A política dos endpoints antigos permanece compatível, com a limitação declarada; sua revisão é um portão de publicação de privacidade, não garantia herdada | médio — já aceito no contrato do Gestor |
| `cliente_empresa` Matriz | omitido | **empresa do grupo** (Alfa, GW, Invest, Delta) de lista fechada, ou `null`; **nunca** nome de cliente final vindo de e-mail/WhatsApp | evita PII de terceiros |
| `link` | ausente | **construído** a partir de rota permitida + identificador estruturado (ex.: `route('tarefas.show', id)` → caminho relativo). Nunca copiado de campo gravado (`url`, `origem_ref` ou texto livre). Sem host, sem query com dado, sem token. O adaptador prefixa o host fixo | baixo |
| `prioridade`, `prazo` | parcial | do campo gravado, pelo mapeamento FR-115; senão `null` | nenhum |
| Texto livre | omitido | **continua omitido**; no lugar, `tipo` + código/número | — |

Testes obrigatórios nos campos equivalentes: `Rua das Flores, 25`, CPF/CNPJ, telefone, e-mail, URL, texto médico e conteúdo ambíguo devem produzir `cliente_empresa=null` nos itens novos. Também testar nome aparentemente inofensivo: ele continua omitido. Regex e truncamento não constituem prova de anonimização. Não alterar o filtro global nem os dados originais nesta frente.

## FR-111 Pontos de gestão manuais (Matriz)
`SecretarioItem` do usuário do token entra na seção `pontos` (`tipo=ponto_gestao`): `tipo` original só se estiver na lista fechada do modelo (senão `desconhecida`), `prioridade` mapeada (FR-115), `prazo` = `quando`, `situacao` pelo `status`, `cliente_empresa` = `empresa` só se estiver na lista do grupo. `titulo` e `descricao` omitidos. Itens concluídos/cancelados são `historica`. Escopo estrito em `user_id` do token.

## FR-112 Próximos passos de clientes (Gestor)
Endpoint novo `GET central/proximos-passos` (ability `cockpit:central`) usa `ReuniaoCliente::comPendencia()` (`reunioes_cliente`), como `CentralController`, e `proximo_passo_em` como prazo. Ator esperado vem exclusivamente de `proximo_passo_user_id`; `user_id` é autor, não prova de quem deve agir. `tarefa_agenda_id` concluída encerra a pendência conforme o scope; sem vínculo, conserva o estado registrado com cobertura explícita. Reunião de suporte exige permissão de suporte, comercial exige comercial; tipo desconhecido é omitido e declarado. Aplicar empresa da reunião; não inferir empresa do cliente. Texto de `proximo_passo` omitido. Funil `PreCliente.estagio_funil` é contexto comercial (`novo_lead|contato|proposta|negociacao` → `em_andamento`, `fechado` → `concluida`, `perdido` → `cancelada`), não próximo passo inventado: não criar seção/endpoint de funil nesta versão. A cobertura declara explicitamente que funil sem próximo passo estruturado não é coberto; as propostas continuam nos endpoints existentes. Não presumir próximo passo ou prazo a partir do estágio. Na Matriz, não reexpor retrato; declarar a fonte Gestor.

## FR-113 Propostas: o que se sabe e o que não se sabe
- Os endpoints atuais dizem "orçamento aguardando/sem retorno", **não** "aguardando aprovação DO FELIPE". Sem evidência de destinatário, `ator_esperado` = `cliente` (aguardando retorno do cliente) ou `indefinido`; **nunca `felipe`**. O adaptador não deve listar proposta em "depende de mim" sem campo gravado que a atribua a ele.
- `OrcamentoResource` ganha, pela menor ampliação: `id` estável (identificador do orçamento, não só `numero`), `responsavel` (criador/vendedor, nome curto), `prazo` (validade, se gravada), e o item no modelo comum. Prioridade e link: `null` e construído de rota, respectivamente. Avaliar em T-2.2 se `criadoPor` já serve de responsável; se o prazo não existir gravado, fica `null`.

## FR-114 Central do Gestor: regras reais
- **Triagem** é um motivo para status `orcamento|aberto|em_atendimento|garantia` sem empresa OU sem técnico. Acrescentar `sem_empresa` e/ou `sem_tecnico` aos motivos quando aplicável. Situação única com precedência: `visita_vencida` > `em_triagem` (empresa ausente) > `sem_tecnico` (empresa presente, técnico ausente) > `parada` > situação nativa normalizada. Motivos coexistentes nunca são descartados; o filtro `situacao` por motivo usa essa lista (um chamado pode aparecer em diferentes filtros, com mesmo id). Sem empresa E sem técnico = situação `em_triagem`, motivos `triagem,sem_empresa,sem_tecnico`.
- **Chamado sem empresa**: usuário com qualquer vínculo `user_empresa` não recebe registros de empresa nula. Mesmo sem vínculo, filtro `empresa_id` exclui nulos. Apenas usuário sem vínculo, sem filtro e autorizado em suporte pode recebê-los, conforme `EmpresasPermitidas::restrita`; declarar exclusão na cobertura sem revelar contagem de registros inacessíveis.
- **Parado** = status fora de `concluido|finalizacao` com última atividade **estritamente anterior** a `agora − 2 dias` (`ultimaAtividade < now()->subDays(2)`). Não é "≥ 2 dias".
- **Visita vencida** = a mesma expressão `visitaVencida` da Central, reaproveitada.
- Um chamado pode atender a mais de um motivo; a API devolve UMA entrada por chamado com `motivos` (lista fechada), na ordem de urgência visita vencida → triagem → parado (a mesma da tela), sem a dedup silenciosa da tela perder informação.
- **Atividade recente** (feed): consulta própria, completa e paginada (FR-102), por fonte, respeitando permissão por fonte (FR-107); só `tipo`, hora e número.

## FR-115 Prioridades originais
Mapeamento fechado confirmado no código, sem regex e sem classificação derivada:

| Fonte | Original → prioridade comum |
|---|---|
| `Tarefa::PRIORIDADES` Matriz | `critica`/`alta` → `alta`; `media` → `normal`; `baixa` → `baixa`; `nao_definida`/ausente/outro → `null` |
| `SecretarioItem.prioridade` | `alta` → `alta`; `normal` → `normal`; `baixa` → `baixa`; outro → `null` |
| `Atendimento.prioridade` Gestor (validação do controller `in:baixa,media,alta`) | `alta` → `alta`; `media` → `normal`; `baixa` → `baixa`; outro → `null` |
| Demais fontes sem campo estruturado confirmado | `null`; não usar urgência do aviso como prioridade da obrigação |

Situações nativas, antes dos motivos calculados:
- Tipos de `OQueEsperaVoce`: `pergunta` → `pergunta`; `validar` → `validacao` apenas com destinatário da passagem gravado; `retorno` → `retorno`; `prazo` → `prazo`; `sem_validador` → `alerta`, `ator_esperado=indefinido`, seção alertas para organizar a equipe, nunca aprovação pessoal atribuída ao Felipe. Valor fora desse mapa → tipo `alerta`/ator indefinido, com cobertura declarada e sem texto bruto.
- Tarefa: `aberta|backlog` → `aberta`; `em_desenvolvimento|em_revisao|em_staging|em_producao` → `em_andamento`; `concluida` → `concluida`; `cancelada` → `cancelada`. Pergunta dirigida à conta e ainda aberta sobrepõe `aguardando_resposta`. Portão de validação só sobrepõe `aguardando_aprovacao` quando destinatário estiver gravado.
- SecretarioItem: `aberto` → `aberta`; `concluido` → `concluida`; `cancelado` → `cancelada`.
- Atendimento: `orcamento|aberto|garantia` → `aberta`; `em_atendimento|finalizacao` → `em_andamento`; `concluido` → `concluida`. `finalizacao` não participa da seleção de parado, conforme tela. Motivos de FR-114 têm precedência.
- Cobrança Matriz `pendente` e conta a pagar `em_aberto` → `aberta` ou `vencida` por vencimento anterior à referência; `pago` → `concluida`, `cancelado` → `cancelada` fora da lista aberta. Gestor reutiliza o predicado válido dos serviços Cockpit; não copiar `status != pago` da tela sem excluir canceladas/inválidas.
- Proposta: `aguardando_aprovacao` → `aguardando_aprovacao`; `em_elaboracao|financeiro` → `em_andamento`; demais valores só entram por mapeamento testado do contrato existente, senão `desconhecida`. Sem retorno é condição do endpoint existente, não situação inventada.
- Notificação: lida/tratada → `concluida`/histórica; não lida → `aberta` como aviso, sem afirmar que a obrigação continua aberta. Atividade é histórica, `aberta=false`, situação `desconhecida` quando o evento não prova o estado atual.
- Qualquer original não enumerado → `desconhecida`; não transmitir valor bruto. Histórico de registros antigos não altera a situação atual de outra entidade.

## FR-116 Valor e efeitos de caixa
`valor_centavos` é inteiro decimal arredondado para centavos na fonte, nunca soma de float no adaptador. Para cobrança/conta válida aberta, usar valor nominal gravado, sentido explícito e critério `obrigacao_aberta_nominal`; não inventar saldo remanescente de baixa parcial. Proposta usa total gravado com `proposta_total_estimado`, é potencial comercial, nunca recebível ou entrada realizada. Sem valor positivo válido/fonte autorizada ou fora de cobertura → `null`, critério `null`, motivo na cobertura; ausência não é zero. Cobrança usa `sentido_valor=a_receber`; ContaPagar usa `a_pagar`; proposta usa `potencial_receita`. Demais itens têm valor, critério e sentido `null`. Nenhum resumo pode transformar saída em receita nem potencial em realizado. Não somar proposta com cobrança relacionada, previsto com realizado, nem fontes com cobertura sobreposta. Tarefa 4.4 e financeiro aprovado permanecem intactos; não chamar potencial de lucro ou de saldo futuro.

## FR-117 Agregação e cobertura parcial
O adaptador efetua múltiplas chamadas GET aos endpoints enumerados e segue suas páginas, sujeito ao orçamento de 30 requisições/min por token. Não há promessa de uma chamada por sistema. Não adicionar endpoint agregador apenas para satisfazer essa promessa. Ferramentas executivas fixas não recebem URL, cabeçalhos, token, SQL ou rota livre. Paginação interrompida por teto de requisições/tempo é cobertura parcial com total conhecido, retornados e cursor de continuação; narrativa nunca afirma conjunto completo. Por fonte registrar situação, período, atualização e falha/cobertura, inclusive fontes sem permissão e financeiro não configurado. Mesma data de referência/fuso em ambas as APIs; sem snapshot transacional entre sistemas. Ordenação e total na API ocorrem sobre consulta completa, podendo usar SQL COUNT/paginação sem carregar tudo em memória.

## Fora de escopo
Qualquer escrita (tratar, adiar, resolver); expor retrato do Gestor pela Matriz; tarefa 4.4; rede/túnel/token real; publicação; deduplicar ou apagar.

## Critérios de sucesso
1. Uma ferramenta executiva consulta as páginas necessárias dos dois sistemas e responde às três perguntas; orçamento e cobertura parcial explícitos (FR-117).
2. Testes provam: sem texto livre, sem vazamento entre contas/empresas, permissão por fonte, paginação sobre conjunto completo, ordenação estável, duplicata marcada e não removida, e-mail incerto fora de "cliente aguardando", histórico ≠ aberta com ambos devolvidos, triagem ≠ sem_tecnico, parado estritamente `<`.
3. Contrato (OpenAPI Matriz, `api-executiva.md` Gestor) e adaptador atualizados; nova revisão independente aprovada antes de implementar e outra antes de homologar.

## Autorização e portões restantes
O pedido e933 autoriza corrigir a spec, implementar pelo pipeline existente, obter QA final e concluir publicação/conexão no escopo já autorizado. Não pedir novamente as quatro decisões anteriores: aplicar as opções conservadoras descritas nesta revisão. A revisão independente desta versão ainda não foi executada; não confundir autorização com parecer técnico. O destino e a autenticação do adaptador dependem de verificar a conta do ChatGPT; OAuth/MCP é uma frente de autenticação ainda sem spec própria. Não improvisar credenciais nem reutilizar MCP de escrita. WhatsApp independente só começa após demonstração real do Cockpit e exige pipeline próprio.
