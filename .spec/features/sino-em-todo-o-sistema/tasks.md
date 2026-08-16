# Tasks: Sino em todo o sistema

> feature: sino-em-todo-o-sistema

<!--
  Como ler este arquivo (o formato é verificado por `onp-spec audit`):
  - T-xxx = tarefa (código de rastreio, único no projeto inteiro).
  - Toda tarefa referencia em `Refs:` pelo menos uma história de usuário
    (US-xxx) ou critério de aceite (AC-xxx).
  - Toda tarefa lista os arquivos que cria/altera em `Arquivos:`.
  Status: pendente | em-andamento | concluida
-->

## T-134 — Destinatários em lote no User [concluida]
- Refs: ASM-086, ASM-087
- Arquivos: app/Models/User.php
- Notas: `idsDeQuemVe(recurso, acao)` no molde de `idsDeQuemTriaTarefas`
  (ativo, sem escopo de revenda, consulta por capacidade) e
  `idsDeAdminsAtivos()` por slug — papel, não recurso.

## T-135 — O quadro avisa do começo ao fim [concluida]
- Refs: AC-327, AC-328, AC-329, AC-330, AC-331
- Arquivos: app/Http/Controllers/TarefaController.php, app/Services/FluxoTarefaService.php
- Notas: direcionamento e triagem no nascimento/edição (controller);
  conclusão/cancelamento na chegada terminal do `mover` (motor);
  exclusão sem `tarefa_id` para o aviso não apagar em cascata; o carimbo do
  painel de mover reusa `avisarTesteRegistrado`, extraído público.

## T-136 — Faturamento gerado avisa quem vê faturamento [concluida]
- Refs: AC-332
- Arquivos: app/Services/FaturamentoService.php
- Notas: no serviço, e não nos dois chamadores — a porta invisível era o cron
  do fechamento mensal. Só quando criou cobrança.

## T-137 — Licença: pedido para a matriz, decisão para a revenda [concluida]
- Refs: AC-333, AC-334
- Arquivos: app/Services/ProvisionadorClienteService.php, app/Services/GerenciadorLicencaService.php
- Notas: o aviso da decisão sai de `auditando()`, pelo mesmo motivo da
  auditoria (toda operação de licença passa ali); rota da revenda é
  `clientes.index`, a tela que o perfil dela alcança.

## T-138 — Conta: dono avisado, admins avisados [concluida]
- Refs: AC-335, AC-336, AC-337, AC-338
- Arquivos: app/Http/Controllers/UsuarioController.php, resources/views/components/nav-icon.blade.php
- Notas: ícone `key` novo (Heroicons outline) para o aviso de senha; a
  transição de admin é detectada em `sincronizarPerfis`, antes/depois do sync.

## T-139 — Lead convertido avisa o comercial [concluida]
- Refs: AC-339
- Arquivos: app/Http/Controllers/LeadController.php

## T-140 — Queda de sincronização vira marca e aviso de transição [concluida]
- Refs: AC-340, AC-341
- Arquivos: database/migrations/2026_08_15_160000_queda_de_sincronizacao_vira_marca_no_sistema.php, app/Models/Sistema.php, app/Services/SincronizadorSistemaService.php
- Notas: `sincronizacao_caiu_em` + motivo fora do `$fillable` (mesmo desenho
  do bloqueio da tarefa); aviso só na transição, nos dois sentidos; sistema
  sem configuração nunca marca queda.

## T-142 — Destravar avisa o responsável [concluida]
- Refs: AC-342
- Arquivos: app/Services/FluxoTarefaService.php, tests/Feature/TarefasDesenvolvimento/SinoDoCicloDaTarefaTest.php
- Notas: reversão pedida pelo dono em 16/08/2026 da decisão de 15/08. O aviso
  mora em `destravar()`, então o portão do staging que passa (e chama o mesmo
  método, sem autor) avisa junto — simétrico ao bloqueio automático. O
  destravamento de passagem no `mover` segue calado de propósito.

## T-141 — Testes de cobertura do sino [concluida]
- Refs: US-090, US-091, US-092, US-093, US-094, US-095
- Arquivos: tests/Feature/TarefasDesenvolvimento/SinoDoCicloDaTarefaTest.php, tests/Feature/SinoForaDoQuadro/FaturamentoAvisaTest.php, tests/Feature/SinoForaDoQuadro/LicencaAvisaTest.php, tests/Feature/SinoForaDoQuadro/ContaAvisaTest.php, tests/Feature/SinoForaDoQuadro/LeadConvertidoAvisaTest.php, tests/Feature/SinoForaDoQuadro/SincronizacaoCaiuTest.php
