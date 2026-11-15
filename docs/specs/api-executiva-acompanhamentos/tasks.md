# Tasks: api-executiva-acompanhamentos (revisão 3)
Nada implementado. Pedido e933 autoriza implementação e opções conservadoras da revisão 3; FR-110 a FR-117 definidas. Código só depois da nova revisão independente.

## Fase 0 — Aprovação
- [x] 0.1 Autorização e933 registrada; usar nomes novos omitidos, prioridade gravada, parado estrito e atribuição comprovada.
- [ ] 0.2 Nova revisão independente da spec/plan/tasks corrigidos (privacidade, cobertura, regras da Central) antes de qualquer código.

## Fase 1 — Matriz
- [ ] 1.1 Teste de caracterização de `OQueEsperaVoce::pendencias`, `CentralDeGestao::linhas` e da leitura de `SecretarioItem` em Acompanhamentos.
- [x] 1.2 Confirmar schema/migrações do leitor: modelo não traz evidência forte nem cabeçalhos; implementar somente classe indefinida e seção emails; taxa de erro não medida.
- [x] 1.3 (parcial: `ItemExecutivo` feito; `Duplicatas` e `ClasseDeRemetente` embutidas em `Acompanhamentos`, classe sempre indefinida) `ItemExecutivo` (enums, ator esperado × responsável, classe, motivos, valor_centavos, criterio_valor, sentido_valor, notificacao_relacionada, nomes por lista fechada, links por rota), `Duplicatas`, `ClasseDeRemetente` + testes.
- [x] 1.4 (seções decisoes, perguntas, pontos, alertas, emails, notificacoes, atividade; `cobrancas` fica para depois) `Acompanhamentos` (seções, incluindo `pontos`) + rota + `Blocos::acompanhamentos`; consulta completa → ordenação estável → total → paginação.
- [x] 1.5 (parcial: tipos validar/sem_validador sem atribuição presumida, privacidade, isolamento, histórico×aberta, duplicata, e-mail incerto, ordenação, 405 cobertos; auditoria já na porta) Normalizar tipos validar/sem_validador sem atribuição presumida; testes de privacidade, isolamento, histórico×aberta (ambos devolvidos), duplicata, e-mail incerto, ordenação, 405/auditoria.
- [x] 1.6 (path acompanhamentos; lint ok; schema `AcompanhamentosEnvelope`/`ItemExecutivo` validado contra 17 respostas reais de teste em 15/11/2026: totais, vazio, as 7 seções, paginação 1/2/além do fim, sem permissão; 401/422 só documentados) OpenAPI atualizado (enums completos, mapeamento de prioridades); lint.

## Fase 2 — Gestor
- [ ] 2.1 Conferir fontes reais: tabela de próximos passos (FR-112), validar prioridade baixa/media/alta do AtendimentoController (FR-115), `ConteudoPessoal` aplicável a nomes. Teste de caracterização de `CentralController` (`proximasAcoes` e `feed`) e extração para `CentralCockpitService` sem alterar a tela.
- [ ] 2.2 `OrcamentoResource`: `id`, `responsavel`, `prazo` e modelo comum (FR-113); teste de ausência de texto livre.
- [ ] 2.3 Endpoints `central/chamados`, `central/atividade`, `central/proximos-passos`, `financeiro/cobrancas-abertas`, ability `cockpit:central`; `EmpresasPermitidas` + permissão por fonte.
- [ ] 2.4 Testes: privacidade (`ConteudoPessoal`), escopo de empresa, permissão por fonte, paginação sobre total, precedência e motivos simultâneos; empresa nula excluída de usuário vinculado, parado `<` agora−2d, 405; contrato `api-executiva.md`.

## Fase 3 — Adaptador
- [ ] 3.1 Ferramentas de leitura compostas e `cobertura` somada; proposta só em "depende de mim" com atribuição gravada.
- [ ] 3.2 Teste com respostas simuladas das duas APIs (falha de uma não derruba a outra; dedup narrativa só no adaptador).

## Fase 4 — Fechamento
- [ ] 4.1 Suíte completa, estilo (Pint), revisão independente, relatório.
- [ ] 4.2 Registrar autorização e933 e QA por pacote; publicar só pacote revisado e isolado. Validar aplicação, rede e ChatGPT separadamente; não emitir segredo para chat/log.

