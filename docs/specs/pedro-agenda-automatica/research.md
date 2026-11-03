# Research: pedro-agenda-automatica

Phase 0 do `/cstk:plan`, 02/11/2026. Fontes: o código do Gestor (`~/projetos/gestor`, lido nesta data) e deste
repositório, e a documentação oficial da Meta lida em 02/11/2026 (URLs em cada decisão). Valor que não veio de uma
dessas fontes está marcado **(padrão)**: é escolha nossa, revisável, e não fato.

## Fatos de partida (Gestor, lidos em 02/11/2026)

- **Envio.** `WhatsAppCloudService::enviarTexto` (`app/Services/WhatsApp/WhatsAppCloudService.php:46`) e
  `enviarTemplate($cfg, $to, $template, $lang, array $bodyParams = [], ?$docUrl, ?$docFilename)` (:98), que aceita N
  parâmetros de texto no corpo, sem botão. Os dois passam por `postMessage()` (:279-297), que devolve o wamid ou
  `null` e **engole o erro da Meta** (só `Log::error`). Modelos aprovados: `templatesAprovados()` (:230-277), com
  `nome, idioma, categoria, corpo, variaveis`.
- **Modelo iniciado pela empresa hoje.** `cobrancas:whatsapp-vencimento` (`app/Console/Commands/WhatsappCobrancasVencimento.php`)
  manda sempre modelo, sem olhar a janela, marca `cobrancas.whatsapp_venc_em` só quando há wamid, não grava em
  `whatsapp_mensagens` e não tem nova tentativa. Agendado em `routes/console.php:96-99`,
  `dailyAt('09:00')->withoutOverlapping()->onOneServer()`.
- **Situação.** `WhatsappWebhookController::processarStatus()` (:293-317) acha a mensagem por `wa_message_id` e grava
  `sent|delivered|read|failed`; sem mensagem, ignora. Não confere ordem (um `delivered` atrasado sobrescreve `read`).
- **Janela.** `WhatsappConversa::janelaAberta()` (:105-110): última mensagem `in` da conversa + 24 h
  (`JANELA_HORAS = 24`).
- **Pedro ou menu.** `MenuBot::processarNaConversa()` (:111-190) decide antes de o Matriz ver a mensagem; a conversa
  com `com_pedro_desde` preenchido vai ao Pedro; o repasse leva `com_pedro` por contato (`X-Gestor-Estado`), e o Matriz
  só enfileira quando ele é verdadeiro (`SuporteWebhookController::repasse`). A lista da casa chega ao Gestor como
  chaves em `app_settings.pedro_numeros_internos` e é lida por `WhatsappConfig::pedroInterno($waId)` (:85-90).
  O número do Pedro é a `WhatsappConfig` com `pedro_repasse_url` preenchido.
- **Visitas.** `atendimento_visitas` (`2026_05_11_180000_…`): `atendimento_id`, `funcionario_id` (responsável),
  `data_inicio`/`data_fim` (obrigatórios), `periodo`, `status` (`agendado|em_atendimento|finalizado|cancelado`),
  `observacoes`, `cancelada_em`, `motivo_cancelamento`, soft delete. Adicionais na pivô `atendimento_visita_tecnicos`,
  com `data_inicio`/`data_fim` individuais (nulos = herdam a visita). Nº = `atendimentos.numero_atendimento`; nome do
  cliente = `Atendimento::nome_tomador` (:116-129).
- **Observer.** `AtendimentoVisitaObserver` (registrado em `AppServiceProvider.php:64`) é síncrono, escuta `created`,
  `updated` (só `data_inicio`, `data_fim`, `funcionario_id`, `status`) e `deleted`, e **não vê a pivô dos adicionais**
  (`sync()` e `updateExistingPivot` em `AgendaTecnicaService.php:376`, `AtendimentoVisitaService.php:76`,
  `AtendimentoController.php:1326`, `AtendimentoStatusController.php:86`, `DashboardTecnicoController.php:799,817`).
  `visitas:finalizar-dia` atualiza em massa, sem observer.
- **Reagendar recria.** `AgendaTecnicaService::definirVisitas` (:255-270), usado no cadastro do atendimento, no
  reagendamento e na Agenda Técnica, **apaga as visitas `agendado` e cria outras**: o id da visita muda a cada
  reagendamento.
