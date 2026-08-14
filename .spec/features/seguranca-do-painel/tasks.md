# Tasks: Seguranca do painel

> feature: seguranca-do-painel

## T-001 — Tirar do ar a recuperação de senha por e-mail [pendente]
- Refs: US-071, AC-260, AC-261, AC-262
- Arquivos: routes/auth.php, app/Http/Controllers/Auth/NewPasswordController.php, app/Http/Controllers/Auth/PasswordResetLinkController.php, app/Http/Controllers/Auth/RegisteredUserController.php, resources/views/auth/forgot-password.blade.php, resources/views/auth/reset-password.blade.php, resources/views/auth/register.blade.php, tests/Feature/Auth/PasswordResetTest.php, tests/Feature/Seguranca/RecuperacaoDeSenhaTest.php
- Notas: as quatro rotas de `password.request`/`password.email`/`password.reset`/`password.store` saem do grupo `guest`. A view de login NÃO muda — ela já condiciona o link a `Route::has('password.request')`, então sumir a rota some o link (AC-260); confirme isso em vez de editar a view. `PasswordResetTest.php` some junto: ele testa o que deixa de existir. O `RegisteredUserController` e o `register.blade.php` saem no mesmo commit — nunca foram roteados, e um controller de cadastro público parado no repositório é uma linha de rota de distância de virar cadastro público. Deixe o comentário de `routes/auth.php` explicando POR QUE não há recuperação (o `MAIL_MAILER=log`, a senha que nasce do admin com `primeiro_acesso`), senão a próxima pessoa a repõe achando que faltou.

## T-002 — Limitar as tentativas de confirmar a senha [pendente]
- Refs: US-072, AC-263
- Arquivos: routes/auth.php, tests/Feature/Seguranca/ConfirmarSenhaTest.php
- Notas: `throttle:6,1` no `POST confirm-password`, o mesmo par que `verification.verify` e `verification.send` já usam neste arquivo — não invente um limite novo. **Depende de T-001**: mesmo arquivo de rotas.

## T-003 — Fechar a escalada por `usuarios` [pendente]
- Refs: US-073, AC-264, AC-265
- Arquivos: app/Http/Controllers/UsuarioController.php, app/Models/User.php, tests/Feature/Seguranca/EscaladaDeUsuariosTest.php
- Notas: um `ehAdmin()` no `User` (a pergunta já é feita solta em três lugares do `UsuarioController`), e duas recusas: `validar()`/`update()`/`store()` rejeitam o perfil `admin` vindo de quem não é admin, e `redefinirSenha()` rejeita alvo administrador. A recusa é no servidor, não no `disabled` da view — é a mesma regra que o `PerfilController` já escreveu no cabeçalho dele. Registre a tentativa recusada na auditoria: quem tenta se promover é exatamente o que a tabela existe para contar.

## T-004 — Cabeçalhos de segurança emitidos pelo aplicativo [pendente]
- Refs: US-074, AC-266, AC-267, AC-268
- Arquivos: app/Http/Middleware/CabecalhosDeSeguranca.php, bootstrap/app.php, tests/Feature/Seguranca/CabecalhosDeSegurancaTest.php
- Notas: middleware novo no grupo `web`. Emite CSP, `Permissions-Policy` e — só em produção — `Strict-Transport-Security`; e repete `X-Frame-Options`, `X-Content-Type-Options` e `Referrer-Policy`, que hoje só existem no nginx e por isso nenhum teste alcança (AC-268). O `script-src` mantém `'unsafe-inline'` e `'unsafe-eval'`: o Alpine avalia expressão com `new Function`, e sem eles o quadro de tarefas para de funcionar (ASM-061) — escreva isso no comentário, com o que a política DE FATO fecha, para ninguém a ler como proteção contra XSS que ela não é. `img-src` precisa de `data:` (as miniaturas de anexo e os ícones embutidos). Confira no navegador as telas mais pesadas de Alpine antes de dar por pronto: quadro de tarefas, Centro de Controle e o funil de leads.

## T-005 — Endereço de sistema integrado só público e em HTTPS [concluida]
- Refs: US-075, AC-269, AC-270
- Arquivos: app/Rules/EnderecoPublico.php, app/Http/Controllers/SistemaController.php, tests/Feature/Seguranca/EnderecoDeSistemaTest.php
- Notas: regra de validação própria, e não um `regex` na lista de regras — a lista de faixas privadas tem seis entradas e precisa de nome. Resolver o nome do host antes de comparar é tentador e fica FORA: a resolução muda entre a validação e o uso, e prometer o que não se pode garantir é pior que a checagem sintática honesta. Os quatro sistemas de hoje são todos `https://*.alfasolucoes.cloud` (ver `SistemaFactory`), então nada em uso quebra.

## T-006 — Guarda de regressão do fechamento de `/storage/` [concluida]
- Refs: US-076, AC-271
- Arquivos: tests/Feature/Seguranca/DiscoDeAnexosFechadoTest.php
- Notas: o teste lê `deploy/nginx-alfamatriz.conf` e afirma as duas metades — `location ^~ /storage/` com `deny all`, e `location ^~ /storage/marcas/` servindo. É a única proteção dos anexos hoje, e mora num arquivo que a suíte nunca abriu. Afirme o `^~` também: é o que vence a regex do `.php` por precedência, e trocá-lo por `/storage/` puro reabriria a execução de PHP no disco de upload sem mudar nada visível.
