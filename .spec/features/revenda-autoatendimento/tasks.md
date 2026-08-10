# Tasks: Revenda autoatendimento

> feature: revenda-autoatendimento

<!--
  CONVENÇÃO DESTE REPO: a tag `@spec:AC-xxx` vai no DOCBLOCK do método de teste
  (o adaptador é tools/onp-spec-tap.php). Exemplo:

      /** @spec:AC-098 A revenda cadastra o cliente pela Matriz. */
      public function test_revenda_abre_o_cadastro(): void

  Teste sem a tag = critério sem prova, e o audit acusa.
-->

## T-066 — Perfil "revenda" e o acesso criado no provisionamento [pendente]

- Refs: US-044, AC-105, AC-106
- Arquivos: database/seeders/PerfilPermissaoSeeder.php, app/Http/Controllers/RevendaController.php, resources/views/revendas/index.blade.php, tests/Feature/RevendaAutoatendimento/AcessoDaRevendaTest.php
- Modelo: claude-sonnet-5
- Esforço: alto
- Notas: base das demais (o perfil precisa existir antes de qualquer teste de escopo). Perfil novo `revenda` no seeder, com `revendas` e `clientes` (ler + incluir) e NADA de sistemas/dashboard/leads/faturamento — decisão do usuário: `operacao` mostraria menus da Alfa. No `RevendaController::provisionar`, além de chamar o `ProvisionadorAlfaGymService`, criar o `User` local com `revenda_id` e o perfil `revenda`, na MESMA transação do provisionamento: usuário criado com o gym recusando deixaria um acesso apontando para revenda não provisionada. O modal de provisionar já coleta nome/e-mail/senha do admin — reusar esses campos, sem pedir de novo.

## T-067 — Cliente criado no AlfaGym a partir da Matriz [pendente]

- Refs: US-041, AC-099, AC-100
- Arquivos: app/Services/ProvisionadorClienteAlfaGymService.php, tests/Feature/RevendaAutoatendimento/ProvisionadorClienteAlfaGymTest.php
- Modelo: claude-sonnet-5
- Esforço: alto
- Notas: o serviço que fala com o `POST /api/matriz/v1/clientes` aberto na feature `api-matriz-escrita` do AlfaGym. Envia `revenda_id_externo` (a âncora da revenda no sistema, via `idExternoNoSistema`), `nome`, `cnpj`, `telefone`, `cidade`, `uf`, `nome_admin`, `email_admin`, `senha_admin`. Espelha o padrão dos serviços irmãos (`ProvisionadorAlfaGymService`, `GerenciadorLicencaAlfaGymService`): header `X-Matriz-Key`, confere `contrato == 1.0`, recusa vira `RuntimeException` com a mensagem do gym. Ao voltar, ancora o cliente (`ancorarEm`) e grava `status_saas` no vínculo `cliente_sistema` — é o que faz a tela mostrar "pendente de licença" sem esperar o sync. Revenda sem âncora no gym recusa com mensagem clara (a revenda precisa estar provisionada antes).

## T-068 — A revenda cadastra o próprio cliente [pendente]

- Refs: US-041, US-043, AC-098, AC-101, AC-104
- Arquivos: app/Http/Controllers/ClienteController.php, resources/views/clientes/_form.blade.php, tests/Feature/RevendaAutoatendimento/CadastroPelaRevendaTest.php
- Modelo: claude-sonnet-5
- Esforço: alto
- Notas: remover o `abort_if(temEscopoDeRevenda)` de `create` e `store` — é o que hoje recusa a revenda ("Os clientes da sua revenda são provisionados pela matriz"). No `store`, forçar `revenda_id` do escopo do usuário quando ele tem revenda (mesma regra que o `update` já aplica), nunca aceitar do formulário. No `create`, a lista de revendas fica só com a dele. Admin continua escolhendo entre todas as ativas (AC-104). Campos novos no `_form` (nome/e-mail/senha do admin da academia) visíveis quando o AlfaGym está marcado nos sistemas. Cliente cadastrado pela revenda nasce AVULSO: valor mensal e dia de vencimento não aparecem para ela (ASM-040). A criação local e a chamada ao gym (T-067) rodam dentro de `DB::transaction` — recusa do gym desfaz a gravação local (AC-100).

## T-069 — Licença é assunto da Alfa, não da revenda [pendente]

- Refs: US-042, AC-102, AC-103
- Arquivos: resources/views/clientes/_tabela.blade.php, tests/Feature/RevendaAutoatendimento/LicencaSoDoAdminTest.php
- Modelo: claude-sonnet-5
- Esforço: medio
- Notas: as quatro ações de licença (liberar/renovar/suspender/reativar) hoje aparecem para qualquer um que chegue na tabela. Esconder para quem tem escopo de revenda — e, mais importante, RECUSAR no controller: esconder botão não é autorização. O `ClienteController::liberarLicenca` e irmãos já chamam `autorizarAcesso`, que só confere se o cliente é da revenda dele; falta negar a operação em si para escopo de revenda. O teste de AC-103 precisa provar que nenhuma licença foi criada ou alterada, não só que a resposta foi 403.

## T-070 — Acesso das revendas migradas [pendente]

- Refs: US-045, AC-107
- Arquivos: app/Console/Commands/CriarAcessosDeRevendas.php, tests/Feature/RevendaAutoatendimento/AcessosDeRevendasMigradasTest.php
- Modelo: claude-sonnet-5
- Esforço: medio
- Notas: comando `alfa:criar-acessos-revendas`. Para cada revenda sem `User` com aquele `revenda_id`: cria o usuário com o e-mail de contato dela, perfil `revenda`, senha forte GERADA e impressa na saída (decisão do usuário — nada compartilhado entre revendas). Idempotente: revenda que já tem acesso é relatada como "já tinha" e NÃO tem a senha redefinida — rodar de novo não pode derrubar quem já entrou. Revenda sem e-mail de contato vira pendência relatada, não recebe acesso inventado (ASM-039).

## T-071 — Relatório de conferência da migração [pendente]

- Refs: US-045, AC-108
- Arquivos: app/Console/Commands/ConferirMigracaoAlfaGym.php, tests/Feature/RevendaAutoatendimento/ConferenciaDaMigracaoTest.php
- Modelo: claude-sonnet-5
- Esforço: medio
- Notas: comando `alfa:conferir-migracao-alfagym`, três recortes separados: (1) clientes sem `revenda_id`; (2) clientes com licença no vínculo mas sem `licenca_id_externo` — sem essa âncora, renovar e suspender falham depois da virada; (3) revendas sem usuário de acesso. Sai 0 só quando as três listas estão vazias; qualquer divergência sai 1, para servir de conferência antes de virar a chave. Só lê, nunca corrige — quem corrige é o sync ou o comando de T-070.

## T-072 — Fechar as pendências que o audit já aponta neste código [pendente]

- Refs: US-041, AC-099
- Arquivos: .spec/features/clientes-via-revenda/tasks.md
- Modelo: claude-sonnet-5
- Esforço: baixo
- Notas: o audit acusa `ProvisionadorAlfaGymService.php` como código órfão (nenhuma tarefa o mapeia) desde a feature anterior. Ele é justamente o serviço que T-066 passa a usar para criar o acesso da revenda — mapear na tarefa que o originou (`clientes-via-revenda`), sem inventar requisito novo. Também rodar `onp-spec verify` nas features com prova desatualizada (dominio-proprio, fluxo-deploy, gestao-acessos, redesign-visual) para o gate desta feature não ficar ilegível sob erro alheio.
