# Auditoria e ajustes individuais — 11/10/2026

## Produto desejado

Cada conta AlfaMatriz é proprietária das conversas, comandos, regras de escolha,
conexões e consumo. A escolha manual de IA é respeitada. No automático, a Central
seleciona uma IA habilitada conforme atividade e explica o motivo. Desenvolvimento
prefere Claude; análise/revisão/planejamento preferem Codex; relatórios e apoio
preferem Copilot. Os padrões podem ser alterados pelo dono da conta.

O dono autorizou **acesso amplo inicialmente para suas IAs** nesta rodada.
Essa autorização não autoriza compartilhar o HOME, login ou memória de Felipe
com Rossini ou Alexandre. Nenhuma outra conta foi habilitada ou modificada.

## Resultado da análise do código

| Área | Constatação e comportamento |
|---|---|
| Histórico e anexos | Consultas por proprietário. Administração não ignora essa propriedade. |
| Telegram | Pareamento por conta e chat privado, consumidor com trava MariaDB, canal explicitamente transferido. Não houve envio real nesta rodada. |
| Roteamento | Antes global. Agora pode ser configurado por conta, com motivo registrado. É classificação por regras, **não uma recomendação produzida pelo GPT**. |
| Escolha manual | Preservada, sujeita à habilitação e aos limites individuais. Não pode contornar regras com IDs no navegador ou comandos Telegram. |
| Fallback | Mesmo proprietário/tarefa/dossiê. Considera habilitação, orçamento, tentativas já realizadas e falhas reconhecidas. Não cria adaptador pago. Não garante qualidade equivalente entre modelos. |
| Limites | Novos limites administrativos por IA e total diário, incluindo tentativas alternativas após salvar regras próprias. Antes disso, a semântica do teto legado é preservada. Concorrência individual limitada pelo teto do servidor. |
| Tokens | Telemetria local quando disponível, não um medidor completo de todas as assinaturas. Ainda não há reserva e teto técnico por execução. |
| Credenciais | Runner local usa logins do usuário Linux. O banco associa registros ao usuário AlfaMatriz, mas isso **não isola dois usuários usando o mesmo HOME Linux**. |
| Pastas e comandos | `acesso=total` gera bypassPermissions/danger-full-access/allow-all. O diretório de trabalho não é fronteira de segurança. Não há matriz efetiva de autorização por pasta/ferramenta. |
| Memória e sessões | Runner lê memória e sessões locais do HOME; há caminho padrão específico de Felipe. Outro usuário precisa de ambiente próprio e configuração própria antes de ativar. |
| Agenda | Fonte atual lê uma ponte local Google Agenda. Não há autorização granular por usuário/provedor nessa ponte, nem adaptador de relatório de uso do banco AlfaMatriz. Escolher Copilot para relatório não concede dados que ele não recebe. |
| Operações críticas | Com acesso amplo, instruções ao modelo não bloqueiam tecnicamente commit/push/deploy. Aprovação técnica central ainda precisa ser implementada. |
| Modo de análise | Rótulo removido de “só leitura”: com acesso amplo essa promessa não era verdadeira. Modo implementar também não promete worktree quando config permite pasta original. |
| Código instalado | Runner está ativo, mas SHA-256 da cópia instalada difere da fonte. A cópia instalada não contém a remoção de OPENAI_API_KEY/ANTHROPIC_API_KEY/ANTHROPIC_AUTH_TOKEN existente na fonte atual. Não foi atualizada ou reiniciada nesta rodada. Não afirmar que autenticação/cobrança do processo atual foram comprovadas. |

## O que foi implementado

- Tabela aditiva `central_ia_preferencias`, única por `user_id`, só regras
  administrativas, **sem credenciais**. Migração local autorizada e aplicada.
- PUT autenticado/CSRF/permissão editar. Identidade vem da sessão, nunca do corpo.
  Payload validado, campos de outro provedor recusados. Revisão monotônica evita
  sobrescrever ajustes salvos em outra janela. Auditoria registra alteração sem
  conteúdo de credenciais.
- Padrões, habilitação por IA, limites de solicitações, fallback e concorrência
  aparecem em Ajustes e são aplicados no backend compartilhado Web/Telegram.
  Provedores desabilitados não recebem novos pedidos nem trabalho pendente.
  Processos já iniciados não são interrompidos.
- Roteamento de relatório como categoria própria, preferindo Copilot; trocar o
  padrão de análise não modifica as preferências de outras pessoas.
- Arquivamento reversível por seleção de IDs **da conta autenticada**. Não há
  delete: tarefas saem de Chats Ativos e pedidos na fila ficam suspensos. Agentes
  executando são preservados. Reabrir em Projetos → Tarefas libera a fila novamente.
  Nenhuma sessão externa é interrompida ou removida.
- Botão “Arquivar chats antigos” atua sobre a lista inteira de chats carregados,
  independentemente do filtro visual; a confirmação explica o alcance.

