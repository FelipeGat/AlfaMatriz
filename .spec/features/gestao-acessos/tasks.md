# Tasks: Gestão de acessos pelo servidor

> feature: gestao-acessos

## T-028 — Comando de alteração de acesso [concluida]
- Refs: US-014, AC-029, AC-030
- Arquivos: app/Console/Commands/AlterarAcesso.php, tests/Feature/Acessos/AlterarAcessoCommandTest.php
- Notas: `php artisan alfa:alterar-acesso {email-atual} {--novo-email=} {--senha=}`.
  Sem `--senha`, pergunta em modo oculto (a senha não pode ficar no histórico do
  shell). Recusa conta inexistente, e-mail já usado por outra pessoa e senha
  curta — sem alterar nada nesses casos.

## T-029 — E-mail do administrador vindo do ambiente [concluida]
- Refs: US-015, AC-031
- Arquivos: database/seeders/DadosIniciaisSeeder.php, deploy/.env.producao.exemplo, tests/Feature/Acessos/SeederEmailAdminTest.php
- Notas: o e-mail fixo no seeder faz a conta antiga voltar no próximo `db:seed`.
  Passa a vir de `ADMIN_EMAIL`, mantendo o endereço atual como padrão para não
  quebrar o ambiente local.

## T-030 — Aplicar a troca no servidor [pendente]

- Refs: US-014, AC-029
- Arquivos: README.md
- Notas: execução real — rodar o comando no container com o e-mail novo, conferir
  que o login funciona e que os perfis continuam ligados (fecha ASM-016).
  A senha é digitada pelo dono, não passa por aqui.
