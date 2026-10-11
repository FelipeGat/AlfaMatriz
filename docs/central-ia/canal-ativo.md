# Atendimento em um canal por vez

## Decisão do proprietário

AlfaMatriz é o canal padrão. Telegram é uma continuação ativada explicitamente,
sem espelhamento permanente. Histórico e contexto permanecem únicos e privados.

## Uso

1. Abra o projeto e, se necessário, selecione a tarefa que deseja continuar.
2. Toque em **Continuar pelo Telegram**. Uma confirmação curta vai ao bot, sem reenvio do histórico.
3. Continue no celular. A web exibe o histórico, mas oculta o compositor enquanto o Telegram está ativo.
4. **Retomar no AlfaMatriz**, ou `/matriz` no bot, retorna o atendimento ao sistema.

O rascunho não enviado permanece no navegador; transferir não o envia nem o armazena no banco.
Execuções em andamento não são canceladas. Novas respostas do projeto/tarefa ativos
são entregues ao Telegram somente quando ele é o canal ativo. Respostas de outros
projetos continuam no histórico, sem invadir a conversa do celular.
Pareamento identifica a conta, mas não ativa o atendimento Telegram.
Desvincular retorna à web; revogar permissão bloqueia acesso e entrega.

## Backend e persistência

- `CanalAtivo`: estado por conversa e proprietário; padrão `web`.
- Migração aditiva `2026_10_11_160000_canal_ativo_da_central_ia.php`: canal, projeto, tarefa e último ID anterior à transferência.
- `POST /central-ia/canal`: autenticação, conta ativa, permissão `central_ia,editar`, CSRF e throttle existentes.
- IDs de tarefa são conferidos pelo proprietário e projeto; nenhum ID de usuário enviado pelo navegador autentica a operação.
- `ConversaService`: revalida o canal sob trava na conta, na gravação; agentes não são disparados pelas confirmações.
- `TelegramCanal`: bloqueia comandos e arquivos de entrada no canal inativo; serializa entrada/entrega com transferência pela trava da conta.
- Uma transferência repetida com o mesmo destino e contexto não gera nova confirmação.
- Entregas anteriores à transferência não são reenviadas. Entregas ainda pendentes no retorno à web ficam marcadas `falhou/canal_inativo`, preservando a mensagem original.
- O envio Telegram em curso termina antes de a transferência devolver sucesso. A transação segura a trava da conta durante essa chamada HTTP: há possível espera limitada pelo timeout da API existente.
- O limite de destinatários continua no consumidor exclusivo já implementado; nenhum segundo consumidor foi criado.

## Publicação do código sem ativação do banco

**Status atual: ativado no AlfaMatriz local em 11/10/2026, após autorização específica.**

Durante a implementação inicial, o banco e o consumidor foram preservados; a ativação descrita abaixo foi executada posteriormente.
A detecção de schema mantém o comportamento anterior até aplicar a migração. Os novos
botões aparecem somente com schema disponível. Isso evita falha de SQL durante a preparação,
e preservou o espelhamento antigo até a ativação. Agora o schema está presente e a exclusividade está habilitada.

Os testes de compatibilidade usam `central_ia.canal_exclusivo=false` somente em laboratório.
O caminho novo é habilitado por padrão com schema presente. Não foi criado um controle
público ou variável de ambiente para usuários desativarem a exclusividade.

## Ativação local, condicionada à aprovação específica

1. Confirmar mounts, projeto Compose e banco `alfamatriz-dev`; jamais usar produção.
2. Fazer snapshot cifrado apenas do banco AlfaMatriz local, com chave fora do Git e dados suprimidos da saída. Não executar cegamente `run-real-backup-validation.py`: o script antigo inclui dois bancos e precisa ser limitado ao banco único atual.
3. Restaurar em MariaDB descartável, comparar integridade e ensaiar esta migração com cópia da mesma versão de código.
4. Aplicar **somente** a migração nova pelo caminho específico; não executar `migrate` genérico que possa incluir outras pendências.
5. Recarregar apenas o consumidor Telegram local autorizado, para ele utilizar a nova verificação antes de disponibilizar a troca de canal ao navegador. Não reiniciar runner, outros projetos ou serviços administrativos.
6. Atualizar a web e verificar início em AlfaMatriz, pareamento preservado, transferência para o celular e retorno sem IA real.

Existe uma janela de ativação entre atualização do schema e recarga do consumidor antigo:
o consumidor antigo não conhece a regra. Coordene a mudança em uma janela curta sem
novos envios/execuções, usando uma parada controlada aprovada do consumidor durante a migração.
A interface não deve ser disponibilizada para troca antes de concluir a recarga.

