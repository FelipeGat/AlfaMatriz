# Plan: api-executiva-acompanhamentos (revisão 3)

## Princípio
Camada fina sobre serviços existentes; regra em serviço, nunca em controller. Blocos novos são adicionais; os atuais não mudam de formato (compatibilidade com o OpenAPI já publicado). **A consulta é completa antes de qualquer limite** (FR-102): nada de reaproveitar resultado já fatiado/deduplicado da tela.

## Matriz (`app/Services/Executivo/`)
- **`ItemExecutivo`**: único lugar que monta o modelo comum (FR-101), aplica listas fechadas, `ator_esperado` × `responsavel`, `classe`, `motivos`, `valor_centavos`, `criterio_valor`, `sentido_valor`, `notificacao_relacionada`, `null`. Nomes de usuário só por lista fechada de contas ativas; links só por `route()` + id estruturado.
- **`Acompanhamentos`** (serviço), para a conta do token:
  - `OQueEsperaVoce::pendencias` → `decisoes`, `perguntas`; normalizar validar→validacao e sem_validador→alerta/ator indefinido (ator esperado = quem deve responder; responsável = dono da tarefa);
  - **`SecretarioItem`** do `user_id` do token → `pontos` (FR-111);
  - `SecretarioAcompanhamento` ativos → `alertas_abertos`;
  - `Cobranca`/`ContaPagar` da Alfa (mesmo critério do `FinanceiroExecutivo`) → `cobrancas` item a item, sem descrição;
  - `Notificacao` → `historico` (lidas/tratadas, 7 dias) e abertas, ligadas por `notificacao_relacionada`/`possivel_duplicata_de` sem esconder nenhuma (FR-103);
  - atividade: tipo + hora; extrair consultas sem `limit($quantas)` por fonte e sem `take` final, preservando a tela.
  - `emails`: scope `aguardando`, classe indefinida, sem ator Felipe presumido.
  - Cada seção: consulta completa → ordenação estável (spec) → `total` → fatia.
- **`Duplicatas`**: chave determinística → `possivel_duplicata_de`.
- **`ClasseDeRemetente`**: devolve somente `indefinida` nesta versão: os metadados existentes não comprovam demanda. Vínculo cadastrado sozinho não basta (FR-105).
- Rota: `GET /api/executivo/acompanhamentos?secao=decisoes|perguntas|pontos|emails|alertas|cobrancas|atividade|historico&pagina&limite`, via `Blocos::acompanhamentos`, mesma pilha de middlewares.
- Reuso: `Areas` (de quem é), `PainelDoDia::prazo`.

## Gestor (`app/Services/Cockpit/`, ability nova `cockpit:central`)
- **`CentralCockpitService`**: extrai de `CentralController` as CONSULTAS de `proximasAcoes` e `feed`, sem os `limit(6)/limit(8)/take(...)` nem a dedup da tela, e sem os campos de texto (`desc`, `detalhe`, `observacao`). O controller passa a usar o serviço **mantendo** seus limites e formato (parâmetros `limite`), com **teste de caracterização escrito antes** que fixa a saída atual da tela (visita vencida, triagem, parado, ordem, dedup, fallback de cliente, feed por permissão).
- O serviço aplica `EmpresasPermitidas` e as permissões por fonte (suporte, comercial, financeiro) — na tela essa regra existe no feed (`verSuporte/verComercial/verFinanceiro`) e precisa ser preservada, não transportada com as limitações da tela.
- `GET central/chamados?situacao=em_triagem|sem_tecnico|parado|visita_vencida` — uma entrada por chamado com `motivos`; triagem = sem empresa **ou** técnico; sem_tecnico = só técnico ausente; parado = última atividade **estritamente anterior** a agora − 2 dias; `cliente_empresa=null` na ampliação; o filtro atual não garante detectar endereços.
- `GET central/atividade` — tipo + hora + número, completa e paginada, por fonte permitida.
- `GET central/proximos-passos` (FR-112) — `ReuniaoCliente::comPendencia()` com `proximo_passo_user_id` e `proximo_passo_em`; texto do passo omitido.
- `GET financeiro/cobrancas-abertas` — lista; reaproveita escopo e critério de `inadimplencia`/`vencimentos`; herda o 503 `configuracao_pendente` enquanto corte e `empresa_alfa_id` seguirem pendentes.
- **Propostas** (FR-113): `OrcamentoResource` ganha `id`, `responsavel`, `prazo` quando gravados, mais campos do modelo comum; `ator_esperado` = `cliente`/`indefinido`, nunca `felipe` sem campo gravado. Mudança mínima, com teste de que nenhum campo de texto livre entrou.
- Links construídos por `route()` com id; nunca de campo gravado.

