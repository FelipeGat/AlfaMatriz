# Continuidade entre provedores e exclusividade Telegram — 11/10/2026

## Pedido e decisões de Felipe

O acesso amplo aos projetos foi autorizado conscientemente pelo dono. Não voltar
ao executor restrito nem tratar essa decisão como regressão não autorizada.
A finalidade é trabalhar pela Central e Telegram, sem depender do VS Code,
aproveitando as assinaturas disponíveis com continuidade e qualidade.

O log apresentado mostrou dois problemas independentes: o Claude recusou a
franquia semanal e o teto administrativo de 20 pedidos da Central bloqueou
inclusive pedidos ao Codex e Copilot. Trocar de agente não contornava esse teto.

Nesta entrega: corrigir o consumidor Telegram, tornar o teto interno opcional e
implementar handoff automático quando um provedor reconhecidamente não atende.
Timeout completo, checkpoint em falhas, sessões/forks e desligamento do legado
ficam como demandas, conforme solicitado. Não houve commit, push, inferência,
migração real, instalação ou reinício dos serviços existentes nesta entrega.

## Implementação

### Telegram: conexão exclusiva MariaDB

`TelegramConsumerLock` substitui a trava de cache de 60 segundos com proprietário
igual ao hostname. Usa `GET_LOCK` numa conexão exclusiva e mantém a trava durante
toda a vida do consumidor. Processos diferentes no mesmo hostname têm conexões
diferentes e não retomam a trava um do outro. Não existe TTL a renovar.

Antes de chamar a API, processar updates, avançar o cursor e guardar mídia depois
do download, o consumidor verifica a propriedade com `IS_USED_LOCK` e
`CONNECTION_ID`. Utiliza o PDO original diretamente, sem reconexão automática:
perder a conexão encerra o consumidor. Parada normal libera a trava; queda do
processo fecha a conexão e libera a trava no servidor.

MariaDB/MySQL é obrigatório para o consumidor real. Em SQLite, o comando recusa
iniciar; testes funcionais simulam essa dependência, e há ensaio MariaDB separado.
Não usa token do bot no nome da trava nem registra conteúdo de exceções.

Limites: coordena consumidores que utilizam o mesmo banco da Central. Um processo
externo/legado que ignora esse contrato continua sujeito ao 409 do Telegram.
Não iniciar outro consumidor do bot antigo. Uma conexão de banco interrompida
pode demorar a ser reconhecida pelo servidor; nesse intervalo o sucessor espera
a liberação, em vez de assumir simultaneamente.

### Limite interno versus franquia das assinaturas

`CENTRAL_IA_LIMITE_DIARIO_ATIVO` tem padrão `false`. O valor histórico
`CENTRAL_IA_LIMITE_DIARIO=20` só bloqueia quando a nova opção está ligada.
A interface mostra "sem teto interno" e mantém os indicadores das franquias.
O paralelismo continua em dois; não houve mudança no timeout.

O contador de pedidos conta mensagens de usuários. Tentativas de recuperação
não consomem outro pedido desse teto administrativo, mas consomem a franquia
real da IA quando chegam ao provedor. O painel por agente continua contando
execuções, incluindo tentativas, para tornar esse consumo visível.

### Handoff automático

1. A conclusão com erro é registrada e mantém seu ID, resposta e estado terminal.
2. Somente erros reconhecidos de franquia/rate limit/capacidade/indisponibilidade
   podem iniciar recuperação. Timeout, login, interrupção, permissões e erro de
   projeto não repetem uma implementação automaticamente.
3. A próxima IA segue a ordem já existente para a categoria da tarefa:
   implementação Claude → Codex → Copilot; revisão/planejamento/investigação
   Codex → Claude → Copilot; apoio Copilot → Codex → Claude.
4. Usa apenas máquinas online da mesma conta que atendem ao projeto, exclui
   provedores esgotados e os já tentados na cadeia. Um provedor por tentativa;
   no máximo os três executores atuais, sem retornar ao primeiro.
5. Preserva proprietário, tarefa, projeto, modo, instrução original, fontes e
   dossiê. O sucessor recebe o checkpoint existente e as instruções de handoff
   para conferir o estado real dos arquivos antes de alterar.
6. Um aviso de sistema na mesma conversa serve como pedido técnico da tentativa
   seguinte. A mensagem original e seus anexos não são movidos ou duplicados;
   o job usa o `pedido_original` validado dentro da conta.
7. A tentativa seguinte possui ID próprio. Reenviar a conclusão anterior não
   cria outra tentativa nem conclui indevidamente a execução do sucessor.