## Portões adicionais obrigatórios
- [x] 0.3 Revisão independente da revisão 3 nesta execução: três bloqueios adicionais corrigidos; segunda leitura confirmou esses pontos aptos para orientar implementação. Parecer estático registrado em execucao-e933.md; não equivale a QA final do código.
- [ ] 1.7 Caracterizar atividade Matriz e extrair conjunto completo antes de limites por fonte.
- [x] 1.8 (parcial: varredura de texto livre em todas as seções; endereço/texto médico só cobertos por omissão total) Regressão de privacidade: endereço físico, identificadores, texto médico, nome aparentemente seguro e equivalente em todos os campos novos → null; armazenamento intacto.
- [ ] 2.5 Validar fonte `ReuniaoCliente::comPendencia`, destinatário por proximo_passo_user_id, tarefa concluída e permissões por tipo/empresa; funil sem próximo passo estruturado explicitamente fora da cobertura nesta versão.
- [ ] 2.6 Testes valores: centavos inteiros, cobrança nominal vs proposta estimada, sentido a receber/a pagar/potencial, cancelado, parcial, ausente, corte e proibição de somar potencial/realizado.
- [ ] 3.3 Testar múltiplas páginas, rate limit, teto/continuação, falha parcial, sem vazamento de totais fora de escopo e sem snapshot alegado.
- [ ] 4.3 Confirmar id/corte da Alfa por ponte autorizada sem PII/segredos; contagem por mês não prova completude. Até validar, somente indicadores afetados indisponíveis.
- [ ] 4.4 Demonstrar consulta real no ChatGPT com as duas fontes e guardar evidência minimizada de origem/atualização/cobertura/links; somente depois iniciar WhatsApp independente em outra spec.

## Fechamento da Fase 1 (15/11/2026, sem commit)

- Ligação aviso↔pendência: só por tipo correspondente (`pergunta`, `retorno`, `teste_staging`→`validar`), preferindo o aviso aberto; histórico preservado. Aviso de outro tipo na mesma tarefa não liga.
- Permissão por seção (mesma da tela): `decisoes`/`perguntas` exigem `tarefas`,`ler` (e não exibição/revenda); `pontos`, `alertas`, `emails`, `notificacoes`, `atividade` exigem `secretario`,`ler`. Sem permissão: situação `nao_configurado`, `total` null, itens vazios; sem `secao`, o total da seção é `null` (nunca 0) e vem listada em `sem_acesso`. Cobertura em teste só no nível do serviço (conta sem perfil e perfil exibição).
- `ItemExecutivo`: `classe`, `cliente_empresa`, `link`, `notificacao_relacionada` e `possivel_duplicata_de` com regra explícita; `id` malformado é exceção.
- Avisos lidos uma vez por requisição; seção desconhecida no serviço é 422 (fora do `proteger`).

## Correções da revisão independente (15/11/2026, sem commit)

- OpenAPI: `AcompanhamentosEnvelope.dados` aceita `null` (falha de fonte, `Envelope::proteger`). Regressão: `test_falha_de_fonte_devolve_dados_null_e_o_openapi_declara_isso`.
- Teste de preferência pelo aviso aberto: `lida_em` fora do `$fillable`, agora gravado por `forceFill`/`save`, com estado persistido afirmado e aviso LIDO mais novo que o ABERTO (distingue preferência de ordenação por data).
- Permissões por HTTP: `test_http_sem_permissao_...` (nenhuma permissão) e `test_http_permissao_parcial_...` (só `tarefas` e só `secretario`, nos dois sentidos).
- Diferença das portas antigas: `Blocos::emails` e `Blocos::alertas` NÃO aplicam `secretario:ler` em bloco (alertas filtra vigia/monitor por permissão própria); a seção `acompanhamentos` aplica a regra da tela por seção. Escopo não ampliado nos blocos antigos — a diferença é registrada, não corrigida aqui.
- Limite conhecido: `link` é validado por formato relativo, não por lista de rotas permitidas; hoje só é montado por `route()`.
