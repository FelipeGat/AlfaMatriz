> **Histórico.** Este documento descreve a primeira versão (aplicação separada).
> Em 10/10/2026 o dono decidiu outro caminho — ver [README.md](README.md).

# Ativação na conta real — somente AlfaMatriz local

## Objetivo

Usar o AlfaMatriz `http://localhost:8096` (Windows) ou `http://home:8096`
(Mac), e o bot privado existente no celular, sem depender do VS Code.
O navegador é cliente; PHP, banco, Redis, filas e long polling continuam
no computador Windows/WSL/Docker. O computador precisa permanecer ligado,
sem suspensão, com Docker e WSL disponíveis. Fechar VS Code não para os serviços.
As políticas Docker atuais do AlfaMatriz e da Central são `unless-stopped`.
Isso não configura inicialização do Docker no Windows nem impede suspensão.

## Autorização atual

Em 10/10/2026 o proprietário autorizou backup cifrado dos dois bancos locais
e restauração isolada. Não autorizou nesta rodada migração real, vinculação,
reinício dos serviços existentes ou inferência. A ativação abaixo permanece
condicionada aos resultados e à aprovação específica.

## Backup e ensaio

`development/run-real-backup-validation.py` utiliza apenas os clientes já
instalados nos dois contêineres de banco. Usa as credenciais técnicas existentes
internamente, sem ler arquivos de autenticação de agentes nem exibir senhas.
MariaDB usa snapshot transacional e recusa tabelas não InnoDB; PostgreSQL usa
dump custom consistente. Dados claros transitam somente em memória e pipes.

Artefatos usam AES-256-GCM, nonce aleatório e contexto autenticado por banco.
São gravados em `development/runtime/real-backup/artifacts`, com permissões
restritas e fora de Git. A chave nova permanece em diretório separado `keys`,
com permissão 0600; não será exibida ou enviada em mensagens. Esses diretórios
no mesmo computador não protegem contra perda do disco inteiro: armazenamento
externo cifrado e custódia separada da chave precisam de uma etapa posterior.

A restauração usa contêineres temporários sem aplicações, agentes, portas,
montagens do host ou saída da rede interna. Executam como UID 999, rootfs
somente leitura, capabilities removidas, sem privilégios adicionais. Seus
bancos existem somente em tmpfs. Event scheduler MariaDB permanece desativado.
Após comparação de tabelas, contagens e digest do conteúdo, só os recursos
temporários do ensaio são removidos; nenhum banco existente é alterado.

O teste também verifica rejeição de chave incorreta e artefato adulterado.
O relatório contém somente metadados agregados. Um backup de banco não inclui
uploads/anexos de filesystem, Redis ou arquivos de configuração; o inventário
e cobertura desses recursos ainda precisam ser definidos antes da ativação.
Autenticações de agentes não serão copiadas para o backup ou a restauração.

Ensaio real concluído em 10/10/2026: AlfaMatriz com 59 tabelas e 20.699
registros; Central com 19 tabelas e 32 registros. Contagens e digests de
conteúdo conferiram após restauração, e as contagens das origens permaneceram
iguais durante o ensaio. Chave incorreta e adulteração foram rejeitadas.
Todos os recursos temporários foram removidos. Relatório agregado:
`development/reports/real-backup-validation.json`.

Como serviços atuais continuam ativos, mensagens posteriores ao snapshot
permanecem na origem. Nunca substituir o banco atual por uma restauração
anterior durante a ativação. A transição deve usar mudanças aditivas na origem
após backup final e janela de escrita controlada especificamente aprovada.

## Mudança concreta a aprovar depois do ensaio

1. Confirmar ID estável pelo login nativo do AlfaMatriz local. Não usar email,
   nome, perfil administrativo ou ID enviado pelo navegador como autenticação.
2. Aplicar os componentes candidatos revisados ao AlfaMatriz local, com flags
   inicialmente desabilitadas; preservar alterações locais e não modificar main
   por commit, merge ou push. Migração de identidade será aditiva e separada.
3. Conceder somente ao proprietário identificado a permissão específica.
   Não conceder pela grade genérica do perfil Administrador. Fixar ID e UUID
   em política de servidor; todos os demais, inclusive administradores, negados.
4. Configurar novas chaves técnicas de comunicação próprias, sem compartilhar
   APP_KEY, cookies ou sessões. Nenhum segredo transita pelo navegador.
5. Atualizar somente o backend da Central existente, preservando seu usuário,
   mensagens, IDs, vínculos Telegram, cursor, entregas e limites. Ensaiar antes
   o preenchimento e vínculo na restauração, sem consumidor Telegram.
6. Confirmar seu ID numérico Telegram a partir do pareamento preservado e da
   conta autenticada. Manter exatamente um consumidor, sem criar segundo bot.
7. Validar Web -> Telegram, Telegram -> Web, deduplicação, renovação de sessão,
   conta inativa, revogação e recusa de acesso cruzado. Nenhum agente executa.
8. Só então desabilitar a antiga interface web 8100, preservando backend,
   banco, filas, Redis e Telegram. Apresentar a parada específica antes de fazê-la.

## Homologação e recuperação

Após ativação aprovada: uma semana com uso desktop/celular, fechamento do VS Code,
recuperação de rede e reinicializações autorizadas, falhas da autoridade,
deduplicação, segurança e restauração. O acesso móvel ao bot não requer publicar
o AlfaMatriz na internet. Acesso à interface web fora da rede local dependerá
de Tailscale privado em etapa posterior; nenhum túnel público será criado.

Rollback preferencial: desabilitar flags do módulo e impedir novas escritas,
preservando esquema aditivo e dados. Restaurar código anterior compatível
somente após análise do estado; não executar migração destrutiva `down`.
Restauração integral é último recurso e precisa preservar mensagens recebidas
após o backup. Não reenviar entregas Telegram automaticamente.
