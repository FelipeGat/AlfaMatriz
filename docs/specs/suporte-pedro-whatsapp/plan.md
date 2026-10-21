# Plano técnico: Pedro, o atendente virtual do suporte

**Spec:** `spec.md` (mesmo diretório) · **Data:** 20/10/2026

## Constitution check

| Princípio | Como este plano cumpre |
|---|---|
| II. Cada pessoa com as suas IAs e a sua máquina | O Pedro é uma conta própria no Matriz (usuário "Pedro", token Sanctum de capacidade própria). Ele roda numa conta Linux própria no servidor (HOME e chave de IA próprios, só no ambiente). Nada de credencial de IA no banco nem no Git. |
| III. Um sistema só | Conversas e mensagens em tabelas do Matriz (`suporte_*`); sem segunda aplicação. |
| VII. Autorização explícita | Ligar o número de produção e o deploy pedem a liberação do Felipe. |
| VIII. Zero fabricação | O Pedro só responde com o que veio no pacote de contexto (cadastro, contratos, situação, tarefas, base dos sistemas). O que não está lá vira "vou verificar" e abre atendimento. |

## Arquitetura

```
WhatsApp (cliente) ──► Meta Cloud API ──webhook──► Matriz /api/suporte/whatsapp/webhook
                                                   │ grava mensagem; reconhece cliente; enfileira
                                                   ▼
                          worker "pedro" (servidor, conta Linux própria)
                          POST /api/suporte/pedro/proxima ◄─ pacote de contexto
                          chama a API de IA ─► {resposta, acao, resumo, sistema}
                          POST /api/suporte/pedro/{conversa}/resultado
                                                   │ Matriz executa a ação
                                                   ▼
                     envia pela Cloud API · abre tarefa "suporte" · avisa sino/Telegram
```

1. **Canal (Matriz):**
   - **Webhook:** confere a assinatura `X-Hub-Signature-256` com o app secret e o verify token. Trata as mensagens
     recebidas e as `smb_message_echoes` (o que o humano respondeu pelo app).
   - **Envio:** `POST /{phone_number_id}/messages`, com o token do sistema da Meta no `.env`. É credencial da Meta,
     não de IA.
2. **Humano assumiu:** um echo do app, ou resposta pela tela, deixa a conversa com o humano. O Pedro fica quieto
   nela até a conversa ser devolvida a ele (botão na tela) ou até 24 h sem mensagem do humano (a confirmar).
3. **Horário:** das 07:00 às 18:00 (America/Sao_Paulo). Fora dele, a mensagem só é gravada; às 07:00 um comando
   agendado põe na fila as conversas que esperam.
4. **Pacote de contexto** (montado pelo Matriz e entregue ao worker):
   - o cliente (`clientes`, `cliente_telefones`) e o que ele tem (`cliente_sistema`, `cliente_contratos`);
   - a situação dos sistemas dele: Monitor (`monitor_alertas`) e manutenção programada (compromissos `deploy`);
   - as tarefas de suporte abertas dele, com etapa;
   - a base de cada sistema (`modelos-por-sistema.md`, convertida em base editável na etapa 2);
   - as últimas mensagens da conversa.
5. **Worker do Pedro:**
   - um processo Node pequeno, separado do runner da Central, sob systemd e numa conta Linux `pedro`;
   - uma chamada à Messages API da Anthropic, com saída estruturada (JSON schema: `resposta`, `acao` ∈ {responder,
     abrir_atendimento, aguardar_humano}, `resumo`, `sistema_id`);
   - modelo e custo a decidir na implementação, conferidos na documentação atual da API, nunca de memória.
6. **Atendimento:**
   - tipo novo de tarefa, `suporte`, sem as etapas de revisão (fora de `TIPOS_COM_PORTOES`);
   - criado pelo `TarefaService::criar` em nome da conta Pedro;
   - responsável o Rossini, com o Alexandre como validador ou acompanhante (a confirmar o campo); os dois são
     avisados no sino e no Telegram;
   - o número da tarefa (T-N) volta ao cliente.
7. **Tela:**
   - Suporte › Conversas WhatsApp: lista, conversa com cliente e sistemas ao lado, "Assumir" e "Devolver ao Pedro",
     vincular número a cliente;
   - visível ao Felipe, ao Rossini e ao Alexandre (recurso `suporte`).

## Dados

- `suporte_conversas`: telefone, cliente_id, nome do perfil do WhatsApp, situação (pedro, humano, encerrada),
  humano_ate, ultima_mensagem_em, tarefa_id.
- `suporte_mensagens`: conversa_id, direção (entrada, pedro, humano_app, humano_matriz), wamid único (idempotência
  do webhook), texto, tipo, payload, enviado_em, status.
- Número desconhecido: o Pedro pergunta a empresa ou o CNPJ. **Vincular o número ao cliente é ato humano na tela**,
  que confirma a sugestão do Pedro.

## Etapas

1. **Base:**
   - tabelas, webhook, envio, tela de conversas e o tipo `suporte`;
   - testes com Http::fake e o número de teste da Meta.
2. **Pedro:**
   - conta, token, worker, pacote de contexto e ações;
   - horário e silêncio quando o humano assume;
   - testes com a IA falsa.
3. **Base de conhecimento editável** por sistema (perguntas frequentes e "não prometer").
4. **Ligar o (27) 3109-3265 em coexistência**, depois da aprovação de Tech Provider e com a liberação do Felipe.

## Riscos

- **Aprovação da Meta** (Tech Provider ou revisão do app): prazo fora do nosso controle. Plano B: parceiro com
  coexistência.
- **Política da Meta sobre IA no WhatsApp Business:** conferir as regras atuais antes de ligar a produção.
- **Memória do servidor (2 GB):** o worker chama a API e não roda agente local.
- **Mensagem fora da janela de 24 h:** a Cloud API exige modelo aprovado. O Pedro só responde dentro da janela e
  nunca inicia conversa.
