# Tasks: Uso real dos sistemas

> feature: uso-real-dos-sistemas

## T-130 — Colunas de uso no vínculo cliente_sistema [concluida]
- Refs: AC-321
- Arquivos: database/migrations/2026_08_15_100000_retrato_de_uso_no_vinculo.php
- Notas: `uso_unidades` (int, nullable), `uso_metricas` (json, nullable),
  `uso_medido_em` (timestamp, nullable) — mesmo desenho do retrato de licença
  (2026_08_07_210000). Nada de backfill: o retrato nasce vazio e o primeiro
  ciclo preenche.

## T-131 — Capacidade sincroniza_uso nos sistemas [concluida]
- Refs: AC-322, AC-326
- Arquivos: database/migrations/2026_08_15_101000_capacidade_de_sincronizar_uso.php, database/seeders/SistemasPrecosSeeder.php, database/factories/SistemaFactory.php
- Notas: migração no padrão de 2026_08_11_095000 (atualiza a linha se existir;
  ver Q-041): `alfacontrol` ganha `sincroniza_uso`; `alfajornada` ganha
  `sincroniza` + `sincroniza_uso`. Seeder e factory acompanham para manter a
  paridade documentada no próprio seeder. Factory ganha o estado `alfajornada()`.

## T-132 — O sincronizador lê /uso e espelha no vínculo [concluida]
- Refs: AC-321, AC-322, AC-323, AC-324, AC-325
- Arquivos: app/Services/SincronizadorSistemaService.php, app/Console/Commands/SincronizarSistemas.php, app/Models/Sistema.php, app/Models/Cliente.php, app/Models/ClienteSistema.php
- Notas: passo `sincronizarUso()` espelhando o padrão das licenças, inclusive a
  limpeza do retrato quando a capacidade sai (AC-323). O JSON de métricas é
  gravado com `json_encode` explícito no serviço (o pivô não tem casts, de
  propósito) e lido por um helper no `ClienteSistema`. `Sistema::clientes()`
  passa a carregar as colunas novas no pivot; o comando relata o uso aplicado.

## T-133 — Provas dos critérios de aceite [concluida]
- Refs: US-088, US-089, AC-321, AC-322, AC-323, AC-324, AC-325, AC-326
- Arquivos: tests/Feature/IntegracaoMultiSistema/UsoRealDoSistemaTest.php
- Notas: um teste por critério, com `Http::fake` + `preventStrayRequests` como
  os vizinhos do diretório. O AC-326 sobe o AlfaJornada pela factory e roda o
  comando sem `--sistema`.
