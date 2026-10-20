# Tarefas: Pedro, o atendente virtual do suporte

Padrões adotados no "siga" de 20/10/2026, ajustáveis:
- sábado, domingo e feriado contam como fora do horário;
- o Pedro volta à conversa pelo botão "Devolver ao Pedro" ou sozinho, 24 h depois da última mensagem do humano;
- o atendimento é uma tarefa **Operacional**, título "Suporte: …", com o Rossini como responsável e o Alexandre
  acompanhando, os dois avisados. Usar o tipo existente evita mexer no fluxo de etapas.

## Etapa 1: canal e tela (pronta no local em 20/10/2026)

- [x] T1.1 Tabelas `suporte_conversas` e `suporte_mensagens`, com o wamid único para não duplicar o que a Meta
  reentrega, e a permissão `suporte` no admin.
- [x] T1.2 Webhook `GET/POST /suporte/whatsapp/webhook`: o aperto de mão (verify token) e a assinatura
  X-Hub-Signature-256. Só entra o evento do nosso `phone_number_id`.
- [x] T1.3 O que o webhook recebe:
  - mensagens do cliente (texto, mídia com legenda, botão, resposta de lista);
  - `smb_message_echoes`: a resposta pelo app do celular passa a conversa para o humano;
  - `statuses`: enviada, entregue, lida, falhou.
- [x] T1.4 Reconhecer o cliente pelo telefone: DDD mais os 8 últimos dígitos, para cobrir o 9º dígito que falta em
  números do WhatsApp. Só reconhece se o número for de um cliente só.
- [x] T1.5 Envio pela Cloud API (v20.0, a mesma do Gestor), só dentro da janela de 24 h.
- [x] T1.6 Tela Desenvolvimento › Suporte WhatsApp:
  - lista e conversa, com os sistemas e contratos do cliente;
  - responder, Assumir e Devolver ao Pedro;
  - ligar um número desconhecido a um cliente;
  - recarrega sozinha quando chega mensagem.
- [x] T1.7 Testes (`tests/Feature/Suporte/SuporteWhatsAppTest.php`, 5 testes).

## Etapa 2: o Pedro (pronta no local em 20/10/2026)

O Felipe decidiu em 20/10/2026 usar **a IA que já está no Matriz**: o Claude ou o Codex do runner da conta dele, e
não uma chave de API.

- [x] T2.1 Conta de sistema "Pedro (suporte)" (`User::EMAIL_DO_PEDRO`), desativada e sem perfil, que assina os
  atendimentos. O runner autorizado é o da conta `services.suporte_pedro.conta` (o Felipe).
- [x] T2.2 Pacote de contexto (`Suporte\Contexto`):
  - o cliente, os sistemas ativos e os contratos, sem valores;
  - os alertas abertos do Monitor e as manutenções dos próximos 3 dias;
  - os atendimentos, com etapa;
  - a base do sistema, sem os preços;
  - as últimas 15 mensagens, marcadas como dado de terceiros.
- [x] T2.3 Fila própria (`suporte_pedidos`), um pedido aberto por conversa:
  - de segunda a sexta, das 07:00 às 18:00;
  - fora do horário, a mensagem fica para a abertura (`suporte:pedro`, a cada minuto);
  - com um humano na conversa, o Pedro não entra.
- [x] T2.4 Runner, módulo `suporte.mjs`:
  - uma vaga só do Pedro, fora do PARALELO;
  - Claude ou Codex sem nenhuma ferramenta, pelo stdin, numa pasta temporária vazia, com 90 s de teto;
  - a resposta é um bloco `<<<PEDRO {…} PEDRO>>>`, validado no Matriz.
- [x] T2.5 Ações:
  - responder;
  - abrir atendimento: tarefa Operacional "Suporte: …" no sistema do cliente, com o Rossini de responsável, aviso no
    sino ao Rossini e ao Alexandre, Telegram no grupo de alertas e "Seu atendimento é o nº T-N" ao cliente;
  - consultar a situação e o status, pelo contexto.
- [x] T2.6 Reserva sem IA: se a resposta não chega em 120 s, ou volta fora do formato, o Matriz responde "Recebemos
  sua mensagem…", abre o atendimento e passa a conversa para a equipe.
- [x] T2.7 Testes:
  - `PedroTest` (5) e `test-suporte.mjs`;
  - prova com a IA real em 20/10/2026: cerca de 12 s, tanto no Sonnet quanto no Haiku. Ficou o Sonnet, com resposta
    mais completa.

## Etapa 3: base de conhecimento editável

- [ ] T3.1 Tela de base por sistema (o que faz, perguntas frequentes, o que não prometer), começando de
  `modelos-por-sistema.md`.

## Etapa 4: ligar o número (precisa de liberação)

- [ ] T4.1 A Alfa como Tech Provider da Meta (verificação da empresa e revisão do app). Plano B: um parceiro.
- [ ] T4.2 Coexistência do (27) 3109-3265 pelo Embedded Signup v4, com a sincronização de contatos e histórico nas
  primeiras 24 h.
- [ ] T4.3 Antes de ligar o Pedro, conferir duas coisas:
  - a política atual da Meta para IA no WhatsApp Business;
  - os termos das assinaturas do Claude e do ChatGPT para uso automatizado no atendimento a clientes.
- [ ] T4.3b Memória do WSL: parar os containers parados ou aumentar o `.wslconfig` antes de ligar. O Pedro roda no
  runner da máquina do Felipe.
- [ ] T4.4 Configuração no `.env` de produção e deploy, com a liberação do Felipe.