8. Sucesso encerra a cadeia. Sem alternativa, publica aviso e mantém a tarefa.
   Mensagens de agentes não passam pelo roteador; recuperação só nasce do
   tratamento explícito de uma conclusão com falha.
9. Se já existe continuação da tarefa na fila, não repete a instrução antiga.
10. Uma falha real retira temporariamente o provedor da escolha automática:
    seis horas para franquia, cinco minutos para indisponibilidade. Uma conclusão
    posterior bem-sucedida remove esse bloqueio; uma cota informada pelo runner
    ainda esgotada continua valendo. Prazo de renovação exato é evolução futura.

O runner agora reconhece erros JSON do Codex e não aceita saída parcial com
código de saída diferente de zero como sucesso, também no Claude e Copilot.
Mensagens de erro recebem redação de formatos conhecidos de credenciais.
Não se trata de garantia de sanitização de qualquer texto arbitrário.

Não há promessa de qualidade idêntica entre modelos: a garantia implementada é
preservar o pedido/contexto e exigir conferência do estado. Testes e revisão
continuam necessários; se falta contexto, o agente deve registrar uma dúvida.

### Autenticação, custo e quantidade de agentes

Hoje existem três executores: `claude`, `codex` e `copilot`. "GPT (Codex)" é o
rótulo do executor `codex`, não um quarto agente conectado. Não foi adicionado um
adaptador separado para o chat do ChatGPT.

A troca reutiliza os CLIs e as autenticações individuais existentes. Não compra
créditos, não adiciona chave de API, não muda o modelo para uma opção paga e não
aciona outro serviço de API. Codex recebe `forced_login_method="chatgpt"`; chaves
de API OpenAI/Anthropic e `ANTHROPIC_AUTH_TOKEN` não são herdados pelo subprocesso.
Não alterar os arquivos pessoais de autenticação. Configurações próprias de
Claude/Copilot devem continuar sendo conferidas na ativação; esta mudança não
certifica todas as possibilidades de cobrança configuradas previamente no host.

