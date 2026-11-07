# Feature Specification: Notificações tratadas uma a uma em Acompanhamentos

**Feature**: `secretario-notificacoes-em-acompanhamentos`
**Created**: 2026-11-07
**Status**: Draft
**Pedido**: Felipe, 07/11/2026, com a captura do sino mostrando 114 não lidas:

> "essa forma de ver as notificações é bem ruim, ela precisa me direcionar para resolver de forma individual cada uma
> nos acompanhamentos. em acompanhamentos preciso mostrar, além do que ela mostra, todas as notificações para tratar
> elas individualmente."

## O problema

O painel do sino mostra as 12 mais recentes, numa linha cortada cada, e o rodapé leva ao Centro de Controle, que não
lista notificação nenhuma. Com 114 não lidas, as outras 102 não aparecem em lugar nenhum. E clicar num aviso de
tarefa abria o quadro inteiro (`/tarefas`), e não a tarefa. Os avisos antigos ainda levavam ao endereço da tailnet.

## Escopo

1. **Acompanhamentos ganha a seção "Notificações"**, com TODAS as não lidas da conta, além do que a tela já mostra.
   Cada linha mostra o título inteiro, a origem, a hora e duas ações: **Abrir**, que leva aonde o aviso se resolve e o
   dá por tratado, e **Tratado**, que o tira da lista sem sair da tela, com **Desfazer** na mesma linha.
2. **Abas por gravidade**: Todas, Críticas, Atenção e Demais, com contagem e busca. A aba **Tratadas** lista as dos
   últimos 7 dias, com **Voltar a tratar**, para quem marcou por engano ou usou o "Marcar lidas" do sino.
3. **O sino leva para lá**: para quem tem Acompanhamentos, clicar num aviso abre Acompanhamentos com ESSE aviso
   destacado no topo da seção, sem dar por lido. O rodapé passa a ser "Tratar uma a uma em Acompanhamentos", com o
   total. Quem não tem a tela (revenda, sem a permissão do Secretário) continua como antes.
4. **Abrir leva ao lugar certo**: aviso de tarefa abre a própria tarefa (`/tarefas?tarefa=N`), e qualquer aviso
   abre no endereço em que o sistema está agora, mesmo que tenha sido gravado com outro.

**Fora desta etapa**: agrupar por assunto (o `tipo` não separa assunto, porque o Suporte usa `direcionamento`),
tratar em lote por grupo, e mudar quem recebe cada aviso.

## User Scenarios & Testing

### User Story 1 - Ver e tratar todas (Priority: P1)

**Acceptance Scenarios**:

1. **Given** 30 não lidas, **When** ele abre Acompanhamentos, **Then** a seção "Notificações" mostra as 30, críticas
   primeiro e as mais novas antes, e não mostra nenhuma de outra conta.
2. **Given** um aviso, **When** ele toca em Tratado, **Then** o aviso sai das não lidas, o número do sino baixa, e
   Desfazer o devolve.
3. **Given** um aviso de tarefa, **When** ele toca em Abrir, **Then** vai para a tarefa no quadro e o aviso fica
   tratado.
4. **Given** um aviso tratado há 2 dias, **Then** ele está na aba Tratadas com "Voltar a tratar". Um de 8 dias, não.

### User Story 2 - O sino direciona (Priority: P1)

**Acceptance Scenarios**:

1. **Given** a conta com Acompanhamentos, **When** ele clica num aviso do sino, **Then** cai em Acompanhamentos com o
   aviso destacado, e o aviso continua a tratar.
2. **Given** a conta de revenda, **Then** o aviso do sino abre o destino como antes, e o rodapé não cita
   Acompanhamentos.

### Edge Cases

- **Aviso sem destino** (`rota` nula): só Tratado, sem Abrir.
- **Destaque de aviso alheio** (`?notificacao=` de outra conta): ignorado, sem erro e sem mostrar nada dela.
- **Volume**: acima de 300 não lidas, mostra as 300 mais recentes e diz quantas ficaram de fora.

## Requirements

- **FR-001**: Acompanhamentos MUST listar todas as não lidas da conta (até 300, avisando o excedente), com título
  inteiro, origem, hora, gravidade e as ações Abrir e Tratado.
- **FR-002**: Tratado e Desfazer MUST valer sem recarregar a tela e MUST atualizar o número do sino.
- **FR-003**: A aba Tratadas MUST listar as lidas nos últimos 7 dias, com Voltar a tratar.
- **FR-004**: Para quem tem Acompanhamentos, o aviso do sino MUST levar a Acompanhamentos com ele destacado, sem dar
  por lido; o rodapé MUST levar à seção com o total.
- **FR-005**: Abrir MUST levar à tarefa quando o aviso tem tarefa e aponta para o quadro, e MUST usar o endereço atual
  do sistema.
- **FR-006**: Tudo MUST valer só para o destinatário, administrador incluído (Princípio I).

## Success Criteria

- **SC-001**: Com 114 não lidas, as 114 aparecem em Acompanhamentos, e cada uma se trata sem recarregar a página.
- **SC-002**: Nenhum aviso de tarefa leva ao quadro genérico.

## Delta Requirements

**Skip**: melhoria sobre `secretario-acompanhamento` e `secretario-central-do-dia`; não há `docs/specs/current/`
neste repositório. — Claude (pedido do Felipe), 2026-11-07