## Rollback

Antes de qualquer inferência, retornar ao canal web e guardar snapshot cifrado.
Se a ativação falhar, restaurar a versão anterior do código e consumidor em janela aprovada;
manter as colunas extras, sem `migrate:rollback` destrutivo. O código anterior ignora essas
colunas e volta ao espelhamento antigo. Restaurar o backup é último recurso em ambiente
isolado, evitando sobrescrever mensagens novas. Não apagar histórico, pareamento ou entregas.

## Testes

`CanalAtivoTest`: padrão web, ausência de espelhamento, recusa de entrada inativa,
transferência persistente/idempotente, preservação de histórico, resposta em andamento,
retorno por `/matriz`, proteção de tarefa alheia, conta sem permissão, fila antiga,
contexto de projeto/tarefa, desvinculação e revogação.

`node tools/central-ia/test-canal-ativo.mjs`: rascunho, bloqueio do envio inativo,
falha de transferência, bloqueio durante envio pendente e rótulos da origem.

Laboratório PHP sem rede com SQLite descartável. Não chama bot, agentes ou serviços pagos.
Antes da ativação real, validar as travas também em MariaDB restaurado e os fluxos visualmente
no desktop e celular. O teste unitário não substitui ensaio concorrente na versão de MariaDB de destino.

### Resultados desta rodada

- Suíte integral: 1.503 testes, 9.095 assertivas, todos passaram.
- Rodada da Central após ajustes finais: 129 testes, 740 assertivas, todos passaram.
- Verificação final de `CanalAtivoTest`: 14 testes, 63 assertivas, todos passaram.
- Duas depreciações PHPUnit já existentes, sem falhas.
- Build Vite, sintaxe JavaScript, teste do canal ativo e testes de histórico/progresso aprovados.
- A suíte integral começou antes da adição dos dois últimos casos; estes estão incluídos nas rodadas específicas posteriores.
- Sem inferência, Telegram real, migração do banco existente, restart ou push.

Commits anteriores consolidados nesta rodada: `1e52f29` (funcionalidades anteriores e menu),
`29caf74` (schema anterior de anexos/progresso). Backend da troca de canal: `935b517`.


## Ativação executada em 11/10/2026

Autorização do proprietário: ambiente de teste; permitido aplicar a ativação e reiniciar serviços necessários.

- Backup cifrado AES-GCM apenas do banco `alfamatriz-dev-db`; chave separada, arquivos 0600 e diretórios privados, fora do Git.
- Script reproduzível: `tools/central-ia/backup-canal-local.py`. Não usa o script antigo de dois bancos.
- Ensaio: `canal-20261011T185612Z-499969`, relatório privado em `central-agentes/development/runtime/real-backup/artifacts/`.
- Restauração validada por hash dos INSERTs do snapshot único; 72 tabelas e contagens preservadas após a migração ensaiada.
- Primeira tentativa descartável foi interrompida por lentidão dos commits individuais. O script foi corrigido para restaurar em transações por tabela; a segunda tentativa passou. Ambos os bancos temporários foram removidos.
- Consumidor `alfamatriz-dev-telegram` parado durante a migração específica e iniciado após a verificação. App, banco, nginx, runner e outros projetos não foram reiniciados.
- Hash conjunto de mensagens e pareamento antes/depois da migração idêntico. 88 mensagens e 1 pareamento preservados.
- Schema pronto; todas as conversas iniciaram com canal web. Nenhuma transferência real ou mensagem de teste ao bot foi enviada pela ferramenta.
- Consumidor iniciado, sem encerramento seguro detectado nos logs resumidos; aquisição de segunda trava recusada.
- HTTP local: `/login` = 200; `/central-ia/painel` sem sessão = 401.
- Backup mantido; relatório atualizado com a comprovação da ativação. Nenhuma chave ou conteúdo de mensagens no Git.
- Sem inferência, API paga, push ou alteração de produção.

### Validação pelo proprietário

Atualizar a página do AlfaMatriz local (`http://localhost:8096` no Windows ou `http://home:8096` no Mac),
abrir a Central, escolher projeto/tarefa e tocar em **Continuar pelo Telegram**.
Conferir a confirmação curta no bot e retornar por `/matriz` ou **Retomar no AlfaMatriz**.
Essa confirmação e o retorno são operações da Central, sem inferência. Mensagens de trabalho
enviadas depois pelo usuário seguem a configuração normal dos agentes.
