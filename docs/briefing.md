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


### Atualização: canal ativo habilitado localmente

Após nova autorização específica, migração 160000 aplicada somente no AlfaMatriz local;
consumidor Telegram parado/iniciado na janela controlada. Backup de banco único cifrado
e ensaio de restauração/migração aprovados. Histórico (88 mensagens) e pareamento (1)
preservados por hash; canal inicial web. Sem inferência, push ou produção. Detalhes e
validação manual pendente no documento `central-ia/canal-ativo.md`.

### Atualização: Ajustes individuais e arquivamento — 11/10/2026

Regras de escolha, habilitação, fallback e limites de solicitações passaram a ser
configuráveis por usuário. Migração 180000 autorizada e aplicada só no dev local,
após backup AES-GCM e ensaio MariaDB isolado. Contagens: 88 mensagens, 9 tarefas,
36 execuções e 1 vínculo Telegram preservados. Nenhum serviço reiniciado ou IA
executada. Arquivamento reversível exige ação na sessão autenticada e suspende
fila sem apagar mensagens ou interromper processos. Ver `central-ia/ajustes-individuais.md`.

Autorização mais recente: acesso amplo inicial às IAs do Felipe. Não habilitar
Rossini/Alexandre com o mesmo HOME/credenciais. Roteador automático atual usa
regras locais, não consulta GPT. Isolamento de executores, concessões por recurso
e limites exatos de tokens continuam pendentes; não afirmar que a tela os garante.
