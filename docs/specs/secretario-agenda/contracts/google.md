# Contrato: Google OAuth 2.0 e Calendar v3 (usados pelo Secretário)

Fonte: documentação oficial do Google, "OAuth 2.0 for Web Server Applications" e "Calendar API v3 reference". Os
mesmos recursos são usados hoje pelo `C:\tmp\gcal\gcal.py` (cliente Python oficial). Nomes de campo marcados
[PROPOSTA — validar na primeira chamada real] onde o uso aqui é o primeiro no repositório.

| Uso | Chamada | Campos usados |
|---|---|---|
| Autorizar | `GET https://accounts.google.com/o/oauth2/v2/auth` | `client_id`, `redirect_uri`, `response_type=code`, `scope`, `access_type=offline`, `prompt=consent`, `state`, `include_granted_scopes=true` |
| Trocar código | `POST https://oauth2.googleapis.com/token` | envio `code`, `client_id`, `client_secret`, `redirect_uri`, `grant_type=authorization_code`; resposta `access_token`, `refresh_token`, `expires_in`, `scope` |
| Renovar | `POST https://oauth2.googleapis.com/token` | `grant_type=refresh_token`, `refresh_token`; erro `invalid_grant` = autorização retirada |
| Revogar | `POST https://oauth2.googleapis.com/revoke` | `token` |
| Agendas | `GET https://www.googleapis.com/calendar/v3/users/me/calendarList` | `items[].id`, `summary`, `primary`, `accessRole`; `nextPageToken` |
| Eventos | `GET https://www.googleapis.com/calendar/v3/calendars/{id}/events` | `timeMin`, `timeMax`, `singleEvents=true`, `orderBy=startTime`, `maxResults=2500`, `pageToken`; `items[].id`, `status`, `summary`, `start.dateTime\|date`, `end.dateTime\|date`, `htmlLink` |
| Criar | `POST https://www.googleapis.com/calendar/v3/calendars/{id}/events?sendUpdates=none` | `summary`, `start.dateTime` + `timeZone`, `end.dateTime` + `timeZone`; resposta `id`, `htmlLink` |

Rotas do Matriz (todas com `permissao:secretario`):

| Método | Rota | O quê |
|---|---|---|
| GET | `/secretario/google/conectar` | gera o `state` (sessão, uso único, 10 min) e redireciona |
| GET | `/secretario/google/retorno` | confere o `state`, troca o código, guarda cifrado, lista agendas, volta a Configurações |
| POST | `/secretario/google/desconectar` | revoga e apaga |
| POST | `/secretario/google/sincronizar` | sincroniza agora |
| PUT | `/secretario/google/agendas/{id}` | `{incluida, padrao, palavras}` |
| GET | `/secretario/agenda` | tela; `?formato=json&de=AAAA-MM-DD&dias=N` devolve a visão |
