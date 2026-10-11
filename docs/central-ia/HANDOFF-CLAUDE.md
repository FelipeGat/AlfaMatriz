> **Histórico.** Este documento descreve a primeira versão (aplicação separada).
> Em 10/10/2026 o dono decidiu outro caminho — ver [README.md](README.md).

# Transferência para Claude Code — Central de IA no AlfaMatriz local

Data: 10/10/2026. Proprietário: Felipe. Este documento é o roteiro para
validar a entrega do Codex e continuar a implementação com segurança.
Leia o documento inteiro antes de alterar código ou executar comandos.

## 1. Objetivo do produto

Felipe quer uma central de trabalho com múltiplos agentes de IA dentro do
AlfaMatriz, acessível pela interface web e pelo Telegram, com **uma mesma
conversa e um único histórico persistente**. Sincronização de mensagens é a
infraestrutura inicial; o produto final inclui planejamento, implementação,
revisão, tarefas, handoffs e aprovações humanas.

O AlfaMatriz local será a única interface web. A aplicação web independente
da Central será aposentada somente depois da transferência e recuperação
comprovadas. Não criar outra plataforma administrativa: autenticação,
usuários, permissões, tarefas, agenda e layout pertencem ao AlfaMatriz.

**Decisão mais recente: um único banco MariaDB do AlfaMatriz.** Conversas,
mensagens, Telegram e execuções deverão residir em tabelas de domínio nesse
banco. Redis pode continuar como infraestrutura de filas e locks. Executores
de IA continuam isolados; banco único não significa acesso dos agentes ao
banco administrativo.

Felipe precisa usar o AlfaMatriz no navegador do Mac sem manter o VS Code
aberto, e conversar pelo bot no celular. Windows/WSL/Docker precisam estar
ligados e disponíveis. Fechar VS Code não deve interromper os serviços.

## 2. Arquitetura de destino

```text
Navegador Windows/Mac -> AlfaMatriz Laravel -> MariaDB único
                                  |
                         domínio central_ia_*
                                  |
                          Redis / filas dedicadas
                         /                      \
              Telegram long polling      executores isolados
              um consumidor do bot       por usuário/provedor
```

O bot é `@GrupoSolucoesAgentBot`. Ele deverá apontar para a mesma conversa
do módulo. Não confundir com outros bots administrativos/MCP/alertas já
existentes no ecossistema Alfa. Não consultar, copiar ou expor seus tokens.

Papéis futuros: Codex/GPT para planejamento, arquitetura, revisão e QA;
Claude para implementação complexa e manutenção; modelos econômicos para
rotinas simples; humano para operações críticas. OneDrive e Google Agenda
são posteriores ao MVP. Não implementá-los agora.

## 3. Ambientes e Git — onde trabalhar

| Caminho/URL | Estado e finalidade |
| --- | --- |
| `/home/duque_de_caxias/projetos/alfamatriz-felipe-central-ia` | Checkout separado de desenvolvimento, branch local `Felipe`; trabalhar aqui. |
| `/home/duque_de_caxias/projetos/alfamatriz` | Checkout em `main`, base `1e2bbb9`, usado pelo AlfaMatriz local existente; preservar. |
| `http://localhost:8096` | AlfaMatriz existente, acessado no Windows. |
| `http://home:8096` | Mesmo AlfaMatriz, acessado pelo Mac na rede atual. |
| `/home/duque_de_caxias/projetos/central-agentes` | Aplicação e serviços legados, candidatos e laboratórios; preservar. |
| `http://localhost:8100` | Interface web antiga da Central, ainda preservada. |
| `https://localhost:8110/central-ia` | Candidato persistente separado com banco e usuários fictícios; não aceita a conta real de Felipe. |

Commit de referência: **`96d7be6` — Registra candidato local da Central de IA
e plano de banco único**. Este handoff é acrescentado depois desse commit.
A branch local `Felipe` recebeu por fast-forward os 200 commits da `main`
local até `1e2bbb9`, antes da entrega. Não houve push. Não afirmar que esse
código está no GitHub ou que as referências remotas estão atualizadas.

Não trocar a branch do checkout servido na porta 8096. Não descartar arquivos
ou alterações de outras sessões. O checkout original tinha `.composer/` e
`.config/` não rastreados; não pertencem a esta entrega e foram preservados.
Referências antigas ao Windows/XAMPP são históricas: o serviço 8096 foi
localizado montando o repositório WSL `/projetos/alfamatriz` em `/var/www`.

## 4. O que já foi entregue

- Interface nativa Blade/Alpine, menu condicionado, histórico paginado,
  autoria, estados de entrega, atualização automática e reconexão.
