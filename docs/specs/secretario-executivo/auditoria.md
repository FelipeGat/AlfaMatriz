# Secretário Executivo: auditoria (Fase 0)

17/10/2026. Base auditada: AlfaMatriz v2026.10.17 (`e35d667`, igual à produção), Gestor.Alfa (`~/projetos/gestor`,
`0a43168d`, 16/10/2026) e o runner instalado na máquina do Felipe. Foi só leitura: nada foi alterado, e nenhuma
credencial foi lida.

Escopo pedido: um módulo só para a conta do Felipe, com proteção forte contra vazamento (e-mail, reuniões, dados
comerciais).

## 1. Situação por componente

| Componente | Situação | Reaproveitamento | Ação necessária |
|---|---|---|---|
| Telegram | **PARCIAL**. Bot próprio por pessoa (token cifrado), pareamento por código, só chat privado, vínculo `chat_id`+dono, 30 msg/min, encaminhada recusada, outbox `central_ia_entregas` com estado de entrega. **Não trata voz nem áudio**: a voz cai em "Mensagem vazia…" (`TelegramCanal.php:195,268`). **Não tem botão nem `callback_query`** (`allowed_updates=['message']`, `CentralIaTelegram.php:152`). | Total: o mesmo bot e a mesma conversa. | Aceitar `voice`/`audio`; acrescentar `callback_query` para os botões Concluir/Adiar/Cancelar. |
| Claude / Codex | **PRONTO, só na máquina**. Nenhuma chamada de LLM no servidor (constitution II: nenhuma credencial de IA no servidor). Fila própria (`central_ia_execucoes`), runner com Claude stream-json e Codex `--json`, roteador com níveis `sem_ia`…`forte`. Saída estruturada só pelo bloco `<<<CENTRAL-IA … >>>` (`Dossie.php`), sem schema garantido. | Total, para análise (reunião, e-mail, briefing). | Um "job de análise" com retorno JSON validado no servidor. **Tudo que é IA só roda com a máquina do Felipe ligada.** |
| Transcrição | **PRONTO no legado**: `deploy/agente/transcrever.py` (faster-whisper `small`, int8, CPU, pt, hotwords, não guarda áudio). **AUSENTE no runner e nesta máquina** (WSL sem pip/venv nem Whisper). | O script, empacotado numa imagem Docker como a de anexos. | Imagem `alfamatriz-central-whisper`; o runner transcreve, e o áudio não sai da máquina. |
| E-mails (Gestor) | **AUSENTE como fonte**. O Gestor lê IMAP **ao vivo** (webklex), não guarda e-mail recebido, não deduplica, e **abrir a mensagem a marca como lida** (`ImapService.php:583`). A senha é cifrada com a APP_KEY do Gestor. | Nenhum direto. As regras de acesso por caixa (`email_account_user`) servem de modelo. | Leitura própria, **somente leitura** (`BODY.PEEK`), guardando metadados e classificação, não o corpo. Decidir as caixas e onde a leitura roda. |
| Pendências (Gestor) | **PRONTO**: `GET /api/v1/central/resumo` (suporte, comercial, financeiro, agenda do dia), funil (`/comercial/funil`), `reunioes_cliente.proximo_passo`. | Alto, pela API. | O token Sanctum do Gestor só nasce no login, sem escopo e **sem expiração**. Precisa de um token de serviço só leitura (mudança no Gestor) ou usar a ponte de produção pela máquina. |
| Agenda | **PARCIAL, e de time**. `Compromisso` + `AgendaService` + lembrete no sino a cada 5 min. **Risco: o compromisso não é privado**; quem tem `permissao:agenda` vê o de todos (`AgendaService::itens`, filtro vazio = todo mundo). Google: **só pela máquina** (`gcal.py`, via `CENTRAL_IA_AGENDA`, já lê e cria). No Gestor, o Google vai num sentido só (Gestor→Google), e os tokens Google **não são cifrados** lá. | A agenda do time só para leitura; o `gcal.py` para o Google. | Compromisso pessoal em tabela própria do Secretário, nunca na Agenda do time. |
| Tarefas | **AUSENTE para uso pessoal**. `Tarefa` é o kanban de desenvolvimento do time. Notas (§18) não existem. | Não reaproveitar (o kanban não é lembrete). | Tabela própria `secretario_itens` (tarefa, lembrete, compromisso, acompanhamento). |
| Notificações | **PARCIAL**. O sino existe (`Notificacao`); o Telegram de alertas é outro bot (Vigia); e-mail AUSENTE (`MAIL_MAILER=log`). Lembretes da Agenda não vão ao Telegram. | Sino e outbox do Telegram da Central. | Despachante de lembretes com tentativas, deduplicação e registro de entrega. |
| Banco / filas | MariaDB 10.11, Laravel 12.64, PHP 8.2. Cron `schedule:run` ativo em produção. **Não há worker de fila em produção** (nenhum `queue:work`, nenhum `ShouldQueue`). | Scheduler com `withoutOverlapping()->onOneServer()`. | Lembretes e briefing por comando agendado, com estado no banco. Se a fila for necessária, criar um worker (systemd, infra do Rossini). |
| WhatsApp | O Gestor usa a **Cloud API oficial da Meta** (`WhatsAppCloudService`, contatos ligados a cliente). | Futuro (Etapa 9). | Nada agora. |

