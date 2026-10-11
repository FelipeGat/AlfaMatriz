# Diretriz de orquestração do perfil de Felipe

Decisão do proprietário: todo novo pedido de trabalho deve passar primeiro pelo Codex, coordenador de planejamento, distribuição e acompanhamento. Claude implementa frontend/backend e manutenção. Copilot organiza Git e realiza QA independente. Deploy continua condicionado à autorização específica; nenhum papel autoriza automaticamente push, exclusão ou operações críticas.

Interface principal: AlfaMatriz. Telegram somente quando Felipe ativa Continuar pelo Telegram; mesma tarefa e contexto, sem envio duplicado permanente aos canais.

Esta diretriz é exclusiva do perfil de Felipe. Não alterar padrões de outros usuários nem compartilhar autenticações. Alexandre e Rossini permanecem sujeitos aos acessos determinados pelo administrador e aos bloqueios técnicos vigentes.

## Diferença em relação à implementação atual

O roteamento atual usa classificação local por palavras e preferências. Não existe ainda uma chamada obrigatória ao Codex para cada pedido nem um ciclo completo e automático Codex → Claude → Copilot → Codex. Este documento registra o requisito; não afirma sua ativação.

Implementação necessária: coordenador por proprietário com tarefas e etapas persistentes, plano estruturado validado no servidor, handoffs com objetivo, contexto mínimo, alterações e evidências; revisão independente; idempotência por etapa; cotas e concorrência; checkpoints em falha; autorizações críticas vinculadas à operação. Respostas de agentes não devem criar pedidos novos indiscriminadamente. Somente transições controladas pelo orquestrador avançam as etapas. Git/QA deve preservar alterações preexistentes e respeitar worktrees/locks por tarefa. Primeiro validar com respostas simuladas.

Cada pedido ao coordenador consome a cota Codex. Se indisponível, registrar e aplicar apenas substituição explicitamente autorizada; nunca fingir que houve planejamento Codex. Não usar API paga automaticamente.

## Limite Copilot

O código atual exibe consumo premium recebido do runner e um teto global opcional de configuração. Esse teto não é uma consulta autenticada ao plano do proprietário. O coletor e a tela precisam distinguir créditos GitHub AI, requisições premium legadas e dados de consumo não confirmados. Confirmar produto, conta GitHub autenticada, plano e unidade antes de apresentar saldo.

A conexão Microsoft/OneDrive não comprova assinatura GitHub Copilot nem transfere credenciais para o runner. Não ler tokens ou reutilizar autenticação OneDrive para essa validação. A integração futura deve usar mecanismos oficiais e credenciais individuais, sem cobrança extra automática.

## Etapa de coordenação preparada, ainda não ativada

Preferência individual `coordenador_codex` inicia cada pedido pela coordenação Codex, modo analisar, preservando destino/modo sugeridos. Se indisponível, o pedido é interrompido; fallback não substitui silenciosamente o coordenador. Não habilitada por padrão, nem gravada em conta real. Não altera autenticação.

Esta etapa propõe a distribuição no histórico, mas ainda não dispara automaticamente os executores. Antes de ativar: testes simulados, restrição efetiva de ferramentas na execução do coordenador (a instrução textual isolada não basta), transições persistentes de delegação/QA com idempotência e aprovações. O runner instalado e o versionado devem ser conciliados sem perder sessões.

Pedidos financeiros/agenda sem período pedem esclarecimento antes da fila. Análise de código usa preferência de desenvolvimento, modo analisar; dados financeiros usam investigação. A detecção atual é lexical e precisa de cobertura para ambiguidades. Regras de evidência proíbem inventar valores e exigem fontes autorizadas; não substituem validação estruturada dos dados.

A revisão automática de aprovação bloqueou o teste Docker por limite de uso do mecanismo de revisão. Não foi decisão de insegurança, mas o comando não foi executado. Não tentar contornar esse controle. Nesta versão não houve teste dos novos caminhos, inferência, ativação real ou reinício. `git diff --check` passou. Validar antes de habilitar a preferência.