- Envio com UUID idempotente; reenvio de mensagem ambígua conserva o UUID.
- Política exclusiva por ID estável e UUID de identidade, conta ativa e
  permissão específica. Administradores não recebem exceção de privacidade.
- Contrato de identidade, tickets curtos de uso único e grants somente entre
  servidores, pertencentes à arquitetura anterior de duas aplicações.
- Referência do backend anterior em `tools/central-ia/reference/backend`,
  incluindo mensagens, Telegram, propriedade, limites e testes.
- Documentação de evolução, transição, backup, recuperação e banco único.
- Ferramentas de reprodução dos testes e do ensaio de backup autorizado.

**Importante:** o código de `CentralBackend` e dos tickets ainda corresponde
à arquitetura anterior. Não está concluída a adaptação para banco único.
Não ativar esse transporte como resultado final da nova arquitetura.
A referência do backend serve para reaproveitar domínio e testes; não é
autorização para instalar outra aplicação ou iniciar outro consumidor.

As flags de UI/integração estão desabilitadas por padrão. A política sem
identidade confirmada nega acesso. A conta real de Felipe ainda não foi
vinculada, e o menu Central de IA ainda não foi ativado no AlfaMatriz 8096.
Não interpretar o login feito no navegador como confirmação técnica do ID:
o executor não recebeu prova server-side daquela sessão.

## 5. Segurança e autorizações obrigatórias

### Identidade e privacidade

Somente a conta real de Felipe terá acesso inicialmente. Rossini, Alexandre
e qualquer outro usuário, inclusive administradores, devem ser recusados no
menu, rotas, APIs, histórico, Telegram, integrações e execuções.

Confirmar o ID pela autenticação nativa no servidor. Email, nome, perfil ou
ID enviado pelo navegador não autenticam. Não autorizar uma fixture por
coincidir seu ID com o de outro banco. Exigir conta ativa, permissão específica
e política de proprietário; manter preparação multiusuário sem habilitar
outros usuários. Compartilhamento futuro somente mediante concessão explícita.

Cada registro deve ter proprietário. Validar autorização em toda operação,
inclusive jobs, Telegram e publicação de respostas. Revogação deve invalidar
acesso e sessões; não utilizar cache como fallback para permissão revogada.

### Credenciais, agentes e projetos

Não compartilhar autenticações ChatGPT/Claude entre usuários. Não copiar
HOME, sessões ou arquivos de autenticação dos agentes existentes. Não montar
repositórios, credenciais pessoais, bancos de produção ou socket Docker nos
executores. Acesso futuro a projetos é por tarefa e escopo explícito, preservando
instruções, sessões e alterações Git. Não habilitar ferramentas irrestritas.

Não exibir segredos em logs, chat, erros, argumentos de processo, relatórios
ou Git. Não usar a senha que apareceu em uma captura anterior; não está
registrada neste documento. Configuração de segredo deve ser local e oculta.

Autenticação ChatGPT é a prioridade; assinatura não equivale a crédito de
API. Não usar API paga ou fallback automático. Autenticação Claude Max pelo
Agent SDK e seus custos ainda precisam de validação oficial; não presumir
cobertura da assinatura. Não executar inferência nesta continuidade sem
aprovação específica e controles comprovados.

### Limites e operações críticas

Uma execução simultânea, timeout de 120 segundos e 20 solicitações diárias.
Preservar quotas individuais e globais, idempotência, autoria, estado, erros
sanitizados e consumo quando disponível. Respostas de agentes/ferramentas e
eventos de entrega não podem disparar novas execuções automaticamente.

Produção, push, publicação, exclusões, migrações reais, mudanças de credenciais,
vinculação real e reinícios dos serviços existentes continuam dependendo de
autorização específica. O consentimento para registrar esta entrega no Git
não autoriza esses atos. Não presumir autorização de commits futuros fora
do escopo aprovado. Trabalhar e testar em ambiente isolado é o caminho atual.

Não ativar scheduler ou filas genéricas do AlfaMatriz local: seu banco é uma
cópia que contém integrações reais. Preservar os bloqueios atuais de hosts e
Telegram administrativo. Planejar um worker dedicado exclusivamente à Central.

## 6. Evidências — validar, não apenas confiar

Última suíte no checkout de entrega: **1.409 testes aprovados, 8.541 asserções,
zero falhas/erros, duas depreciações preexistentes**. PHP 8.2.30, SQLite em
memória, contêiner sem rede, código somente leitura. Os 34 testes filtrados
por `Central` também passaram: 197 asserções.