## Adaptador (somente leitura)
`o_que_depende_de_mim(data)`, `destrava_equipe`, `melhora_caixa`, compostas das duas APIs; cada item com `origem`; sem URL livre, SQL ou rota livre; tokens só no servidor do adaptador. `cobertura` soma as lacunas e as fontes sem permissão/falha.
- depende de mim = itens com `ator_esperado=felipe` (decisões, perguntas, validações, pontos, alertas abertos). Propostas só entram se houver campo que as atribua a ele (FR-113);
- destrava a equipe = chamados `em_triagem`/`sem_tecnico`/`parado`/`visita_vencida` + perguntas feitas A outros que travam tarefa;
- melhora o caixa = cobranças vencidas/a vencer, propostas sem retorno (`ator_esperado=cliente`); valores só quando a fonte dá, senão `null`; sentido a_receber/a_pagar/potencial_receita explícito, sem confundir saída com entrada.
- Dedup narrativa (notificação × pendência) só no adaptador, contando uma vez; a API devolve ambas.

## Constitution Check
I (conta própria): escopo no token, sem `user_id` na requisição ✔. Leitura só ✔. VIII (financeiro sem lucro, `null`) herdado ✔. Privacidade: ampliação limitada a FR-110, com nomes novos omitidos e revisão. **Ressalvas**: extração no Gestor altera a Central — caracterização antes; `OrcamentoResource` muda — teste de campos.

## Riscos
Classificação de e-mail incerta por natureza (conservador, documentado, taxa de erro não medida); duplicata por chave frágil (só sinaliza); extração da Central; volume (paginação sobre conjunto completo — custo de consulta maior que a tela); cobranças item a item aumentam superfície (título/descrição fora); nomes internos só aliases literais da lista fechada, nomes novos de clientes omitidos; proposta sem aprovador gravado não pode ser atribuída.

## Testes (dados fictícios)
Privacidade (nenhum texto livre, nem em campo novo; nomes novos são omitidos inclusive quando aparentemente seguros), isolamento entre contas e empresas, permissão por fonte (fonte omitida declarada), paginação sobre conjunto completo (`total` > limite), ordenação estável, histórico×aberta com ambos devolvidos e ligados, duplicata marcada sem remoção, e-mail de contato cadastrado sem evidência forte = `indefinida`, triagem ≠ sem_tecnico, parado com fronteira exata (`<`), links só por rota, 405 em escrita, auditoria, regressão do `OQueEsperaVoce` e da Central do Gestor.

## Fechamento da revisão 3
Aplicar FR-114 a FR-117 integralmente: motivos simultâneos com situação predominante; chamados sem empresa fora do escopo vinculado; prioridades e situações com tabelas explícitas; valor nominal de obrigação separado do potencial de proposta. Agregação fica no adaptador com várias chamadas paginadas e orçamento, sem promessa de uma chamada/sistema. Consulta de próximos passos usa reuniões; funil não vira passo inventado e não ganha rota/agregador nesta versão. `cliente_empresa` nos itens novos do Gestor é null até existir representação nominal segura revisada. Links apontam para registros por rota permitida; não enviar nome em query.

## Conexão e infraestrutura (frente separada)
Actions aceita API Key/OAuth. MCP autenticado no ChatGPT exige OAuth e não aceita API key personalizada como autenticação do servidor. Adaptador preserva tokens Sanctum apenas no backend, com OAuth próprio na frente; não reenviar token OAuth do ChatGPT às APIs. Escolha não definitiva até conferir conta. OAuth precisa de spec/plan/tasks próprios pelo cstk; nenhum endpoint público sem QA, descoberta e validação de escopo/audiência. Não implementar autenticação caseira nesta ampliação. Publicação da aplicação, rede e instalação no ChatGPT são etapas distintas com teste e rollback próprios.
