---
name: execucao-sequencial-apos-perda-em-paralelo
description: Rossini prefere execução sequencial feita por mim a faixas paralelas headless, depois de 10 faixas perderem o trabalho
metadata:
  type: feedback
---

Em 06/08/2026, no redesign visual do AlfaMatriz, 10 das 12 faixas paralelas do
`executar-tarefas.sh` (skill onp-spec-driven) perderam o trabalho: os agentes
headless fizeram as telas mas não commitaram, o `git merge` devolveu "already
up to date", o script leu isso como sucesso e rodou `git worktree remove
--force`, apagando o worktree com as alterações. ~US$ 45 sem resultado.

Diante disso, Rossini pediu para fazer "uma de cada vez".

**Why:** o script não distingue "nada para mesclar" de "havia trabalho não
commitado". Entregar rápido não vale nada se o trabalho evapora na mesclagem.

**How to apply:** em features grandes neste projeto, prefira implementar tarefa
a tarefa na árvore principal, commitando cada uma antes de começar a próxima.
Se for usar as faixas paralelas, primeiro ponha no executor a trava que
commita o que sobrou no worktree e recusa apagar worktree sujo. Relacionado:
[[onp-spec-fundacao-antes-das-faixas]].