O bootstrap de laboratório em `tools/central-ia/testing/TestCase.php` exige
SQLite descartável, impede HTTP não simulado e desativa Vite nos testes PHP.
Ele foi montado sobre o TestCase padrão somente no contêiner. A aprovação
não comprova integração real com a conta/banco em uso nem execução real de IA.

Na primeira tentativa, o `/tmp` sem execução fez testes antigos de deploy
falharem. A repetição com `exec` somente nesse tmpfs passou integralmente.
Storage/cache permaneceram `noexec`. Não transportar essa concessão para
executores de IA. Os scripts de deploy executados pela suíte eram simulações
em ambiente sem rede; nenhum deploy real foi feito.

Reprodução, usando imagem/vendor já disponíveis, sem instalar dependências:

```bash
cd /home/duque_de_caxias/projetos/alfamatriz-felipe-central-ia
git status --short
git branch --show-current
git log -3 --oneline
python3 tools/central-ia/run-tests.py \
  --central-root /home/duque_de_caxias/projetos/central-agentes
```

Não executar testes por padrão no contêiner/banco real da porta 8096.
Leia a ferramenta antes de usá-la e confira suas montagens e ambiente.

Outras evidências históricas: isolamento de três usuários fictícios,
administrador recusado, carga curta de 30 leituras com seis simultâneas,
persistência de dados após reiniciar somente os seis novos contêineres de
desenvolvimento, suites do backend em SQLite/PostgreSQL e contrato HTTPS.
Consultar os relatórios; não confundir esses ensaios com homologação real.

### HTTP 503

Reprodução controlada confirmou: um worker PHP no AlfaMatriz esgota a
capacidade no ciclo Alfa -> Central -> autoridade Alfa, causando timeout/503.
Com oito workers, a mesma verificação respondeu 200. Isso não identifica
com certeza a causa do incidente histórico. A arquitetura de banco único
deve remover esse ciclo desnecessário. Validar regressão no destino local.

### Investigação de Codex

No legado, EROFS foi investigado e resolvido sem tornar todo o filesystem
gravável. A investigação posterior de `account/read` terminou com `-32603`
no isolamento estrito, inclusive na última validação controlada de rede.
O usuário determinou encerrar esse método nesta versão; adaptador Codex CLI
com autenticação ChatGPT é a alternativa a avaliar depois. Não reiniciar
rodadas exploratórias do App Server ou inferência automaticamente. Consultar
os documentos originais em `central-agentes/docs/specs/fase-2/` antes disso.

## 7. Backup e recuperação já ensaiados

O usuário autorizou explicitamente backup cifrado dos dois bancos locais e
restauração isolada. O ensaio passou:

| Banco de origem | Tabelas | Registros |
| --- | ---: | ---: |
| AlfaMatriz MariaDB | 59 | 20.699 |
| Central PostgreSQL | 19 | 32 |

AES-256-GCM com chave separada, permissões restritas, nenhum arquivo de dump
claro no host. Restauração em tmpfs, rede interna isolada, sem aplicações,
agentes, montagens do host ou portas publicadas. Contagens e digests conferiram;
chave incorreta e adulteração foram rejeitadas. Os recursos temporários foram
removidos. Origens não foram migradas e serviços não foram reiniciados.

Relatório: `docs/central-ia/evidencias/real-backup-validation.json`.
Artefatos e chaves estão fora de Git, em diretórios distintos sob
`central-agentes/development/runtime/real-backup/`. Não exibir, mover para
o checkout, incluir em commits ou enviar seus conteúdos. Chaves e backups
no mesmo computador não cobrem perda do disco: custódia externa ainda pendente.

Esse backup cobre bancos, não todos os anexos, Redis ou configurações. Fazer
inventário e definir cobertura antes da ativação. Não copiar autenticações
de agentes. O snapshot não contém mensagens posteriores: nunca substituir
dados atuais por backup antigo descartando mensagens novas.

## 8. Próximos passos — ordem recomendada

### A. Auditoria da entrega

1. Ler instruções aplicáveis do repositório e `docs/central-ia/README.md`.
2. Conferir branch, commit, estado Git e isolamento do checkout.
3. Revisar autorizações, consultas por proprietário, fronteiras de jobs,
   idempotência, logs, limites e flags desabilitadas.
4. Reproduzir os testes de forma isolada; registrar falhas existentes e
   introduzidas separadamente. Verificar dependências conforme lock.
5. Informar a Felipe resultado, riscos e mudanças propostas. Não afirmar
   que o módulo já funciona com sua conta ou com histórico Telegram real.