- **Funcionário.** `funcionarios.celular` string(20), sem normalização (`2026_03_23_000001_…:20-21`), `ativo`,
  `is_tecnico`, soft delete; `users.funcionario_id`. `TelefoneHelper::chave()` = DDD + 8 últimos dígitos, só para
  número com 55 e 12 ou 13 dígitos (`app/Helpers/TelefoneHelper.php:21-38`).
- **Agendador e fila.** `routes/console.php`, fuso `America/Sao_Paulo` (`config/app.php:68`);
  `whatsapp:pedro-tempo-limite` roda `everyMinute()`. **O `schedule:run` do host não está no repositório.** Há worker
  de fila (`gestor_queue`), mas `scripts/deploy-prod.sh` reinicia só o `php-fpm` (linhas 31 e 99-100).
- **Configuração e auditoria.** `AppSetting::get` (cache de 300 s) e `set` (limpa o cache). `spatie/laravel-activitylog`
  com retenção de 365 dias (`config/activitylog.php:14`) e o middleware `AuditSystemChanges` em web e api.
- **Autorização.** Sem Policies nem Gates; a integração com o Matriz autentica pelo middleware
  `AutenticaIntegracaoMatriz` (segredo `services.matriz_pedro.token`), com `throttle:120,1` (`routes/api.php:39-59`).
- **Cache.** `CACHE_STORE` padrão `database`; a tabela `cache_locks` existe. `Cache::lock` não é usado no código.

## Fatos da Meta (documentação oficial, 02/11/2026)

- Corpo do modelo: até **1024 caracteres** (`…/whatsapp/templates/components`). A doc atual não diz se o limite vale
  com as variáveis preenchidas; a referência antiga da Message API dava 1024 para o texto do parâmetro do corpo.
- Modelo não pode **começar nem terminar com variável** (erro 2388299) nem ter **variáveis demais para o tamanho do
  texto** (2388293, sem número na doc); exemplo de cada variável é obrigatório (`…/templates/template-review`).
- **Quebra de linha em variável:** nenhuma regra na doc oficial. Fontes secundárias relatam recusa (código 100 numa,
  132018 noutra). O desenho abaixo não manda quebra de linha em variável, então não depende disso.
- **UTILITY** exige não ser promocional e ser específico ao usuário ou essencial; aviso de escala a funcionário não
  aparece nos exemplos (interpretação nossa: cabe). Desde 09/04/2025 a Meta aprova como MARKETING o que julgar
  MARKETING, e recategoriza aprovados com 1 dia de aviso (webhook `template_category_update`)
  (`…/templates/template-categorization`).
- **Preço** (`…/whatsapp/pricing`): cobrança por mensagem entregue desde 01/07/2025; **desde 01/10/2026 o modelo
  UTILITY dentro da janela voltou a ser cobrado**; o texto livre (mensagem de serviço) tem as primeiras 1.000 por mês,
  por número, gratuitas.
- **Janela:** só a mensagem do usuário abre; fora dela, só modelo (`…/messages/send-messages`; erro 131047).
- **Status:** `sent`, `delivered`, `read`, `failed` (e `played`, só áudio); o `delivered` pode não vir quando a
  mensagem é lida na hora; erro em `statuses[].errors[]` (`…/webhooks/reference/messages/status`).
- **Opt-in:** número dado pela pessoa e consentimento (pode ser geral); pedido de parar é obrigatório respeitar
  (`…/getting-opt-in`, `whatsappbusiness.com/policy`). Botão de resposta rápida é permitido em UTILITY, mas o "parar"
  de UTILITY é tratado pelo próprio sistema.

---

## Decision 1: Onde roda

