# Perguntas e conversa simplificada

Perguntas pendentes do dossiê aparecem separadas do histórico, com botão Responder pergunta. O contrato do agente pede `duvida` e `opcoes_duvida` (até seis opções, textos limitados a 250 caracteres). Dados são saneados e expostos apenas pela consulta de tarefas do proprietário. Campos são limpos no próximo pedido. Não há migração.

A caixa permite escolher ou digitar e confirma o envio na mesma tarefa pelo mecanismo existente de idempotência. Trocar de assunto impede enviar a resposta para outro chat; rascunhos e envios pendentes não são sobrescritos. Respostas não equivalem a aprovar deploy, conceder acesso a produção ou alterar permissões. Quando Telegram está ativo, o envio pela caixa Web fica bloqueado como o compositor existente.

Mensagens antigas não são reescritas: Responder em diálogo abre o texto original quando ele contém interrogação. Não se inferem opções automaticamente a partir de texto livre. Futuras opções dependem do agente devolver o bloco estruturado; fallback mantém campo livre. Não há popup automático que interrompa a leitura.

Opções do chat recolhem seletores e detalhes de Git, roteamento e entrega. Autor, mensagem e data permanecem visíveis. Controles podem ser reabertos sem mudar tarefas, permissões ou histórico.

Validação: teste-perguntas.mjs, test-ajustes.mjs, build Vite e git diff --check passaram. Sem inferência, migração ou reinício. PHP/backend não revalidado: revisão automática de aprovação atingiu limite e bloqueou o teste Docker na rodada anterior. Não contornar a revisão; validar contrato do dossiê, autorização e persistência antes de declarar homologação concluída. Revisão visual no navegador pendente.
