# Feature Specification: Reunião vinculada ao produto e compartilhada com quem participou

**Feature**: `secretario-reuniao-produto`
**Created**: 2026-10-19
**Status**: Draft
**Pedido**: Felipe, 19/10/2026: "essa parte de transcrição, preciso que apenas eu consiga vincular a reunião ao
projeto."
**Base**: `docs/specs/secretario-reunioes`.

## Decisões do Felipe (19/10/2026)

- **Projeto** é o **produto do catálogo** (Vix-ID, VixLink, Vix-Beer, Gestor VixCard, SerraPark, AlfaGym…), e o
  seletor fica na linha, como o da Empresa.
- **Quem vê:** o dono e quem participou da reunião, **exceto clientes**. Ou seja, só quem tem conta no Matriz.

## Escopo

1. **Vínculo.** A reunião ganha o `sistema_id`, que só o dono da reunião escolhe, na lista e na página dela.
2. **Participantes com conta.** Na página da reunião, o dono marca quem participou entre as contas ativas do
   Matriz. Os nomes que a análise encontrou vêm pré-sugeridos, e nada é compartilhado sem a marcação do dono.
3. **O que o participante vê**, só para leitura:
   - título, data, produto e resumo;
   - decisões, próximos passos e as tarefas já aprovadas;
   - a ata.
   **Não vê** a transcrição, as propostas ainda não revisadas nem os campos de edição. É o retrato que se manda a
   quem esteve na reunião, e não o caderno do dono.
4. **Onde aparece:**
   - no sino, quando o participante é marcado;
   - em Reuniões › **Compartilhadas comigo**, para quem tem o Secretário;
   - em **Projetos VixCard**, no produto, para quem tem o acesso VixCard.

## Requirements

- **FR-001**: Só o dono MUST mudar o produto e os participantes; qualquer outro recebe 404.
- **FR-002**: A página do participante MUST checar se ele está marcado na reunião, e não o perfil: ninguém vê sem a
  marcação, administrador inclusive.
- **FR-003**: Desmarcar alguém MUST tirar o acesso na hora.
- **FR-004**: A visão do participante MUST NOT mostrar trechos da transcrição nem propostas pendentes.
- **FR-005**: No painel Projetos VixCard, só reuniões de produto VixCard compartilhadas com a pessoa.
