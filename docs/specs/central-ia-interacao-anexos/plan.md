# Plano

Constitution Check: Blade/Alpine e tokens existentes; dados privados por conta; um consumidor Telegram; conversores isolados sem rede; somente ambiente local.

Tabela central_ia_anexos, UUID, hash, mensagem e dono. Upload separado sequencial para respeitar post_max_size. Associação transacional e idempotência. Download web autenticado e runner via capacidade central-ia-runner. Telegram verifica conta antes de baixar. UI acompanha status e heartbeat existentes. Converter PDF/vídeo em imagem com Poppler/FFmpeg em container isolado, anexar via --image do CLI Codex local (inclusive resume).


Complemento: migração aditiva de progresso JSON, confirmação e último aviso nas execuções. Serviço compartilhado publica mensagens/entregas atomicamente sob lock da execução. Ciclo exclusivo do bot, batida do runner e poll autenticado acionam a mesma rotina (sem scheduler genérico). Runner envia metadados a cada 30 s por endpoint privado, com sequência crescente e saída reduzida a enums. Consultas diretas usam o mesmo retrato; reenvio web usa client_id e lock da conta. Testes isolados de backend e parser de eventos antes de migração/restart local.


Histórico: ampliar metadados privados do painel com projeto/tarefa da última mensagem do Telegram e nome da conta logada. Reutilizar abrir(), histórico paginado e estilos existentes. Atalho de navegação remove recorte de outra tarefa, reabre histórico salvo e mantém compositor na tarefa da conversa quando existir. Evitar mudança automática se URL/projeto já escolhido; primeira visita pode seguir último contexto do Telegram. Permitir paginação com tarefa selecionada. Testes backend de isolamento/paginação e frontend de troca de projeto e persistência de rascunho.
