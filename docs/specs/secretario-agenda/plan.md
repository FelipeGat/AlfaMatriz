# Implementation Plan: Secretário, Etapa 2 (Agenda consolidada com Google)

**Spec**: [spec.md](spec.md) · [research.md](research.md) · [data-model.md](data-model.md) ·
[contracts/google.md](contracts/google.md) · 17/10/2026

## Summary

- **Conexão**: OAuth do Google feito pelo próprio Matriz, com tokens cifrados como o do bot do Telegram.
- **Sincronização**: a cada 10 min pelo agendador, guardando o mínimo dos eventos (de ontem a +30 dias).
- **Visão consolidada**: Secretário, Agenda do time e Google, com conflitos e livres.
- **Chat**: perguntas de agenda e de horário livre respondidas sem IA. "Agenda uma reunião…" vira proposta e, só
  depois de confirmada, evento no Google sem convidados.

Tudo continua isolado por conta.

## Technical Context

| Campo | Valor |
|---|---|
| Linguagem | PHP 8.2 / Laravel 12.64 |
| Dependências novas | nenhuma (HTTP direto, research D1) |
| Storage | MariaDB 10.11 / SQLite nos testes |
| Testing | PHPUnit, `Http::fake` para o Google |
| Target | servidor do AlfaMatriz; cron por minuto já existente |
| Config nova | `GOOGLE_SECRETARIO_CLIENT_ID` e `GOOGLE_SECRETARIO_CLIENT_SECRET` (`config/services.php`), pendentes do Felipe |
| Restrições | sem IA no servidor, sem fila, migração aditiva, token fora de log |

## Constitution Check

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade por conta | PASS | Tudo por `user_id` da sessão; `state` amarrado à sessão e à conta; 404 para outra conta. |
| II. IA na máquina | PASS | Nenhuma IA nova; perguntas respondidas por regra. |
| III. Um sistema só | PASS | Mesmo banco e mesma aplicação; nenhum processo novo. |
| IV. Segredos | PASS | Tokens com `encrypted` + `$hidden`; erro guardado como código; id e segredo do cliente no `.env`. |
| V. Prova | PASS | Testes com Google simulado; migração em MariaDB e SQLite. |
| VI. Rastro | PASS | Conectar, desconectar, criar evento e falha viram evento, sem título. |
| VII. Ação externa | PASS | Criar evento só depois da confirmação da pessoa, sem convidados; nada vai a terceiros. Conectar é gesto da própria pessoa no Google. |
| VIII. Zero fabricação | PASS | Endpoints da doc oficial (contracts/google.md); "agendado" só depois do 200; sem sincronização recente, a resposta diz a limitação. |

**Re-check pós-design**: PASS.

## Design

### Novo

- `app/Models/SecretarioGoogle{Conta,Agenda,Evento}.php`
- `app/Services/Secretario/GoogleAgenda.php`: HTTP do OAuth e do Calendar. Tem renovação com trava, erros
  classificados (`nao_enviada`, `recusada`, `invalid_grant`, `limite`) e nunca põe token em exceção.
- `app/Services/Secretario/SincronizadorGoogle.php`: agendas e eventos da janela, apagando o que saiu.
- `app/Services/Secretario/AgendaConsolidada.php`: `periodo(user, de, ate)`, `conflitos()`, `livres(user, dia)`
  e `situacaoGoogle(user)`.
- `app/Services/Secretario/ConsultaDeAgenda.php`: reconhece a pergunta e monta a resposta de texto.
- `app/Console/Commands/SecretarioSincronizarGoogle.php`: `secretario:sincronizar-google`, a cada 10 min.
- `app/Http/Controllers/SecretarioGoogleController.php` e `SecretarioController::agenda`.
- `resources/views/secretario/agenda.blade.php` e o bloco Google em `configuracoes.blade.php`.

### Alterado

| Arquivo | Mudança |
|---|---|
| `Interpretador` | gatilho "agenda/marca/marque" só quando pedido (`agendar: true`); duração "por N min/h" e "das X às Y" |
| `Propostas` | tipo `evento_google`: resumo com agenda, fim, sem convidados e conflitos; confirmação chama o Google |
| `Conversa` | antes de propor, pergunta de agenda vai ao `ConsultaDeAgenda` (só com Google conectado) |
| `routes/web.php`, `routes/console.php`, `config/services.php` | rotas, agendamento, chaves do cliente |
| navegação | "Agenda" no grupo Secretário |

## Project Structure

```
docs/specs/secretario-agenda/{spec,research,data-model,plan,tasks}.md, contracts/google.md
app/Services/Secretario/{GoogleAgenda,SincronizadorGoogle,AgendaConsolidada,ConsultaDeAgenda}.php  +
app/Models/SecretarioGoogle{Conta,Agenda,Evento}.php  +
app/Http/Controllers/SecretarioGoogleController.php  +
app/Console/Commands/SecretarioSincronizarGoogle.php  +
database/migrations/2026_10_17_140000_secretario_google.php  +
resources/views/secretario/agenda.blade.php  +
tests/Feature/Secretario/{GoogleConexao,AgendaConsolidada,ConsultaDeAgenda,EventoGoogle}Test.php  +
```

## Convenções de Borda

| Camada | Estilo | Validação | Fonte da verdade |
|---|---|---|---|
| Banco | snake_case | migração | `2026_10_17_140000_secretario_google.php` |
| JSON da visão | snake_case | `validate` | este plano (`periodo`) |
| Google (envio/resposta) | camelCase do Google (`timeMin`, `htmlLink`) | conversão em `GoogleAgenda` | `contracts/google.md` |
| Datas | o Google em RFC3339 com offset, convertido para America/Sao_Paulo ao gravar | Carbon | `SincronizadorGoogle` |

## Complexity Tracking

Nenhuma violação.
