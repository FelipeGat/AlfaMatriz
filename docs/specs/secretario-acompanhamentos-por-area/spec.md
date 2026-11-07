# Feature Specification: De quem é cada coisa em Acompanhamentos

**Feature**: `secretario-acompanhamentos-por-area`
**Created**: 2026-11-07
**Status**: Draft
**Pedido**: Felipe, 07/11/2026, com a captura de Acompanhamentos (114 notificações misturadas):

> "esses acompanhamentos, tem que distinguir o que é para mim e o que é para o Rossini e o que é para o Alexandre.
> Tudo que for suporte é Alexandre, tudo que for suporte e desenvolvimento é Rossini, tudo que for comercial e
> administrativo para mim. Tanto do gestor quanto do matriz."

## O problema

Acompanhamentos lista tudo o que chegou à conta, e a conta do Felipe recebe aviso de tudo: a sincronização que caiu
(vai a todo administrador), a fila do WhatsApp (vai a todo atendente), a licença que expira (a quem vê Clientes), a
pergunta numa tarefa. Nada na linha diz de quem é o aviso, então ele lê os 114 para achar os dele.

## Leitura do pedido

- **Suporte → Alexandre**: o atendimento ao cliente — o WhatsApp do Pedro, a fila, a cobrança do atendimento, a
  avaliação ruim, a tarefa que nasceu de um atendimento.
- **Suporte e desenvolvimento → Rossini**: o suporte que mexe em sistema e o desenvolvimento — sistema que parou de
  sincronizar, Manutenção, Central de IA, tarefa de desenvolvimento e bug.
- **Comercial e administrativo → Felipe**: clientes, licenças, revendas, funil, orçamentos, cobranças, contas,
  faturamento, usuários — e o retrato do Gestor (orçamentos, funil e reuniões de cliente são comerciais; cobranças e
  contas a pagar, administrativas).
- **O que é dirigido à pessoa é dela, de qualquer área**: a pergunta feita a ela numa tarefa, a tarefa que ela
  ganhou, a validação, a volta para corrigir, o prazo e o lembrete dela, o compromisso dela, o cliente do WhatsApp
  que pediu por ela pelo nome ("Chamaram você"). O Rossini perguntar ao Felipe numa tarefa de desenvolvimento é o
  Rossini esperando o Felipe; marcar "do Rossini" faria a tarefa parar.
- **Notícia de tarefa é de quem está com ela** (o responsável); sem responsável, da área pelo tipo.

## Escopo

1. Cada notificação e cada linha da tabela mostram de quem são: "Você", "Rossini · Desenvolvimento",
   "Alexandre · Suporte", "Você · Comercial".
2. Um filtro "De quem é" no topo — Para mim, Rossini, Alexandre, Todos — vale para as notificações e para a tabela,
   com a contagem do que está a tratar, que baixa com o Tratado. A tela abre em "Para mim" e lembra a última escolha.
3. A tela diz quem cuida de cada área, e a regra do que é pessoal.
4. Vale para todas as contas com Acompanhamentos: na tela do Rossini, a licença é "Felipe · Comercial".

**Fora desta etapa**: mudar quem RECEBE cada aviso (o Felipe continua recebendo o que é do Rossini e do Alexandre); o
sino; novos blocos do Gestor (o retrato não traz chamados de suporte hoje).

## User Scenarios & Testing

### User Story 1 - Separar o que é meu (Priority: P1)

**Acceptance Scenarios**:

1. **Given** avisos de sincronização, da fila do WhatsApp, de licença e de faturamento, **When** o Felipe abre
   Acompanhamentos, **Then** cada linha diz de quem é e o filtro mostra Para mim, Rossini e Alexandre com as contagens.
2. **Given** a pergunta do Rossini ao Felipe numa tarefa de desenvolvimento, **Then** ela é "Você · Desenvolvimento".
3. **Given** o retrato do Gestor, **Then** orçamento é "Você · Comercial" e cobrança, "Você · Administrativo".
4. **Given** o filtro em Rossini, **When** ele trata um aviso, **Then** a contagem do Rossini baixa; Desfazer devolve.

### Edge Cases

- **Área sem conta ativa** (e-mail ausente ou conta desativada): a linha diz só a área, e o filtro ganha "Sem dono".
- **Pessoa só nas tratadas**: leva o rótulo, mas não ganha botão.
- **Aviso sem destino nem tarefa**: é de quem recebeu.

## Requirements

- **FR-001**: Cada notificação e cada linha da tabela MUST mostrar a pessoa e a área, pelas regras acima.
- **FR-002**: O filtro "De quem é" MUST valer para as duas listas e MUST contar só o que está a tratar.
- **FR-003**: O dono de cada área MUST vir da configuração pelo e-mail da conta ativa (`services.areas`).
- **FR-004**: Quem recebe cada aviso MUST continuar igual.

## Success Criteria

- **SC-001**: Com o filtro em "Para mim", nenhum aviso de sincronização ou da fila do WhatsApp aparece.
- **SC-002**: Nenhuma pergunta feita ao Felipe aparece como de outra pessoa.

## Delta Requirements

**Skip**: melhoria sobre `secretario-notificacoes-em-acompanhamentos` e `secretario-central-do-dia`; não há
`docs/specs/current/` neste repositório. — Claude (pedido do Felipe), 2026-11-07