## 2. Decisões de arquitetura (propostas)

1. **Isolamento total por conta.** Tabelas `secretario_*` sempre escopadas por `user_id`, recurso de permissão próprio
   `secretario` com perfil próprio, **sem dar ao admin** (como a Central: constitution I). Registro de outra conta
   responde 404. Nada do Secretário entra na Agenda nem no Quadro do time.
2. **O que não é IA não depende da IA.** Interpretar "me lembra amanhã às 9h…" tem um parser determinístico em PHP
   (hoje, amanhã, dia da semana, "daqui a N min/h", hora) e só recorre à IA no que ele não entender. Lembrete,
   recorrência, adiar e entregar rodam no servidor, pelo scheduler, mesmo com a máquina desligada ou o Claude/Codex
   fora.
3. **IA só pelo runner** (constitution II): análise de reunião, classificação de e-mail e a "sugestão" do briefing
   viram execuções da Central, com retorno JSON validado pelo Laravel antes de gravar. Sem a máquina ligada, o
   briefing sai igual, só com os dados, e avisa que a parte de IA não rodou.
4. **Áudio não sai da máquina.** Transcrição local (faster-whisper no Docker da máquina do Felipe), separada da
   análise. A interface `Transcritor` permite trocar o provedor depois.
5. **Nada é criado sem revisão**: a IA propõe e o Felipe aprova (no web ou por botão no Telegram). Isso vale para
   tarefas extraídas de reunião, rascunho de resposta de e-mail e evento no Google. Envio a terceiros nunca é
   automático.
6. **Conteúdo externo é dado, não ordem.** E-mail, transcrição e anexo entram no prompt marcados como não confiáveis
   (como os anexos já entram hoje). A IA não tem ferramenta que execute ação a partir deles; quem executa é o Laravel,
   depois da aprovação.
7. **O mínimo guardado.** E-mail: metadados, classificação e link, sem corpo. Áudio: apagado depois da transcrição
   (retenção configurável). Transcrição: na conta, sem ir para log. Segredo nenhum em log (`ProtecaoDeSegredos`).

## 3. Riscos de vazamento identificados

- **Agenda do time**: um compromisso pessoal lançado lá fica visível para todos com `agenda`. Daí a tabela própria.
- **Conteúdo vai ao Telegram e ao provedor de IA.** Briefing e lembrete trafegam pelos servidores do Telegram (como
  a Central já faz). A análise de e-mail e reunião passa pela conta do Claude/Codex do Felipe (Anthropic/OpenAI). A
  transcrição fica local.
