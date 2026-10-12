# Respostas executivas — desenvolvimento local

## Diagnóstico

O Blade usava `x-text` para a resposta inteira: Markdown aparecia literalmente. Não havia contrato de relatório visual nem biblioteca de gráficos instalada. O armazenamento contém o texto original e a autorização continua no servidor. O CommonMark já instalado agora renderiza Markdown com HTML de entrada removido, links inseguros bloqueados e limite de aninhamento. Nenhuma dependência foi adicionada.

## Implementação

`RespostaVisual` interpreta um envelope versionado, valida tipos e tamanhos e publica apenas dados de componentes conhecidos. Cards monetários usam centavos inteiros; valores ausentes invalidam a estrutura, não viram zero. Gráficos horizontais mostram valores absolutos com o valor assinado em texto. Tabelas e fontes ficam recolhidas. Respostas longas têm prévia e expansão. Textos históricos ganham Markdown sem reescrever o banco. O envelope permanece no conteúdo original; não há migração.

Formato: `[CENTRAL_RELATORIO_V1]` JSON `[/CENTRAL_RELATORIO_V1]`. Campos obrigatórios: `versao:1`, `titulo`, `periodo`, `fonte`, `metricas:[{rotulo,centavos,criterio}]`. Opcionais: `grafico:{titulo,tipo:"barras|linha",pontos:[{rotulo,centavos}]}`, `tabela:[{descricao,data,centavos}]`. Máximo 8 métricas, 24 pontos, 100 contas. Estruturas inválidas permanecem como texto seguro. Não há execução de HTML, scripts ou configuração arbitrária de gráficos fornecidos pelo agente.

Telegram reutiliza a entrega existente e envia título, período, todos os KPIs e fonte, sem envelope. Não inicia uma nova execução. Relatórios extensos continuam limitados pelo tamanho de mensagem do canal; a íntegra permanece no histórico. Nenhum consumidor foi adicionado.

## Tempo de resposta e precisão

A instrução passa a exigir conclusão curta, prioridades e pergunta no dossiê estruturado quando indispensável. Isto reduz o texto pedido; não comprova redução de latência. O relato de quase dois minutos não separa espera em fila, consulta e geração. Nenhuma inferência ou nova consulta financeira foi realizada. Não se deve prometer números mais rápidos sem medir uma execução autorizada.

Os valores históricos do usuário são exclusivamente fixture de teste e não consulta atual. O backend valida estrutura, não atesta a verdade do banco consultado pelo agente. Reconciliação contábil e fonte financeira tipada ainda precisam de implementação própria; números sem evidência não devem ser produzidos.

## Incidente informado de credencial

O relato afirma que uma credencial apareceu em saída de ferramenta. Não acessamos scripts de acesso, credenciais, memórias ou logs reais do Gestor. Auditoria somente do código do runner: stdout/stderr alimentam progresso e buffers; erros de processo podem alcançar o resultado. Uma credencial exposta pode estar no histórico do provedor ou em progresso, além do relatório. Não é possível confirmar abrangência pelo relato sem auditoria autorizada de metadados/redação controlada. Recomendação: identificar a credencial com o responsável, revogar/rotacionar na origem e revisar cópias/backups; não executar rotação automática. Mascaramento por regex não garante eliminar todo segredo.

## Limitações e próximos passos

- Barras horizontais e linhas implementadas; distribuições e comparação automática entre períodos não são inferidas.
- A prévia textual é truncada, não um resumo gerado; pode interromper uma frase. Cards são exibidos antes dela.
- Falta validação visual em navegador real, celular, acessibilidade e ensaio end-to-end Telegram.
- Falta medir fila/consulta/primeiro resultado/total, com metadados sem prompts/segredos.
- Preservação do texto da mensagem está mantida; o dossiê técnico já era extraído antes do armazenamento, e esta versão não muda esse comportamento.
- Nenhum commit, push, deploy, migração ou chamada de IA nesta etapa.

Rollback: reverter somente os arquivos desta alteração e reconstruir assets. Nenhum dado foi reescrito; respostas com envelope permanecem recuperáveis como texto original.

### Atualização da auditoria

O endpoint de progresso já aceita apenas etapa, sequência, horário e estado do processo: saída bruta não é armazenada por esse endpoint. O risco confirmado no código é buffer local e erro de processo, não prova de vazamento no progresso. Foi adicionada redação defensiva de padrões reconhecíveis (chave privada, tokens conhecidos, Bearer e campos de senha/token) na conclusão antes da persistência e na leitura/Telegram. Não garante identificar segredos sem rótulo; backups anteriores e histórico do provedor não são limpos. A segurança prevalece sobre preservar literalmente um segredo; o restante da resposta original é mantido.

A versão também suporta linhas, contagens, alertas e próximas ações. O exemplo offline reutiliza o fragmento Blade efetivo: `node tools/central-ia/demo-respostas.mjs` cria `/tmp/central-ia-demo.html`, sem banco, rede ou modelo. Não houve navegador automatizado disponível para validação visual; essa validação continua pendente.

## Resultado de validação

153 testes PHP passaram, com 861 asserções, incluindo regressão da Central e testes unitários do renderizador; dois avisos de depreciação PHPUnit já existentes. Quatro verificações Node de perguntas, filtros, ajustes e governança passaram. Build Vite e `git diff --check` passaram. Casos cobertos: Markdown legado/tabela, script/link perigoso, envelope inválido, ausência de valor, positivo/negativo, subtotal histórico, linha/barras e redação de segredos sintéticos. Não houve inferência nem conexão financeira real.

Arquivos alterados: controller público; serviços Agentes, Conversa, Dossie e TelegramCanal; novos serviços RespostaVisual e ProtecaoDeSegredos; Blade da Central; CSS; testes unitários e fixture histórica; gerador de demonstração offline; este documento.
