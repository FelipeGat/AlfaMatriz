# Feature Specification: Acesso VixCard (Victor)

**Feature**: `acesso-vixcard`
**Created**: 2026-10-18
**Status**: Draft (local)
**Pedido**: Felipe, 18/10/2026: "crie um usuário para o Victor ter acesso ao Matriz trazendo para ele APENAS VixCard.
A ideia é ele ver os andamentos dos projetos que temos relacionados à VixCard: agendas, projetos, entregas" · "ele é
um cara de gestão, bem acelerado: quer bater o olho na tela e ver os projetos dele, com resumo de cada um em andamento
e como está. Simples e funcional. Em modo card e lista."

## Decisões (18/10/2026)

- Os projetos da VixCard moram no **quadro de Tarefas**. Cada produto é um sistema com natureza `vixcard`
  (spec `produtos-vixcard`), e cada frente é uma tarefa.
- O Victor **vê e opera**: cria, edita, move, comenta e entrega, mas **só** nas tarefas dos produtos VixCard. Não tem
  triagem.
- Os 8 projetos do VixOrganize viram tarefas-mãe, uma por produto.
- O VixOrganize sai do caminho; a integração fica parada no código.

## Escopo

1. Perfil **VixCard** (`vixcard`): `tarefas` (ler, incluir, editar) e `agenda` (ler, incluir, editar). Nada mais.
2. **Escopo VixCard**: quem tem o perfil só enxerga, em qualquer consulta da sessão:
   - tarefas de sistema `vixcard`, e os itens, anexos, comentários, entregas e eventos delas;
   - sistemas `vixcard`;
   - compromissos ligados a uma tarefa ou a um sistema VixCard, ou dos quais ele participa ou que ele criou;
   - nas listas de pessoas, só quem trabalha nas tarefas VixCard (responsáveis, criadores e participantes) e ele
     mesmo.
3. **Projetos VixCard**, a tela inicial dele, com alternância entre card e lista. Para cada produto mostra:
   - resumo (a descrição da tarefa-mãe);
   - situação (em dia, atenção ou atrasado);
   - andamento (% pelo peso da etapa de cada frente; ver abaixo);
   - o que está em andamento agora;
   - próximo prazo, última entrega e próximo compromisso.
   Um clique leva ao quadro filtrado pelo produto.

**Andamento** (18/10/2026): é a média das frentes, pelo peso da etapa de cada uma: fila 0%, em andamento 25%,
revisão 50%, staging 75%, produção 90% e concluída 100%. A tarefa-mãe parada na fila não entra na conta.

## Requirements

- **FR-001**: Com o escopo, `Tarefa`, `Sistema` e `Compromisso` MUST ser filtrados por escopo global ligado à
  sessão. Busca por id, route binding e relações (mãe, subtarefas) de fora MUST resultar em 404 ou ficar vazias.
- **FR-002**: Criar ou editar tarefa MUST aceitar só sistema VixCard, mãe VixCard e compromisso visível.
- **FR-003**: Itens, anexos, comentários e entregas de tarefa fora do escopo MUST dar 404.
- **FR-004**: As listas de filtro (sistemas, pessoas) e a equipe da Agenda MUST trazer só o que o escopo permite.
- **FR-005**: O MCP MUST recusar conta com escopo VixCard.
- **FR-006**: Sem o perfil, nada muda para ninguém: o escopo só existe na sessão de quem tem o perfil.
- **FR-007**: A tela Projetos VixCard MUST abrir como tela inicial dele, em card ou lista, e a escolha MUST ficar
  lembrada no navegador.

## User Scenarios & Testing

1. **Given** o Victor logado, **Then** ele cai em Projetos VixCard e vê um card por produto, com resumo, % e situação.
2. **Given** uma tarefa do AlfaGym, **When** o Victor abre `/tarefas?tarefa=<id>`, o modal ou um comentário dela,
   **Then** recebe 404, e ela não aparece no quadro, na Agenda nem nas listas (FR-001, FR-003).
3. **Given** o formulário, **When** ele tenta criar tarefa no AlfaControl, **Then** recebe 422 (FR-002).
4. **Given** um compromisso do time sem ligação com a VixCard, **Then** ele não aparece na Agenda (FR-001).
5. **Given** o admin, **Then** tudo continua igual (FR-006).

## Delta Requirements

**Skip**: frente nova sem `docs/specs/current/`. — Claude (pedido do Felipe), 2026-10-18
