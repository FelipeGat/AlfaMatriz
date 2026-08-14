# Tasks: Permissoes editar e imprimir

> feature: permissoes-editar-e-imprimir

## T-001 — A ação `imprimir` passa a recusar [pendente]

- Refs: US-077, AC-272, AC-273, AC-274
- Arquivos: routes/web.php, app/Http/Controllers/FaturamentoController.php, tests/Feature/Permissoes/ImprimirTest.php
- Notas: `cobrancas.anexos.download` e `contas-pagar.anexos.download` ganham a ação fixada no middleware (`permissao:cobrancas,imprimir`), como `usuarios.ativo` já faz — sem fixar, o GET seria lido como `ler` e nada mudaria. A exportação do faturamento é o caso torto: ela não tem rota própria, é a MESMA `faturamento.index` com `?exportar=csv`, então a recusa não cabe no middleware e vai no controller, onde o parâmetro é lido. Fixe a ação também no `tarefas.anexos.ver`? **Não** — ele fica em `ler` de propósito (ver "Fora de escopo": é como o modal pinta o print, não é tirar dado de dentro).

## T-002 — A coluna `editar` e o backfill [pendente]

- Refs: US-078, AC-279, AC-280
- Arquivos: database/migrations/2026_08_15_090000_separar_editar_de_incluir.php, database/seeders/PerfilPermissaoSeeder.php, tests/Feature/Permissoes/MigracaoEditarTest.php
- Notas: coluna booleana `editar` em `perfil_permissao`, `default(false)`, e **backfill `editar = incluir`** na mesma migração — é ele que cumpre o AC-279, e sem ele a publicação tira a edição de todo mundo de uma vez. O `down()` derruba a coluna. O seeder passa a conceder `editar` junto de `incluir` em cada linha que já escreve; o `admin` recebe os cinco. Espelhe a migração do bloqueio (`2026_08_11_140000_bloqueio_vira_marca_na_tarefa.php`), que é o padrão de backfill deste repositório. **Rode `grep -r` por `editar` em `database/migrations` antes de criar** — segundo backfill sobre dado já migrado é o pior erro possível aqui.

## T-003 — `editar` vira ação de verdade no domínio [pendente]

- Refs: US-078, AC-275, AC-276, AC-277
- Arquivos: app/Models/User.php, app/Http/Middleware/ChecarPermissao.php, app/Http/Controllers/PerfilController.php, resources/views/usuarios/_permissoes.blade.php, tests/Feature/Permissoes/GradeComEditarTest.php
- Notas: `User::ACOES` e `PerfilController::AÇÕES` ganham `editar` — as duas listas viram nome de coluna, e uma sem a outra faz a grade salvar o que ninguém lê. `ChecarPermissao` passa a mapear `PUT`/`PATCH` para `editar` (hoje caem em `incluir`). A grade da view ganha a quinta caixa. **Antes de fechar, varra as views por `canPermissao(..., 'incluir')`** (ASM-064): tela que decide mostrar o botão de editar perguntando por `incluir` passaria a oferecer o que a rota vai recusar — botão que aparece e dá 403 se lê como sistema quebrado.

## T-004 — As edições que usam POST [pendente]

- Refs: US-078, AC-278
- Arquivos: routes/web.php, tests/Feature/Permissoes/EdicaoPorPostTest.php
- Notas: fixar `,editar` rota a rota. São edição: `tarefas.mover`, `tarefas.bloquear`, `tarefas.conversar`, `tarefas.posicionar`, `tarefas.itens.ordenar`, `leads.mover`, `cobrancas.baixar`, `cobrancas.baixarEmMassa`, `contas-pagar.baixar`, `contas-pagar.baixarEmMassa`, `contas-fixas-pagar.pausar`, as quatro de `clientes.*Licenca` e `usuarios.senha`. **Continuam em `incluir`** porque CRIAM registro: `revendas.provisionar`, `faturamento.gerar`, `contas-fixas-pagar.gerar`, `precos.store`, `tarefas.itens.store`, `tarefas.anexos.store` (ASM-063 — confirme a lista com o dono do produto). **Depende de T-003**: sem a ação existindo, fixá-la no middleware recusa todo mundo. Mesmo arquivo de rotas que T-001, então as duas não rodam juntas.