Documentação oficial consultada em 11/10/2026:
- [Autenticação Codex](https://learn.chatgpt.com/docs/auth): login ChatGPT para
  assinatura e chave de API para cobrança por uso são mecanismos distintos.
- [Franquias Codex](https://learn.chatgpt.com/docs/pricing): uso varia com modelo,
  contexto e complexidade; mensagens locais e tarefas em nuvem compartilham
  franquia, com possíveis limites semanais. Não estimar franquia por número de
  mensagens nem presumir créditos de API incluídos.

## Testes reproduzíveis e evidências

Nenhum teste abaixo executa IA ou usa o Telegram real.

```bash
cd /home/duque_de_caxias/projetos/alfamatriz-felipe-central-ia
python3 tools/central-ia/run-tests.py \
  --central-root /home/duque_de_caxias/projetos/central-agentes --filter CentralIa
node tools/central-ia/test-erros-runner.mjs
node tools/central-ia/test-historico.mjs
node tools/central-ia/test-progresso.mjs
python3 tools/central-ia/test-telegram-lock.py \
  --docker-config /home/duque_de_caxias/projetos/central-agentes/.docker
```

O teste PHP usa código somente de leitura, `.env` real oculto, SQLite em memória,
rede desativada e HTTP simulado. O ensaio MariaDB cria somente um contêiner
descartável sem rede, portas ou montagens do host, com dados em tmpfs. Verifica
recusa do segundo consumidor, retenção depois de 62 segundos e aquisição após
encerrar a conexão dona. Remove apenas seu próprio contêiner de teste.

Testes novos cobrem: ultrapassar 20 pedidos sem teto interno, teto opcional,
cascata limitada, reentrega idempotente, continuidade de contexto/Git, anexos,
entrega Telegram simulada, isolamento de contas, indisponibilidade, erros que
não podem repetir escrita, continuação mais recente na fila e perda da trava.

Resultados da versão final:
- Suíte integral: **1.491 testes, 9.041 assertions, sem falhas**; PHP 8.2.30,
  PHPUnit 11.5.56; duas depreciações preexistentes. Duração: 3 min.
- Central: **115 testes, 677 assertions, sem falhas**, antes do ensaio integral.
- Node: interpretação de erros dos três CLIs, histórico/reconexão e progresso
  passaram; `node --check` e `git diff --check` passaram.
- MariaDB 10.11 descartável: **passou**. Segundo consumidor bloqueado,
  retenção verificada após **62 segundos** (62,5 s medidos), recuperação após
  queda da conexão dona e remoção do contêiner de teste. Rede `none`, sem dados reais.

Os primeiros ensaios de retenção não passaram e não foram aceitos como prova.
O diagnóstico mostrou a conexão perdida durante a inicialização: o teste aceitava
`SELECT 1` do servidor temporário da imagem, encerrado antes do MariaDB definitivo.
O ensaio corrigido aguarda PID 1 `mariadbd` e SQL pronto antes de adquirir a trava.
O processo dono roda dentro do contêiner, desacoplado do stream Docker do host.

## Ativação local e rollback para Claude conferir

O app da porta 8096 monta este worktree; PHP/configuração novos podem ser vistos
pela Web antes de reiniciar processos de longa duração. O consumidor atual e a
cópia instalada do runner não recarregam automaticamente todo o código.

Antes de ativar: conferir o diff desta entrega junto das alterações preexistentes
da tarefa 9; verificar ausência de execução ativa; preservar uma cópia dos
arquivos instalados do runner e uma cópia controlada do código anterior do bot.
Não usar `git reset`, `clean`, checkout de branch ou restaurar arquivos inteiros
por cima dos trabalhos ainda não commitados.

Sob autorização de reinício controlado, copiar apenas `runner.mjs` para o caminho
já usado pelo serviço e reiniciar somente `central-ia-runner` e o serviço
`telegram` do Compose local já existente. Não tocar no consumidor legado, app,
MariaDB, produção ou outros projetos. Não é necessário migrar banco.
Validar que existe um consumidor e testar primeiro com respostas simuladas.
Uma inferência real de homologação exige autorização específica.

Rollback do roteamento: `central_ia.agentes.fallback_automatico=false` por
configuração controlada. O teto interno pode ser religado pela nova opção.
Tentativas e mensagens existentes permanecem no histórico, sem exclusões.
Não reverter a trava para o hostname/cache sem analisar o risco de consumidores
simultâneos. Na recuperação, manter o contrato de exclusividade por conexão.

## Demandas pendentes — não implementadas nesta entrega

| Demanda | Critério de aceite |
|---|---|
| Timeout de ponta a ponta | Um deadline inclui download, conversão, IA e conclusão; cancelamento não deixa filho ativo nem expira tarefa enquanto há trabalho sem controle. Testar múltiplos PDFs/vídeos. |
| Checkpoint em toda falha | Medir Git também na pasta direta e na worktree criada na execução que falhou; preservar arquivos pendentes e sessão conhecida quando houver. |
| Sessões e forks | Definir transferência definitiva VS Code → Central; uma sessão canônica por tarefa/provedor; preservar origem e contexto, evitar forks repetidos e resumos divergentes. |
| Consolidar Git e documentação | Separar a tarefa 9 desta entrega para revisão, suíte verde, documentação vigente única e commits somente com autorização. |
| Desligar interface antiga | Inventário de filas/produtores, backup/restauração, confirmação do histórico escolhido pelo dono e rollback; só depois desligar a Web antiga sem excluir banco/volumes. |
| Eficiência de franquias | Escolher modelos oficialmente disponíveis por complexidade, contexto incremental e revisão proporcional; considerar capacidade restante sem degradar silenciosamente o trabalho. Não automatizar o navegador do ChatGPT como quarto executor. |
| Renovação e tentativas em fila | Preferir reset oficial ao cooldown conservador e reavaliar agente de pedidos já na fila quando houver confirmação de esgotamento. |

## Roteiro de revisão para Claude

1. Leia `docs/central-ia/README.md`, esta entrega e a tarefa 9; reconheça o acesso
   amplo autorizado e as mudanças de escopo posteriores ao handoff antigo.
2. Confira `git status` e não sobrescreva alterações preexistentes.
3. Revise `TelegramConsumerLock`, as verificações na API/consumidor e o contrato
   do mesmo banco para todos os consumidores. Repita o ensaio descartável.
4. Revise classificação de erros, limite de tentativas, proprietário, anexos,
   dossiê, reentrega e ausência de roteamento de mensagens de agentes.
5. Confira o login individual dos CLIs sem ler/exibir arquivos de autenticação.
6. Compare arquivos instalados com a branch; não alegue ativação antes de
   atualizar/reiniciar os processos autorizados.
7. Execute a suíte isolada. Apresente resultados, riscos e demandas restantes
   antes de qualquer inferência, commit, desligamento ou publicação.
