# Tasks: Clientes via revenda

> feature: clientes-via-revenda

## T-053 — Revenda obrigatória no cadastro de cliente [pendente]

- Refs: US-033, AC-072
- Arquivos: app/Http/Controllers/ClienteController.php, resources/views/clientes/_form.blade.php, tests/Feature/Redesign/ClientesTest.php
- Modelo: claude-sonnet-5
- Esforço: baixo
- Notas: trocar `revenda_id` de `nullable` para `required` no `validated()` do ClienteController; remover a opção "— Venda direta da Alfa —" do select no `_form.blade.php`.

## T-054 — Listagem sem recorte "venda direta" e sync sempre vincula à revenda [pendente]

- Refs: US-033, AC-073, AC-074
- Arquivos: app/Http/Controllers/ClienteController.php, resources/views/clientes/index.blade.php, app/Services/SincronizadorAlfaGymService.php, tests/Feature/Redesign/ClientesTest.php, tests/Feature/SincronizadorAlfaGymTest.php
- Modelo: claude-sonnet-5
- Esforço: médio
- Notas: remover o filtro `revenda=direta` do controller (linhas ~28–30) e da view `index.blade.php` (opção `direta` e label "Venda direta"); garantir teste de sync provando que cliente chega vinculado à revenda via `revenda_id_externo`.

## T-055 — Aba "Clientes" dentro da tela de Revendas [pendente]

- Refs: US-034, AC-075, AC-076
- Arquivos: app/Http/Controllers/RevendaController.php, resources/views/revendas/index.blade.php, resources/views/layouts/navigation.blade.php, tests/Feature/Redesign/RevendasTest.php, tests/Feature/Autorizacao/EscopoDeRevendaTest.php
- Modelo: claude-sonnet-5
- Esforço: alto
- Notas: adicionar abas na tela de Revendas ("Revendas" e "Clientes"); a aba Clientes lista os clientes com filtro de revenda (admin vê todas, usuário de revenda vê só a própria); remover o item "Clientes" do menu lateral (decisão do usuário: "Some do menu").

## T-056 — Persistir status do cliente vindo do AlfaGym no vínculo [pendente]

- Refs: US-035, AC-077
- Arquivos: database/migrations/2026_08_08_120000_add_status_saas_to_cliente_sistema_table.php, app/Services/SincronizadorAlfaGymService.php, app/Models/Cliente.php, app/Models/Sistema.php, tests/Feature/SincronizadorAlfaGymTest.php
- Modelo: claude-sonnet-5
- Esforço: médio
- Notas: coluna nova `status_saas` no pivot `cliente_sistema` (pendente/ativo/bloqueado), gravada no `sincronizarClientes()` a partir de `$item['status']` (atualmente o payload traz `status` mas o serviço ignora). Adicionar ao `withPivot` de `Cliente` e `Sistema::clientes()`.

## T-057 — Admin libera a licença do cliente pelo AlfaGym [pendente]

- Refs: US-035, AC-078, AC-079, AC-080
- Arquivos: app/Http/Controllers/ClienteController.php, app/Services/LiberadorLicencaAlfaGymService.php, resources/views/clientes/index.blade.php, routes/web.php, tests/Feature/SincronizadorAlfaGymTest.php, tests/Feature/Redesign/ClientesTest.php
- Modelo: claude-sonnet-5
- Esforço: alto
- Notas: novo serviço `LiberadorLicencaAlfaGymService` que chama `POST /api/matriz/v1/licencas` (tipo mensal/anual, valor, obs; X-Matriz-Key) e grava o retorno no pivot; rota + ação "Liberar licença" visível só para clientes com `status_saas` pendente; cliente permanece vinculado à revenda (não vira avulso); recusa do gym vira erro sem gravar nada.
