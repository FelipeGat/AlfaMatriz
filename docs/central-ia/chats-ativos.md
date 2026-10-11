# Chats Ativos — 11/10/2026

## Objetivo e uso

Separar assuntos em andamento do cadastro de projetos. A aba **Chats Ativos**
vem antes de **Projetos** e lista cada tarefa da Central pelo título, projeto,
agente e estado. Cinco assuntos no mesmo projeto aparecem como cinco chats,
sem criar cinco entradas de projeto. Clique em um para continuar esse assunto.

Use a busca e os filtros de projeto/estado para reduzir a lista. O filtro inicial
inclui tarefas abertas, em execução, aguardando o usuário e com falha. Pausadas
podem ser selecionadas pelo filtro; concluídas continuam disponíveis em
**Projetos → Tarefas**. Para começar outro assunto, abra o projeto e escolha
Nova tarefa. A confirmação do envio abre o chat criado.

Um chat representa uma tarefa da Central, não uma sessão externa recentemente
encontrada no disco. Nenhuma sessão Codex/Claude foi importada ou iniciada.
As entradas alfamatriz e alfamatriz-felipe-central-ia são diretórios distintos
do repositório e da worktree; não foram fundidas nem tiveram seus dados alterados.

## Preservação e segurança

- Histórico filtrado no servidor por proprietário, projeto e tarefa, inclusive
  os estados recentes. Administrador não ganha acesso a mensagens privadas.
- Cada chat tem rascunho, anexos e estado de envio próprios em memória da página.
  Rascunhos não são persistidos após recarregar; mensagens confirmadas permanecem
  no banco. A URL identifica projeto/tarefa para retomar o histórico.
- Resposta de atualização ou envio atrasada não troca o chat selecionado nem
  insere mensagens de outro assunto nele. O compositor fixa o destino no chat.
- A transferência explícita entre Web e Telegram permanece existente; listar
  um chat não transfere automaticamente o canal nem inicia um agente.
- Sem migração, reinício, inferência, mudança de credenciais ou produção.

## Verificação e revisão

132 testes CentralIa passaram, com 755 assertivas; duas depreciações PHPUnit
preexistentes. Cobertura adicionada: cinco assuntos no mesmo projeto, histórico
antigo recuperável por tarefa e bloqueio de acesso cruzado mesmo por administrador.

Testes JavaScript: test-chats-ativos.mjs, test-historico.mjs,
test-canal-ativo.mjs e test-progresso.mjs. Build Vite e git diff --check passaram.
O teste de chats cobre filtros, rascunhos separados, resposta de poll atrasada,
envio atrasado durante troca de chat e destino fixo de envio.

Revisão manual pendente: desktop/celular, temas claro/escuro, lista recolhida,
retomar pela URL e alternar Telegram/Web no chat selecionado. Não foi executado
teste visual automatizado nem uma chamada real de IA para esta entrega.

Rollback: reverter os commits desta entrega e reconstruir os assets. Não há
alteração de esquema nem remoção de histórico para desfazer.
