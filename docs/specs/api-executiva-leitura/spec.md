# Feature Specification: API Executiva da Matriz (só leitura, para o ChatGPT)

**Feature**: `api-executiva-leitura`
**Created**: 2026-11-15
**Status**: Draft — aguardando aprovação (nenhum código)
**Pedido**: Felipe, 15/11/2026. Complementa a API Executiva do Gestor, sem duplicá-la e sem alterar os sistemas atuais.

## Escopo da v1 (ordem do pedido)

1. Agenda consolidada e compromissos.
2. E-mails prioritários e pendentes de resposta — só metadados.
3. Tarefas, pendências e decisões que aguardam o Felipe.
4. Financeiro da Alfa: receitas, despesas, cobranças, realizado e previsto.
5. Alertas operacionais e notificações relevantes.
6. Pedro: fila, avaliações e pendências próprias da Matriz.

## Regras (requisitos)

- **FR-001** Só `GET`. Nenhuma rota escreve, nem para marcar "visto".
- **FR-002** Token próprio, capacidade única `executivo:ler`, sem a `mcp`. O `/mcp` não é reaproveitado.
- **FR-003** Escopo fixo na conta do Felipe, pelo dono do token; nenhuma rota recebe `user_id` (Constitution I).
- **FR-004** Sem corpo de e-mail, mensagem de WhatsApp, telefone, documento, credencial ou conteúdo de ata.
- **FR-005** Nada do Gestor. O retrato do Gestor guardado na Matriz NÃO é reexposto.
- **FR-006** Todo bloco devolve `fonte`, `periodo`, `consultado_em`, `atualizado_em` e `situacao` (`ok | desatualizado | falha | nao_configurado`) com o motivo. Fonte fora do ar não derruba a resposta: o bloco vem com `situacao` e sem dados.
- **FR-007** Valor ausente é `null`, nunca zero. Realizado, previsto e lucro são campos separados, em centavos, com o critério escrito (Constitution VIII).
- **FR-008** Cada chamada é registrada (token, rota, hora, status), sem o conteúdo devolvido.
- **FR-009** Nenhuma porta pública e nenhuma mudança de rede nesta etapa.

## Falhas das integrações (já existem sinais, falta só expô-los)

- **Google Agenda**: `AgendaConsolidada::situacaoGoogle()` já classifica `ok | desatualizado (>30 min) | reconectar | desconectado | nao_configurado`, com a última leitura. `invalid_grant` marca a conta como `reconectar` (`GoogleAgenda::marcarReconectar`). Reuso direto.
- **E-mail (IMAP)**: `SecretarioEmailConta` guarda `situacao` (`ok|erro`), `erro` (código) e `lida_em`. Falta a regra de "desatualizado" por `lida_em`; a leitura de e-mail não tem equivalente ao `situacaoGoogle`.

## Decisões pendentes (para o Felipe)

1. **Como o ChatGPT se autentica**: Actions com chave de API (mais simples) ou OAuth, ou conector MCP só de leitura. Recomendação: chave de API em token Sanctum, curta e revogável.
2. **Validade do token**: sugerido 30 dias, renovável por comando.
3. **Exposição à internet**: produção só atende pela tailnet; o ChatGPT não a alcança. Expor só `/api/executivo/*` (como o webhook do GitHub, modelo em `deploy/cloudflared-alfamatriz.yml`) é decisão sua, fora desta etapa.
4. **LGPD**: o remetente de e-mail e o título de compromisso são dado de terceiros. Propostas: remetente só como nome (sem endereço), título truncado em 80 caracteres, sem participantes.
5. **Pedro**: aceitar só contagens e idades da fila, sem nome de cliente?
6. **Financeiro**: confirmar o critério de "realizado" (baixa) e "previsto" (aberto por vencimento) já usado nas telas, antes de devolver qualquer valor.