**Decision**: Verificações, montagem das mensagens, envio, registro, situação e a resposta ao técnico ficam no
**Gestor**. O AlfaMatriz é a tela de administração e de supervisão, por portas novas da integração que já existe
(Matriz → Gestor, mesmo segredo e mesmo middleware). O Matriz não ganha tabela.
**Rationale**: FR-027 exige que nada dependa da máquina da Central; no Gestor já estão a agenda, o agendador, o token da
Meta, os modelos, o webhook de situação e a decisão Pedro/menu. Montar no Matriz exigiria a agenda pelo retrato, que a
máquina renova a cada 10 min (quebra FR-027 e o SC-003 de 10 min). A decisão de 31/10 ("o AlfaMatriz continua sendo o
ambiente de supervisão e inteligência do Pedro") fica com a tela.
**Alternatives considered**: (a) tudo no Matriz, lendo a agenda pelo retrato — depende da máquina; (b) tela de
administração no Gestor — duas telas para o mesmo assunto e a supervisão longe do resto do Pedro; (c) agendador no
Matriz chamando o Gestor a cada minuto — duas peças para o mesmo relógio.

## Decision 2: Como a mudança é percebida

**Decision**: Comparação periódica, a cada minuto: para cada destinatário com programação já comunicada de hoje ou de
amanhã, a programação atual é montada de novo e comparada com a última comunicada. O aviso só sai quando a programação
nova ficou **estável por 2 minutos (padrão)**; mudança desfeita antes disso volta a ser igual à comunicada e não gera
nada. O `AtendimentoVisitaObserver` **não** é usado.
**Rationale**: o observer não vê a pivô dos adicionais, é síncrono (chama o Google na hora) e não vê o
`visitas:finalizar-dia`; e o `definirVisitas` apaga e recria visitas, então um evento por visita leria um reagendamento
como "cancelada + nova". A comparação vê qualquer caminho de gravação (tela, app, API, Agenda Técnica, comando),
resolve "várias gravações viram um aviso só" e "mudança desfeita não gera nada" (FR-015) sem código extra, e o volume é
pequeno (7 visitas e 6 técnicos em 03/11, leitura de 02/11). 2 min de estabilidade + 1 min de ciclo cabem nos 10 min
do SC-003.
**Alternatives considered**: observer + fila — perde a pivô e o reagendamento, e o deploy não reinicia o worker
(Decision 4); observer marcando "sujo" + comparação — mais uma peça para o mesmo resultado.

## Decision 3: A identidade de um compromisso

**Decision**: Um compromisso é **técnico + atendimento (nº) + dia**, e não o id da visita. Na comparação, o
compromisso de antes é pareado com o de agora pelo atendimento (dois do mesmo atendimento no mesmo dia, pela ordem de
início). Classificação de cada diferença:

| Antes (comunicado) | Agora | Mensagem |
|---|---|---|
| — | compromisso novo | **Nova visita** |
| compromisso | mesmo atendimento, outro horário | **Horário mudou** (antigo → novo) |
| compromisso | sumiu do dia; o técnico tem visita ativa do mesmo atendimento em outra data | **Remarcada** (data antiga → nova) |
| compromisso | sumiu; visita do atendimento cancelada (`status = cancelado`) | **Cancelada, não deve mais ser executada** |
| compromisso | sumiu; o atendimento segue com outro técnico, ou a visita foi apagada sem outra | **Saiu da sua agenda: não vá a ela** |
| compromisso | virou `em_atendimento` ou `finalizado` | nada (FR-009 tira da programação; não é mudança) |

Troca de responsável sai dessa tabela sozinha: o que saiu cai na penúltima linha, o que entrou na primeira (FR-013).
**Rationale**: é o que o técnico enxerga (cliente, nº, horário) e é estável ao `definirVisitas`. FR-016: mudança em
campo que não aparece na mensagem (observação, período, duração sem mudar horário) não altera o compromisso.
**Alternatives considered**: id da visita — quebra no reagendamento; hash do dia inteiro sem pareamento — sabe que
mudou, mas não diz o quê (FR-013).

## Decision 4: Agendamento, verificação perdida e fila

**Decision**: Um comando, `whatsapp:agenda-automatica`, agendado `everyMinute()->withoutOverlapping()->onOneServer()`
em `routes/console.php`. Os horários (06:30, 16:30, 17:00, silêncio a partir das 20:00) são lidos da configuração a
cada ciclo, e não da expressão do cron. Cada verificação do dia (manhã, programação, relatório) tem uma linha única em
`agenda_verificacoes` (tipo + dia + modo): o ciclo roda a que já passou da hora e não está concluída, então uma
verificação perdida roda ao voltar, **no mesmo dia**, e nunca duas vezes (FR-028-INFRA-SCHED). Sem job de fila: o envio
roda dentro do comando.
**Rationale**: o horário configurável pela tela não cabe numa expressão fixa do cron; a linha única dá a recuperação e
a prova de que rodou. O `deploy-prod.sh` não reinicia o worker de fila, então um job novo rodaria com código velho até
alguém reiniciar; o volume (dezenas de mensagens por dia) não pede fila.
**Alternatives considered**: três `dailyAt` — sem recuperação e sem horário pela tela; job por mensagem — worker com
código velho.
**A conferir na Fase A** (fora do repositório): que o `schedule:run` do host roda a cada minuto. Prova: a linha da
verificação aparece em `agenda_verificacoes` em modo desligado (Decision 13).

## Decision 5: Idempotência e serialização

**Decision**: O aviso nasce como linha `preparada` em `agenda_avisos`, com índice único em
**(destinatário, dia da agenda, tipo, versão, modo)**, **antes** de qualquer chamada à Meta; só quem criou a linha chama a
Meta. A versão é o sha256 da programação canônica do destinatário naquele dia (compromissos ordenados, só os campos da
mensagem). A **versão comunicada** de um dia é a da última linha do mesmo modo com situação `aceita`, `entregue`,
`lida` ou `sem_envio` (a programação vazia registrada às 16:30). Verificação e comparação do mesmo destinatário são
serializadas por `lockForUpdate` na linha do destinatário, dentro de transação.
**Rationale**: FR-029 pede exatamente essa chave; a linha antes da chamada impede o envio em dobro mesmo com o
agendador repetido ou o processo reiniciado no meio; o banco já é a trava usada no projeto (`lockForUpdate` em
`IntegracaoMatriz`, `AtendimentoController`), e `Cache::lock` não é usado em lugar nenhum. Falha não conta como
comunicada, então a mudança seguinte é calculada contra o que o técnico de fato recebeu.
**Alternatives considered**: `Cache::lock` — trava nova no projeto, e a chave única já basta; marca em coluna da visita
(como `whatsapp_venc_em`) — não serve a vários destinatários por visita.

## Decision 6: Mensagem livre ou modelo

**Decision**: Janela aberta → texto livre (`enviarTexto`), com quebra de linha, uma visita por linha. Janela fechada →
modelo aprovado. A janela é a do número do destinatário: a conversa mais recente do contato cuja chave
(`TelefoneHelper::chave`) é a do celular, pela regra de `WhatsappConversa::janelaAberta()`. Se a janela fecha em menos
de **5 min (padrão)**, vai modelo.
**Rationale**: é o FR-022. Com a regra de preço de 01/10/2026, o livre dentro da janela também é o mais barato (entra
nas 1.000 mensagens de serviço gratuitas do mês; o modelo UTILITY na janela é cobrado). A margem evita perder a janela
entre a decisão e a chegada à Meta.
**Alternatives considered**: sempre modelo — mais simples, mas pago mesmo dentro da janela e sem quebra de linha.

## Decision 7: Os modelos da Meta

**Decision**: Quatro modelos, categoria **UTILITY**, idioma `pt_BR`, só corpo (sem cabeçalho, botão nem mídia), todos
terminando no texto fixo da assinatura — `agenda_programacao`, `agenda_mudanca`, `agenda_relatorio` e `agenda_alerta`.
Os textos estão em `contracts/mensagens.md`, marcados **[PROPOSTA]**: o dono aprova o texto; a criação na Meta é ação
externa, feita só com autorização dele, depois deste plano. A lista de compromissos vai numa variável só, **sem quebra
de linha**, itens separados por " • "; se o corpo preenchido passar de 1024 caracteres, a mensagem sai em partes
"(1/2)", "(2/2)", cada uma completa. O "parar" vai como frase ("Para não receber mais, responda PARAR"), sem botão.
Antes de usar, o sistema confere na lista de aprovados (`templatesAprovados`) nome, idioma, número de variáveis e
**categoria**: modelo ausente, diferente ou recategorizado como MARKETING vira pendência "sem modelo" e nada sai fora da
janela.
**Rationale**: o `enviarTemplate` só manda variáveis de corpo; sem quebra de linha não importa como a Meta trata `\n`
em variável (não documentado); o fim fixo atende à regra 2388299; conferir a categoria impede pagar MARKETING sem
saber. O texto com "responda PARAR" dispensa mexer no `enviarTemplate` para botão.
**Alternatives considered**: botão "Parar" de resposta rápida — permitido, mas exige mudar o envio de modelo e tratar
o payload; modelo "convite" que só abre a janela — não leva o conteúdo essencial (FR-022); um modelo genérico "{{1}}" —
recusado ou feito MARKETING pela própria regra da Meta.

## Decision 8: O erro da Meta

**Decision**: `WhatsAppCloudService` ganha um envio que devolve o resultado (wamid, ou código e título do erro da
Meta), sem mudar `enviarTexto`, `enviarTemplate` nem `postMessage` para quem já os usa.
**Rationale**: FR-024 e a US4 (cenário 5) pedem o erro devolvido; hoje ele só vai ao log. Mudar o retorno dos métodos
existentes afetaria o menu, a cobrança e a integração.
**Alternatives considered**: ler o erro do log — frágil; propriedade "último erro" no serviço — estado escondido num
serviço compartilhado.

## Decision 9: A situação pelo webhook

**Decision**: `processarStatus` continua como está para `whatsapp_mensagens`; quando não acha a mensagem pelo wamid,
procura o aviso da agenda pelo mesmo wamid. No aviso a situação só avança: `aceita` (sent) → `entregue` (delivered) →
`lida` (read); `failed` vira `falha` com o código e o título de `errors[0]`. `read` sem `delivered` antes vale como lida.
**Rationale**: as mensagens automáticas não entram em `whatsapp_mensagens` (Decision 12), e a Meta pode mandar os
status fora de ordem ou pular o `delivered`.
**Alternatives considered**: gravar o aviso também em `whatsapp_mensagens` — exigiria conversa e poria a mensagem no
atendimento (FR-023).

## Decision 10: Tentativas

**Decision**: Até **3 tentativas (padrão da spec)**: a 2ª **5 min** e a 3ª **15 min (padrão)** depois da anterior,
feitas pelo mesmo ciclo de minuto, na mesma linha do aviso (mesma chave, wamid da tentativa mais recente). Depois da 3ª,
`falha` definitiva: aparece na supervisão e como pendência no relatório; nada é reenviado no dia seguinte. Vale para a
recusa na chamada e para o `failed` do webhook. No silêncio (Decision 11), a tentativa espera o fim dele.
**Rationale**: FR-026; a linha única mantém a idempotência entre as tentativas.
**Alternatives considered**: linha por tentativa — mais rastro, mas a chave de idempotência deixaria de ser a do aviso.

## Decision 11: Horários e silêncio

**Confirmada** pelo Felipe em 02/11/2026 ("Sim, as quatro e seguir para as tarefas"). Vale a parte da noite: a programação ou o relatório perdidos saem na hora, mesmo depois das 20:00.

**Decision**: A configuração fica em `app_settings`, chave `agenda_automatica` (JSON): `modo`
(`desligada|ensaio|ligada`, padrão `desligada`), `manha` 06:30, `programacao` 16:30, `relatorio` 17:00,
`silencio_inicio` 20:00 (padrão da spec). O silêncio vai de `silencio_inicio` até `manha`. No silêncio, o ciclo não
manda aviso de mudança nem tentativa; a verificação da manhã manda o que ficou (FR-014). As verificações agendadas
(programação, relatório) não obedecem ao silêncio: a perdida que roda ao voltar à noite sai na hora, porque o
FR-028-INFRA-SCHED manda rodar no mesmo dia e o silêncio da spec é do aviso imediato (FR-015).
**Rationale**: `AppSetting::set` limpa o cache, então a mudança vale no ciclo seguinte; é o mesmo lugar onde já mora a
lista da casa.
**Alternatives considered**: `.env` — exigiria deploy para mudar horário (US4).

## Decision 12: Separado do atendimento

**Decision**: A mensagem automática não é gravada em `whatsapp_mensagens` nem cria conversa: o registro é
`agenda_avisos`. A resposta fixa ao técnico (Decision 14) é gravada na conversa que o próprio técnico abriu, como
`direcao = system`, igual ao menu.
**Rationale**: FR-023 — nada de conversa nova na fila do atendimento do Gestor por causa de um aviso; o mesmo que o
lembrete de cobrança já faz.
**Alternatives considered**: conversa por técnico — poluiria a tela do WhatsApp e mexeria no `aguardando_menu`.

## Decision 13: Desligada, ensaio e produção

**Decision**: Nasce `desligada` (sem a chave, vale `desligada`). Em `ensaio`, tudo roda e é registrado com modo
`ensaio` e situação `ensaio`, com o texto exato, e nada chama a Meta. Em `ligada`, envio real **só com
`APP_ENV=production`**; em qualquer outro ambiente, `ligada` se comporta como `ensaio`. Além disso, o botão "Prévia
agora" da tela roda a programação ou o relatório de amanhã em ensaio a qualquer momento, em qualquer modo, sem mexer na
versão comunicada real. As linhas de ensaio não contam para a comparação do modo `ligada`.
**Rationale**: FR-025 e SC-006; o banco de desenvolvimento do Gestor é espelho de produção, com os celulares reais
(constituição do Gestor, II), então a trava pelo ambiente impede disparo a partir de uma cópia.
**Alternatives considered**: chave no `.env` para liberar o envio — tiraria do administrador a ação de ligar (FR-025).

## Decision 14: A resposta do técnico (FR-021) e o "parar" (FR-006)

**Decision**: No `MenuBot::processarNaConversa`, logo depois do caso `suporte_no_matriz` e só para conversa em
`aguardando_menu`: se o número é de **técnico destinatário ativo** (chave do celular do cadastro) e **não está na lista
da casa** (`pedroInterno`), o Gestor:
1. zera `com_pedro_desde` — o repasse leva `com_pedro = false` e o Matriz só registra, sem IA;
2. responde a frase fixa ("Esta é uma mensagem automática da agenda. Para falar sobre ela, fale com o seu gestor."
   **[PROPOSTA de texto]**) pelo `enviarSistema`, **uma por sequência e no máximo uma a cada 2 min** (a regra do
   `mandouMenuRecentemente`, que é o mesmo ritmo do aviso fixo de fora do horário do Matriz);
3. manda aos gestores destinatários ativos um `alerta` com o nome do técnico e o texto, um por sequência (chave: a
   primeira mensagem da sequência);
4. se o texto, normalizado (minúsculas, sem acento e sem pontuação), é `parar`, marca o destinatário como parado,
   responde "Pronto, você não recebe mais os avisos automáticos da agenda." **[PROPOSTA]** e o alerta diz isso.

O menu de reserva não sai: a conversa nunca fica com o Pedro, e o `whatsapp:pedro-tempo-limite` só olha as que estão.
**Rationale**: a escolha Pedro/menu acontece no Gestor antes de o Matriz ver a mensagem (lição de 31/10/2026); os
números dos destinatários e a lista da casa já estão lá; funciona com a máquina e o Matriz fora do ar. O Pedro do
Matriz não muda. Quem está na lista da casa segue com o Pedro, como pede o FR-021.
**Alternatives considered**: tratar no Matriz, como o aviso de fora do horário — o Matriz não conhece os números dos
destinatários sem o retrato, que depende da máquina, e a conversa chegaria lá já como "cliente novo".

## Decision 15: Aviso aos gestores e ao administrador

**Confirmada** pelo Felipe em 02/11/2026 ("Sim, as quatro e seguir para as tarefas").

**Decision**: Pelo WhatsApp, o mesmo canal do relatório: livre na janela, modelo `agenda_alerta` fora dela. O alerta
também fica na supervisão do Matriz. O administrador é avisado do "parar" por esse alerta (ele é gestor destinatário) e
pela supervisão.
**Rationale**: alcança o Wallas, que pode não ter conta nem Telegram ligado no Matriz; não depende do Matriz no ar.
**Alternatives considered**: sino ou Telegram do Matriz — só a conta dona tem o Telegram ligado, e exigiria porta nova
Gestor → Matriz.

## Decision 16: Administração e quem é "administrador autorizado"

**Confirmada** pelo Felipe em 02/11/2026 ("Sim, as quatro e seguir para as tarefas").

**Decision**: Aba nova **"Agenda automática"** em Suporte, no Matriz. Ver (destinatários, configuração, envios,
ensaio): `permissao:suporte,ler`, como o resto do Suporte. Mudar (destinatário, consentimento, horários, modo) e rodar a
prévia: **só a conta dona do Pedro** (`services.suporte_pedro.conta`), a mesma regra da lista da casa
(`SuporteConhecimentoController::dono`, FR-A1). Cada mudança vai ao Gestor com o e-mail de quem mudou; o Gestor grava
o antes e o depois no `activity_log` (`activity()->withProperties`), além do registro que o `AuditSystemChanges` já faz
da rota.
**Rationale**: FR-005; a tela de quem já administra o Pedro; sem perfil novo nem sistema de permissão paralelo.
**Alternatives considered**: editores do Pedro (`services.suporte_pedro.editores`) — hoje é a mesma pessoa; fica como
mudança de uma linha se o dono quiser estender.

## Decision 17: O número e quem nunca recebe

**Decision**: O número sai do `funcionarios.celular` do funcionário ligado, lido a cada envio: só dígitos; com 10 ou 11
dígitos ganha o 55; precisa passar no `TelefoneHelper::chave`. Pendências: sem celular ou inválido → "sem número";
mesma chave de outro funcionário ativo → "número compartilhado"; funcionário inativo ou apagado → "inativo". Chaves em
`AGENDA_NUNCA_ENVIAR` (`.env`, separadas por vírgula) nunca recebem: é onde o 3269 entra (FR-004), sem o número no
código.
**Rationale**: FR-003 e FR-004; a mesma chave de 10 dígitos que a lista da casa usa; números completos ficam em
configuração, não em código.
**Alternatives considered**: usar `telefone` quando falta `celular` — a decisão do dono foi o celular.

## Decision 18: Conteúdo e cálculo

**Decision**: Programação do técnico = visitas `agendado` do dia em que ele é responsável ou adicional (o adicional com o
horário da pivô, quando houver), ordenadas pelo início; cliente = `nome_tomador`; nº = `numero_atendimento`; horário
"HH:MM–HH:MM"; link fixo do portal em configuração (`AGENDA_PORTAL_URL`, padrão o endereço da spec), sem token; nada
de `observacoes`, telefone, valores nem outro técnico (FR-010). Relatório: **visitas** = visitas `agendado` distintas;
**técnicos** = funcionários distintos entre responsáveis e adicionais; **conflito** = o mesmo técnico em duas visitas
cujo intervalo (o individual, para o adicional) se sobrepõe; **pendências** = visita sem responsável, técnico com
visita sem aviso aceito (sem destinatário, sem número, compartilhado, inativo, sem consentimento, parou, sem modelo,
falha) e falha de leitura. Linha por técnico; técnicos com exatamente os mesmos compromissos no dia ficam numa linha só.
**Rationale**: FR-007 a FR-011 e FR-017 a FR-019; `nome_tomador` é o nome que a tela de atendimento já mostra.
**Alternatives considered**: contar participações como visitas — proibido pelo FR-018.

## Decision 19: Consentimento

**Decision**: Cada destinatário tem `consentimento_em` e `consentimento_por`, marcados na tela pelo administrador
("a pessoa concordou em receber os avisos da agenda por este número"). Sem eles, nada sai e a pendência é "sem
consentimento". O "parar" grava `parou_em`; voltar a receber exige o administrador registrar consentimento de novo.
**Rationale**: FR-006 e a política da Meta (número dado pela pessoa e opt-in, que pode ser geral; pedido de parar é
obrigatório).
**Alternatives considered**: consentimento pela própria mensagem ("responda SIM") — exigiria modelo de convite antes da
primeira programação.

## Decision 20: Retenção

**Confirmada** pelo Felipe em 02/11/2026 ("Sim, as quatro e seguir para as tarefas").

**Decision**: O aviso guarda no Gestor o número completo para onde saiu (auditoria de para onde foi); a porta do
Matriz devolve só o final. Avisos com mais de **365 dias** perdem o número e o texto (ficam tipo, dia, situação e
contagens), por `model:prune`, o mesmo prazo do `activity_log` do Gestor (`config/activitylog.php:14`).
**Rationale**: FR-024 e LGPD (minimização); prazo que o projeto já usa para auditoria.
**Alternatives considered**: guardar só o final — perde a prova de para onde a mensagem foi quando o cadastro muda.
