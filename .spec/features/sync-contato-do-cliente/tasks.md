# Tasks: Sync contato do cliente

> feature: sync-contato-do-cliente

<!--
  CONVENÇÃO DESTE REPO: a tag `@spec:AC-xxx` vai no DOCBLOCK do método de teste
  (o adaptador é tools/onp-spec-tap.php).
-->

## T-077 — O sincronizador grava o contato do cliente [concluida]
- Refs: US-046, US-047, AC-109, AC-110, AC-111, AC-112, AC-113, AC-114
- Arquivos: app/Services/SincronizadorAlfaGymService.php, tests/Feature/SyncContatoDoCliente/ContatoDoClienteTest.php
- Modelo: claude-sonnet-5
- Esforço: medio
- Notas: em `sincronizarClientes()`, `email` e `telefone` saem do array de atribuição em massa (onde são descartados) e passam a ser gravados nas tabelas próprias, depois de o cliente existir. Regras, todas com critério de aceite: grava só o que veio preenchido (AC-112); casa por VALOR antes de criar, para o ciclo horário não empilhar cópias (AC-110); marca como principal apenas quando o cliente ainda não tem nenhum principal — se já tem, entra como adicional, porque o sync não desfaz escolha de gente (ASM-041); e NUNCA apaga o que já está lá, nem o que o time acrescentou na Matriz (AC-111, AC-114). Atenção ao padrão vizinho: `ClienteController::sincronizarEmails()` apaga tudo e regrava — aqui isso destruiria o contato cadastrado à mão, então o caminho é outro. AC-113 não pede código novo: o sincronizador já varre a coleção inteira a cada execução, então rodar `app:sincronizar-alfagym` depois da correção preenche os já migrados; o teste prova isso com um cliente que chega sem contato e passa a ter.

## T-078 — Registrar a dívida do descarte silencioso [concluida]
- Refs: US-046, AC-109
- Arquivos: README.md
- Modelo: claude-sonnet-5
- Esforço: baixo
- Notas: Q-016 ficou respondida como "não nesta feature". O que escondeu este bug — o Eloquent descartar em silêncio campo fora do `$fillable` — continua valendo para todo modelo do projeto. Registrar em uma linha, na seção de convenções do README, que atribuição em massa aqui descarta calada e que campo novo vindo de integração precisa ser conferido contra o `$fillable`. É o aviso barato que evita o próximo caso; a decisão de ligar `preventSilentlyDiscardingAttributes` fica para uma varredura própria.
