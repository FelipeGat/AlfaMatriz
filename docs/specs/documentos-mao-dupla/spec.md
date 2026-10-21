# Feature Specification: Documentos em mão dupla com o OneDrive

**Feature**: `documentos-mao-dupla`
**Created**: 2026-10-19
**Status**: Draft
**Pedido**: Felipe, 19/10/2026: "pode até diminuir um pouco esses cards, deixando até 5 por linha e a pessoa
escolhe modo linha ou card. E preciso que tenha uma forma de criar pasta e arrastar documentos de fora para
dentro e ele sincronizar com o OneDrive."
**Base**: `docs/specs/documentos-espelhados` (o espelho de ida, OneDrive → Matriz).

## Decisões do Felipe (19/10/2026)

- **Quem altera:** quem tem acesso à pasta, hoje o Felipe e o Victor. Ver continua igual.
- **O que se pode fazer pelo Matriz:** criar pasta, enviar arquivos arrastando, apagar e mover/renomear.

## Contexto

- O servidor do Matriz não alcança o OneDrive; a máquina do dono alcança. Por isso o Matriz **não grava** no
  OneDrive: ele anota a alteração, e o runner do dono a aplica na pasta. Depois o espelho de ida confirma.
- O PHP de produção aceita até 13 MB por requisição. Por isso o navegador envia o arquivo em partes de 8 MB, como
  o runner já faz.

## Escopo

1. **Tela.** Os cards ficam menores, com até 5 por linha. A pessoa escolhe entre **Cards** e **Linhas**, e a escolha
   fica lembrada no navegador. Há também:
   - o botão **Nova pasta**, no nível em que a pessoa está;
   - **arrastar arquivos** para a tela, ou o botão **Enviar arquivos**: vão para o nível atual;
   - em cada arquivo e em cada pasta, **Mover/renomear** (novo caminho) e **Apagar** (com confirmação);
   - o painel **Aguardando a sua máquina**, com o que ainda não foi aplicado no OneDrive, e as alterações
     recentes, com quem fez, o que fez e quando.
2. **Fila de alterações** (`documento_operacoes`): tipo, caminho, destino, quem pediu, situação
   (`pendente`/`feita`/`falhou`) e erro. O arquivo enviado fica no disco privado até o runner gravá-lo.
3. **Runner**: a cada ~30 s, pergunta se há alteração pendente nas pastas dele. Se houver, aplica cada uma na pasta
   do OneDrive, devolve o resultado e espelha de novo em seguida.

## Requirements

- **FR-001**: Só quem vê a pasta (o dono e as pessoas com acesso) MUST poder alterá-la; os outros recebem 404.
- **FR-002**: Todo caminho MUST passar pelo `Espelho::caminhoSeguro` no servidor, e de novo no runner, que resolve o
  caminho dentro da raiz configurada. MUST NOT existir: `..`, caminho absoluto, alvo fora da raiz ou a própria
  raiz.
- **FR-003**: O envio MUST respeitar os mesmos limites do espelho: no máximo 200 MB e sem executável. O arquivo
  chega em partes e MUST ser conferido pelo tamanho.
- **FR-004**: Arquivo enviado com o nome de um que já existe MUST NOT sobrescrever: ganha o sufixo " (2)", " (3)"…
  Mover para um destino que já existe MUST falhar com o motivo.
- **FR-005**: Uma alteração aplicada MUST se refletir no Matriz sem esperar o ciclo de 15 min:
  - **enviar** cria o documento com o próprio arquivo, sem subir de novo;
  - **mover** troca o caminho dos documentos;
  - **apagar** tira os documentos do Matriz.
- **FR-006**: Pasta vazia MUST aparecer, porque o inventário passa a mandar também as pastas.
- **FR-007**: Toda alteração MUST guardar quem pediu. Apagar no OneDrive manda o arquivo para a lixeira online do
  OneDrive, que o guarda por 30 dias.
- **FR-008**: Alteração que a máquina não aplicou MUST aparecer como falha, com o motivo, e MUST NOT ser repetida
  sozinha.

## Fora do escopo

- Arrastar uma **pasta inteira** do computador. Por enquanto vão só arquivos; a pasta se cria pelo botão.
- Editar arquivo dentro do Matriz.
