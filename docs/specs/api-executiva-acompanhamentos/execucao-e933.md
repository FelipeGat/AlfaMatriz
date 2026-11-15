# Execução e933 — 15/11/2026

## Estado e autorização
Matriz: branch Felipe, HEAD 422b240, spec desta ampliação não rastreada; nenhum código implementado nesta execução. Gestor: HEAD b914e173, 74 entradas no status porcelain na fotografia inicial, incluindo outras frentes. Somente o relatório de homologação foi editado no Gestor. Não alterar essas frentes nem usar o worktree inteiro para publicar. Autorizações de publicação/conexão mantidas; não pedir novamente autorização genérica.

Revisão 2: parecer de 18:27 exigiu correções antes da implementação. Revisão 3: fechados modelo, conteúdo conservador, tipos de e-mail, motivos simultâneos, precedência, empresa nula, fontes de próximos passos, mapeamentos e agregação. Uma primeira revisão independente desta execução apontou três inconsistências adicionais (funil sem contrato, direção financeira, tipos validar/sem_validador); corrigidas e submetidas novamente. Na segunda leitura, o revisor confirmou que os três bloqueios documentais foram resolvidos e os documentos estão aptos a orientar a implementação nesses pontos, sem nova contradição observada. É revisão estática: não constitui QA final de código. Parecer de código final inexiste porque nenhum código novo foi implementado.

## Evidência de cobertura financeira
Ponte autorizada do Gestor, transação READ ONLY e rollback, 15/11/2026. Consulta minimizada por empresa candidata Alfa e vencimento: setembro 01–30; outubro 01–31; novembro 01–15. Saída em C:\tmp\e933\evidencia-minimizada.json. Não foram consultados valores, documentos, telefone ou e-mail de cliente. Contagens por vencimento não comprovam completude, data de pagamento, competência, conciliação nem corte de migração. A consulta cadastral de 15/11/2026 às 18:39:18 -03:00 retornou um único cadastro correspondente a Alfa: `empresas.id=4`, razão social `ALFA TECNOLOGIA`. Isso identifica o registro; a data de migração/ausência de lacunas continua sem comprovação. Não configurar o corte em produção com base apenas nesta consulta. Os indicadores que dependem de cobertura continuam indisponíveis; os demais módulos não devem ser bloqueados por essa pendência.

## Conexão ChatGPT — documentação oficial consultada
- https://developers.openai.com/api/docs/actions/getting-started — GPT personalizado, OpenAPI, API Key ou OAuth.
- https://developers.openai.com/api/docs/guides/custom-mcp-server — MCP personalizado, SSE/streaming HTTP, OAuth/sem autenticação; instalação e seleção do plugin na conversa, sujeitas à conta/workspace.
- https://developers.openai.com/plugins/build/auth — MCP autenticado não aceita API keys personalizadas; OAuth do usuário. Preferir provedor estabelecido; não desenvolver autenticação caseira. Tokens upstream Sanctum permanecem no backend e não são o token OAuth do ChatGPT.

A documentação estabelece compatibilidade técnica, não acesso desta conta ou disponibilidade dentro do Projeto Cockpit. Não tive acesso à interface da conta. Nenhum token emitido e nenhum segredo lido.

## Ação manual de conta — sem transmitir segredo
1. No ChatGPT pela web, abrir Plugins (a documentação atual usa esse nome), botão +, Add custom MCP server. Verificar se a opção está disponível e se o workspace permite instalar um plugin privado; não cadastrar o /mcp de escrita da Matriz.
2. No Projeto Cockpit, abrir uma conversa e verificar se o seletor @ permite escolher um plugin privado instalado. Essa verificação confirma disponibilidade na conta; a presença de API no servidor não confirma isso. Não concluir que GPT Actions pode ser anexado ao Projeto sem testar.
3. Após publicar e testar o adaptador de leitura com OAuth, cadastrar apenas a URL fixa desse adaptador, escolher OAuth, instalar o plugin e autorizar a conta Felipe no provedor. Não colar token Sanctum no chat, em campo de URL, em descrição ou em arquivo versionado.
4. Se a conta não permitir MCP privado no Projeto, usar GPT personalizado como alternativa: criar GPT → Actions → importar o OpenAPI validado, configurar autenticação no campo protegido e testar. A chave só deve ser emitida com destino definido e entregue por canal protegido; nunca por esta conversa.
5. Aceitação real: no Cockpit, selecionar a integração e pedir “Consulte Matriz e Gestor: o que depende de mim em 15/11/2026, o que destrava a equipe e quais ações afetam o caixa?”. Conferir chamadas reais das duas fontes, id/origem/atualização/cobertura/links e limites financeiros. Resposta simulada ou teste de unidade não conclui essa etapa.

## Etapas restantes
Implementar conforme tasks.md após revisão documental; caracterização das telas; contratos; adaptador; spec própria do OAuth pelo cstk caso esse mecanismo seja escolhido; testes e QA final em checkout limpo; publicar pacote isolado; validar rede e consumidores internos antes do bloqueio /mcp; cadastrar conexão na conta e demonstrar consulta real. Só depois abrir a integração independente WhatsApp, sem Pedro, com spec própria.
