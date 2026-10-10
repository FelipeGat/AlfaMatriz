<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Acesso · Central de Agentes</title><link rel="stylesheet" href="/central.css"></head>
<body class="login-body"><main class="login-card"><span class="eyebrow">CENTRAL DE AGENTES</span><h1>Seu trabalho, uma conversa.</h1><p class="muted">Acesse o histórico compartilhado entre o VS Code e seu Telegram privado.</p>
<form method="post" action="/login">@csrf
<label for="email">E-mail</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required maxlength="254">
<label for="password">Senha</label><input id="password" name="password" type="password" autocomplete="current-password" required>
@if ($errors->any())<p role="alert" class="error">{{ $errors->first() }}</p>@endif
<button type="submit">Entrar na Central</button></form><p class="footnote">Acesso privado · Sem cadastro público</p></main></body></html>
