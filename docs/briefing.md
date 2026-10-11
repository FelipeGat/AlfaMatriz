# Briefing — AlfaMatriz / Central de IA

Fonte: README.md, docs/central-ia/README.md e tarefa #9 do dono.
Painel interno Laravel 12, Blade, Alpine e Tailwind. A Central coordena agentes no runner de cada conta e mantém conversa privada com Telegram.
Objetivo desta frente: confirmar recebimento, mostrar atividade contínua e aceitar fotos, PDFs e vídeos com leitura efetiva no runner.
Escopo autorizado: branch Felipe, ambiente local, Codex, sem commit/push/merge/deploy.


## Continuação em 11/10/2026 — tarefa 10

Correção da exclusividade Telegram e continuidade entre provedores após limites
reais; teto interno opcional. Leia
`docs/central-ia/tarefa-10-continuidade-provedores.md`. Preservar a tarefa 9 ainda
não commitada. Não reiniciar serviços, executar inferência ou realizar commits
automaticamente. Demandas de timeout/checkpoint/sessões/legado ficaram pendentes
por determinação do dono. O acesso amplo permanece autorizado.


## Atendimento por canal ativo — 11/10/2026

Proprietário aprovou substituir o espelhamento permanente por transferência explícita
AlfaMatriz ↔ Telegram, mantendo histórico único. Implementação, testes simulados e
plano de ativação/rollback em [canal-ativo.md](central-ia/canal-ativo.md).
Migração aditiva preparada; NÃO aplicada ao banco existente. Consumidor NÃO reiniciado.
Código compatível com schema anterior enquanto aguarda ativação específica aprovada.
Commits locais autorizados; nenhum push autorizado nesta etapa.