## Como usar o Codex no AlfaMatriz

1. Atualizar a página; arquivar os assuntos antigos na sessão autenticada.
2. Projetos → escolher o projeto → Nova tarefa.
3. Selecionar GPT (Codex), escolher Analisar / responder e enviar o pedido.
4. A resposta será registrada no chat novo pelo runner configurado da conta.
5. Para ir ao celular, abrir esse chat e usar Continuar pelo Telegram.

O Codex da Central é uma **sessão diferente desta conversa no VS Code**.
Não transfere automaticamente este histórico ou esta instância. Para continuar
o trabalho, indicar ao agente este documento e os commits da branch Felipe.
O computador/WSL/Docker e runner precisam estar disponíveis; VS Code no Mac não.
Antes de validar o automático como operacional, atualizar a cópia instalada em
janela autorizada, após arquivar pedidos antigos e comprovar ausência de execuções
em curso. Não iniciar inferência para testar sem autorização. A regra do backend
já se aplica ao runner antigo, mas proteções de seu processo não são retroativas.

## Pendências obrigatórias antes de acesso granular/multiusuário

1. Executor por usuário/provedor, com HOME e autenticação próprios, sem cópia
   de logins existentes. Para ambiente compartilhado, contêiner/usuário separado.
2. Recursos concedidos explicitamente por tarefa e comprovados no SO: mounts,
   rede, fontes de dados e ferramentas. Denegar o não concedido; testar escape,
   symlink, IDs cruzados e troca de provedor. Não tratar instrução textual como ACL.
3. Vincular origem de agenda/OneDrive/dados AlfaMatriz à conta e às permissões.
   Sem buscar credenciais no banco ou receber tokens pelo navegador.
4. Ledger de consumo por execução/modelo; validar metadados oficiais disponíveis.
   Não prometer saldo de assinatura ou limite exato quando não observável.
5. Se desejado, GPT como recomendador explícito: chamada auditada e contabilizada,
   sem execução de ferramentas, com validação final da política no servidor.
6. Timeout de ponta a ponta, checkpoint em falhas e proteção de forks/sessões,
   já registrados no documento de continuidade de provedores.
7. Concorrência global por capacidade da máquina: o teto atual é por conta,
   limitado por configuração do servidor, não uma trava global entre contas.

## Ativação e rollback

As fontes servem o ambiente de desenvolvimento. O formulário informa se a tabela
não existe e não afirma ter aplicado ajustes. Antes de ativar persistência real:
backup AES-GCM do único banco local, restauração isolada, ensaio da migração
`2026_10_11_180000_preferencias_individuais_central_ia.php`, aprovação e aplicação
**somente dessa migração** no app local. Não executar migrate geral nem seed.

Comando de ensaio: `python3 tools/central-ia/backup-canal-local.py --migration preferencias`.
O ensaio não escreve no banco de origem nem reinicia os serviços existentes;
artefatos cifrados e chave separada ficam fora do Git em diretório privado.

Ativação local aprovada nesta rodada: ensaio MariaDB verificou 72 tabelas,
integridade lógica e preservação das contagens. Relatório privado em
`central-agentes/development/runtime/real-backup/artifacts/canal-20261011T193140Z-925be0/report.json`.
Aplicação limitada à migração 180000 por `activate-preferencias-local.py --apply
--report <relatório-aprovado>`. Contagens antes/depois idênticas: 1 conversa,
88 mensagens, 9 tarefas, 36 execuções, 67 entregas e 1 pareamento. Nenhuma
preferência real foi criada por script; cada usuário salva pela sessão autenticada.
Os chats reais ainda não foram arquivados por script: o botão autenticado evita
presumir a identidade do proprietário a partir de nome ou e-mail.

Rollback de código: reverter commits desta entrega e reconstruir assets; manter
a tabela adicional intacta evita perda de preferências. Arquivamento: reabrir cada
tarefa, lembrando que isso libera pedidos na fila. Restauração de banco só com
aprovação e definição de corte, pois substituir snapshot perderia mensagens novas.

## Resultados de validação

- Suíte integral final: **1.516 testes / 9.161 assertivas**, sem falhas;
  duas depreciações PHPUnit preexistentes.
- Central IA: **140 testes / 797 assertivas**, incluindo preferências distintas
  para três perfis sintéticos, bloqueio de ID enviado pelo cliente, administrador
  sem permissão, conflitos de revisão, teto zero, reenvio idempotente no último
  lugar da cota, preservação de anexos, arquivamento privado e fila suspensa.
- JavaScript: ajustes, chats ativos, histórico, canal ativo, progresso e erros
  do runner passaram. Build Vite e git diff --check passaram.
- Backup/restauração/ensaio MariaDB concluídos; migração real local limitada à
  tabela adicional, com contagens protegidas antes/depois iguais.
- Não houve inferência, pareamento novo, cópia de credenciais, reinício, push,
  deploy ou ativação de outro usuário. Revisão visual no navegador ainda é manual.
