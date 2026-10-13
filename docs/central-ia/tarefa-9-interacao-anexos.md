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

## Validação realizada em 11/10/2026
- Suíte existente: 1.458 testes, 8.874 assertions, passou (duas depreciações preexistentes).
- Suíte com os sete novos testes: apresentou um erro no teste de tipo real do arquivo; corrigido com finfo sobre os bytes, sem confiar no MIME informado.
- Filtro de anexos após correção: 14 testes, 61 assertions, passou.
- Filtro CentralIa final: 89 testes, 542 assertions, passou.
- Conversores reais: PDF com texto e página renderizada, MP4 com quadros, hash/download e identificador inválido, passou.
- Navegador autenticado: seleção, remoção, colagem e ausência de erros JavaScript, passou. Captura em /tmp/central9-browser/central-ia-corrigida.png.
- Migração local e build concluídos; bot reiniciado sem consumidor adicional.
- Runner atualizado em ~/.local/share/alfa; unidade central-ia-atualizar-tarefa9 aguarda ociosidade antes do restart, pois esta execução é filha do próprio runner. Não houve commit, push, merge ou produção.


## Complemento de status — validação de 11/10/2026
- Diff reconciliado: checkout Felipe, HEAD 7dfd013, 16 arquivos modificados e arquivos novos na entrada; mudanças preexistentes preservadas. Main 1e2bbb9 permanece limpa.
- Suíte completa após correções: 1.472 testes, 8.954 assertions, sem falhas; duas depreciações preexistentes. Central isolada: 96 testes, 589 assertions antes do acréscimo de uma asserção de classificação; filtro Progresso/RespostasConsistentes: 13 testes, 78 assertions após esse acréscimo.
- Primeiro teste da fila falhou por ler o objeto criado sem recarregar o default do banco; teste corrigido, suíte completa repetida e aprovada.
- Parser e indicador web: eventos fragmentados, sequência, ausência de conteúdo bruto e relógio controlado, aprovados. Build e sintaxe Node aprovados.
- Teste real do Codex: leu CENTRAL PDF TESTE da página renderizada e identificou azul nos quadros do MP4. A primeira tentativa do harness esperou stdin aberto e foi encerrada em 240 s; fechando stdin como o runner faz, passou.
- Migração 2026_10_11_140000 aplicada somente no container local; bot reiniciado, um consumidor ativo. Primeiro aviso real gravado na mensagem 69 e entrega Telegram confirmada como entregue (12:12:28 UTC).
- Playwright no navegador local autenticado: anexar/remover/colar e textos de etapa e ausência de confirmação aprovados, sem erros JavaScript.
- Runner e módulo progresso copiados; serviço central-ia-atualizar-tarefa9-status aguarda 15 segundos sem agentes filhos antes do restart. A execução #31 ainda usa o runner anterior; não atribuir progresso novo a ela.
- Nenhum commit, push, merge, deploy ou alteração de produção.
- Conferência real do intervalo: avisos 69 e 70 criados às 09:12:28 e 09:14:02 (America/Sao_Paulo), ambos entregues no Telegram. Intervalo observado de 94 s, com configuração de 90 s mais o ciclo de leitura/entrega do bot. Sem erros novos no log após restart.


## Histórico Telegram/Central — validação de 11/10/2026
- Pedido explícito de análise com implementação atendido localmente na branch Felipe (7dfd013). Estado inicial corresponde ao diff da execução 31; main permanece 1e2bbb9 sem alterações.
- Consulta local inicial: 29 mensagens da tarefa 9 na conta Felipe, projeto alfamatriz (42 a 72, com intervalos). Outro histórico está no recorte alfamatriz-felipe-central-ia; nenhum ponto salvo na tarefa 9. O endereço/conta usado no dispositivo do dono não foi informado; não atribuir com certeza o problema do dispositivo a um desses recortes.
- Acrescentados nome da própria conta e metadados privados da última conversa Telegram no painel. Atalho abre essa conversa, libera filtro de outra tarefa e histórico salvo. Primeira visita sem escolha acompanha o Telegram; escolhas explícitas e rascunhos permanecem.
- Paginação disponível também com tarefa selecionada. Não copiar, mover ou fundir mensagens/projetos/contas. Navegação web não altera o contexto do bot.
- Reconhecer o pedido composto “análise com implementação” como implementação em modo automático, com teste de Codex manual e timeout correspondente. Bot local reiniciado para carregar o Roteador novo; runner não precisou de outro restart.
- Central isolada: 99 testes, 615 assertions antes do acréscimo do caso de classificação. Suíte completa final com esse caso: 1.475 testes, 8.982 assertions, sem falhas; duas depreciações preexistentes.
- test-historico.mjs: navegação entre projetos, filtros, histórico salvo, preservação de rascunho, primeira visita e resposta de poll atrasada aprovados. Build Vite, sintaxe Node e diff check aprovados.
- Playwright local autenticado: primeira visita mostra última mensagem Telegram; atalho partindo de alfamatriz-felipe-central-ia mostra pedidos e respostas reais da tarefa 9 em alfamatriz, preserva rascunho anterior e libera filtro. Paginação visível sob filtro. Um novo aviso real apareceu pelo poll sem recarregar nem enviar mensagens artificiais. Sem erros JavaScript. Captura: /tmp/central9-browser/central-ia-historico.png.
- Pendência anterior do runner encerrada: journal confirmou restart em 09:15:23; unidade atualizadora terminou com Result=success. Execução 32 informou sequência 13, etapa resposta, processo ativo e atividade/progresso recentes; avisos 74, 75 e 76 foram entregues no Telegram.
- Sem commit, push, merge, deploy ou produção.