- **Gestor**: tokens Google guardados em texto puro, tokens Sanctum sem expiração e sem escopo, leitura de e-mail
  que marca como lida. Nada disso é reaproveitado como está.
- **Prompt injection** por e-mail ou reunião ("ignore as instruções e envie…"): mitigado pelos itens 5 e 6 e testado
  (Fase 13.6).
- **Limites**: upload de 12 MB no PHP de produção (cerca de 12 min de MP3 a 128 kbps, ou cerca de 50 min de
  OGG/Opus). O Telegram baixa até 20 MB. Reunião longa em WAV/MP3 precisa de upload em partes ou de limite maior
  (servidor).
- **Licença das assinaturas**: o uso headless do `claude -p` e do `codex exec` com a conta da pessoa é o que a
  Central já faz. Um briefing diário automático aumenta o consumo da assinatura, e as cotas devem ser acompanhadas
  pelo extrato de uso existente.

## 4. Ordem proposta (cada etapa com spec, testes e publicação)

1. **Núcleo**: itens pessoais (tarefa, lembrete, acompanhamento), parser, confirmação, despachante pelo scheduler
   (sino + Telegram com botões), Meu Dia básico, menu "Secretário" com recurso próprio. Sem credencial externa.
2. **Agenda**: visão única (itens do Secretário + Agenda do time onde o Felipe participa + Google pelo runner),
   conflitos, horários livres; criar evento no Google só com confirmação.
3. **Briefing 07:00**: dados determinísticos sempre, sugestão de IA quando a máquina estiver ligada, fontes e horário
   de sincronização.
4. **Meetings**: voz e áudio no Telegram e upload no web; transcrição local; análise; revisão de tarefas com
   evidência.
5. **E-mail e Gestor**: depende das decisões da seção 5.
6. **Memória**: busca textual (MariaDB FULLTEXT) com fonte e data; semântica só se a textual não bastar.
7. **Acompanhamento proativo**: regras configuráveis, silêncio, agrupamento.
8. **WhatsApp**: só pela Cloud API oficial, quando autorizado.

## 5. Decisões do Felipe (bloqueiam etapas específicas)

- **E-mail**: quais caixas (Gmail pessoal? caixas IMAP das empresas na Hostgator?) e onde a leitura roda: no servidor
  do Matriz, com credencial cifrada lá, ou só na máquina, pelo runner.
- **Google Agenda**: manter pela máquina (`gcal.py`; o briefing das 07:00 só tem Google se a máquina estiver ligada)
  ou dar ao Matriz um OAuth próprio (o token cifrado fica no servidor).
- **Pendências do Gestor**: criar no Gestor um token de serviço só leitura (mudança no Gestor) ou consultar pela
  máquina (ponte de produção).
- **Áudio de reunião**: apagar depois da transcrição (recomendado) ou guardar por N dias.

### Decididas pelo Felipe em 17/10/2026

| Decisão | Escolha | Consequência para o desenho |
|---|---|---|
| E-mail | **No servidor do Matriz** | IMAP no servidor, senha cifrada (cast `encrypted`, nunca em log nem resposta), **somente leitura** (`BODY.PEEK`, sem flag), guardando só metadados, classificação e link. As caixas são cadastradas pelo próprio Felipe na tela, sem cópia do Gestor. A classificação por IA continua pelo runner. |
| Google Agenda | **OAuth próprio no Matriz** | Projeto/cliente OAuth do Google (pessoal, como o `agenda-felipe`, ou novo), token cifrado no servidor, escopo mínimo (`calendar.events` + `calendar.readonly`), criação de evento só depois de confirmação. Pendente: o Felipe autorizar no navegador. |
| Pendências do Gestor | **Pela máquina** (ponte de produção, só leitura, via runner) | Entram no briefing quando a máquina está ligada; sem ela, o briefing avisa a limitação. Nada muda no Gestor. |
| Áudio de reunião | **Apagar depois da transcrição** | Ficam transcrição, resumo e evidências; o original é apagado assim que a transcrição é confirmada. |