### B. Implementação do banco único em desenvolvimento

1. Inventariar schemas existentes sem imprimir dados privados/credenciais.
2. Projetar tabelas `central_ia_*`, usando `users` nativo. Não importar contas
   da Central, hashes de senha, cookies, APP_KEY ou sessões de login.
3. Reaproveitar domínio/testes do backend de referência. Evitar colisões de
   tabelas, cache, migrations, jobs e auditoria com o AlfaMatriz.
4. Adaptar interface para serviços locais Laravel, substituindo o BFF/tickets
   entre aplicações. Manter autorização e privacidade server-side.
5. Preparar importador idempotente com referências legadas únicas. Transferir
   conversas, mensagens, autoria, vínculos, cursor, entregas, auditoria,
   execuções e quotas; mapear IDs e proprietários explicitamente.
6. Usar fixtures primeiro. Ensaiar posteriormente somente nas restaurações
   descartáveis autorizadas, sem aplicações ou conexões ao Telegram.
7. Conferir texto via digests, datas/fusos, JSON, tipos, UUIDs, ordenação,
   constraints, contadores e ausência de duplicação em importação repetida.
8. Testar isolamento, administração, revogação, ID manipulado, reconexão,
   falha de serviços, envios ambíguos e concorrência proporcional ao uso.

### C. Proposta de ativação real — parar para aprovação

Apresentar arquivos, resultados, cobertura de backup, identidade ainda a
confirmar, janela de escrita, serviços afetados e rollback. Só depois de
aprovação específica: confirmar ID autenticado de Felipe, conceder permissão
exclusiva, migrar aditivamente, preservar pareamento/cursor e trocar o único
consumidor. Não modificar token ou criar novo bot por conveniência.

Não executar simultaneamente o consumidor antigo e o novo. Verificar entregas
pendentes/ambíguas antes de retomar; não reenviar automaticamente mensagens
já entregues. Preservar PostgreSQL, diretórios, volumes, backups e configurações
até comprovação de transferência e recuperação.

### D. Homologação e agentes — etapas seguintes

Após ativação aprovada, iniciar uma semana de uso desktop/celular, fechamento
do VS Code, quedas de rede, reinicializações autorizadas, deduplicação,
revogação, estabilidade e recuperação. Acesso externo à web poderá usar
Tailscale privado posteriormente; não publicar portas na internet.

Depois: adaptador de agente com autenticação individual oficialmente suportada,
simulações primeiro, bloqueios de ferramentas/rede/filesystem comprovados,
chamada única real somente com aprovação específica. Não habilitar respostas
automáticas permanentes por consequência de um teste.

## 9. Critérios de aceite e rollback

- Felipe entra no AlfaMatriz local e vê Central de IA; outros usuários não.
- Web e Telegram usam exatamente a mesma conversa no MariaDB único.
- Histórico e pareamento antigos conservados, sem duplicações/reenvios.
- Fechar VS Code não interrompe Web, filas ou long polling.
- AlfaMatriz administrativo continua funcional se agentes estiverem offline.
- Nenhuma credencial pessoal fica no banco, navegador, Git ou logs.
- Isolamento, revogação, quotas e recuperação comprovados com evidências.

Rollback preferencial: desabilitar flags/acesso e novas escritas, preservar
schema aditivo e dados, reconciliar mensagens posteriores antes de retomar
versão anterior. Não executar `down` destrutivo, apagar origens ou restaurar
snapshot sobre dados novos. Operações reais de rollback exigem aprovação.

## 10. Como entregar a próxima etapa

Apresentar o que mudou, arquivos, testes/asserções, falhas, riscos e pendências.
Distinguir candidato, ensaio e ativação real. Atualizar documentação e handoff
com decisões novas, sem apagar o histórico das anteriores. Não executar push,
deploy, inferência, migração real ou reinício existente como passo implícito.

**Primeira ação esperada do Claude:** revisar `96d7be6` e este documento,
validar o candidato em laboratório e preparar a adaptação para banco único.
O objetivo imediato não é ligar o módulo a qualquer custo: é tornar a entrega
segura e verificável para a ativação local de Felipe, preservando seus dados.

## Governança administrativa por usuário — 11/10/2026

Filtro de metadados em Atividade e cotas por conta em Ajustes implementados. Consulte `governanca-usuarios.md`. Não habilitar Alexandre com o runner amplo atual: execução de contas não administrativas bloqueada no backend até comprovar isolamento individual. Não confundir preferências de provedores com permissão de deploy/OneDrive. Nenhuma política real foi gravada automaticamente nesta etapa.
