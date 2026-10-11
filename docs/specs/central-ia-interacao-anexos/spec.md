# Tarefa 9 — Interação e anexos

## Especificação
Confirmar envio imediatamente; distinguir fila, execução, máquina offline e falha. Renovar typing no Telegram enquanto houver execução ativa. Receber imagem JPEG/PNG/WebP, PDF e vídeo MP4/WebM/QuickTime. Limite escolhido: 20 MiB por arquivo, até 5 por pedido na web, envio sequencial para respeitar PHP/Nginx locais.
Arquivos ficam em disco privado, com UUID e autorização por conta tanto na web quanto no runner. Reenvio não duplica pedido nem associa anexos de outra conta.

## Esclarecimentos
O comando mais recente IMPLEMENTAR substitui o cabeçalho ANALISAR. Preservar os seis arquivos previamente modificados no HEAD 7dfd013. Nenhum acesso de produção. Vídeo será lido por quadros, sem prometer transcrição de áudio. PDF terá texto extraído e páginas renderizadas quando necessário. O Codex recebe imagens nativamente; o prompt registra limites de extração.

## Plano e tarefas
1. Criar armazenamento, upload idempotente e download privado.
2. Associar anexos atomicamente ao pedido e receber mídias no Telegram.
3. Acrescentar compositor, colagem de screenshot, indicadores e recuperação.
4. Runner: download autenticado, extração PDF/vídeo, imagens para Codex.
5. Testar privacidade, limites, reenvio, Telegram e UI; disponibilizar apenas localmente.

## Critérios de aceite
Upload e download autorizados; outra conta recebe 404. Tipo/tamanho inválidos recebem erro explícito. Mensagens sem texto com anexo funcionam. Typing termina com a execução. Sem consumidor adicional. Testes isolados verdes e build web válido.


## Complemento autorizado — status a cada 90 segundos (11/10/2026)
A confirmação do dono “Ok. Pode seguir com o codex para análise e implantação” autoriza implementação local e substitui o cabeçalho ANALISAR nesta etapa. Sem produção, commit, push, merge ou deploy.
Avisos por execução ativa, inclusive fila, a cada 90 segundos, gravados como mensagens do sistema e entregues pelo mecanismo existente nos dois canais. Deduplicação transacional por execução; nenhuma publicação após estado terminal. Dados observados: etapa técnica, última saída do processo e última confirmação do runner, sem conteúdo bruto, percentuais ou promessa de progresso. Sem confirmação recente, avisar que não há comprovação de atividade.
Consultas curtas de status (/status, “como estamos?”, “travou?”) respondem diretamente do banco sem criar execução. Pedidos de status de negócio continuam com a IA. Reconhecer implementar/inplementar/implementação/implantação e manter modo e timeout coerentes, preservando escolha explícita de só análise. Uma execução por tarefa permanece.
Critérios: relógio controlado prova 89/90/180 segundos, duplicidade, fila, desconexão e término; endpoint privado por conta e máquina; sequências antigas não sobrescrevem progresso; conversores e leitura visual pelo Codex validados sem dados reais.


## Histórico compartilhado Telegram/Central — complemento autorizado
Pedido do dono: “análise com implementação ... não estou conseguindo ver no matriz o histórico ... aqui”. O banco local mantém a mesma conversa privada por conta, com recortes por projeto e tarefa. Não copiar nem mover mensagens entre projetos ou contas.
A Central deve indicar a conta logada e oferecer atalho à última conversa registrada no Telegram, abrir o projeto correto na aba Conversa, liberar o filtro de outra tarefa e o histórico salvo da tarefa correspondente. Primeira visita sem projeto escolhido usa essa conversa; escolhas explícitas e rascunhos permanecem. Carregar mensagens anteriores deve estar disponível também sob filtro de tarefa. Poll continua mostrando novas mensagens sem recarregar a página.
Critérios: mensagem do Telegram e resposta aparecem na conta correspondente; atalho não expõe conta alheia, não cria execução e não modifica vínculo; histórico com mais de 50 mensagens acessível; mudança de projeto durante poll não mistura respostas antigas na nova tela; teste no navegador local com a conversa real sem enviar mensagens de teste.
