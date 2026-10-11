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
