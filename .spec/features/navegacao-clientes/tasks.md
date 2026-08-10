# Tasks: Navegação clientes

> feature: navegacao-clientes

<!--
  CONVENÇÃO DESTE REPO: a tag `@spec:AC-xxx` vai no DOCBLOCK do método de teste
  (o adaptador é tools/onp-spec-tap.php).
-->

## T-079 — Cadastro de cliente tem um lugar só [concluida]
- Refs: US-048, AC-115, AC-116
- Arquivos: app/Http/Controllers/ClienteController.php, routes/web.php, resources/views/clientes/_modal-novo.blade.php, resources/views/clientes/index.blade.php
- Modelo: claude-sonnet-5
- Esforço: medio
- Notas: a página `clientes/create.blade.php` era órfã (zero referências em views) e duplicava o mesmo `_form`. Apagada; `ClienteController::create()` removido e a query de revendas ATIVAS que só ela tinha migrou para `dadosDaLista()` como `revendasParaCadastro` — o modal usava a lista do filtro, que inclui inativas. Rota vira `->except(['create', 'show'])`: sem isso `clientes.create` apontaria para método inexistente (500, não 404), e `show` nunca teve método. O modal virou a partial `_modal-novo`, com `:show` para reabrir quando há erro — sem isso a recusa do AlfaGym volta com o modal fechado e a tela parece inerte.

## T-080 — A aba de clientes ganha cabeçalho e cadastro próprios [concluida]
- Refs: US-048, AC-115, AC-116, AC-119, AC-121
- Arquivos: app/Http/Controllers/RevendaController.php, resources/views/revendas/index.blade.php, resources/views/clientes/_tabela.blade.php
- Modelo: claude-sonnet-5
- Esforço: medio
- Notas: o slot `acoes` era fixo em "+ Nova revenda" e por isso a aba de clientes não tinha por onde cadastrar. Agora a ação segue a aba, cada uma no seu gate (cliente exige `canPermissao('clientes','incluir')`; revenda esconde de quem tem escopo, que tomaria 403). O `contexto` também segue a aba — usava `$linhas`, vazia na aba de clientes, e dizia "0 de N" com a lista cheia. O form de filtro do `_tabela` ganhou o hidden `aba`: sem ele, buscar submetia para `/revendas` sem recorte e expulsava o usuário da aba onde estava.

## T-081 — Descoberta a partir do menu [concluida]
- Refs: US-048, AC-117, AC-118
- Arquivos: resources/views/layouts/navigation.blade.php
- Modelo: claude-sonnet-5
- Esforço: baixo
- Notas: rótulo do item passa a "Revendas e clientes" — o `pattern` já cobria `clientes.*`, então a marcação de ativo continua correta. O item ganha `params`: para usuário de revenda aponta direto para `?aba=clientes`, a carteira dele. `$escopo` precisou subir para ANTES do array `$grupos`, senão estaria indefinido no ponto de uso. Descartado dar subitens ao menu: o rail recolhido tem 60px e o menu é deliberadamente plano.

## T-082 — Os testes que provam o caminho, não o endpoint [concluida]
- Refs: US-048, AC-115, AC-116, AC-117, AC-118, AC-119, AC-120, AC-121
- Arquivos: tests/Feature/NavegacaoClientes/CaminhoAteOCadastroTest.php, tests/Feature/RevendaAutoatendimento/CadastroPelaRevendaTest.php, tests/Feature/Redesign/MigalhasTest.php, tests/Feature/Redesign/ShellTest.php
- Modelo: claude-sonnet-5
- Esforço: alto
- Notas: a classe de teste que faltava é "seguir a interface a partir do menu" — AC-117 extrai os href de dentro de `<nav id="menu-principal">`, segue os links e exige que a cadeia termine numa página com o cliente E com o formulário de cadastro. Cuidado descoberto ao escrever: `route('clientes.store')` e `route('clientes.index')` são a MESMA URL, então assertar a URL sozinha passa por acaso (o link da logo já a contém para usuário de revenda) — as asserções precisam de marcador específico do formulário. Os ACs AC-098 e AC-104 de `revenda-autoatendimento` foram reapontados do endpoint para a tela alcançável, ficando mais verdadeiros. `MigalhasTest` e `ShellTest` trocaram a tela órfã pela de edição, que tem a mesma estrutura de migalha.
