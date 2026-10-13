> **Histórico.** Este documento descreve a primeira versão (aplicação separada).
> Em 10/10/2026 o dono decidiu outro caminho — ver [README.md](README.md).

# Identidade multiusuário — primeira etapa

## Escopo implementado em desenvolvimento

Código candidato em `development/multiuser`; aplicação em execução e AlfaMatriz
preservados. A cópia reaproveita a Central existente e suas migrações, serviços,
rotas e testes. Não houve inferência, API paga, alteração de produção, commit ou push.

A identidade Central possui associação única `(issuer, subject)` ao AlfaMatriz,
estado e geração de sessão. Cada leitura, envio, execução, pareamento e operação
de integração exige autorização atual da fonte oficial. Falha da autoridade
retorna erro genérico e bloqueia acesso; não existe fallback para administrador
local, permissões antigas ou senha da Central.

Mensagens, execuções, entregas, integrações e sessões possuem proprietário.
Conversas e vínculos mantêm o usuário existente. APIs selecionam registros pelo
proprietário autenticado; IDs de outros usuários retornam 404. Não existe acesso
especial a conteúdo privado por ser administrador. Campos de autoria e propriedade
enviados pelo cliente são ignorados. Compartilhamento ainda não está habilitado.

Integrações possuem provedor, geração, estado e referência opaca exclusiva para
credencial. Não armazenam tokens, cookies, senhas ou arquivos de autenticação no
banco. Sessões exigem a mesma propriedade da integração e da conversa. Revogação
individual invalida sessões e execuções pendentes; mudanças de autorização são
reverificadas antes de executar e antes de publicar uma resposta.

Telegram exige conversa privada com remetente igual ao chat, código de uso único
e confirmação posterior pela mesma conta autenticada na Web. Códigos e confirmações
expiram em cinco minutos e são vinculados à geração da sessão. O pareamento antigo
é preservado pelo preenchimento de propriedade, sem criar novo vínculo.
Atualizações duplicadas não criam novas mensagens. Usuário sem autorização não
produz mensagem; sua atualização proibida é consumida para não bloquear os demais.
Indisponibilidade temporária da autoridade mantém a atualização para nova tentativa.

Permissão de enviar não concede permissão de executar agente. O candidato permanece
com agentes desabilitados e modo exclusivamente simulado. Limites persistentes:
20 admissões/dia globais e 20/usuário, com deduplicação e bloqueio transacional.
Bloqueios de execução global e individual permitem uma execução simultânea;
timeout do executor permanece 120 segundos. Timeout do worker é 125 segundos e
expiração do bloqueio é 150 segundos, para não liberar concorrência antes do executor.
Resposta de agente não agenda outra execução.

Auditoria registra eventos e IDs permitidos, sem mensagens ou referências secretas.
Exceções da autoridade não preservam seu corpo, URL ou exceção anterior nos erros.
Isso não substitui o controle de acesso aos backups, logs e infraestrutura.

## O que ainda não foi ativado

- Ativação do endpoint de autoridade e fluxo SSO no AlfaMatriz original: implementação
  e testes nas cópias concluídos na etapa seguinte, documentada em identity-integration.md.
- Vinculação da conta real de Felipe, migração ou preenchimento do banco existente.
- Provisionamento e autenticação de executores por usuário/provedor.
- Adaptador CLI, modelo real, credenciais reais, recursos compartilhados e módulo visual.

O contrato exige resposta com `issuer`, `subject`, `active`, `ready`,
`session_epoch` inteiro e lista `operations`. O AlfaMatriz deve produzir essa
resposta a partir de suas permissões, usando autenticação técnica nova e restrita.
Não reutilizar APP_KEY, senha de usuário, tokens pessoais ou autenticação de agentes.
O transporte inicial usa HTTPS, não segue redirecionamentos e não repete requisições.
A etapa seguinte validou o mecanismo de identidade do AlfaMatriz de desenvolvimento
e a sessão Central por ticket curto e de uso único, com contas sintéticas; a identidade
real de Felipe e a ativação no ambiente existente continuam pendentes.

## Evidências e limitações dos testes

Testes executados com PHP 8.4.26 / PHPUnit 12.5.38, SQLite em memória e PostgreSQL 17
descartável. Todos os usuários, conteúdos, respostas e pareamentos são sintéticos.

Resultado final em 10/10/2026:
- SQLite: **56 testes / 266 asserções**, aprovados, 4,259 segundos.
- PostgreSQL: **56 testes / 266 asserções**, aprovados, 6,678 segundos.
- Laravel Pint: verificação final aprovada, 71 arquivos.
- Central original: consulta somente de leitura `GET /up` na porta 8100 retornou HTTP 200.
- Serviços PostgreSQL/testes descartáveis foram encerrados pelo Compose após a suite.

Cobertura: acesso cruzado por ID, administrador sem privilégios sobre conteúdo,
propriedade forjada, três usuários, revogação de permissões e gerações de sessão,
pareamento individual e confirmação autenticada, expiração e repetição, entregas
inconsistentes recusadas, propriedade de integrações/sessões, limites globais e
individuais, bloqueios ocupados, autoria e ausência de loops, falha de autoridade,
erros sanitizados, auditoria restrita e preenchimento idempotente de legado.

A preservação de histórico foi comprovada com fixtures: IDs, texto, vínculo e cursor
mantidos durante vinculação/preenchimento repetidos. Não representa restauração
ensaiada do banco real. Testes de locks ocupados não representam teste de carga
concorrente com múltiplos workers Redis reais; esse ensaio precederá a ativação.
Não foi feita inspeção de credenciais reais ou garantia absoluta sobre logs históricos.
Nenhum backup real foi criado; o plano seguinte precisa ser executado e verificado.

## Plano de backup e restauração — antes de alterar dados existentes

1. Confirmar ambiente, serviços e banco exclusivos da Central e a versão exata do
   código. Confirmar o ID estável da conta AlfaMatriz de Felipe em desenvolvimento;
   nunca atribuir propriedade apenas por nome ou email recebido do cliente.
2. Preparar destino restrito, fora de Git e dos diretórios expostos pela aplicação,
   permissão 0700 e arquivos 0600; definir cifragem e gestão da chave fora do backup.
   Não enviar conteúdo de dados, segredos ou dump a terminal, chat ou logs.
3. Pausar somente os produtores/consumidores da Central: Web em manutenção e filas
   e long polling parados. Não parar AlfaMatriz ou outros projetos. Registrar
   estados e contagens, cursor Telegram e entregas pendentes/ambíguas sem conteúdo.
4. Gerar dump PostgreSQL consistente em formato custom diretamente para cifragem,
   usando credenciais já disponíveis ao serviço e sem passá-las na linha de comando.
   Conservar esquema, migrações, mensagens, vínculos, auditoria, runs e contadores.
   Não copiar nem montar volumes de autenticação dos agentes; eles permanecem intactos.
5. Restaurar em banco novo e isolado, com Telegram, rede externa e agentes bloqueados.
   Conferir checksum do artefato, integridade, contagens, IDs, vínculo e cursor. Os
   conteúdos privados podem ser comparados por digests dentro do ambiente, sem exportar
   texto. Só liberar a mudança após comprovar a restauração.
6. Ensaiar nesse banco a migração aditiva, `OwnershipBackfill` e `IdentityLinker`.
   O preenchimento é transacional, recusa conflitos e altera apenas proprietários
   nulos. A associação conserva o ID Central, histórico, vínculo e cursor existentes.
7. Reconciliar quotas e execuções legadas: preservar contador global e atribuir
   uso histórico individual comprovável, sem reiniciar limites. Runs antigos sem
   identidade/geração válida não devem executar; entregas ambíguas não são reenviadas.
8. Apresentar resultado do ensaio e mudança concreta antes de executar no banco real.
   Reativar somente serviços Central e validar canais e autorização após a mudança.

## Implantação incremental e rollback

1. Aprovar este candidato e o plano de backup/restauração; manter ambiente atual ativo.
2. Implementar autoridade e SSO exclusivamente em cópia de desenvolvimento do AlfaMatriz.
   Testar assinatura/autenticação, ticket de uso único, expiração, audiência,
   alteração de permissões, sessões e indisponibilidade da Central sem derrubar AlfaMatriz.
3. Ensaiar restauração e vinculação de Felipe antes de qualquer preenchimento real.
4. Aplicar de forma controlada somente após validação; preservar pareamento antigo.
   Testar isolamento no ambiente integrado, inclusive workers concorrentes e revogação.
5. Preparar módulo visual e inclusão individual dos demais usuários. Só depois:
   executores independentes com autenticação própria, CLI e eventual teste real autorizado.

Rollback preferencial: pausar acesso ao módulo e workers, manter esquema aditivo e
dados e restaurar versão compatível com os controles de propriedade. A migração
recusa `down()` destrutivo. Antes de existir qualquer segundo usuário, pode-se
reverter para o funcionamento mono-usuário preservando os campos adicionais.
Após inclusão de outros usuários, não expor novamente código antigo sem isolamento.
Restauração integral é último recurso: arquivar cifrado o estado posterior à mudança,
restaurar snapshot verificado e reconciliar mensagens posteriores para não descartá-las.
Mensagens já entregues ao Telegram não são revertidas nem reenviadas automaticamente.

## Riscos residuais e critérios de ativação

Autorização de aplicação impede acesso automático de administradores; acesso root
ao host ou acesso privilegiado ao banco continua exigindo governança própria.
Uma entrega externa já em trânsito não pode ser desfeita por revogação posterior.
Falha da autoridade bloqueia a Central por segurança; AlfaMatriz deverá continuar
operando independentemente do WSL2. O bot único é credencial técnica compartilhada
do transporte, não credencial ChatGPT/Claude; nunca autoriza acesso por si só.

A referência opaca é apenas preparação: armazenamento secreto e montagem exclusiva
por usuário/provedor ainda precisam ser implementados e testados. Não usar a
autenticação atual de Felipe para Rossini ou Alexandre. Não habilitar inferência
nem fallback pago como parte desta etapa.
